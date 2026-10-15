<?php

declare(strict_types=1);

namespace Tyhp\Composer;

final class ExtraRequireSyncException extends \RuntimeException
{
    public function __construct(
        public readonly int $tyhpCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $tyhpCode, $previous);
    }
}
