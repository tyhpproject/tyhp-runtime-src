<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tyhp\Rhetoric\Fixtures\Migrations\ColumnContext;
use function Tyhp\Rhetoric\Fixtures\Migrations\ratingHasLanded;
use Tyhp\Rhetoric\Laravel\Commands\MigrationCommand;
use Tyhp\Rhetoric\Laravel\RhetoricServiceProvider;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Migrations\Auto\AutoClock;
use Tyhp\Rhetoric\Migrations\Auto\AutoMigrator;
use Tyhp\Rhetoric\Migrations\Auto\MigrationFailedException;
use Tyhp\Rhetoric\Migrations\Auto\MigrationLock;
use Tyhp\Rhetoric\Migrations\Auto\MigrationManifest;
use Tyhp\Rhetoric\Migrations\Auto\MigrationsInProgressException;
use Tyhp\Rhetoric\Migrations\Auto\MigrationStatus;
use Tyhp\Rhetoric\Migrations\Auto\RunPlanner;
use Tyhp\Rhetoric\Migrations\Auto\PlannedMigration;
use Tyhp\Rhetoric\Migrations\LaravelMigrationWriter;
use Tyhp\Rhetoric\Migrations\MigrationPlan;
use Tyhp\Rhetoric\Migrations\SchemaExtractor;
use Tyhp\Rhetoric\Migrations\SnapshotStore;
use Tyhp\Tests\Rhetoric\Support\WorkerConfig;

require_once __DIR__ . '/Support/WorkerConfig.php';

final class AutoMigrateTest extends TestCase
{
    protected function tearDown(): void
    {
        AutoMigrator::flush();
        MapCache::clear();
        ColumnContext::$shape = 'plain';
        Facade::clearResolvedInstances();
    }

    public function testRunPlannerStopsAtDeferredAndSkipsIndependent(): void
    {
        $first = new PlannedMigration('a', 'a.php', false, false);
        $deferred = new PlannedMigration('b', 'b.php', true, false);
        $later = new PlannedMigration('c', 'c.php', false, false);
        $stopped = RunPlanner::plan([$first, $deferred, $later], false, null);
        self::assertSame(['a'], $this->names($stopped->run));
        self::assertTrue($stopped->blocked);
        self::assertSame(['b'], $stopped->deferredPending);

        $independent = new PlannedMigration('b', 'b.php', true, true);
        $skipped = RunPlanner::plan([$first, $independent, $later], false, null);
        self::assertSame(['a', 'c'], $this->names($skipped->run));
        self::assertFalse($skipped->blocked);

        $onlyDeferred = RunPlanner::plan([$deferred], false, null);
        self::assertSame([], $onlyDeferred->run);
        self::assertFalse($onlyDeferred->blocked);

        $all = RunPlanner::plan([$first, $deferred, $later], true, null);
        self::assertSame(['a', 'b', 'c'], $this->names($all->run));

        $from = RunPlanner::plan([$first, $deferred, $later], true, 'b');
        self::assertSame(['b', 'c'], $this->names($from->run));
    }

    public function testDeferFlagsStayOutOfOrdinaryMigrations(): void
    {
        $plan = new MigrationPlan([], []);
        $ordinary = LaravelMigrationWriter::source(ColumnContext::class, 'a', 'b', $plan);
        self::assertStringNotContainsString('$deferred', $ordinary);
        self::assertStringNotContainsString('$independent', $ordinary);
        $deferred = LaravelMigrationWriter::source(ColumnContext::class, 'a', 'b', $plan, true, false);
        self::assertStringContainsString('public bool $deferred = true;', $deferred);
        self::assertStringContainsString('public bool $independent = false;', $deferred);
        $independent = LaravelMigrationWriter::source(ColumnContext::class, 'a', 'b', $plan, true, true);
        self::assertStringContainsString('public bool $independent = true;', $independent);
    }

