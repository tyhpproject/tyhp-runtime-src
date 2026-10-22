<?php

// Verify emitted runtime-package Source Map v3 files against their PHP and Tyhp sources.
// Usage: php verify-sourcemaps.php

declare(strict_types=1);

const VLQ_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
// Only declaration-start lines (not doc comments that mention "class already" / "class wins").
const DECLARATION = '/^\s*(?:(?:final|abstract|readonly|public|protected|private|static)\s+)*'
    . '(?:(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)'
    . '|function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\()/';

function show(mixed $value): string
{
    return \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: \var_export($value, true);
}

/** @return list<int> */
function decode_vlq_values(string $segment): array
{
    $values = [];
    $result = 0;
    $shift = 0;
    $length = \strlen($segment);
    for ($i = 0; $i < $length; $i++) {
        $digit = \strpos(VLQ_ALPHABET, $segment[$i]);
        if ($digit === false) {
            throw new \UnexpectedValueException('invalid VLQ character ' . show($segment[$i]));
        }
        $result += ($digit & 31) << $shift;
        if (($digit & 32) !== 0) {
            $shift += 5;
            continue;
        }
        $magnitude = $result >> 1;
        $values[] = ($result & 1) !== 0 ? -$magnitude : $magnitude;
        $result = 0;
        $shift = 0;
    }
    if ($shift !== 0) {
        throw new \UnexpectedValueException('truncated VLQ segment');
    }
    return $values;
}

/** @return list<array{int, int, int, int, int, ?int}> (gen_line, gen_col, src_idx, orig_line, orig_col, name_idx) */
function decode_mappings(string $mappings): array
{
    $decoded = [];
    $genLine = 0;
    $prevSrc = 0;
    $prevOrigLine = 0;
    $prevOrigCol = 0;
    $prevName = 0;
    foreach (\explode(';', $mappings) as $group) {
        $prevGenCol = 0;
        if ($group !== '') {
            foreach (\explode(',', $group) as $segment) {
                if ($segment === '') {
                    continue;
                }
                $values = decode_vlq_values($segment);
                $count = \count($values);
                if (!\in_array($count, [1, 4, 5], true)) {
                    throw new \UnexpectedValueException("illegal VLQ field count {$count} in " . show($segment));
                }
                $genCol = $prevGenCol + $values[0];
                $prevGenCol = $genCol;
                if ($count === 1) {
                    continue;
                }
                $srcIdx = $prevSrc + $values[1];
                $origLine = $prevOrigLine + $values[2];
                $origCol = $prevOrigCol + $values[3];
                $prevSrc = $srcIdx;
                $prevOrigLine = $origLine;
                $prevOrigCol = $origCol;
                $nameIdx = null;
                if ($count === 5) {
                    $nameIdx = $prevName + $values[4];
                    $prevName = $nameIdx;
                }
                $decoded[] = [$genLine, $genCol, $srcIdx, $origLine, $origCol, $nameIdx];
            }
        }
        $genLine++;
    }
    return $decoded;
}

/** @return list<string> */
function php_lines_without_map_url(string $php): array
{
    return \array_values(\array_filter(
        \explode("\n", $php),
        static fn (string $line): bool => !\str_contains($line, 'sourceMappingURL='),
    ));
}

function resolve_source_text(string $sourceRoot, string $source, ?array $sourcesContent, int $index, string $packageDir): ?string
{
    if ($sourcesContent !== null && $index < \count($sourcesContent)) {
        $embedded = $sourcesContent[$index];
        if (\is_string($embedded)) {
            return $embedded;
        }
    }
    $rel = \ltrim($sourceRoot . $source, '/');
    $candidate = \realpath($packageDir . '/' . $rel);
    if ($candidate !== false && \is_file($candidate)) {
        $text = \file_get_contents($candidate);
        return $text === false ? null : $text;
    }
    return null;
}

