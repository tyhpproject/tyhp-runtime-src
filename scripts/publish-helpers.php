<?php

// JSON, file, and Packagist helpers for publish-runtime-packages.sh.
// Usage: php publish-helpers.php <command> [args...]
//   composer-name <composer.json>                     name, or empty
//   composer-version <composer.json>                  version (exit 1 when missing)
//   handed-off <composer.json>                        exit 0 when extra.tyhp.ownership.status is handed-off
//   repo-description <composer.json> <pkg>            GitHub About text (at most 350 characters)
//   published-composer <src> <dest>                   copy without "repositories"
//   published-impl-composer <src> <dest>              published -impl composer.json
//   normalize-generated <dest> <tag>                  copy the tag's " * Generated:" line onto the worktree
//   packagist-exists <name> <user-agent>              exit 0 = on Packagist, 1 = missing, 2 = lookup failed
//   packagist-username <credentials>                  validate credentials, print the username
//   packagist-create <credentials> <repo-url> <user-agent>
//   packagist-update <credentials> <repo-url> <user-agent>
//                                                     POST /api/update-package (the Update button)
//   packagist-missing-tags <name> <user-agent>        stdin: git ls-remote --tags output
//                                                     stdout: tags Packagist has not crawled
//                                                     exit 0 = lookup ok, 1 = package missing, 2 = lookup failed
// The Packagist token is read from the credentials file here, so it never appears in a process argv.
// check-version-bumps.php includes this file for published_composer_text() and
// published_impl_composer_text().

declare(strict_types=1);

const GENERATED_PREFIX = ' * Generated:';

function fail(string $message, int $code = 1): never
{
    \fwrite(\STDERR, $message . "\n");
    exit($code);
}

function decode_object(string $path): \stdClass
{
    $text = @\file_get_contents($path);
    if ($text === false) {
        throw new \RuntimeException("cannot read {$path}");
    }
    $doc = \json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
    if (!$doc instanceof \stdClass) {
        throw new \RuntimeException("{$path} is not a JSON object");
    }
    return $doc;
}

function load_composer(string $path): \stdClass
{
    try {
        return decode_object($path);
    } catch (\Throwable $e) {
        fail("error: {$e->getMessage()}");
    }
}

function field(mixed $object, string $key): mixed
{
    return $object instanceof \stdClass && \property_exists($object, $key) ? $object->{$key} : null;
}

function text(mixed $value): string
{
    if (\is_string($value)) {
        return $value;
    }
    if (\is_int($value) || \is_float($value)) {
        return (string) $value;
    }
    return '';
}

function json_text(mixed $value): string
{
    return \json_encode(
        $value,
        \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
    ) . "\n";
}

function write_text(string $path, string $text): void
{
    if (\file_put_contents($path, $text) === false) {
        fail("error: cannot write {$path}");
    }
}

/** Published composer.json: the source without in-tree path repositories. */
function published_composer_text(string $src): string
{
    $data = decode_object($src);
    unset($data->repositories);
    return json_text($data);
}

function args(array $argv, int $count, string $usage): array
{
    if (\count($argv) !== $count + 2) {
        fail("usage: php publish-helpers.php {$usage}", 2);
    }
    return \array_slice($argv, 2);
}

function ownership_handed_off(string $path): bool
{
    try {
        $doc = decode_object($path);
    } catch (\Throwable $e) {
        fail("error: {$e->getMessage()}");
    }
    return field(field(field(field($doc, 'extra'), 'tyhp'), 'ownership'), 'status') === 'handed-off';
}

