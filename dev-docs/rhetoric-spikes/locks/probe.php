<?php

declare(strict_types=1);

function line(string $message): void
{
    echo $message, "\n";
}

function drivers(): void
{
    line('pdo_drivers=' . implode(',', \PDO::getAvailableDrivers()));
    foreach (['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'pdo_dblib', 'pdo_sqlsrv', 'sqlsrv', 'pgsql', 'mysqli', 'sqlite3'] as $ext) {
        line('ext ' . $ext . '=' . (\extension_loaded($ext) ? 'yes' : 'no'));
    }
}

function tryPdo(string $label, string $dsn, string $user = '', string $pass = ''): ?\PDO
{
    try {
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 1,
        ]);
        line('connect ' . $label . '=yes');
        return $pdo;
    } catch (\Throwable $e) {
        line('connect ' . $label . '=no ' . $e::class . ' ' . $e->getMessage());
        return null;
    }
}

function portOpen(string $host, int $port): bool
{
    $errno = 0;
    $err = '';
    $socket = @\fsockopen($host, $port, $errno, $err, 0.4);
    if (\is_resource($socket)) {
        \fclose($socket);
        return true;
    }
    line('port ' . $host . ':' . $port . '=closed ' . $errno . ' ' . $err);
    return false;
}

function mysqlLocks(\PDO $a, \PDO $b): void
{
    $got = $a->query("SELECT GET_LOCK('rhetoric_spike', 2)")->fetchColumn();
    line('mysql GET_LOCK first=' . \var_export($got, true));
    $blocked = $b->query("SELECT GET_LOCK('rhetoric_spike', 1)")->fetchColumn();
    line('mysql GET_LOCK second while held timeout 1s=' . \var_export($blocked, true));
    $a->exec('CREATE TABLE IF NOT EXISTS rhetoric_spike_ddl (id INT)');
    $still = $a->query("SELECT IS_USED_LOCK('rhetoric_spike')")->fetchColumn();
    line('mysql IS_USED_LOCK after DDL=' . \var_export($still, true));
    $a->exec('DROP TABLE IF EXISTS rhetoric_spike_ddl');
    $a = null;
    $after = $b->query("SELECT GET_LOCK('rhetoric_spike', 2)")->fetchColumn();
    line('mysql GET_LOCK after first disconnect=' . \var_export($after, true));
    $b->query("SELECT RELEASE_LOCK('rhetoric_spike')");
}

function pgLocks(\PDO $a, \PDO $b): void
{
    $a->query('SELECT pg_advisory_lock(42001)');
    $try = $b->query('SELECT pg_try_advisory_lock(42001)')->fetchColumn();
    line('pg pg_try_advisory_lock while held=' . \var_export($try, true));
    try {
        $b->exec("SET lock_timeout = '300ms'");
        $b->query('SELECT pg_advisory_lock(42001)');
        line('pg pg_advisory_lock with lock_timeout=acquired');
    } catch (\Throwable $e) {
        line('pg pg_advisory_lock with lock_timeout=' . $e::class . ' ' . $e->getMessage());
    }
    $a->exec('CREATE TABLE IF NOT EXISTS rhetoric_spike_ddl (id int)');
    $still = $b->query('SELECT pg_try_advisory_lock(42001)')->fetchColumn();
    line('pg try after DDL on holder=' . \var_export($still, true));
    $a->exec('DROP TABLE IF EXISTS rhetoric_spike_ddl');
    $a = null;
    $after = $b->query('SELECT pg_try_advisory_lock(42001)')->fetchColumn();
    line('pg try after holder disconnect=' . \var_export($after, true));
    if ($after === true || $after === 't' || $after === 1 || $after === '1') {
        $b->query('SELECT pg_advisory_unlock(42001)');
    }
}

