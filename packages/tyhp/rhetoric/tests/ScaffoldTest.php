<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tyhp\Rhetoric\Fixtures\ConventionBlogContext;
use Tyhp\Rhetoric\Laravel\Commands\BaselineCommand;
use Tyhp\Rhetoric\Laravel\Commands\ScaffoldCommand;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Migrations\MapDiffer;
use Tyhp\Rhetoric\Migrations\MigrationWorkspace;
use Tyhp\Rhetoric\Migrations\SchemaExtractor;
use Tyhp\Rhetoric\Migrations\SnapshotStore;
use Tyhp\Rhetoric\Scaffolding\ScaffoldMap;
use Tyhp\Rhetoric\Scaffolding\ScaffoldOptions;
use Tyhp\Rhetoric\Scaffolding\Scaffolder;

final class ScaffoldTest extends TestCase
{
    private ?Manager $capsule = null;

    protected function tearDown(): void
    {
        MapCache::clear();
        if ($this->capsule !== null) {
            $this->capsule->getDatabaseManager()->purge();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->capsule = null;
    }

    public function testChirperScaffoldMatchesTheMapping(): void
    {
        $this->sqlite();
        $this->chirper();
        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Chirper\\ChirperContext', 'Scaffolded\\Chirper'), $dir . '/snapshots');

        self::assertCount(21, $result->files);
        $user = $this->source($dir . '/User.tyhp');
        $userColumns = $this->source($dir . '/Generated/UserColumns.tyhp');
        $chirpColumns = $this->source($dir . '/Generated/ChirpColumns.tyhp');
        $cache = $this->source($dir . '/Cache.tyhp');
        $sessionColumns = $this->source($dir . '/Generated/SessionColumns.tyhp');
        self::assertStringContainsString('class User', $user);
        self::assertStringContainsString('use \\Scaffolded\\Chirper\\Generated\\UserColumns;', $user);
        self::assertStringContainsString('public string $email', $userColumns);
        self::assertStringContainsString('public array<\\Scaffolded\\Chirper\\Chirp> $chirps', $userColumns);
        self::assertStringContainsString('public int $userId', $chirpColumns);
        self::assertStringContainsString('public string $message', $chirpColumns);
        self::assertStringContainsString('OnDelete::Cascade', $chirpColumns);
        self::assertStringContainsString("Table('cache')", $cache);
        self::assertStringContainsString('public ?int $userId', $sessionColumns);
        self::assertStringNotContainsString('?\\Scaffolded\\Chirper\\User', $sessionColumns);
        self::assertFileDoesNotExist($dir . '/PostTag.tyhp');
        foreach (['User', 'PasswordResetToken', 'Session', 'Cache', 'CacheLock', 'Job', 'JobBatch', 'FailedJob', 'Chirp'] as $short) {
            self::assertFileExists($dir . '/' . $short . '.tyhp');
        }

        $store = new SnapshotStore($dir . '/snapshots');
        $written = $store->load('Scaffolded\\Chirper\\ChirperContext');
        $current = SchemaExtractor::extract(ScaffoldMap::compile($result->plan));
        self::assertSame(1, $written->toArray()['format'] ?? null);
        self::assertSame($current->hash(), $written->hash());
        self::assertSame([], MapDiffer::diff($written, $current, [])->operations);
        $users = $written->table('users');
        self::assertNotNull($users);
        self::assertSame('id', $users->column('id')?->blueprint);
        self::assertTrue($users->column('id')?->generated);
        $chirps = $written->table('chirps');
        self::assertNotNull($chirps);
        self::assertSame('foreignId', $chirps->column('user_id')?->blueprint);
        self::assertSame('cascade', $chirps->foreignKeys[0]->onDelete ?? null);
        $sessions = $written->table('sessions');
        self::assertNotNull($sessions);
        self::assertFalse($sessions->column('id')?->generated);
        $tokens = $written->table('password_reset_tokens');
        self::assertSame(['email'], $tokens?->key?->columns);
        $cacheTable = $written->table('cache');
        self::assertSame(['key'], $cacheTable?->key?->columns);

        $shell = $this->source($dir . '/Chirp.tyhp');
        Schema::table('chirps', static function (Blueprint $table): void {
            $table->string('nickname')->nullable();
        });
        $again = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Chirper\\ChirperContext', 'Scaffolded\\Chirper'), $dir . '/snapshots');
        self::assertSame($shell, $this->source($dir . '/Chirp.tyhp'));
        self::assertStringContainsString('public ?string $nickname', $this->source($dir . '/Generated/ChirpColumns.tyhp'));
        $updated = SchemaExtractor::extract(ScaffoldMap::compile($again->plan));
        self::assertSame($updated->hash(), $store->load('Scaffolded\\Chirper\\ChirperContext')->hash());

        \file_put_contents($dir . '/Chirp.tyhp', "<?tyhp\n");
        $forced = $this->options($dir, 'Scaffolded\\Chirper\\ChirperContext', 'Scaffolded\\Chirper');
        $forced = new ScaffoldOptions(
            $forced->contextClass,
            $forced->namespace,
            $forced->output,
            $forced->attributes,
            $forced->pluralize,
            true,
            $forced->connection,
            $forced->schema,
            $forced->tables,
            $forced->exclude,
        );
        Scaffolder::run($this->connection(), $forced, null);
        self::assertStringContainsString('class Chirp', $this->source($dir . '/Chirp.tyhp'));
    }

    public function testFluentPivotViewAndNaming(): void
    {
        $this->sqlite();
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        Schema::create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('post_tag', static function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained();
            $table->foreignId('tag_id')->constrained();
            $table->primary(['post_id', 'tag_id']);
        });
        $this->connection()->statement('create view active_posts as select * from posts');
        $dir = $this->temp();
        $result = Scaffolder::run(
            $this->connection(),
            new ScaffoldOptions('Scaffolded\\Links\\LinksContext', 'Scaffolded\\Links', $dir, false, true, false, null, null, [], []),
            $dir . '/snapshots',
        );
        self::assertFileDoesNotExist($dir . '/PostTag.tyhp');
        $post = $this->source($dir . '/Generated/PostColumns.tyhp');
        self::assertStringNotContainsString('#[', $post);
        self::assertStringContainsString('public array<\\Scaffolded\\Links\\Tag> $tags', $post);
        $mappings = $this->source($dir . '/Generated/LinksContextMappings.tyhp');
        self::assertStringNotContainsString('pivotTable', $mappings);
        $active = $this->source($dir . '/Generated/ActivePostColumns.tyhp');
        self::assertStringNotContainsString('#[', $active);
        $shell = $this->source($dir . '/ActivePost.tyhp');
        self::assertStringNotContainsString('Keyless', $shell);
        self::assertStringContainsString('->keyless()', $mappings);
        $current = SchemaExtractor::extract(ScaffoldMap::compile($result->plan));
        self::assertNull($current->table('active_posts')?->key);
        self::assertNotNull($current->table('post_tag'));
        self::assertTrue($current->table('post_tag')?->pivot);
        self::assertSame([], MapDiffer::diff((new SnapshotStore($dir . '/snapshots'))->load('Scaffolded\\Links\\LinksContext'), $current, [])->operations);
    }

