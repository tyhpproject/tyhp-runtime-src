<?php

declare(strict_types=1);

$password = \getenv('PGPASSWORD');
if (!\is_string($password) || $password === '') {
    $password = \trim((string) \stream_get_contents(\STDIN));
    if ($password !== '') {
        \putenv('PGPASSWORD=' . $password);
    }
}
if (!\is_string($password) || $password === '') {
    echo "pg=blocked no PGPASSWORD in environment\n";
    exit(0);
}

function env(string $name, string $default): string
{
    $value = \getenv($name);
    return \is_string($value) && $value !== '' ? $value : $default;
}

function connect(): \PDO
{
    $password = \getenv('PGPASSWORD');
    return new \PDO(
        'pgsql:host=' . env('PGHOST', '127.0.0.1') . ';port=' . env('PGPORT', '5432')
            . ';dbname=' . env('PGDATABASE', 'postgres') . ';connect_timeout=2',
        env('PGUSER', 'postgres'),
        \is_string($password) ? $password : '',
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
}

$key = 864201104;
$a = connect();
$b = connect();
$a->query('SELECT pg_advisory_lock(' . $key . ')');
$try = $b->query('SELECT pg_try_advisory_lock(' . $key . ')')->fetchColumn();
echo 'pg try while held=' . \var_export($try, true) . "\n";
try {
    $b->exec("SET lock_timeout = '300ms'");
    $b->query('SELECT pg_advisory_lock(' . $key . ')');
    echo "pg lock_timeout=acquired\n";
} catch (\Throwable $e) {
    echo 'pg lock_timeout=' . $e::class . ' ' . $e->getMessage() . "\n";
}

try {
    $a->beginTransaction();
    $a->exec('CREATE TEMP TABLE rhetoric_spike_ddl (id int)');
    $a->commit();
    $still = $b->query('SELECT pg_try_advisory_lock(' . $key . ')')->fetchColumn();
    echo 'pg try after temp DDL=' . \var_export($still, true) . "\n";
} catch (\Throwable $e) {
    echo 'pg ddl=' . $e::class . ' ' . $e->getMessage() . "\n";
}

$pid = (int) $a->query('SELECT pg_backend_pid()')->fetchColumn();
echo 'pg holder pid=' . $pid . "\n";
try {
    $a->query('SELECT pg_terminate_backend(' . $pid . ')');
} catch (\Throwable $e) {
    echo 'pg terminate=' . $e::class . "\n";
}
$a = null;
$alive = $b->query('SELECT count(*) FROM pg_stat_activity WHERE pid = ' . $pid)->fetchColumn();
echo 'pg holder still in pg_stat_activity=' . \var_export($alive, true) . "\n";
$after = $b->query('SELECT pg_try_advisory_lock(' . $key . ')')->fetchColumn();
echo 'pg try after holder disconnect=' . \var_export($after, true) . "\n";
if ($after === true || $after === 't' || $after === 1 || $after === '1') {
    $b->query('SELECT pg_advisory_unlock(' . $key . ')');
}
echo 'pg server=' . env('PGHOST', '127.0.0.1') . ':' . env('PGPORT', '5432') . ' db=' . env('PGDATABASE', 'postgres') . ' role=' . env('PGUSER', 'postgres') . "\n";