/** @return list<string> */
function check_php_file(string $phpPath, string $packageDir): array
{
    $errors = [];
    $mapPath = $phpPath . '.map';
    $phpName = \basename($phpPath);
    $php = (string) \file_get_contents($phpPath);
    if (!\is_file($mapPath)) {
        return ["{$phpPath}: missing " . \basename($mapPath)];
    }
    if (!\str_contains($php, "//# sourceMappingURL={$phpName}.map")) {
        $errors[] = "{$phpPath}: missing sourceMappingURL comment";
    }

    try {
        $data = \json_decode((string) \file_get_contents($mapPath), true, 512, \JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        return ["{$mapPath}: invalid JSON ({$e->getMessage()})"];
    }
    if (!\is_array($data)) {
        return ["{$mapPath}: invalid JSON (not an object)"];
    }

    if (($data['version'] ?? null) !== 3) {
        $errors[] = "{$mapPath}: version is " . show($data['version'] ?? null) . ', expected 3';
    }
    if (($data['file'] ?? null) !== $phpName) {
        $errors[] = "{$mapPath}: file is " . show($data['file'] ?? null) . ', expected ' . show($phpName);
    }

    $sources = $data['sources'] ?? null;
    $mappings = $data['mappings'] ?? null;
    $sourceRoot = $data['sourceRoot'] ?? '';
    $sourceRoot = \is_string($sourceRoot) ? $sourceRoot : '';
    if (!\is_array($sources) || !\array_is_list($sources) || $sources === []) {
        $errors[] = "{$mapPath}: sources must be a non-empty array";
        return $errors;
    }

    $phpLines = php_lines_without_map_url($php);
    $hasDeclaration = false;
    foreach ($phpLines as $line) {
        if (\preg_match(DECLARATION, $line) === 1) {
            $hasDeclaration = true;
            break;
        }
    }
    if (!$hasDeclaration) {
        // Struct-shape / callable-shape type aliases erase to nothing, so the
        // namespace file is declare + namespace only. Empty mappings are expected.
        return $errors;
    }

    if (!\is_string($mappings) || $mappings === '') {
        $errors[] = "{$mapPath}: mappings must be a non-empty string";
        return $errors;
    }

    try {
        $decoded = decode_mappings($mappings);
    } catch (\Throwable $e) {
        $errors[] = "{$mapPath}: VLQ decode failed ({$e->getMessage()})";
        return $errors;
    }
    if ($decoded === []) {
        $errors[] = "{$mapPath}: decoded mappings are empty";
        return $errors;
    }

    $sourcesContent = $data['sourcesContent'] ?? null;
    if ($sourcesContent !== null && (!\is_array($sourcesContent) || \count($sourcesContent) !== \count($sources))) {
        $errors[] = "{$mapPath}: sourcesContent length does not match sources";
        $sourcesContent = null;
    }

    $sourceTexts = [];
    foreach ($sources as $i => $source) {
        if (!\is_string($source) || $source === '') {
            $errors[] = "{$mapPath}: sources[{$i}] is empty";
            $sourceTexts[] = null;
            continue;
        }
        $text = resolve_source_text($sourceRoot, $source, $sourcesContent, $i, $packageDir);
        if ($text === null) {
            $errors[] = "{$mapPath}: cannot resolve source {$sourceRoot}{$source}";
        }
        $sourceTexts[] = $text;
    }

    $phpLineCount = \count($phpLines);
    $sourceCount = \count($sources);
    foreach ($decoded as [$genLine, , $srcIdx, $origLine]) {
        if ($genLine >= $phpLineCount) {
            $errors[] = "{$mapPath}: mapping generated line {$genLine} is past PHP ({$phpLineCount} lines)";
            break;
        }
        if ($srcIdx < 0 || $srcIdx >= $sourceCount) {
            $errors[] = "{$mapPath}: source index {$srcIdx} out of range";
            break;
        }
        $srcText = $sourceTexts[$srcIdx];
        if ($srcText === null) {
            continue;
        }
        $srcLineCount = \count(\explode("\n", $srcText));
        if ($origLine < 0 || $origLine >= $srcLineCount) {
            $errors[] = "{$mapPath}: original line {$origLine} out of range for {$sources[$srcIdx]} ({$srcLineCount} lines)";
            break;
        }
    }

    // Spot-check: a class/function declaration in PHP should map back to a Tyhp line
    // that names the original identifier. Compiler-generated helpers (__tyhp*, new_Tyhp_*)
    // correctly map to the owning type and are skipped.
    $mappedByGen = [];
    foreach ($decoded as [$genLine, , $srcIdx, $origLine]) {
        $mappedByGen[$genLine][] = [$srcIdx, $origLine];
    }
    $checked = 0;
    foreach ($phpLines as $genLine => $line) {
        if (\preg_match(DECLARATION, $line, $match) !== 1) {
            continue;
        }
        $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        if (\str_contains($name, '__tyhp') || \str_starts_with($name, 'new_Tyhp_')) {
            continue;
        }
        $hits = $mappedByGen[$genLine] ?? [];
        if ($hits === []) {
            continue;
        }
        [$srcIdx, $origLine] = $hits[0];
        $srcText = $sourceTexts[$srcIdx];
        if ($srcText === null) {
            continue;
        }
        $srcLines = \explode("\n", $srcText);
        if ($origLine >= \count($srcLines)) {
            continue;
        }
        $original = $srcLines[$origLine];
        $start = \max(0, $origLine - 1);
        $window = \implode('', \array_slice($srcLines, $start, $origLine + 2 - $start));
        if (!\str_contains($original, $name) && !\str_contains($window, $name)) {
            // Compiler-injected members (e.g. PHP 8.2/8.3 property-hook polyfill
            // constructors) map to the owning type declaration.
            if (\preg_match('/\b(?:class|interface|trait|enum)\b/', $original) === 1) {
                continue;
            }
            $errors[] = "{$mapPath}: generated '{$name}' on PHP line " . ($genLine + 1)
                . " mapped to {$sources[$srcIdx]}:" . ($origLine + 1)
                . ' which does not contain that name (' . show(\trim($original)) . ')';
        }
        $checked++;
        if ($checked >= 8) {
            break;
        }
    }

    return $errors;
}

function source_xy(string $composerJson): string
{
    $doc = \json_decode((string) \file_get_contents($composerJson), true);
    $raw = \is_array($doc) && \is_string($doc['version'] ?? null) ? $doc['version'] : '';
    if (\preg_match('/^(\d+)\.(\d+)(?:\.(\d+)(?:-([0-9A-Za-z.-]+))?)?$/D', $raw, $m) !== 1) {
        throw new \UnexpectedValueException("invalid version in {$composerJson}: " . show($raw));
    }
    if (($m[3] ?? '') === '') {
        return "{$m[1]}.{$m[2]}";
    }
    $xy = "{$m[2]}.{$m[3]}";
    if (($m[4] ?? '') !== '') {
        $xy .= "-{$m[4]}";
    }
    return $xy;
}

/** @return list<string> */
function php_files_under(string $dir): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && \str_ends_with($file->getFilename(), '.php')) {
            $files[] = $file->getPathname();
        }
    }
    \sort($files, \SORT_STRING);
    return $files;
}

