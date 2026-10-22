<?php

// composer.json reads for build-common.sh.
// Usage: php build-helpers.php <command> <composer.json>
//   source-version-parts      X|Y|prerelease|raw (dist X.Y from the source version)
//   source-version            raw source version, validated
//   interop-contract-version  extra.tyhp.interopContractVersion
//   tyhp-require              extra.tyhp.require as one-line JSON ({} when omitted)
//   tyhp-package              extra.tyhp.package as JSON indented for the dist template

declare(strict_types=1);

const VERSION_PATTERN = '/^(\d+)\.(\d+)(?:\.(\d+)(?:-([0-9A-Za-z.-]+))?)?$/D';

function fail(string $message): never
{
    \fwrite(\STDERR, $message . "\n");
    exit(1);
}

function show(mixed $value): string
{
    return \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: \var_export($value, true);
}

function load_composer(string $path): \stdClass
{
    $text = @\file_get_contents($path);
    if ($text === false) {
        fail("cannot read {$path}");
    }
    try {
        $doc = \json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        fail("invalid JSON in {$path}: {$e->getMessage()}");
    }
    if (!$doc instanceof \stdClass) {
        fail("{$path} is not a JSON object");
    }
    return $doc;
}

function field(mixed $object, string $key): mixed
{
    return $object instanceof \stdClass && \property_exists($object, $key) ? $object->{$key} : null;
}

function tyhp_extra(\stdClass $doc): mixed
{
    return field(field($doc, 'extra'), 'tyhp');
}

function source_version(string $path, \stdClass $doc): array
{
    $raw = field($doc, 'version');
    $raw = \is_string($raw) ? $raw : '';
    if (\preg_match(VERSION_PATTERN, $raw, $m) !== 1) {
        fail("Invalid version in {$path}: " . show($raw) . ' (expected X.Y or MAJOR.MINOR.PATCH[-prerelease])');
    }
    return [$raw, $m];
}

function encode(mixed $value, int $flags = 0): string
{
    return \json_encode($value, $flags | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
}

if ($argc !== 3) {
    fail('usage: php build-helpers.php <command> <composer.json>');
}

[, $command, $path] = $argv;
$doc = load_composer($path);

switch ($command) {
    case 'source-version-parts':
        [$raw, $m] = source_version($path, $doc);
        if (($m[3] ?? '') === '') {
            echo "{$m[1]}|{$m[2]}||{$raw}\n";
        } else {
            $pre = $m[4] ?? '';
            echo "{$m[2]}|{$m[3]}|{$pre}|{$raw}\n";
        }
        break;

    case 'source-version':
        [$raw] = source_version($path, $doc);
        echo $raw, "\n";
        break;

    case 'interop-contract-version':
        $version = field(tyhp_extra($doc), 'interopContractVersion');
        if (!\is_int($version)) {
            fail("Missing/invalid extra.tyhp.interopContractVersion in {$path}: " . show($version));
        }
        echo $version, "\n";
        break;

    case 'tyhp-require':
        $require = field(tyhp_extra($doc), 'require') ?? new \stdClass();
        if (!$require instanceof \stdClass) {
            fail("Missing/invalid extra.tyhp.require in {$path}: " . show($require));
        }
        $pairs = [];
        foreach (\get_object_vars($require) as $name => $constraint) {
            if (!\is_string($constraint)) {
                fail("Invalid extra.tyhp.require entry in {$path}: " . show($name) . ' -> ' . show($constraint));
            }
            $pairs[] = encode((string) $name) . ': ' . encode($constraint);
        }
        echo '{', \implode(', ', $pairs), "}\n";
        break;

    case 'tyhp-package':
        $package = field(tyhp_extra($doc), 'package');
        if (!$package instanceof \stdClass) {
            fail("Missing/invalid extra.tyhp.package in {$path}: " . show($package));
        }
        $lines = \explode("\n", encode($package, \JSON_PRETTY_PRINT));
        $out = [\array_shift($lines)];
        foreach ($lines as $line) {
            $out[] = $line === '' ? $line : '            ' . $line;
        }
        echo \implode("\n", $out), "\n";
        break;

    default:
        fail("unknown command: {$command}");
}