function repo_description(string $path, string $pkg): string
{
    try {
        $doc = decode_object($path);
    } catch (\Throwable) {
        $doc = new \stdClass();
    }
    $desc = \trim((string) \preg_replace('/\s+/u', ' ', text(field($doc, 'description'))));
    if ($desc === '') {
        $name = \trim(text(field($doc, 'name')));
        $desc = 'Tyhp package ' . ($name !== '' ? $name : $pkg);
    }
    $chars = \preg_split('//u', $desc, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    if (\count($chars) > 350) {
        $desc = \implode('', \array_slice($chars, 0, 347)) . '...';
    }
    return $desc;
}

/** Published -impl composer.json: canonical -impl name, extra.tyhp.public, no path repositories. */
function published_impl_composer_text(string $src): string
{
    $data = decode_object($src);
    unset($data->repositories);
    $name = text(field($data, 'name'));
    if (!field($data, 'extra') instanceof \stdClass) {
        $data->extra = new \stdClass();
    }
    if (!field($data->extra, 'tyhp') instanceof \stdClass) {
        $data->extra->tyhp = new \stdClass();
    }
    $tyhp = $data->extra->tyhp;
    $public = text(field($tyhp, 'public'));
    if (\str_ends_with($name, '-impl')) {
        $public = $public !== '' ? $public : \substr($name, 0, -\strlen('-impl'));
        $impl = $name;
    } else {
        $public = $public !== '' ? $public : $name;
        $impl = $public . '-impl';
    }
    $data->name = $impl;
    $tyhp->public = $public;
    if (!\str_contains(text(field($data, 'description')), 'Implementation package')) {
        $data->description = "Implementation package for {$public}. Require {$public}, not this package.";
    }
    $keywords = field($data, 'keywords');
    if (!\is_array($keywords)) {
        $keywords = ['dev', 'static analysis'];
    }
    if (!\in_array('internal', $keywords, true)) {
        $keywords[] = 'internal';
    }
    $data->keywords = $keywords;
    $data->type = 'library';
    return json_text($data);
}

function git_show(string $dest, string $spec): ?string
{
    $proc = \proc_open(
        ['git', '-C', $dest, 'show', $spec],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    if (!\is_resource($proc)) {
        return null;
    }
    $out = \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    $code = \proc_close($proc);
    return $code === 0 && \is_string($out) ? $out : null;
}

function first_generated_line(string $text): ?string
{
    foreach (\preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
        if (\str_starts_with($line, GENERATED_PREFIX)) {
            return $line;
        }
    }
    return null;
}

function normalize_generated_timestamps(string $dest, string $tag): void
{
    $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($dest, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $file): bool => !($file->isDir() && $file->getFilename() === '.git'),
    ));
    $root = \rtrim($dest, '/') . '/';
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $text = @\file_get_contents($path);
        if ($text === false || !\str_contains($text, GENERATED_PREFIX) || \preg_match('//u', $text) !== 1) {
            continue;
        }
        $tagged = git_show($dest, $tag . ':' . \substr($path, \strlen($root)));
        if ($tagged === null) {
            continue;
        }
        $normalized = with_tag_generated_line($text, $tagged);
        if ($normalized !== $text) {
            \file_put_contents($path, $normalized);
        }
    }
}

/** $text with its first " * Generated:" line replaced by the one in $tagged. */
function with_tag_generated_line(string $text, string $tagged): string
{
    $tagLine = first_generated_line($tagged);
    if ($tagLine === null) {
        return $text;
    }
    $lines = \preg_split('/(?<=\n)/', $text) ?: [];
    foreach ($lines as $i => $line) {
        $raw = \rtrim($line, "\r\n");
        if (\str_starts_with($raw, GENERATED_PREFIX)) {
            $lines[$i] = $tagLine . \substr($line, \strlen($raw));
            return \implode('', $lines);
        }
    }
    return $text;
}