    public function testNoPluralizeAndPrefixAndArtisan(): void
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'app_',
            'foreign_key_constraints' => true,
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
        $dir = $this->temp();
        Scaffolder::run(
            $this->connection(),
            new ScaffoldOptions('Scaffolded\\Prefixed\\UsersContext', 'Scaffolded\\Prefixed', $dir, true, false, false, null, null, [], []),
            null,
        );
        $users = $this->source($dir . '/Users.tyhp');
        self::assertStringContainsString('class Users', $users);
        self::assertStringNotContainsString('app_users', $users);
        self::assertStringNotContainsString("Table('users')", $users);

        $out = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'Scaffolded\\Artisan\\BlogContext',
            '--namespace' => 'Scaffolded\\Artisan',
            '--output' => $out,
            '--tables' => 'users',
            '--baseline' => true,
            '--snapshot' => $out . '/snapshots',
        ]);
        self::assertSame(0, $code, $text);
        self::assertFileExists($out . '/User.tyhp');
        self::assertFileExists($out . '/snapshots/BlogContext.snapshot.json');
        [$rejected] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'Scaffolded\\Artisan\\BlogContext',
            '--output' => $out,
            '--attributes' => true,
            '--fluent' => true,
        ]);
        self::assertSame(1, $rejected);
    }

    public function testAttributesWriteEachOverrideOnce(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
        });
        Schema::create('logs', static function (Blueprint $table): void {
            $table->string('line');
        });
        Schema::create('articles', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->foreign('author')->references('id')->on('users')->cascadeOnDelete();
        });

        $attributes = $this->temp();
        Scaffolder::run($this->connection(), $this->options($attributes, 'Scaffolded\\Once\\OnceContext', 'Scaffolded\\Once'), null);
        $mappings = $this->source($attributes . '/Generated/OnceContextMappings.tyhp');
        self::assertStringContainsString("Table('cache')", $this->source($attributes . '/Cache.tyhp'));
        self::assertStringContainsString('Keyless', $this->source($attributes . '/Log.tyhp'));
        self::assertStringContainsString('Attributes\\Key]', $this->source($attributes . '/Generated/CacheColumns.tyhp'));
        self::assertStringNotContainsString('->table(', $mappings);
        self::assertStringNotContainsString('->keyless()', $mappings);
        self::assertStringNotContainsString('->key(', $mappings);
        // The relation is not named after its column, so it is declared in the mappings only.
        self::assertStringContainsString('->foreignKey(', $mappings);
        self::assertStringContainsString('->onDelete(', $mappings);
        self::assertStringNotContainsString('Attributes\\ForeignKey', $this->source($attributes . '/Generated/ArticleColumns.tyhp'));

        $fluent = $this->temp();
        Scaffolder::run(
            $this->connection(),
            new ScaffoldOptions('Scaffolded\\Once\\OnceContext', 'Scaffolded\\Once', $fluent, false, true, false, null, null, [], []),
            null,
        );
        $fluentMappings = $this->source($fluent . '/Generated/OnceContextMappings.tyhp');
        self::assertStringContainsString("->table('cache')", $fluentMappings);
        self::assertStringContainsString('->keyless()', $fluentMappings);
        self::assertStringContainsString('->key(', $fluentMappings);
        self::assertStringNotContainsString('Table(', $this->source($fluent . '/Cache.tyhp'));
        self::assertStringNotContainsString('Keyless', $this->source($fluent . '/Log.tyhp'));
        self::assertStringNotContainsString('#[', $this->source($fluent . '/Generated/CacheColumns.tyhp'));
    }

    public function testReservedClassNamesGetASuffix(): void
    {
        $this->sqlite();
        Schema::create('matches', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('strings', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('lists', static function (Blueprint $table): void {
            $table->id();
        });
        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Words\\WordsContext', 'Scaffolded\\Words'), $dir . '/snapshots');

        foreach (['MatchModel' => 'matches', 'StringModel' => 'strings', 'ListModel' => 'lists'] as $class => $tableName) {
            self::assertFileExists($dir . '/' . $class . '.tyhp');
            self::assertStringContainsString("Table('" . $tableName . "')", $this->source($dir . '/' . $class . '.tyhp'));
            self::assertStringContainsString('class ' . $class . ' {', $this->source($dir . '/' . $class . '.tyhp'));
        }
        self::assertFileDoesNotExist($dir . '/Match.tyhp');
        self::assertNotNull($result->plan->model('Scaffolded\\Words\\MatchModel'));
        self::assertNotNull(SchemaExtractor::extract(ScaffoldMap::compile($result->plan))->table('matches'));
    }

    public function testValuesThatLookLikeStubTokensAreWrittenAsTheyAre(): void
    {
        $this->sqlite();
        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->string('body')->default('keep {{class}} and {{body}}');
        });
        $dir = $this->temp();
        Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Tokens\\TokensContext', 'Scaffolded\\Tokens'), null);

        $columns = $this->source($dir . '/Generated/NoteColumns.tyhp');
        self::assertStringContainsString("public string \$body = 'keep {{class}} and {{body}}';", $columns);
    }

    public function testTablesWithoutThePrefixAreSkipped(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_', 'foreign_key_constraints' => true], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $this->connection()->unprepared('create table legacy (id integer primary key, name text)');
        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Skip\\SkipContext', 'Scaffolded\\Skip'), null);

        self::assertCount(1, $result->plan->models);
        self::assertFileExists($dir . '/User.tyhp');
        self::assertFileDoesNotExist($dir . '/Legacy.tyhp');
    }

    public function testPivotLinksMustPointAtTheRelatedKey(): void
    {
        $this->sqlite();
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('codes', static function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
        });
        Schema::create('post_code', static function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained();
            $table->string('code');
            $table->foreign('code')->references('code')->on('codes');
            $table->primary(['post_id', 'code']);
        });
        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Links\\CodesContext', 'Scaffolded\\Links'), null);

        self::assertFileExists($dir . '/PostCode.tyhp');
        foreach (['Post', 'Code'] as $short) {
            $model = $result->plan->model('Scaffolded\\Links\\' . $short);
            self::assertNotNull($model);
            foreach ($model->relations as $relation) {
                self::assertNotSame(\Tyhp\Rhetoric\Mapping\RelationKind::BelongsToMany, $relation->kind);
            }
        }
    }

    public function testPublishedStubsAreUsedAndMissingOnesFallBack(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $stubs = $this->temp();
        \file_put_contents($stubs . '/model.stub', "<?tyhp\n\n// published\n{{namespace}}{{uses}}{{attributes}}class {{class}} {\n    use {{trait}};\n{{body}}}\n");
        $dir = $this->temp();
        $options = new ScaffoldOptions('Scaffolded\\Stubs\\StubsContext', 'Scaffolded\\Stubs', $dir, true, true, false, null, null, [], [], $stubs);
        Scaffolder::run($this->connection(), $options, null);

        self::assertStringContainsString('// published', $this->source($dir . '/User.tyhp'));
        self::assertStringNotContainsString('// published', $this->source($dir . '/Generated/UserColumns.tyhp'));

        $unpublished = $this->temp();
        $out = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'Scaffolded\\Stubs\\ArtisanContext',
            '--output' => $out,
        ], ['path.base' => $unpublished]);
        self::assertSame(0, $code, $text);
        self::assertStringNotContainsString('// published', $this->source($out . '/User.tyhp'));

        $base = $this->temp();
        \mkdir($base . '/stubs/rhetoric', 0777, true);
        \copy($stubs . '/model.stub', $base . '/stubs/rhetoric/model.stub');
        $published = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'Scaffolded\\Stubs\\PublishedContext',
            '--output' => $published,
        ], ['path.base' => $base]);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('// published', $this->source($published . '/User.tyhp'));
        self::assertFileExists($published . '/Generated/UserColumns.tyhp');
    }

    public function testPublishedStubWithoutARequiredTokenWritesNothing(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
        });
        $stubs = $this->temp();
        \file_put_contents($stubs . '/model.stub', "<?tyhp\n\n{{namespace}}{{uses}}{{attributes}}class {{class}} {\n{{body}}}\n");
        $dir = $this->temp();
        $options = new ScaffoldOptions('Scaffolded\\Missing\\MissingContext', 'Scaffolded\\Missing', $dir, true, true, false, null, null, [], [], $stubs);
        try {
            Scaffolder::run($this->connection(), $options, $dir . '/snapshots');
            self::fail('Expected the stub without {{trait}} to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('{{trait}}', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/*') ?: []);

        \file_put_contents($stubs . '/model.stub', "<?tyhp\n\n{{namespace}}class {{class}} {\n    use {{trait}};\n    {{unknown}}\n}\n");
        try {
            Scaffolder::run($this->connection(), $options, null);
            self::fail('Expected the stub with an unknown token to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unfilled token', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/*') ?: []);
    }

    public function testStrayBracesInDefaultsAreWrittenAsTheyAre(): void
    {
        $this->sqlite();
        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->string('body')->default('open {{ only');
            $table->string('label')->default('{{namespace}}{{trait}}');
        });
        $dir = $this->temp();
        Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Braces\\BracesContext', 'Scaffolded\\Braces'), null);

        $columns = $this->source($dir . '/Generated/NoteColumns.tyhp');
        self::assertStringContainsString("public string \$body = 'open {{ only';", $columns);
        self::assertStringContainsString("public string \$label = '{{namespace}}{{trait}}';", $columns);
    }

    public function testInverseIsNotWrittenTwiceWhenTheMappingsDeclareTheRelation(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });
        Schema::create('articles', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->foreign('author')->references('id')->on('users');
        });
        $dir = $this->temp();
        Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Inverse\\InverseContext', 'Scaffolded\\Inverse'), null);

        $mappings = $this->source($dir . '/Generated/InverseContextMappings.tyhp');
        self::assertStringContainsString('->inverseOf(', $mappings);
        self::assertStringNotContainsString('InverseOf', $this->source($dir . '/Generated/ArticleColumns.tyhp'));
        self::assertStringContainsString('Attributes\\InverseOf', $this->source($dir . '/Generated/CommentColumns.tyhp'));
    }

    public function testFluentModeWritesInverseOnceInTheMappingsOnly(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });
        Schema::create('articles', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->foreign('author')->references('id')->on('users');
        });
        $dir = $this->temp();
        $options = new ScaffoldOptions('Scaffolded\\Fluent\\FluentContext', 'Scaffolded\\Fluent', $dir, false, true, false, null, null, [], []);
        Scaffolder::run($this->connection(), $options, null);

        foreach (['User', 'Comment', 'Article'] as $short) {
            self::assertStringNotContainsString('InverseOf', $this->source($dir . '/Generated/' . $short . 'Columns.tyhp'));
        }
        $mappings = $this->source($dir . '/Generated/FluentContextMappings.tyhp');
        // Only the two sides of the unconventional articles relation are declared here; each names its inverse once.
        self::assertSame(1, \substr_count($mappings, '->belongsTo('), $mappings);
        self::assertSame(1, \substr_count($mappings, '->hasMany('), $mappings);
        self::assertSame(2, \substr_count($mappings, '->inverseOf('), $mappings);
        self::assertSame(1, \substr_count($mappings, '->inverseOf(fn (\\Scaffolded\\Fluent\\User $user) => $user->articles)'), $mappings);
        self::assertSame(1, \substr_count($mappings, '->inverseOf(fn (\\Scaffolded\\Fluent\\Article $article) => $article?->authorRef)'), $mappings);
    }

    public function testATableNamedLikeThePrefixIsSkipped(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_', 'foreign_key_constraints' => true], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $this->connection()->unprepared('create table app_ (id integer primary key)');
        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Bare\\BareContext', 'Scaffolded\\Bare'), null);

        self::assertCount(1, $result->plan->models);
    }

    public function testPrefixedTablesAndAnEmptyPrefix(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_', 'foreign_key_constraints' => true], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;
        Schema::create('app_posts', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $this->connection()->unprepared('create table app_ (id integer primary key)');
        $this->connection()->unprepared('create table legacy (id integer primary key)');
        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Prefix\\PrefixContext', 'Scaffolded\\Prefix'), null);

        self::assertCount(2, $result->plan->models);
        self::assertFileExists($dir . '/AppPost.tyhp');
        self::assertFileExists($dir . '/User.tyhp');
        self::assertFileDoesNotExist($dir . '/Legacy.tyhp');

        $this->capsule->getDatabaseManager()->purge();
        $bare = $this->sqlite();
        $bare->getConnection('default')->unprepared('create table app_ (id integer primary key)');
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $other = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($other, 'Scaffolded\\Bare\\BareContext', 'Scaffolded\\Bare'), null);
        self::assertCount(2, $result->plan->models);
    }

    public function testBaselineFailureWritesNoFilesAndNoBaselineWritesNoSnapshot(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $dir = $this->temp();
        $blocker = $this->temp() . '/snapshots';
        \file_put_contents($blocker, 'not a directory');
        try {
            Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $blocker . '/nested');
            self::fail('Expected the unwritable snapshot directory to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not a directory', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/*') ?: []);

        $occupied = $this->temp();
        \mkdir($occupied . '/snapshots/BaselineContext.snapshot.json', 0777, true);
        $dir = $this->temp();
        try {
            Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $occupied . '/snapshots');
            self::fail('Expected a snapshot path that is a directory to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('is a directory', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/*') ?: []);

        $dangling = $this->temp();
        \symlink($dangling . '/missing-target', $dangling . '/snapshots');
        $dir = $this->temp();
        try {
            Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $dangling . '/snapshots');
            self::fail('Expected a dangling symlink snapshot directory to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not a directory', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/*') ?: []);

        $existing = $this->temp();
        \mkdir($existing . '/snapshots', 0777, true);
        $snapshotFile = $existing . '/snapshots/BaselineContext.snapshot.json';
        \file_put_contents($snapshotFile, 'stale');
        $dir = $this->temp();
        Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $existing . '/snapshots');
        self::assertFileExists($dir . '/User.tyhp');
        self::assertNotSame('stale', \file_get_contents($snapshotFile));

        \chmod($snapshotFile, 0444);
        if (!\is_writable($snapshotFile)) {
            $dir = $this->temp();
            try {
                Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $existing . '/snapshots');
                self::fail('Expected an unwritable snapshot file to be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Could not write', $exception->getMessage());
            } finally {
                \chmod($snapshotFile, 0644);
            }
            self::assertSame([], \glob($dir . '/*') ?: []);
        }

        $dir = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $dir . '/new/snapshots');
        self::assertFileExists($dir . '/User.tyhp');
        self::assertFileExists($dir . '/new/snapshots/BaselineContext.snapshot.json');
        self::assertSame($dir . '/new/snapshots/BaselineContext.snapshot.json', $result->snapshotPath);

        $elsewhere = $this->temp();
        $dir = $this->temp();
        Scaffolder::run($this->connection(), $this->options($dir, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), $elsewhere . '/snapshots');
        self::assertFileExists($dir . '/User.tyhp');
        self::assertFileExists($elsewhere . '/snapshots/BaselineContext.snapshot.json');

        $plain = $this->temp();
        $result = Scaffolder::run($this->connection(), $this->options($plain, 'Scaffolded\\Baseline\\BaselineContext', 'Scaffolded\\Baseline'), null);
        self::assertNull($result->snapshotPath);
        self::assertFileExists($plain . '/User.tyhp');
        self::assertSame([], \glob($plain . '/*.snapshot.json') ?: []);
        self::assertFileDoesNotExist($plain . '/snapshots');

        $out = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'Scaffolded\\Baseline\\CommandContext',
            '--output' => $out,
            '--snapshot' => $out . '/snapshots',
        ]);
        self::assertSame(0, $code, $text);
        self::assertFileExists($out . '/User.tyhp');
        self::assertFileDoesNotExist($out . '/snapshots');
    }

    public function testTheCommandTrimsALeadingBackslashFromTheNamespace(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $out = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'SlashContext',
            '--namespace' => '\\Scaffolded\\Slash',
            '--output' => $out,
        ]);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('namespace Scaffolded\\Slash;', $this->source($out . '/User.tyhp'));
        self::assertStringContainsString('namespace Scaffolded\\Slash;', $this->source($out . '/SlashContext.tyhp'));

        $bad = $this->temp();
        [$rejected] = $this->artisan('rhetoric:scaffold', [
            '--context' => 'List',
            '--namespace' => 'Scaffolded\\Slash',
            '--output' => $bad,
        ]);
        self::assertSame(1, $rejected);
        self::assertSame([], \glob($bad . '/*') ?: []);
    }

    public function testBadNamesWriteNothing(): void
    {
        $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $dir = $this->temp();
        foreach ([
            ['Scaffolded\\Bad\\Context', 'Scaffolded\\Bad-Name'],
            ['Scaffolded\\Bad\\Match', 'Scaffolded\\Bad'],
            ['Scaffolded\\Bad\\1Context', 'Scaffolded\\Bad'],
        ] as [$context, $namespace]) {
            try {
                Scaffolder::run($this->connection(), $this->options($dir, $context, $namespace), null);
                self::fail('Expected ' . $context . ' in ' . $namespace . ' to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], \glob($dir . '/*') ?: []);
            }
        }
    }

    public function testArtisanUsesTheApplicationDatabaseManager(): void
    {
        $capsule = $this->sqlite();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $instance = new \ReflectionProperty(Manager::class, 'instance');
        $instance->setValue(null, null);
        try {
            $out = $this->temp();
            [$code, $text] = $this->artisan('rhetoric:scaffold', [
                '--context' => 'Scaffolded\\Manager\\ManagerContext',
                '--output' => $out,
            ], ['db' => $capsule->getDatabaseManager()]);
            self::assertSame(0, $code, $text);
            self::assertFileExists($out . '/User.tyhp');
        } finally {
            $capsule->setAsGlobal();
        }
    }

    public function testBaselineWritesTheContextSnapshot(): void
    {
        $dir = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:baseline', [
            '--context' => ConventionBlogContext::class,
            '--snapshot' => $dir,
        ]);
        self::assertSame(0, $code, $text);
        $store = new SnapshotStore($dir);
        $written = $store->load(ConventionBlogContext::class);
        $current = SchemaExtractor::extract((new ConventionBlogContext())->mapping());
        self::assertSame($current->hash(), $written->hash());
        $report = (new MigrationWorkspace($store, $dir . '/migrations'))->pending(new ConventionBlogContext());
        self::assertTrue($report->clean());
    }

    public function testLiveDriversKeepStringLength(): void
    {
        $mysql = \getenv('RHETORIC_TEST_MYSQL_DSN');
        $pgsql = \getenv('RHETORIC_TEST_PGSQL_DSN');
        if (!\is_string($mysql) && !\is_string($pgsql)) {
            self::markTestSkipped('RHETORIC_TEST_MYSQL_DSN and RHETORIC_TEST_PGSQL_DSN are not set.');
        }
        if (\is_string($mysql) && $mysql !== '') {
            $this->liveLength($mysql, 'mysql');
        }
        if (\is_string($pgsql) && $pgsql !== '') {
            $this->liveLength($pgsql, 'pgsql');
        }
    }

    private function liveLength(string $dsn, string $expectedDriver): void
    {
        $parts = \parse_url($dsn);
        self::assertIsArray($parts);
        $scheme = $parts['scheme'] ?? '';
        if ($scheme === 'postgres' || $scheme === 'postgresql') {
            $scheme = 'pgsql';
        }
        self::assertSame($expectedDriver, $scheme);
        $prefix = 'rhetoric_' . \bin2hex(\random_bytes(4)) . '_';
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => $scheme,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? ($scheme === 'mysql' ? 3306 : 5432),
            'database' => \ltrim((string) ($parts['path'] ?? ''), '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
            'prefix' => $prefix,
            'foreign_key_constraints' => true,
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;
        Schema::create('widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('label', 100);
        });
        try {
            $dir = $this->temp();
            $result = Scaffolder::run(
                $this->connection(),
                new ScaffoldOptions('Scaffolded\\Live\\LiveContext', 'Scaffolded\\Live', $dir, true, true, false, null, null, ['widgets'], []),
                $dir . '/snapshots',
            );
            $columns = $this->source($dir . '/Generated/WidgetColumns.tyhp');
            self::assertStringContainsString('MaxLength(100)', $columns);
            $current = SchemaExtractor::extract(ScaffoldMap::compile($result->plan));
            self::assertSame(100, $current->table('widgets')?->column('label')?->maxLength);
            self::assertSame([], MapDiffer::diff((new SnapshotStore($dir . '/snapshots'))->load('Scaffolded\\Live\\LiveContext'), $current, [])->operations);
        } finally {
            $this->connection()->getSchemaBuilder()->dropAllTables();
        }
    }

    private function chirper(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_reset_tokens', static function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });
        Schema::create('chirps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message', 255);
            $table->timestamps();
        });
    }

    public function testEloquentScaffoldRejectsFluentAndBaseline(): void
    {
        $dir = $this->temp();
        [$fluent, $fluentText] = $this->artisan('rhetoric:scaffold', [
            '--eloquent' => true,
            '--fluent' => true,
            '--context' => 'Scaffolded\\Eloquent\\BlogContext',
            '--output' => $dir,
        ]);
        self::assertSame(1, $fluent, $fluentText);
        self::assertStringContainsString('Choose eloquent or fluent.', $fluentText);
        [$baseline, $baselineText] = $this->artisan('rhetoric:scaffold', [
            '--eloquent' => true,
            '--baseline' => true,
            '--context' => 'Scaffolded\\Eloquent\\BlogContext',
            '--output' => $dir,
        ]);
        self::assertSame(1, $baseline, $baselineText);
        self::assertStringContainsString('Eloquent models do not write a snapshot.', $baselineText);
    }

    public function testEloquentScaffoldWritesAttributeHooks(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Typed queries over Eloquent models require PHP 8.4 or newer.');
        }
        $this->sqlite();
        Schema::create('articles', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->integer('rating');
            $table->timestamp('published_at')->nullable();
            $table->string('headline');
            $table->softDeletes();
        });
        $dir = $this->temp();
        $options = new ScaffoldOptions(
            'Scaffolded\\Eloquent\\BlogContext',
            'Scaffolded\\Eloquent',
            $dir,
            true,
            true,
            false,
            null,
            null,
            [],
            [],
            null,
            true,
        );
        $result = Scaffolder::run($this->connection(), $options, null);
        self::assertCount(2, $result->files);
        $shell = $this->source($dir . '/Article.tyhp');
        $columns = $this->source($dir . '/Generated/ArticleColumns.tyhp');
        self::assertStringContainsString('class Article extends \\Illuminate\\Database\\Eloquent\\Model', $shell);
        self::assertStringContainsString('use \\Scaffolded\\Eloquent\\Generated\\ArticleColumns;', $shell);
        self::assertStringContainsString("getAttribute('title')", $columns);
        self::assertStringContainsString("setAttribute('title'", $columns);
        self::assertStringContainsString("getAttribute('published_at')", $columns);
        self::assertStringContainsString("Column('headline')", $columns);
        self::assertStringContainsString("setTable('articles')", $columns);
        self::assertStringContainsString('timestamps = false', $columns);
        self::assertStringContainsString("'rating' => 'integer'", $columns);
        self::assertStringContainsString('SoftDeletes', $columns);
        self::assertFileDoesNotExist($dir . '/BlogContext.tyhp');
        self::assertFileDoesNotExist($dir . '/Generated/BlogContextMappings.tyhp');

        \file_put_contents($dir . '/Article.tyhp', "<?tyhp\n");
        Scaffolder::run($this->connection(), $options, null);
        self::assertSame("<?tyhp\n", $this->source($dir . '/Article.tyhp'));
        self::assertStringContainsString("getAttribute('title')", $this->source($dir . '/Generated/ArticleColumns.tyhp'));
    }

    public function testEloquentScaffoldRenamesPropertiesThatEloquentDeclares(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Typed queries over Eloquent models require PHP 8.4 or newer.');
        }
        $this->sqlite();
        Schema::create('failures', static function (Blueprint $table): void {
            $table->id();
            $table->string('connection');
            $table->string('table');
            $table->string('table_value');
        });
        $dir = $this->temp();
        $options = new ScaffoldOptions(
            'Scaffolded\\Clash\\BlogContext',
            'Scaffolded\\Clash',
            $dir,
            true,
            true,
            false,
            null,
            null,
            [],
            [],
            null,
            true,
        );
        Scaffolder::run($this->connection(), $options, null);
        $columns = $this->source($dir . '/Generated/FailureColumns.tyhp');
        self::assertStringContainsString("Column('connection')]\n    public string \$connectionValue {", $columns);
        self::assertStringContainsString("Column('table')]\n    public string \$tableValue2 {", $columns);
        self::assertStringContainsString('public string $tableValue {', $columns);
        self::assertStringNotContainsString('public string $connection {', $columns);
        self::assertStringNotContainsString('public string $table {', $columns);
    }

    private function options(string $dir, string $context, string $namespace): ScaffoldOptions
    {
        return new ScaffoldOptions($context, $namespace, $dir, true, true, false, null, null, [], []);
    }

    private function sqlite(): Manager
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;

        return $capsule;
    }

    private function bindFacades(Manager $capsule): void
    {
        $app = $capsule->getContainer();
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('db.schema');
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.schema', $capsule->getConnection('default')->getSchemaBuilder());
    }

    private function connection(): \Illuminate\Database\Connection
    {
        self::assertInstanceOf(Manager::class, $this->capsule);

        return $this->capsule->getConnection('default');
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $instances
     * @return array{int, string}
     */
    private function artisan(string $command, array $arguments, array $instances = []): array
    {
        $container = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }

            public function basePath(string $path = ''): string
            {
                return '/app' . ($path === '' ? '' : '/' . $path);
            }

            public function databasePath(string $path = ''): string
            {
                return '/app/database' . ($path === '' ? '' : '/' . $path);
            }
        };
        foreach ($instances as $abstract => $instance) {
            $container->instance($abstract, $instance);
        }
        $application = new Application($container, new Dispatcher($container), '1');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->add(new ScaffoldCommand());
        $application->add(new BaselineCommand());
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => $command] + $arguments), $output);

        return [$code, $output->fetch()];
    }

    private function source(string $path): string
    {
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function temp(): string
    {
        $dir = \sys_get_temp_dir() . '/rhetoric-scaffold-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Could not create ' . $dir);
        }

        return $dir;
    }
}
