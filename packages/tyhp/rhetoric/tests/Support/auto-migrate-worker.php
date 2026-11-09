<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Tyhp\Rhetoric\Migrations\Auto\AutoMigrator;
use Tyhp\Rhetoric\Migrations\Auto\AutoSettings;
use Tyhp\Rhetoric\Migrations\Auto\MigrationLock;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/WorkerConfig.php';

$specFile = $argv[1] ?? '';
if ($specFile === '' || !\is_file($specFile)) {
    \fwrite(\STDERR, "Missing worker spec.\n");
    exit(2);
}
$spec = require $specFile;
if (!\is_array($spec)) {
    \fwrite(\STDERR, "Worker spec is not an array.\n");
    exit(2);
}

$resultFile = (string) ($spec['result'] ?? '');
$write = static function (array $payload) use ($resultFile): void {
    if ($resultFile !== '') {
        \file_put_contents($resultFile, \json_encode($payload));
    }
};

try {
    $app = new class extends \Illuminate\Container\Container {
        public function runningInConsole(): bool
        {
            return true;
        }

        public function environment(mixed ...$environments): string|bool
        {
            if ($environments === []) {
                return 'production';
            }

            return \in_array('production', $environments, true);
        }
    };
    $config = new Tyhp\Tests\Rhetoric\Support\WorkerConfig((array) ($spec['config'] ?? []));
    $app->instance('config', $config);
    $events = new Dispatcher($app);
    $log = (string) ($spec['events'] ?? '');
    if ($log !== '') {
        $events->listen(MigrationsStarted::class, static function () use ($log): void {
            \file_put_contents($log, "started\n", \FILE_APPEND | \LOCK_EX);
        });
        $events->listen(MigrationsEnded::class, static function () use ($log): void {
            \file_put_contents($log, "ended\n", \FILE_APPEND | \LOCK_EX);
        });
    }
    $app->instance('events', $events);
    $database = (array) ($spec['database'] ?? []);
    if (($database['driver'] ?? '') === 'sqlite') {
        $file = (string) ($database['database'] ?? '');
        if ($file !== '' && $file !== ':memory:' && !\is_file($file)) {
            \touch($file);
        }
    }
    $capsule = new Manager($app);
    $capsule->addConnection($database, (string) ($spec['connection'] ?? 'sqlite'));
    $capsule->getDatabaseManager()->setDefaultConnection((string) ($spec['connection'] ?? 'sqlite'));
    $capsule->setAsGlobal();
    Facade::setFacadeApplication($app);
    $app->instance('db', $capsule->getDatabaseManager());
    AutoMigrator::attach($app);

    $mode = (string) ($spec['mode'] ?? 'migrate');
    if ($mode === 'hold') {
        $connection = $capsule->getConnection((string) ($spec['connection'] ?? 'sqlite'));
        $settings = AutoMigrator::currentSettings();
        if (!($settings instanceof AutoSettings)) {
            throw new \RuntimeException('Settings were not loaded.');
        }
        $lock = MigrationLock::open($connection, 'rhetoric-migrate:hold', $settings, $app);
        if (!$lock->acquire(0)) {
            throw new \RuntimeException('Could not acquire the hold lock.');
        }
        $ready = (string) ($spec['ready'] ?? '');
        if ($ready !== '') {
            \file_put_contents($ready, '1');
        }
        \sleep((int) ($spec['sleep'] ?? 2));
        $write(['status' => 'held']);
        exit(0);
    }

    $result = AutoMigrator::ensure((string) ($spec['connection'] ?? 'sqlite'), (string) ($spec['set'] ?? 'app'));
    $write([
        'status' => $result->status->name,
        'applied' => $result->applied,
        'fromMarker' => $result->fromMarker,
    ]);
} catch (\Throwable $exception) {
    $write([
        'status' => 'error',
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ]);
    exit(1);
}
