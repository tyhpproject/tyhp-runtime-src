<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use Tyhp\EventLoop;
use Tyhp\Io\Db\PdoConnection;
use Tyhp\Io\Offload;

require __DIR__ . '/bootstrap.php';

$loop = EventLoop::getInstance();
$pid = $loop->runUntilSettled(Offload::runAsync(new PidJob()));

$db = new PdoConnection(['dsn' => 'sqlite::memory:', 'username' => null, 'password' => null, 'attributes' => []]);
$results = $loop->runUntilSettled($db->transactionAsync([
    ['sql' => 'CREATE TABLE t (v INTEGER)', 'params' => []],
    ['sql' => 'INSERT INTO t VALUES (?), (?)', 'params' => [1, 2]],
    ['sql' => 'SELECT COUNT(*) FROM t', 'params' => []],
]));

echo \json_encode([
    'usesWorkers' => Offload::usesWorkers(),
    'sameProcess' => $pid === \getmypid(),
    'count' => $results[2]->scalar(),
], \JSON_THROW_ON_ERROR);