    public function testDroppingAColumnSuggestsDefer(): void
    {
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $dir = $this->temp();
        $snapshots = $dir . '/snapshots';
        $migrations = $dir . '/migrations';
        $manifest = $dir . '/manifest.php';
        \file_put_contents($manifest, "<?php\n\nreturn ['sets' => []];\n");
        (new SnapshotStore($snapshots))->save(SchemaExtractor::extract((new ColumnContext())->mapping()));
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.manifest' => $manifest,
            'rhetoric.snapshot_path' => $snapshots,
        ]);
        [$code, $text] = $this->artisan($app['app'], [
            'command' => 'rhetoric:migration',
            'name' => 'drop_post_rating',
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
        ]);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('Pass --defer to leave it for rhetoric:migrate-deferred.', $text);
        $files = \glob($migrations . '/*.php') ?: [];
        self::assertCount(1, $files);
        $source = \file_get_contents($files[0]);
        self::assertIsString($source);
        self::assertStringNotContainsString('$deferred', $source);
        self::assertFileDoesNotExist($manifest);

        ColumnContext::$shape = 'rated';
        MapCache::clear();
        (new SnapshotStore($snapshots))->save(SchemaExtractor::extract((new ColumnContext())->mapping()));
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        [$deferredCode, $deferredText] = $this->artisan($app['app'], [
            'command' => 'rhetoric:migration',
            'name' => 'drop_post_rating_later',
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
            '--defer' => true,
        ]);
        self::assertSame(0, $deferredCode, $deferredText);
        $deferredFiles = \glob($migrations . '/*drop_post_rating_later.php') ?: [];
        self::assertCount(1, $deferredFiles);
        $deferredSource = \file_get_contents($deferredFiles[0]);
        self::assertIsString($deferredSource);
        self::assertStringContainsString('public bool $deferred = true;', $deferredSource);
    }

    public function testEnsureAppliesPendingMigrationsAndThenAnswersFromTheMemo(): void
    {
        $dir = $this->temp();
        $database = $dir . '/db.sqlite';
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $app = $this->application($database, $migrations, $dir . '/marker');
        $started = 0;
        $ended = 0;
        $app['events']->listen(MigrationsStarted::class, static function () use (&$started): void {
            $started++;
        });
        $app['events']->listen(MigrationsEnded::class, static function () use (&$ended): void {
            $ended++;
        });

        $first = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Migrated, $first->status);
        self::assertFalse($first->fromMarker);
        self::assertSame(['2026_11_05_000001_create_posts'], $first->applied);
        self::assertSame(1, $started);
        self::assertSame(1, $ended);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('posts'));
        $batches = $app['connection']->table('migrations')->pluck('batch');
        self::assertSame([1], $batches->all());

        $app['connection']->flushQueryLog();
        $app['connection']->enableQueryLog();
        $second = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::UpToDate, $second->status);
        self::assertTrue($second->fromMarker);
        self::assertSame([], $second->applied);
        self::assertSame([], $app['connection']->getQueryLog());
        self::assertLessThan(5000.0, $this->median(static function (): void {
            AutoMigrator::ensure('sqlite', 'app');
        }));
    }

    public function testDeferredOrderingOnSqlite(): void
    {
        $dir = $this->temp();
        $database = $dir . '/db.sqlite';
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $this->migration($migrations, '2026_11_05_000002_defer_rating', $this->createTable('ratings', true, false));
        $this->migration($migrations, '2026_11_05_000003_create_later', $this->createTable('later', false, false));
        $app = $this->application($database, $migrations, $dir . '/marker');

        $blocked = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::BlockedByDeferred, $blocked->status);
        self::assertSame(['2026_11_05_000001_create_posts'], $blocked->applied);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('posts'));
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('ratings'));
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('later'));

        $again = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::BlockedByDeferred, $again->status);
        self::assertTrue($again->fromMarker);
        self::assertSame([], $again->applied);

        $ran = AutoMigrator::migrateDeferred('sqlite', 'app', null, 'console');
        self::assertSame(MigrationStatus::Migrated, $ran->status);
        self::assertSame(['2026_11_05_000002_defer_rating', '2026_11_05_000003_create_later'], $ran->applied);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('ratings'));
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('later'));
        self::assertSame([1, 2, 2], $app['connection']->table('migrations')->orderBy('id')->pluck('batch')->all());
    }

    public function testIndependentDeferredMigrationsDoNotHoldLaterOnes(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $this->migration($migrations, '2026_11_05_000002_defer_rating', $this->createTable('ratings', true, true));
        $this->migration($migrations, '2026_11_05_000003_create_later', $this->createTable('later', false, false));
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker');

        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Migrated, $result->status);
        self::assertSame(['2026_11_05_000001_create_posts', '2026_11_05_000003_create_later'], $result->applied);
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('ratings'));
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('later'));

        $only = AutoMigrator::migrateDeferred('sqlite', 'app', '2026_11_05_000002_defer_rating', 'console');
        self::assertSame(['2026_11_05_000002_defer_rating'], $only->applied);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('ratings'));
    }

    public function testNamedClassMigrationsRunOnTheAutoPath(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_named_ones', $this->namedClass('CreateNamedOnes', 'named_ones', false));
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker');

        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Migrated, $result->status);
        self::assertSame(['2026_11_05_000001_create_named_ones'], $result->applied);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('named_ones'));
    }

    public function testNamedClassMigrationFlagsAreHonoured(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_named_firsts', $this->namedClass('CreateNamedFirsts', 'named_firsts', false));
        $this->migration($migrations, '2026_11_05_000002_create_named_deferreds', $this->namedClass('CreateNamedDeferreds', 'named_deferreds', true));
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker');

        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(['2026_11_05_000001_create_named_firsts'], $result->applied);
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('named_deferreds'));
        $manifest = $dir . '/manifest.php';
        MigrationManifest::write($manifest, AutoMigrator::currentSettings()->sets);
        $written = require $manifest;
        $flags = [];
        foreach ($written['sets']['app']['migrations'] as $row) {
            $flags[$row['name']] = $row['deferred'];
        }
        self::assertSame([
            '2026_11_05_000001_create_named_firsts' => false,
            '2026_11_05_000002_create_named_deferreds' => true,
        ], $flags);
    }

    public function testAnUnreachableDatabaseBacksOffInsteadOfRetryingEachRequest(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.failure_backoff' => [30, 600],
        ]);
        \unlink($dir . '/db.sqlite');
        try {
            AutoMigrator::ensure('sqlite', 'app');
            self::fail('A missing database did not throw.');
        } catch (MigrationFailedException $exception) {
            self::assertSame('15', (string) ($exception->getHeaders()['Retry-After'] ?? ''));
        }
        self::assertNotSame([], \glob($dir . '/marker/*.php') ?: []);

        $record = (string) \file_get_contents((\glob($dir . '/marker/*.php') ?: [''])[0]);
        self::assertStringContainsString("'attempts' => 1", $record);
        AutoClock::freeze(\time() + 1);
        try {
            AutoMigrator::ensure('sqlite', 'app');
            self::fail('The failure record did not back off.');
        } catch (MigrationFailedException) {
            $again = (string) \file_get_contents((\glob($dir . '/marker/*.php') ?: [''])[0]);
            self::assertSame($record, $again);
        }
        AutoMigrator::reset('sqlite', 'app');
        self::assertSame([], \glob($dir . '/marker/*.php') ?: []);
    }

    public function testAMistypedOnlyThrowsWithoutRecordingAFailure(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker');

        try {
            AutoMigrator::migrateDeferred('sqlite', 'app', '2026_11_05_000009_nope', 'console');
            self::fail('A mistyped --only did not throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('2026_11_05_000009_nope', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/marker/*.php') ?: []);

        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Migrated, $result->status);
    }

    public function testARunningMigrationThatThrowsInvalidArgumentStillRecordsAFailureWithOnly(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration(
            $migrations,
            '2026_11_05_000001_create_posts',
            "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n"
            . "    public function up(): void { throw new \\InvalidArgumentException('bad column'); }\n"
            . "    public function down(): void {}\n"
            . "};\n",
        );
        $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.failure_backoff' => [30, 600],
        ]);

        try {
            AutoMigrator::migrateDeferred('sqlite', 'app', '2026_11_05_000001_create_posts', 'console');
            self::fail('The failing migration did not throw.');
        } catch (MigrationFailedException $exception) {
            self::assertSame('bad column', $exception->getMessage());
        }
        self::assertNotSame([], \glob($dir . '/marker/*.php') ?: []);
        $record = (string) \file_get_contents((\glob($dir . '/marker/*.php') ?: [''])[0]);
        self::assertStringContainsString("'attempts' => 1", $record);

        $other = new \SplFileObject($dir . '/db.sqlite.rhetoric-lock', 'c+');
        $wouldBlock = null;
        self::assertTrue($other->flock(\LOCK_EX | \LOCK_NB, $wouldBlock), 'The failed run kept the lock.');
        $other->flock(\LOCK_UN);

        AutoClock::freeze(\time() + 1);
        try {
            AutoMigrator::migrateDeferred('sqlite', 'app', '2026_11_05_000001_create_posts', 'console');
            self::fail('The failure record did not back off.');
        } catch (MigrationFailedException) {
            self::assertSame($record, (string) \file_get_contents((\glob($dir . '/marker/*.php') ?: [''])[0]));
        }
    }

    public function testASuccessAfterAFailureClearsTheBackoffAndReleasesTheLock(): void
    {
        $dir = $this->temp();
        $count = $dir . '/count.txt';
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->flaky($count));
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.failure_backoff' => [30, 600],
        ]);

        try {
            AutoMigrator::ensure('sqlite', 'app');
            self::fail('The failing migration did not throw.');
        } catch (MigrationFailedException) {
            self::assertStringContainsString("'attempts' => 1", (string) \file_get_contents((\glob($dir . '/marker/*.php') ?: [''])[0]));
        }

        $other = new \SplFileObject($dir . '/db.sqlite.rhetoric-lock', 'c+');
        $wouldBlock = null;
        self::assertTrue($other->flock(\LOCK_EX | \LOCK_NB, $wouldBlock), 'The failed run kept the lock.');
        $other->flock(\LOCK_UN);

        AutoClock::freeze(\time() + 3600);
        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Migrated, $result->status);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('posts'));
        $record = (string) \file_get_contents((\glob($dir . '/marker/*.php') ?: [''])[0]);
        self::assertStringNotContainsString('attempts', $record);
        self::assertStringNotContainsString('error', $record);
    }

    public function testDeferredOnlyPendingCountsAsUpToDate(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000002_defer_rating', $this->createTable('ratings', true, false));
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker');
        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame([], $result->applied);
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('migrations'));
        self::assertFileExists($dir . '/marker');
    }

    public function testAFailedMigrationBacksOffUntilReset(): void
    {
        $dir = $this->temp();
        $count = $dir . '/count.txt';
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->flaky($count));
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.failure_backoff' => [30, 600],
            'rhetoric.auto_migrate.retry_after' => 15,
        ]);

        try {
            AutoMigrator::ensure('sqlite', 'app');
            self::fail('The failing migration did not throw.');
        } catch (MigrationFailedException $exception) {
            self::assertSame('boom', $exception->getMessage());
            self::assertSame('15', (string) ($exception->getHeaders()['Retry-After'] ?? ''));
        }
        self::assertSame('1', \file_get_contents($count));

        AutoClock::freeze(\time() + 1);
        try {
            AutoMigrator::ensure('sqlite', 'app');
            self::fail('The failure record did not back off.');
        } catch (MigrationFailedException) {
            self::assertSame('1', \file_get_contents($count));
        }

        AutoMigrator::reset('sqlite', 'app');
        $result = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Migrated, $result->status);
        self::assertSame('2', \file_get_contents($count));
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('posts'));
    }

    public function testOnFailureContinueDoesNotThrowInsideTheWindow(): void
    {
        $dir = $this->temp();
        $count = $dir . '/count.txt';
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->flaky($count));
        $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.on_failure' => 'continue',
            'rhetoric.auto_migrate.failure_backoff' => [30, 600],
        ]);
        $failed = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Failed, $failed->status);
        AutoClock::freeze(\time() + 1);
        $again = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::Failed, $again->status);
        self::assertSame('1', \file_get_contents($count));
    }

    public function testWaitTimeoutThrowsOrContinues(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $ready = $dir . '/ready';
        $holder = $this->startWorker($this->spec($dir, $dir . '/db.sqlite', $migrations, 'hold', [
            'ready' => $ready,
            'sleep' => 3,
            'wait' => ['http' => 1, 'console' => 1, 'queue' => 1],
        ]));
        $this->waitFor($ready);

        $thrown = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.wait' => ['http' => 1, 'console' => 1, 'queue' => 1],
            'rhetoric.auto_migrate.on_timeout' => 'throw',
        ]);
        try {
            AutoMigrator::ensure('sqlite', 'app');
            self::fail('A held lock did not time out.');
        } catch (MigrationsInProgressException $exception) {
            self::assertSame('15', (string) ($exception->getHeaders()['Retry-After'] ?? ''));
        }
        AutoMigrator::flush();

        $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.wait' => ['http' => 1, 'console' => 1, 'queue' => 1],
            'rhetoric.auto_migrate.on_timeout' => 'continue',
        ]);
        $continued = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(MigrationStatus::UpToDate, $continued->status);
        self::assertFalse($continued->fromMarker);
        self::assertFalse($thrown['connection']->getSchemaBuilder()->hasTable('posts'));

        $this->finish($holder);
        $released = MigrationLock::open(
            $thrown['connection'],
            'rhetoric-migrate:after',
            AutoMigrator::currentSettings(),
            $thrown['app'],
        );
        self::assertTrue($released->acquire(0));
        $released->release();
    }

    public function testAProcessExitReleasesTheSqliteLock(): void
    {
        $dir = $this->temp();
        $database = $dir . '/db.sqlite';
        \touch($database);
        $migrations = $dir . '/migrations';
        \mkdir($migrations);
        $ready = $dir . '/ready';
        $holder = $this->startWorker($this->spec($dir, $database, $migrations, 'hold', [
            'ready' => $ready,
            'sleep' => 2,
        ]));
        $this->waitFor($ready);
        $app = $this->application($database, $migrations, $dir . '/marker');
        $blocked = MigrationLock::open($app['connection'], 'rhetoric-migrate:probe', AutoMigrator::currentSettings(), $app['app']);
        self::assertFalse($blocked->acquire(0));
        $this->finish($holder);
        self::assertTrue($blocked->acquire(0));
        $blocked->release();
    }

    public function testEightProcessesLetOneMigrationRun(): void
    {
        $dir = $this->temp();
        $database = $dir . '/db.sqlite';
        $migrations = $dir . '/migrations';
        $events = $dir . '/events.log';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->slowPosts($dir . '/ups.log'));
        $workers = [];
        foreach (\range(1, 8) as $index) {
            $workers[] = $this->startWorker($this->spec($dir, $database, $migrations, 'migrate', [
                'events' => $events,
                'result' => $dir . '/result-' . $index . '.json',
                'wait' => ['http' => 15, 'console' => 15, 'queue' => 15],
            ]));
        }
        $statuses = [];
        foreach ($workers as $worker) {
            $payload = $this->finish($worker);
            $statuses[] = $payload['status'] ?? 'missing';
        }
        \sort($statuses);
        self::assertSame(['Migrated', 'UpToDate', 'UpToDate', 'UpToDate', 'UpToDate', 'UpToDate', 'UpToDate', 'UpToDate'], $statuses);
        $log = \is_file($events) ? (string) \file_get_contents($events) : '';
        self::assertSame(1, \substr_count($log, 'started'));
        self::assertSame(1, \substr_count($log, 'ended'));
        self::assertSame(1, \substr_count((string) \file_get_contents($dir . '/ups.log'), 'up'));
        $check = $this->application($database, $migrations, $dir . '/marker');
        self::assertSame([1], $check['connection']->table('migrations')->pluck('batch')->all());
    }

    public function testCacheLockOwnershipExpiresWithTheClock(): void
    {
        $dir = $this->temp();
        $seen = new \stdClass();
        $seen->ttl = 0;
        $app = $this->application($dir . '/db.sqlite', $dir . '/migrations', $dir . '/marker', [
            'rhetoric.auto_migrate.lock' => ['driver' => 'cache', 'store' => null, 'ttl' => 5],
        ]);
        $app['app']->instance('cache', new class ($seen) implements \Illuminate\Contracts\Cache\Factory {
            public function __construct(private \stdClass $seen)
            {
            }

            public function store($name = null)
            {
                return new class ($this->seen) implements \Illuminate\Contracts\Cache\LockProvider, \Illuminate\Contracts\Cache\Repository {
                    public function __construct(private \stdClass $seen)
                    {
                    }

                    public function lock($name, $seconds = 0, $owner = null)
                    {
                        $this->seen->ttl = $seconds;

                        return new class implements \Illuminate\Contracts\Cache\Lock {
                            public function get($callback = null)
                            {
                                return true;
                            }

                            public function block($seconds, $callback = null)
                            {
                                return true;
                            }

                            public function release()
                            {
                                return true;
                            }

                            public function forceRelease()
                            {
                            }

                            public function owner()
                            {
                                return 'test';
                            }
                        };
                    }

                    public function restoreLock($name, $owner)
                    {
                        return $this->lock($name, 0, $owner);
                    }

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $default;
                    }

                    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
                    {
                        return true;
                    }

                    public function delete(string $key): bool
                    {
                        return true;
                    }

                    public function clear(): bool
                    {
                        return true;
                    }

                    public function getMultiple(iterable $keys, mixed $default = null): iterable
                    {
                        return [];
                    }

                    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
                    {
                        return true;
                    }

                    public function deleteMultiple(iterable $keys): bool
                    {
                        return true;
                    }

                    public function has(string $key): bool
                    {
                        return false;
                    }

                    public function pull($key, $default = null)
                    {
                        return $default;
                    }

                    public function put($key, $value, $ttl = null)
                    {
                        return true;
                    }

                    public function add($key, $value, $ttl = null)
                    {
                        return true;
                    }

                    public function increment($key, $value = 1)
                    {
                        return 1;
                    }

                    public function decrement($key, $value = 1)
                    {
                        return 1;
                    }

                    public function forever($key, $value)
                    {
                        return true;
                    }

                    public function remember($key, $ttl, $callback)
                    {
                        return $callback();
                    }

                    public function sear($key, $callback)
                    {
                        return $callback();
                    }

                    public function rememberForever($key, $callback)
                    {
                        return $callback();
                    }

                    public function touch($key, $ttl)
                    {
                        return true;
                    }

                    public function forget($key)
                    {
                        return true;
                    }

                    public function getStore()
                    {
                        throw new \RuntimeException('unused');
                    }
                };
            }
        });
        $lock = MigrationLock::open($app['connection'], 'rhetoric-migrate:cache', AutoMigrator::currentSettings(), $app['app']);
        AutoClock::freeze(1_000);
        self::assertTrue($lock->acquire(1));
        self::assertSame(5, $seen->ttl);
        self::assertTrue($lock->owns());
        AutoClock::freeze(1_006);
        self::assertFalse($lock->owns());
        $lock->release();
    }

    public function testTwoDatabasesBehindOneConnectionNameKeepSeparateMarkers(): void
    {
        $dir = $this->temp();
        $first = $dir . '/one.sqlite';
        $second = $dir . '/two.sqlite';
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $app = $this->application($first, $migrations, $dir . '/marker', [], 'tenant');
        $one = AutoMigrator::ensure('tenant', 'app');
        self::assertSame(MigrationStatus::Migrated, $one->status);

        \touch($second);
        $next = [
            'driver' => 'sqlite',
            'database' => $second,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
        $app['config']->set('database.connections.tenant', $next);
        $app['capsule']->addConnection($next, 'tenant');
        $app['capsule']->getDatabaseManager()->purge('tenant');
        $two = AutoMigrator::ensure('tenant', 'app');
        self::assertSame(MigrationStatus::Migrated, $two->status);
        self::assertNotSame([], \glob($dir . '/marker/*.php') ?: []);
        self::assertGreaterThanOrEqual(2, \count(\glob($dir . '/marker/*.php') ?: []));

        $back = [
            'driver' => 'sqlite',
            'database' => $first,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
        $app['config']->set('database.connections.tenant', $back);
        $app['capsule']->addConnection($back, 'tenant');
        $app['capsule']->getDatabaseManager()->purge('tenant');
        $repeat = AutoMigrator::ensure('tenant', 'app');
        self::assertTrue($repeat->fromMarker);
        $connection = $app['capsule']->getConnection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $memo = AutoMigrator::ensure('tenant', 'app');
        self::assertTrue($memo->fromMarker);
        self::assertSame([], $connection->getQueryLog());
    }

    public function testAManifestIsTrustedUntilItIsRemoved(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $manifest = $dir . '/manifest.php';
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.manifest' => $manifest,
        ]);
        MigrationManifest::write($manifest, AutoMigrator::currentSettings()->sets);
        $this->migration($migrations, '2026_11_05_000002_create_later', $this->createTable('later', false, false));
        $cached = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(['2026_11_05_000001_create_posts'], $cached->applied);
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('later'));

        MigrationManifest::forget($manifest);
        $property = new \ReflectionProperty(AutoMigrator::class, 'memo');
        $property->setValue(null, []);
        $fresh = AutoMigrator::ensure('sqlite', 'app');
        self::assertSame(['2026_11_05_000002_create_later'], $fresh->applied);
        self::assertTrue($app['connection']->getSchemaBuilder()->hasTable('later'));
    }

    public function testSchemaHasAnswersFromAppliedSnapshotHistory(): void
    {
        $dir = $this->temp();
        $snapshots = $dir . '/snapshots';
        $migrations = $dir . '/migrations';
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $context = new ColumnContext();
        $store = new SnapshotStore($snapshots);
        $plain = SchemaExtractor::extract($context->mapping());
        $store->remember($plain);
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $rated = SchemaExtractor::extract($context->mapping());
        $store->remember($rated);
        $this->migration($migrations, '2026_11_05_000001_create_posts', "<?php\n\n/**\n * Context: " . ColumnContext::class . "\n * From: none\n * Snapshot: " . $plain->hash() . "\n */\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void {}\n    public function down(): void {}\n};\n");
        $this->migration($migrations, '2026_11_05_000002_add_rating', "<?php\n\n/**\n * Context: " . ColumnContext::class . "\n * From: " . $plain->hash() . "\n * Snapshot: " . $rated->hash() . "\n */\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n    public bool \$deferred = true;\n    public bool \$independent = false;\n    public function up(): void {}\n    public function down(): void {}\n};\n");
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.snapshot_path' => $snapshots,
            'rhetoric.auto_migrate.sets' => [
                'app' => [
                    'paths' => [$migrations],
                    'table' => 'migrations',
                    'context' => ColumnContext::class,
                ],
            ],
        ]);
        $app['connection']->getSchemaBuilder()->create('migrations', static function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $app['connection']->table('migrations')->insert(['migration' => '2026_11_05_000001_create_posts', 'batch' => 1]);
        \Tyhp\Rhetoric\Migrations\Auto\SchemaAwareness::forget();
        self::assertFalse(ratingHasLanded($context));
        $app['connection']->flushQueryLog();
        $app['connection']->enableQueryLog();
        self::assertFalse(ratingHasLanded($context));
        self::assertSame([], $app['connection']->getQueryLog());

        $app['connection']->table('migrations')->insert(['migration' => '2026_11_05_000002_add_rating', 'batch' => 2]);
        \Tyhp\Rhetoric\Migrations\Auto\SchemaAwareness::forget();
        self::assertTrue(ratingHasLanded($context));
        $app['connection']->flushQueryLog();
        self::assertTrue(ratingHasLanded($context));
        self::assertSame([], $app['connection']->getQueryLog());
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('posts'));
    }

    public function testDisabledAutoMigrateRegistersNoHooks(): void
    {
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $this->migration($migrations, '2026_11_05_000001_create_posts', $this->createPosts());
        $app = $this->application($dir . '/db.sqlite', $migrations, $dir . '/marker', [
            'rhetoric.auto_migrate.enabled' => false,
        ]);
        $provider = new RhetoricServiceProvider($app['app']);
        $provider->register();
        $provider->boot();
        $app['app']->fireBooting();
        self::assertFalse(AutoMigrator::hooksInstalled());
        self::assertFalse($app['connection']->getSchemaBuilder()->hasTable('posts'));
        self::assertSame([], \glob($dir . '/marker/*.php') ?: []);
    }

    public function testDatabaseLocksUseTheWriteConnection(): void
    {
        $dir = $this->temp();
        $app = $this->application($dir . '/db.sqlite', $dir . '/migrations', $dir . '/marker');
        foreach (['mysql', 'pgsql'] as $driver) {
            $connection = new RecordingLockConnection(new \PDO('sqlite::memory:'), 'locks', '', ['driver' => $driver]);
            $lock = MigrationLock::open($connection, 'rhetoric-migrate:write', AutoMigrator::currentSettings(), $app['app']);
            self::assertTrue($lock->acquire(0), $driver);
            self::assertTrue($lock->owns(), $driver);
            $lock->release();
            self::assertNotSame([], $connection->reads, $driver);
            foreach ($connection->reads as $sql => $useReadPdo) {
                self::assertFalse($useReadPdo, $driver . ': ' . $sql);
            }
        }
    }

    public function testLockSqlMatchesTheDriverFunctions(): void
    {
        self::assertStringContainsString('get_lock', MigrationLock::MYSQL_ACQUIRE);
        self::assertStringContainsString('release_lock', MigrationLock::MYSQL_RELEASE);
        self::assertStringContainsString('is_used_lock', MigrationLock::MYSQL_OWNS);
        self::assertStringContainsString('pg_try_advisory_lock', MigrationLock::POSTGRES_ACQUIRE);
        self::assertStringContainsString('pg_advisory_unlock', MigrationLock::POSTGRES_RELEASE);
        self::assertStringContainsString('sp_getapplock', MigrationLock::SQLSRV_ACQUIRE);
        self::assertStringContainsString('sp_releaseapplock', MigrationLock::SQLSRV_RELEASE);
        $name = MigrationLock::lockName(\str_repeat('a', 64));
        self::assertLessThanOrEqual(64, \strlen($name));
        self::assertStringStartsWith('rhetoric-migrate:', $name);
        $halves = MigrationLock::keyHalves('tenant-a');
        self::assertCount(2, $halves);
        self::assertIsInt($halves[0]);
        self::assertIsInt($halves[1]);

        $mysql = \getenv('RHETORIC_TEST_MYSQL_DSN');
        if (\is_string($mysql) && $mysql !== '') {
            $this->liveLock($mysql, 'mysql');
        }
        $pgsql = \getenv('RHETORIC_TEST_PGSQL_DSN');
        if (\is_string($pgsql) && $pgsql !== '') {
            $this->liveLock($pgsql, 'pgsql');
        }
    }

    /**
     * @param array<int, PlannedMigration> $migrations
     * @return array<int, string>
     */
    private function names(array $migrations): array
    {
        $names = [];
        foreach ($migrations as $migration) {
            $names[] = $migration->name;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{app: RhetoricApp, capsule: Manager, config: WorkerConfig, connection: \Illuminate\Database\Connection, events: Dispatcher}
     */
    private function application(string $database, string $migrations, string $marker, array $extra = [], string $connection = 'sqlite'): array
    {
        AutoMigrator::flush();
        if (!\is_dir($migrations)) {
            \mkdir($migrations, 0777, true);
        }
        if ($database !== ':memory:' && !\is_file($database)) {
            \touch($database);
        }
        $config = new WorkerConfig([
            'database' => [
                'default' => $connection,
                'connections' => [
                    $connection => [
                        'driver' => 'sqlite',
                        'database' => $database,
                        'prefix' => '',
                        'foreign_key_constraints' => true,
                    ],
                ],
            ],
            'rhetoric' => [
                'contexts' => [],
                'snapshot_path' => null,
                'auto_migrate' => [
                    'enabled' => true,
                    'environments' => ['production', 'testing'],
                    'connections' => [$connection => 'app'],
                    'sets' => [
                        'app' => [
                            'paths' => [$migrations],
                            'table' => 'migrations',
                            'context' => ColumnContext::class,
                        ],
                    ],
                    'trigger' => 'boot',
                    'except_paths' => ['up'],
                    'console' => [],
                    'queue' => false,
                    'octane' => false,
                    'lock' => ['driver' => 'database', 'store' => null, 'ttl' => 3600],
                    'wait' => ['http' => 10, 'console' => 10, 'queue' => 10],
                    'on_timeout' => 'throw',
                    'on_failure' => 'throw',
                    'retry_after' => 15,
                    'failure_backoff' => [30, 600],
                    'marker' => ['store' => 'file', 'path' => $marker, 'cache_store' => null],
                    'deferred' => ['dispatch' => false, 'queue' => null],
                ],
            ],
        ]);
        foreach ($extra as $key => $value) {
            $config->set($key, $value);
        }
        $app = new RhetoricApp();
        $events = new Dispatcher($app);
        $app->instance('config', $config);
        $app->instance('events', $events);
        $capsule = new Manager($app);
        $capsule->addConnection($config->get('database.connections.' . $connection), $connection);
        $capsule->getDatabaseManager()->setDefaultConnection($connection);
        $capsule->setAsGlobal();
        Facade::setFacadeApplication($app);
        $app->instance('db', $capsule->getDatabaseManager());
        AutoMigrator::attach($app);

        return [
            'app' => $app,
            'capsule' => $capsule,
            'config' => $config,
            'connection' => $capsule->getConnection($connection),
            'events' => $events,
        ];
    }

    private function migration(string $directory, string $name, string $source): void
    {
        if (!\is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }
        \file_put_contents($directory . '/' . $name . '.php', $source);
    }

    private function createPosts(): string
    {
        return $this->createTable('posts', false, false);
    }

    private function createTable(string $table, bool $deferred, bool $independent): string
    {
        $flags = '';
        if ($deferred) {
            $flags = "    public bool \$deferred = true;\n    public bool \$independent = " . ($independent ? 'true' : 'false') . ";\n";
        }

        return "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n"
            . $flags
            . "    public function up(): void {\n"
            . "        \\Illuminate\\Database\\Capsule\\Manager::schema()->create('" . $table . "', function (\$blueprint): void {\n"
            . "            \$blueprint->increments('id');\n"
            . "        });\n"
            . "    }\n"
            . "    public function down(): void {}\n"
            . "};\n";
    }

    private function namedClass(string $class, string $table, bool $deferred): string
    {
        $flags = $deferred ? "    public bool \$deferred = true;\n    public bool \$independent = false;\n" : '';

        return "<?php\n\nclass " . $class . " extends \\Illuminate\\Database\\Migrations\\Migration {\n"
            . $flags
            . "    public function up(): void {\n"
            . "        \\Illuminate\\Database\\Capsule\\Manager::schema()->create('" . $table . "', function (\$blueprint): void {\n"
            . "            \$blueprint->increments('id');\n"
            . "        });\n"
            . "    }\n"
            . "    public function down(): void {}\n"
            . "}\n";
    }

    private function flaky(string $count): string
    {
        return "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n"
            . "    public function up(): void {\n"
            . "        \$count = \\is_file(" . \var_export($count, true) . ") ? (int) \\file_get_contents(" . \var_export($count, true) . ") : 0;\n"
            . "        \\file_put_contents(" . \var_export($count, true) . ", (string) (\$count + 1));\n"
            . "        if (\$count < 1) { throw new \\RuntimeException('boom'); }\n"
            . "        \\Illuminate\\Database\\Capsule\\Manager::schema()->create('posts', function (\$blueprint): void {\n"
            . "            \$blueprint->increments('id');\n"
            . "        });\n"
            . "    }\n"
            . "    public function down(): void {}\n"
            . "};\n";
    }

    private function slowPosts(string $log): string
    {
        return "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n"
            . "    public function up(): void {\n"
            . "        \\file_put_contents(" . \var_export($log, true) . ", \"up\\n\", \\FILE_APPEND | \\LOCK_EX);\n"
            . "        \\usleep(1500000);\n"
            . "        \\Illuminate\\Database\\Capsule\\Manager::schema()->create('posts', function (\$blueprint): void {\n"
            . "            \$blueprint->increments('id');\n"
            . "        });\n"
            . "    }\n"
            . "    public function down(): void {}\n"
            . "};\n";
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function spec(string $dir, string $database, string $migrations, string $mode, array $overrides = []): array
    {
        $connection = 'sqlite';
        $wait = $overrides['wait'] ?? ['http' => 10, 'console' => 10, 'queue' => 10];

        return [
            'mode' => $mode,
            'connection' => $connection,
            'set' => 'app',
            'database' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'result' => $overrides['result'] ?? ($dir . '/worker-result.json'),
            'events' => $overrides['events'] ?? '',
            'ready' => $overrides['ready'] ?? '',
            'sleep' => $overrides['sleep'] ?? 2,
            'config' => [
                'database' => [
                    'default' => $connection,
                    'connections' => [
                        $connection => [
                            'driver' => 'sqlite',
                            'database' => $database,
                            'prefix' => '',
                            'foreign_key_constraints' => true,
                        ],
                    ],
                ],
                'rhetoric' => [
                    'snapshot_path' => null,
                    'auto_migrate' => [
                        'enabled' => true,
                        'environments' => null,
                        'connections' => [$connection => 'app'],
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
                        'queue' => false,
                        'octane' => false,
                        'lock' => ['driver' => 'database', 'store' => null, 'ttl' => 3600],
                        'wait' => $wait,
                        'on_timeout' => 'throw',
                        'on_failure' => 'throw',
                        'retry_after' => 15,
                        'failure_backoff' => [30, 600],
                        'marker' => ['store' => 'file', 'path' => $dir . '/marker', 'cache_store' => null],
                        'deferred' => ['dispatch' => false, 'queue' => null],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{process: resource, result: string}
     */
    private function startWorker(array $spec): array
    {
        $specFile = $this->temp() . '/spec.php';
        \file_put_contents($specFile, "<?php\n\nreturn " . \var_export($spec, true) . ";\n");
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/Support/auto-migrate-worker.php', $specFile],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $specFile . '.log', 'a'],
                2 => ['file', $specFile . '.log', 'a'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);

        return ['process' => $process, 'result' => (string) $spec['result']];
    }

    /**
     * @param array{process: resource, result: string} $worker
     * @return array<string, mixed>
     */
    private function finish(array $worker): array
    {
        $exit = \proc_close($worker['process']);
        $payload = [];
        if (\is_file($worker['result'])) {
            $decoded = \json_decode((string) \file_get_contents($worker['result']), true);
            if (\is_array($decoded)) {
                $payload = $decoded;
            }
        }
        self::assertSame(0, $exit, \json_encode($payload) ?: 'worker failed');

        return $payload;
    }

    private function waitFor(string $path): void
    {
        $deadline = \microtime(true) + 5;
        while (!\is_file($path)) {
            if (\microtime(true) > $deadline) {
                self::fail('Timed out waiting for ' . $path);
            }
            \usleep(50000);
        }
    }

    private function median(\Closure $callback): float
    {
        $samples = [];
        for ($index = 0; $index < 21; $index++) {
            $started = \hrtime(true);
            $callback();
            $samples[] = (\hrtime(true) - $started) / 1000;
        }
        \sort($samples);

        return $samples[10];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{int, string}
     */
    private function artisan(RhetoricApp $app, array $arguments): array
    {
        $application = new Application($app, new Dispatcher($app), '1');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->add(new MigrationCommand());
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput($arguments), $output);

        return [$code, $output->fetch()];
    }

    private function liveLock(string $dsn, string $driver): void
    {
        $parts = \parse_url($dsn);
        self::assertIsArray($parts);
        $config = [
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432),
            'database' => \ltrim((string) ($parts['path'] ?? ''), '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
            'prefix' => '',
        ];
        $class = $driver === 'mysql' ? \Illuminate\Database\MySqlConnection::class : \Illuminate\Database\PostgresConnection::class;
        $first = new $class(new \PDO($dsn), (string) $config['database'], '', $config);
        $second = new $class(new \PDO($dsn), (string) $config['database'], '', $config);
        $dir = $this->temp();
        $app = $this->application($dir . '/unused.sqlite', $dir . '/migrations', $dir . '/marker');
        $settings = AutoMigrator::currentSettings();
        $held = MigrationLock::open($first, 'rhetoric-migrate:live', $settings, $app['app']);
        $waiting = MigrationLock::open($second, 'rhetoric-migrate:live', $settings, $app['app']);
        self::assertTrue($held->acquire(2));
        self::assertFalse($waiting->acquire(0));
        $held->release();
        self::assertTrue($waiting->acquire(2));
        $waiting->release();
    }

    private function temp(): string
    {
        $dir = \sys_get_temp_dir() . '/rhetoric-phase7-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Could not create ' . $dir);
        }

        return $dir;
    }
}

final class RecordingLockConnection extends \Illuminate\Database\Connection
{
    /** @var array<string, bool> */
    public array $reads = [];

    public function scalar($query, $bindings = [], $useReadPdo = true)
    {
        $this->reads[(string) $query] = (bool) $useReadPdo;

        return 1;
    }
}

final class RhetoricApp extends Container
{
    /** @var array<int, \Closure> */
    public array $booting = [];

    public string $environment = 'production';

    public bool $console = false;

    public function booting($callback): void
    {
        $this->booting[] = $callback;
    }

    public function fireBooting(): void
    {
        foreach ($this->booting as $callback) {
            $callback();
        }
    }

    public function environment(mixed ...$environments): string|bool
    {
        if ($environments === []) {
            return $this->environment;
        }

        return \in_array($this->environment, $environments, true);
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }

    public function runningUnitTests(): bool
    {
        return true;
    }
}