function sqliteFlock(string $dir): void
{
    $path = $dir . '/rhetoric.lock';
    $first = \fopen($path, 'c');
    if ($first === false) {
        line('sqlite flock open=no');
        return;
    }
    $locked = \flock($first, \LOCK_EX | \LOCK_NB);
    line('sqlite flock first=' . ($locked ? 'yes' : 'no'));
    $second = \fopen($path, 'c');
    $secondGot = $second !== false && \flock($second, \LOCK_EX | \LOCK_NB);
    line('sqlite flock second while held=' . ($secondGot ? 'yes' : 'no'));
    \flock($first, \LOCK_UN);
    \fclose($first);
    $after = $second !== false && \flock($second, \LOCK_EX | \LOCK_NB);
    line('sqlite flock after release=' . ($after ? 'yes' : 'no'));
    if ($second !== false) {
        \flock($second, \LOCK_UN);
        \fclose($second);
    }
    \unlink($path);
}

line('php=' . \PHP_VERSION);
drivers();

$sockets = [
    '/tmp/mysql.sock',
    '/opt/homebrew/var/mysql/mysql.sock',
    '/tmp/.s.PGSQL.5432',
    '/opt/homebrew/var/postgresql@16/.s.PGSQL.5432',
    '/opt/homebrew/var/postgresql@17/.s.PGSQL.5432',
    '/opt/homebrew/var/postgresql@18/.s.PGSQL.5432',
];
foreach ($sockets as $socket) {
    line('socket ' . $socket . '=' . (\file_exists($socket) ? 'yes' : 'no'));
}

$mysqlPort = portOpen('127.0.0.1', 3306);
line('port 127.0.0.1:3306=' . ($mysqlPort ? 'open' : 'closed'));
$pgPort = portOpen('127.0.0.1', 5432);
line('port 127.0.0.1:5432=' . ($pgPort ? 'open' : 'closed'));
$bouncer = portOpen('127.0.0.1', 6432);
line('port 127.0.0.1:6432 pgbouncer=' . ($bouncer ? 'open' : 'closed'));
$sqlPort = portOpen('127.0.0.1', 1433);
line('port 127.0.0.1:1433=' . ($sqlPort ? 'open' : 'closed'));

if ($mysqlPort) {
    $a = tryPdo('mysql-a', 'mysql:host=127.0.0.1;port=3306;connect_timeout=1', 'root', '');
    $b = tryPdo('mysql-b', 'mysql:host=127.0.0.1;port=3306;connect_timeout=1', 'root', '');
    if ($a instanceof \PDO && $b instanceof \PDO) {
        try {
            mysqlLocks($a, $b);
        } catch (\Throwable $e) {
            line('mysql locks=' . $e::class . ' ' . $e->getMessage());
        }
    }
}

if ($pgPort) {
    $user = \getenv('USER') ?: '';
    $a = tryPdo('pgsql-a', 'pgsql:host=127.0.0.1;port=5432;dbname=postgres;connect_timeout=1', $user, '');
    $b = tryPdo('pgsql-b', 'pgsql:host=127.0.0.1;port=5432;dbname=postgres;connect_timeout=1', $user, '');
    if ($a instanceof \PDO && $b instanceof \PDO) {
        try {
            pgLocks($a, $b);
        } catch (\Throwable $e) {
            line('pg locks=' . $e::class . ' ' . $e->getMessage());
        }
    }
}

if ($sqlPort && \extension_loaded('pdo_dblib')) {
    $sql = tryPdo('dblib', 'dblib:host=127.0.0.1:1433;dbname=master', 'sa', '');
    if ($sql instanceof \PDO) {
        try {
            $row = $sql->query("DECLARE @r int; EXEC @r = sp_getapplock @Resource='rhetoric_spike', @LockMode='Exclusive', @LockOwner='Session', @LockTimeout=1000; SELECT @r AS result")->fetchColumn();
            line('sp_getapplock=' . \var_export($row, true));
        } catch (\Throwable $e) {
            line('sp_getapplock=' . $e::class . ' ' . $e->getMessage());
        }
    }
} else {
    line('sqlserver=blocked no listening server or no pdo_sqlsrv (pdo_dblib=' . (\extension_loaded('pdo_dblib') ? 'yes' : 'no') . ')');
}

sqliteFlock(\sys_get_temp_dir());