/** @return array{string, string} username, token */
function packagist_credentials(string $path): array
{
    $text = @\file_get_contents($path);
    if ($text === false) {
        fail("error: cannot read {$path}");
    }
    $username = '';
    $token = '';
    foreach (\preg_split('/\r\n|\n|\r/', $text) ?: [] as $raw) {
        $line = \trim($raw);
        if ($line === '' || \str_starts_with($line, '#') || !\str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = \explode('=', $line, 2);
        $value = \trim(\trim(\trim($value), "'"), '"');
        match (\trim($key)) {
            'PACKAGIST_USERNAME' => $username = $value,
            'PACKAGIST_API_TOKEN' => $token = $value,
            default => null,
        };
    }
    if ($username === '') {
        fail("error: PACKAGIST_USERNAME is empty in {$path}");
    }
    if ($token === '') {
        fail("error: PACKAGIST_API_TOKEN is empty in {$path}");
    }
    return [$username, $token];
}

/** @return array{int, string} HTTP status (0 when the request did not complete), body or transport error */
function http_request(string $url, string $userAgent, ?string $body = null, array $headers = []): array
{
    if (!\function_exists('curl_init')) {
        fail('error: the PHP curl extension is required for Packagist requests', 2);
    }
    $ch = \curl_init($url);
    $options = [
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_TIMEOUT => 60,
        \CURLOPT_USERAGENT => $userAgent,
        \CURLOPT_HTTPHEADER => $headers,
        \CURLOPT_FOLLOWLOCATION => $body === null,
    ];
    if ($body !== null) {
        $options[\CURLOPT_POST] = true;
        $options[\CURLOPT_POSTFIELDS] = $body;
    }
    \curl_setopt_array($ch, $options);
    $response = \curl_exec($ch);
    if (!\is_string($response)) {
        return [0, \curl_error($ch)];
    }
    return [(int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE), $response];
}

function packagist_exists(string $name, string $userAgent): int
{
    $parts = \explode('/', $name, 2);
    if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        \fwrite(\STDERR, 'error: invalid Composer name ' . \json_encode($name) . "\n");
        return 2;
    }
    $url = 'https://packagist.org/packages/' . \rawurlencode($parts[0]) . '/' . \rawurlencode($parts[1]) . '.json';
    [$status, $body] = http_request($url, $userAgent);
    if ($status >= 200 && $status < 300) {
        return 0;
    }
    if ($status === 404) {
        return 1;
    }
    $reason = $status === 0 ? $body : "HTTP {$status}";
    \fwrite(\STDERR, "error: Packagist lookup {$name} failed: {$reason}\n");
    return 2;
}

function mentions_existing(string $text): bool
{
    $lowered = \strtolower($text);
    return \str_contains($lowered, 'already') || \str_contains($lowered, 'exists');
}

function packagist_name_parts(string $name): ?array
{
    $parts = \explode('/', $name, 2);
    if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        \fwrite(\STDERR, 'error: invalid Composer name ' . \json_encode($name) . "\n");
        return null;
    }
    return $parts;
}

/** @return array{int, array<string, true>} status, version set (empty unless status is 0) */
function packagist_release_index(string $name, string $userAgent): array
{
    $parts = packagist_name_parts($name);
    if ($parts === null) {
        return [2, ''];
    }
    $url = 'https://repo.packagist.org/p2/' . \rawurlencode($parts[0]) . '/' . \rawurlencode($parts[1]) . '.json';
    [$status, $body] = http_request($url, $userAgent);
    if ($status === 404) {
        return [1, []];
    }
    if ($status < 200 || $status >= 300) {
        $reason = $status === 0 ? $body : "HTTP {$status}";
        \fwrite(\STDERR, "error: Packagist metadata {$name} failed: {$reason}\n");
        return [2, []];
    }
    try {
        $doc = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, "error: Packagist metadata {$name} is not JSON: {$e->getMessage()}\n");
        return [2, []];
    }
    $items = \is_array($doc) ? ($doc['packages'][$name] ?? null) : null;
    if (!\is_array($items)) {
        \fwrite(\STDERR, "error: Packagist metadata {$name} has no package list\n");
        return [2, []];
    }
    $versions = [];
    foreach ($items as $item) {
        if (!\is_array($item) || !isset($item['version']) || !\is_string($item['version'])) {
            continue;
        }
        $versions[$item['version']] = true;
        $stripped = \ltrim($item['version'], 'vV');
        if ($stripped !== $item['version']) {
            $versions[$stripped] = true;
        }
    }
    if ($items !== [] && $versions === []) {
        \fwrite(\STDERR, "error: Packagist metadata {$name} has no version field\n");
        return [2, []];
    }
    return [0, $versions];
}

