<?php

declare(strict_types=1);

namespace Tyhp\Expression;

/**
 * Turns a closure source string into a closure.
 *
 * The source holds the expression shape only. Values are the `$bound` array,
 * exposed to that source as `$__bound`. This file is plain PHP so the lambda
 * package can compile those closures.
 */
final class EvalClosure
{
    public static int $compilations = 0;

    public static string $lastSource = '';

    /**
     * @param array<int, mixed> $bound
     */
    public static function compile(string $source, array $bound): \Closure
    {
        if (
            !\str_starts_with($source, 'return function (')
            || !\str_contains($source, ') use ($__bound)')
            || \str_contains($source, "\0")
        ) {
            throw new \InvalidArgumentException('Expression source is not a bound closure.');
        }

        $__bound = $bound;
        self::$lastSource = $source;
        self::$compilations++;
        try {
            $result = eval($source);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Could not compile the expression closure.', 0, $error);
        }
        if (!$result instanceof \Closure) {
            throw new \RuntimeException('Compiled expression was not a closure.');
        }
        return $result;
    }
}
