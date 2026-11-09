<?php

declare(strict_types=1);

$enabled = false;
if (\function_exists('env') && \class_exists(\PhpOption\Option::class)) {
    $enabled = \filter_var(\env('RHETORIC_AUTO_MIGRATE', false), \FILTER_VALIDATE_BOOLEAN);
}

$migrations = 'database/migrations';
if (\function_exists('database_path')) {
    $migrations = \database_path('migrations');
}

$marker = 'storage/framework/rhetoric';
if (\function_exists('storage_path')) {
    $marker = \storage_path('framework/rhetoric');
}

return [
    'contexts' => [],
    'snapshot_path' => null,
    'migrations_path' => null,

    // File rhetoric:cache writes. Null uses bootstrap/cache/rhetoric-mapping.php.
    'mapping_cache' => null,

    'auto_migrate' => [
        'enabled' => $enabled,
        'environments' => null,

        'connections' => [],

        'sets' => [
            'app' => [
                'paths' => [$migrations],
                'table' => 'migrations',
                'context' => null,
            ],
        ],

        'trigger' => 'boot',
        'except_paths' => ['up'],
        'console' => [],
        'queue' => true,
        'octane' => true,

        'lock' => [
            'driver' => 'database',
            'store' => null,
            'ttl' => 3600,
        ],
        'wait' => ['http' => 10, 'console' => 300, 'queue' => 300],
        'on_timeout' => 'throw',
        'on_failure' => 'throw',
        'retry_after' => 15,
        'failure_backoff' => [30, 600],

        'marker' => [
            'store' => 'file',
            'path' => $marker,
            'cache_store' => null,
        ],

        'deferred' => [
            'dispatch' => false,
            'queue' => null,
        ],
    ],
];
