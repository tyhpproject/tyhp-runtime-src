<?php

declare(strict_types=1);

$build = $argv[1] ?? (__DIR__ . '/build');

require __DIR__ . '/vendor/autoload.php';

\spl_autoload_register(static function (string $class) use ($build): void {
    $prefix = 'Rhetoric\\Spikes\\Types\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = $build . '/Rhetoric/Spikes/Types/' . $relative . '.php';
    if (\is_file($file)) {
        require $file;
    }
}, prepend: true);

$which = $argv[2] ?? 'all';
if ($which === 'typeof' || $which === 'all') {
    \Rhetoric\Spikes\Types\TypeofProbe::main();
}
if ($which === 'hydrate' || $which === 'all') {
    \Rhetoric\Spikes\Types\HydrateProbe::main();
}
if ($which === 'hooks' || $which === 'all') {
    \Rhetoric\Spikes\Types\HookProbe::main();
}
