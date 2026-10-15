<?php

declare(strict_types=1);

namespace Tyhp\Tests\Async;

use Tyhp\Deferred;
use Tyhp\Type;

/**
 * Typed Deferred construction for plain PHP tests.
 *
 * `new Deferred()` from PHP binds `T` to its declared default, `void`, so resolving it with a
 * value is rejected. The generic factory binds `T` explicitly.
 */
final class Deferreds
{
    public static function mixed(): Deferred
    {
        return Deferred::new_Tyhp_Deferred__tyhpGeneric(Type::mixed());
    }
}