function tag_from_ls_remote_line(string $line): string
{
    $line = \trim($line);
    if ($line === '' || \str_contains($line, '^{}')) {
        return '';
    }
    if (\str_contains($line, 'refs/tags/')) {
        $line = \substr($line, \strrpos($line, 'refs/tags/') + \strlen('refs/tags/'));
    }
    if (\str_starts_with($line, 'v') || \str_starts_with($line, 'V')) {
        $bare = \substr($line, 1);
        if ($bare !== '' && \preg_match('/^[0-9]/', $bare) === 1) {
            return $bare;
        }
    }
    return $line;
}

/** Exit 0 and print tags Packagist has not crawled. Exit 1 when the package is missing. */
function packagist_missing_tags(string $name, string $userAgent): int
{
    [$status, $versions] = packagist_release_index($name, $userAgent);
    if ($status !== 0) {
        return $status;
    }
    $stdin = \stream_get_contents(\STDIN);
    if (!\is_string($stdin)) {
        \fwrite(\STDERR, "error: cannot read git tags for {$name}\n");
        return 2;
    }
    $missing = [];
    foreach (\preg_split('/\r\n|\n|\r/', $stdin) ?: [] as $line) {
        $tag = tag_from_ls_remote_line($line);
        if ($tag === '' || isset($versions[$tag]) || isset($missing[$tag])) {
            continue;
        }
        $missing[$tag] = true;
    }
    if ($missing !== []) {
        echo \implode("\n", \array_keys($missing)), "\n";
    }
    return 0;
}

