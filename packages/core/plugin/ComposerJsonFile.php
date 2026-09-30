<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Reads and writes root composer.json require-dev (and allow-plugins.tyhp/core) while
 * preserving other keys.
 */
final class ComposerJsonFile
{
    private const JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        try {
            $raw = \file_get_contents($this->path);
            if ($raw === false) {
                throw new \RuntimeException('file_get_contents failed');
            }
            $decoded = \json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new ExtraRequireSyncException(
                DiagnosticCodes::ROOT_JSON_WRITE_FAILED,
                DiagnosticCodes::rootJsonMessage($this->path, $e->getMessage()),
                $e,
            );
        }

        if (!$decoded instanceof \stdClass) {
            throw new ExtraRequireSyncException(
                DiagnosticCodes::ROOT_JSON_WRITE_FAILED,
                DiagnosticCodes::rootJsonMessage($this->path, 'root is not a JSON object'),
            );
        }

        try {
            $data = \json_decode(\json_encode($decoded, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new ExtraRequireSyncException(
                DiagnosticCodes::ROOT_JSON_WRITE_FAILED,
                DiagnosticCodes::rootJsonMessage($this->path, $e->getMessage()),
                $e,
            );
        }

        if (!\is_array($data)) {
            throw new ExtraRequireSyncException(
                DiagnosticCodes::ROOT_JSON_WRITE_FAILED,
                DiagnosticCodes::rootJsonMessage($this->path, 'root is not a JSON object'),
            );
        }

        return $data;
    }

    /**
     * @param array<string, string> $requireDev
     */
    public function writeRequireDev(array $requireDev): void
    {
        $data = $this->read();
        $data['require-dev'] = self::orderRequireDev(
            \is_array($data['require-dev'] ?? null) ? $data['require-dev'] : [],
            $requireDev,
        );
        self::mergeAllowPlugins($data);
        $this->write($data);
    }

    /**
     * Writes config.allow-plugins.tyhp/core = true when the key is missing.
     * Existing values (including an explicit false) are left untouched.
     *
     * @return bool True when the file was rewritten
     */
    public function ensureAllowPlugins(): bool
    {
        $data = $this->read();
        if (!self::mergeAllowPlugins($data)) {
            return false;
        }

        $this->write($data);

        return true;
    }

    /**
     * Adds config.allow-plugins.tyhp/core = true when the key is missing.
     * Does not overwrite an explicit false or a non-object config/allow-plugins.
     *
     * @param array<string, mixed> $data
     * @return bool True when $data was mutated
     */
    public static function mergeAllowPlugins(array &$data): bool
    {
        if (\array_key_exists('config', $data) && !\is_array($data['config'])) {
            return false;
        }

        $config = \is_array($data['config'] ?? null) ? $data['config'] : [];
        if (\array_key_exists('allow-plugins', $config) && !\is_array($config['allow-plugins'])) {
            return false;
        }

        $allow = \is_array($config['allow-plugins'] ?? null) ? $config['allow-plugins'] : [];
        if (\array_key_exists('tyhp/core', $allow)) {
            return false;
        }

        $allow['tyhp/core'] = true;
        $config['allow-plugins'] = $allow;
        $data['config'] = $config;

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        try {
            if (\class_exists(\Composer\Json\JsonFile::class)) {
                $file = new \Composer\Json\JsonFile($this->path);
                $file->write($data);

                return;
            }

            $json = \json_encode($data, self::JSON_FLAGS | \JSON_THROW_ON_ERROR);
            if (!\str_ends_with($json, "\n")) {
                $json .= "\n";
            }
            if (\file_put_contents($this->path, $json, \LOCK_EX) === false) {
                throw new \RuntimeException('file_put_contents failed');
            }
        } catch (ExtraRequireSyncException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExtraRequireSyncException(
                DiagnosticCodes::ROOT_JSON_WRITE_FAILED,
                DiagnosticCodes::rootJsonMessage($this->path, $e->getMessage()),
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, string> $merged
     * @return array<string, string>
     */
    public static function orderRequireDev(array $existing, array $merged): array
    {
        $ordered = [];
        foreach ($existing as $name => $_) {
            $name = \strtolower((string) $name);
            if (isset($merged[$name])) {
                $ordered[$name] = $merged[$name];
            }
        }
        $appended = [];
        foreach ($merged as $name => $constraint) {
            if (!isset($ordered[$name])) {
                $appended[$name] = $constraint;
            }
        }
        \ksort($appended, \SORT_STRING);
        foreach ($appended as $name => $constraint) {
            $ordered[$name] = $constraint;
        }

        return $ordered;
    }
}
