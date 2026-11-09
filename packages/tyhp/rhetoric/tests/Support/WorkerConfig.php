<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric\Support;

use Illuminate\Contracts\Config\Repository;

final class WorkerConfig implements Repository, \ArrayAccess
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public function has($key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function get($key, $default = null): mixed
    {
        $current = $this->items;
        foreach (\explode('.', (string) $key) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function set($key, $value = null): void
    {
        $segments = \explode('.', (string) $key);
        $current = &$this->items;
        foreach ($segments as $index => $segment) {
            if ($index === \count($segments) - 1) {
                $current[$segment] = $value;

                return;
            }
            if (!isset($current[$segment]) || !\is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
    }

    public function prepend($key, $value): void
    {
    }

    public function push($key, $value): void
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}