/** @return list<array{string, string}> (php file, package dir) */
function iter_php_files(string $distRoot): array
{
    $pairs = [];
    $composerFiles = \glob(\dirname($distRoot) . '/tyhp/*/composer.json') ?: [];
    \sort($composerFiles, \SORT_STRING);
    foreach ($composerFiles as $composerJson) {
        $packageDir = \dirname($composerJson);
        $pkgName = \basename($packageDir);
        $xy = source_xy($composerJson);
        $srcDirs = \glob("{$distRoot}/tyhp-{$pkgName}/*.{$xy}/src", \GLOB_ONLYDIR) ?: [];
        \sort($srcDirs, \SORT_STRING);
        foreach ($srcDirs as $srcDir) {
            foreach (php_files_under($srcDir) as $phpPath) {
                $pairs[] = [$phpPath, $packageDir];
            }
        }
    }
    return $pairs;
}

$distRoot = __DIR__ . '/dist';
if (!\is_dir($distRoot)) {
    \fwrite(\STDERR, "dist directory not found: {$distRoot}\n");
    exit(1);
}

try {
    $pairs = iter_php_files($distRoot);
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(1);
}
if ($pairs === []) {
    \fwrite(\STDERR, "no emitted PHP files under {$distRoot}\n");
    exit(1);
}

$errors = [];
foreach ($pairs as [$phpPath, $packageDir]) {
    \array_push($errors, ...check_php_file($phpPath, $packageDir));
}

if ($errors !== []) {
    echo 'Source map verification failed (' . \count($errors) . ' issue(s), ' . \count($pairs) . " PHP files):\n";
    foreach ($errors as $error) {
        echo "  {$error}\n";
    }
    exit(1);
}

echo 'Source maps OK: ' . \count($pairs) . " PHP files, each with a valid v3 map.\n";
exit(0);
