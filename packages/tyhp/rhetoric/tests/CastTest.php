<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\ConventionBlogContext;
use Tyhp\Rhetoric\Fixtures\Migrations\CastContext;
use Tyhp\Rhetoric\Fixtures\Migrations\Color;
use Tyhp\Rhetoric\Fixtures\Migrations\Painted;
use Tyhp\Rhetoric\Fixtures\Migrations\Shout;
use Tyhp\Rhetoric\Fixtures\Post;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Query\Hydrator;
use Tyhp\Rhetoric\Tracking\PropertyBag;

final class CastTest extends TestCase
{
    protected function setUp(): void
    {
        MapCache::clear();
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
    }

    public function testBuiltInCastsHydrateAndStore(): void
    {
        $context = new CastContext();
        $context->createSchema();
        $painted = $context->mapping()->model(Painted::class);
        $schema = $context->connection()->getSchemaBuilder();
        self::assertTrue($schema->hasTable($painted->table));
        self::assertTrue($schema->hasColumn($painted->table, 'painted_at'));
        self::assertNotNull($painted->column('color')->cast);
        self::assertNotNull($painted->column('paintedAt')->cast);
        self::assertNotNull($painted->column('active')->cast);
        self::assertNotNull($painted->column('id')->cast);

        $model = Hydrator::model($painted, (object) [
            'id' => '1',
            'color' => 'blue',
            'painted_at' => '2020-01-02 03:04:05',
            'active' => 1,
        ]);
        self::assertInstanceOf(Painted::class, $model);
        self::assertSame(1, $model->id);
        self::assertSame(Color::Blue, $model->color);
        self::assertInstanceOf(\DateTimeImmutable::class, $model->paintedAt);
        self::assertSame('2020-01-02 03:04:05', $model->paintedAt->format('Y-m-d H:i:s'));
        self::assertTrue($model->active);

        $empty = Hydrator::model($painted, (object) [
            'id' => 2,
            'color' => 'red',
            'painted_at' => null,
            'active' => 0,
        ]);
        self::assertInstanceOf(Painted::class, $empty);
        self::assertNull($empty->paintedAt);
        self::assertFalse($empty->active);
        self::assertSame(Color::Red, $empty->color);

        self::assertSame('blue', PropertyBag::database($painted->column('color'), Color::Blue));
        self::assertSame('2020-01-02 03:04:05', PropertyBag::database($painted->column('paintedAt'), $model->paintedAt));
        self::assertNull(PropertyBag::database($painted->column('paintedAt'), null));
        self::assertSame(1, PropertyBag::database($painted->column('active'), true));
        self::assertSame(0, PropertyBag::database($painted->column('active'), false));

        $shoutMap = $context->mapping()->model(Shout::class);
        $shout = Hydrator::model($shoutMap, (object) ['id' => 1, 'word' => 'HELLO']);
        self::assertInstanceOf(Shout::class, $shout);
        self::assertSame('hello', $shout->word);
        self::assertSame('HI', PropertyBag::database($shoutMap->column('word'), 'hi'));
    }

    public function testBoolCastSetAcceptsAValueThatIsAlreadyStored(): void
    {
        $cast = new \Tyhp\Rhetoric\Casts\BoolCast();
        self::assertSame(1, $cast->set(true));
        self::assertSame(1, $cast->set(1));
        self::assertSame(1, $cast->set('1'));
        self::assertSame(0, $cast->set(false));
        self::assertSame(0, $cast->set(0));
        self::assertNull($cast->set(null));
    }

    public function testExistingNullableDatesStayNull(): void
    {
        $posts = (new ConventionBlogContext())->mapping()->model(Post::class);
        self::assertNotNull($posts->column('publishedAt')->cast);
        self::assertNull(PropertyBag::inbound($posts->column('publishedAt'), null));
        $published = PropertyBag::inbound($posts->column('publishedAt'), '2024-05-06 07:08:09');
        self::assertInstanceOf(\DateTimeImmutable::class, $published);
        self::assertSame('2024-05-06 07:08:09', $published->format('Y-m-d H:i:s'));
        self::assertSame(4, PropertyBag::inbound($posts->column('id'), '4'));
        self::assertSame(4, PropertyBag::database($posts->column('id'), 4));
        self::assertNull(PropertyBag::database($posts->column('id'), null));
    }
}
