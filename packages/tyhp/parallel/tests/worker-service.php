<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$socket = $argv[1] ?? '';
$workers = (int) ($argv[2] ?? '1');
\Tyhp\Parallel::serve($socket, $workers);