function packagist_update(string $credentials, string $repoUrl, string $userAgent): int
{
    [$username, $token] = packagist_credentials($credentials);
    $attempt = 0;
    $sleepFor = 15;
    while (true) {
        $attempt++;
        [$status, $raw] = http_request(
            'https://packagist.org/api/update-package',
            $userAgent,
            (string) \json_encode(['repository' => $repoUrl], \JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json', "Authorization: Bearer {$username}:{$token}"],
        );
        if ($status === 429 || $status === 503) {
            if ($attempt >= 5) {
                \fwrite(\STDERR, "error: Packagist update-package HTTP {$status} for {$repoUrl}\n");
                if ($raw !== '') {
                    \fwrite(\STDERR, $raw . "\n");
                }
                return 1;
            }
            \fwrite(\STDERR, "Packagist update-package HTTP {$status} for {$repoUrl}; retrying in {$sleepFor}s\n");
            \sleep($sleepFor);
            $sleepFor = \min($sleepFor * 2, 120);
            continue;
        }
        break;
    }
    if ($status === 0) {
        \fwrite(\STDERR, "error: Packagist update-package failed for {$repoUrl}: {$raw}\n");
        return 1;
    }
    if ($status === 404) {
        \fwrite(\STDERR, "error: Packagist has no package for {$repoUrl}\n");
        return 2;
    }
    if ($status < 200 || $status >= 300) {
        \fwrite(\STDERR, "error: Packagist update-package HTTP {$status} for {$repoUrl}\n");
        if ($raw !== '') {
            \fwrite(\STDERR, $raw . "\n");
        }
        return 1;
    }
    $payload = \json_decode($raw, true);
    $payload = \is_array($payload) ? $payload : [];
    $state = \strtolower(text($payload['status'] ?? null));
    if ($state !== '' && $state !== 'success') {
        \fwrite(\STDERR, "error: Packagist update-package rejected {$repoUrl}: {$raw}\n");
        return 1;
    }
    $jobs = $payload['jobs'] ?? [];
    if (\is_array($jobs) && $jobs !== []) {
        $ids = [];
        foreach ($jobs as $job) {
            if (\is_scalar($job)) {
                $ids[] = (string) $job;
            }
        }
        if ($ids !== []) {
            echo 'queued ' . \implode(' ', $ids), "\n";
        }
    }
    return 0;
}

function packagist_create(string $credentials, string $repoUrl, string $userAgent): int
{
    [$username, $token] = packagist_credentials($credentials);
    [$status, $raw] = http_request(
        'https://packagist.org/api/create-package',
        $userAgent,
        (string) \json_encode(['repository' => $repoUrl], \JSON_UNESCAPED_SLASHES),
        ['Content-Type: application/json', "Authorization: Bearer {$username}:{$token}"],
    );
    if ($status === 0) {
        \fwrite(\STDERR, "error: Packagist create-package failed for {$repoUrl}: {$raw}\n");
        return 1;
    }
    if ($status < 200 || $status >= 300) {
        if (\in_array($status, [400, 409], true) && mentions_existing($raw)) {
            echo $raw, "\n";
            return 0;
        }
        \fwrite(\STDERR, "error: Packagist create-package HTTP {$status} for {$repoUrl}\n");
        if ($raw !== '') {
            \fwrite(\STDERR, $raw . "\n");
        }
        return 1;
    }
    $payload = \json_decode($raw, true);
    $payload = \is_array($payload) ? $payload : [];
    if (\in_array(\strtolower(text($payload['status'] ?? null)), ['', 'success'], true)) {
        return 0;
    }
    $message = text($payload['message'] ?? null);
    if (mentions_existing($message !== '' ? $message : $raw)) {
        return 0;
    }
    \fwrite(\STDERR, "error: Packagist create-package rejected {$repoUrl}: {$raw}\n");
    return 1;
}

function publish_helpers_main(array $argv): never
{
    $command = $argv[1] ?? '';

    switch ($command) {
        case 'composer-name':
            [$path] = args($argv, 1, 'composer-name <composer.json>');
            try {
                echo text(field(decode_object($path), 'name')), "\n";
            } catch (\Throwable) {
            }
            exit(0);

        case 'composer-version':
            [$path] = args($argv, 1, 'composer-version <composer.json>');
            $version = \trim(text(field(load_composer($path), 'version')));
            if ($version === '') {
                fail("Missing version in {$path}");
            }
            echo $version, "\n";
            exit(0);

        case 'handed-off':
            [$path] = args($argv, 1, 'handed-off <composer.json>');
            exit(ownership_handed_off($path) ? 0 : 1);

        case 'repo-description':
            [$path, $pkg] = args($argv, 2, 'repo-description <composer.json> <pkg>');
            echo repo_description($path, $pkg), "\n";
            exit(0);

        case 'published-composer':
            [$src, $dest] = args($argv, 2, 'published-composer <src> <dest>');
            try {
                write_text($dest, published_composer_text($src));
            } catch (\Throwable $e) {
                fail("error: {$e->getMessage()}");
            }
            exit(0);

        case 'published-impl-composer':
            [$src, $dest] = args($argv, 2, 'published-impl-composer <src> <dest>');
            try {
                write_text($dest, published_impl_composer_text($src));
            } catch (\Throwable $e) {
                fail("error: {$e->getMessage()}");
            }
            exit(0);

        case 'normalize-generated':
            [$dest, $tag] = args($argv, 2, 'normalize-generated <dest> <tag>');
            normalize_generated_timestamps($dest, $tag);
            exit(0);

        case 'packagist-exists':
            [$name, $userAgent] = args($argv, 2, 'packagist-exists <name> <user-agent>');
            exit(packagist_exists($name, $userAgent));

        case 'packagist-username':
            [$path] = args($argv, 1, 'packagist-username <credentials>');
            [$username] = packagist_credentials($path);
            echo $username, "\n";
            exit(0);

        case 'packagist-create':
            [$path, $repoUrl, $userAgent] = args($argv, 3, 'packagist-create <credentials> <repo-url> <user-agent>');
            exit(packagist_create($path, $repoUrl, $userAgent));

        case 'packagist-update':
            [$path, $repoUrl, $userAgent] = args($argv, 3, 'packagist-update <credentials> <repo-url> <user-agent>');
            exit(packagist_update($path, $repoUrl, $userAgent));

        case 'packagist-missing-tags':
            [$name, $userAgent] = args($argv, 2, 'packagist-missing-tags <name> <user-agent>');
            exit(packagist_missing_tags($name, $userAgent));

        default:
            fail("usage: php publish-helpers.php <command> [args...] (unknown command: {$command})", 2);
    }
}

if (\get_included_files()[0] === __FILE__) {
    publish_helpers_main($argv);
}
