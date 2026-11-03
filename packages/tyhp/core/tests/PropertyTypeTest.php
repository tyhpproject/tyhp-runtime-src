<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core\PropertyTypeFixtures {
    final class Post
    {
        public string $title = '';
    }

    final class Table
    {
        public array $rows = [];
    }

    final class Blog
    {
        #[\Tyhp\PropertyType('array<Tyhp\Tests\Core\PropertyTypeFixtures\Post>')]
        public array $posts = [];

        #[\Tyhp\PropertyType('array<string, Tyhp\Tests\Core\PropertyTypeFixtures\Post>')]
        public array $byKey = [];

        #[\Tyhp\PropertyType('?Tyhp\Tests\Core\PropertyTypeFixtures\Table<Tyhp\Tests\Core\PropertyTypeFixtures\Blog>')]
        public ?Table $blogs = null;

        #[\Tyhp\PropertyType('struct Tyhp\Tests\Core\PropertyTypeFixtures\Address')]
        public array $address = [];

        #[\Tyhp\PropertyType('Tyhp\Tests\Core\PropertyTypeFixtures\UserId')]
        public int $owner = 0;

        #[\Tyhp\PropertyType('array<(')]
        public array $broken = [];

        public ?Post $featured = null;

        public int|string $key = 0;

        public $untyped;

        public function __construct(
            #[\Tyhp\PropertyType('array<int>')]
            public array $ids = [],
        ) {}
    }
}

namespace Tyhp\Tests\Core {
    use PHPUnit\Framework\TestCase;
    use Tyhp\Exceptions\InvalidTypeException;
    use Tyhp\Exceptions\PropertyNotFoundException;
    use Tyhp\Tests\Core\PropertyTypeFixtures\Blog;
    use Tyhp\Tests\Core\PropertyTypeFixtures\Post;
    use Tyhp\Tests\Core\PropertyTypeFixtures\Table;
    use Tyhp\Type;

    final class PropertyTypeTest extends TestCase
    {
        private const FIXTURES = 'Tyhp\\Tests\\Core\\PropertyTypeFixtures\\';

        public static function setUpBeforeClass(): void
        {
            try {
                Type::register(self::FIXTURES . 'Address', static fn (): Type => Type::struct(
                    null,
                    ['city' => Type::string()],
                    ['city'],
                ));
                Type::register(self::FIXTURES . 'UserId', static fn (): Type => Type::int());
            } catch (InvalidTypeException) {
                // Registered by an earlier run in the same process.
            }
        }

        public function testParseScalarsAndClasses(): void
        {
            self::assertSame(Type::string(), Type::parse('string'));
            self::assertSame(Type::fromClassName(Post::class), Type::parse('\\' . Post::class));
            self::assertSame(Type::fromClassName(Post::class), Type::parse(Post::class));
            self::assertSame('?string', (string)Type::parse('?string'));
            self::assertTrue(Type::parse('string|null')->isNullable());
            self::assertSame(Type::bool(), Type::parse('true'));
            self::assertSame(Type::fromClassName('Tyhp\\Decimal'), Type::parse('decimal'));
        }

        public function testParseArraysAndGenerics(): void
        {
            $list = Type::parse('array<' . Post::class . '>');
            self::assertSame('array<' . Post::class . '>', (string)$list);
            self::assertSame(Type::fromClassName(Post::class), $list->elementType());

            $map = Type::parse('array<string, ' . Post::class . '>');
            self::assertSame(Type::string(), $map->keyType());
            self::assertSame(Type::fromClassName(Post::class), $map->elementType());

            $generic = Type::parse('\\' . Table::class . '<\\' . Blog::class . '>');
            self::assertSame(Table::class, $generic->getName());
            self::assertCount(1, $generic->typeArguments());
            self::assertSame(Type::fromClassName(Blog::class), $generic->typeArgument(0));
            self::assertNull($generic->typeArgument(1));
            self::assertNull($generic->elementType());

            $nested = Type::parse('array<int, array<' . Post::class . '>>');
            self::assertSame(Type::fromClassName(Post::class), $nested->elementType()?->elementType());
        }

        public function testParseRoundTripsToString(): void
        {
            foreach ([
                'int',
                '?string',
                'array<int>',
                'array<string, int>',
                Table::class . '<' . Blog::class . '>',
                'int|string',
            ] as $spelling) {
                self::assertSame($spelling, (string)Type::parse($spelling), $spelling);
            }
        }

        public function testParseRejectsInvalidSpellings(): void
        {
            foreach (['', 'array<', 'int<string>', 'array<int, int, int>', 'int)', 'a b'] as $spelling) {
                try {
                    Type::parse($spelling);
                    self::fail('Expected InvalidTypeException for "' . $spelling . '".');
                } catch (InvalidTypeException $e) {
                    self::assertNotSame('', $e->getMessage());
                }
            }
        }

        public function testParseResolvesRegisteredNames(): void
        {
            $address = Type::parse('struct ' . self::FIXTURES . 'Address');
            self::assertSame('struct', $address->getKind());
            self::assertSame(Type::string(), $address->fieldType('city'));
            self::assertNull($address->fieldType('zip'));

            self::assertSame(Type::int()->getKind(), Type::parse(self::FIXTURES . 'UserId')->getKind());
        }

        public function testFromPropertyReadsStamp(): void
        {
            $posts = Type::fromProperty(Blog::class, 'posts');
            self::assertSame('array<' . Post::class . '>', (string)$posts);
            self::assertSame(Type::fromClassName(Post::class), $posts->elementType());

            $blogs = Type::fromProperty(Blog::class, 'blogs');
            self::assertTrue($blogs->isNullable());
            self::assertSame(Type::fromClassName(Blog::class), $blogs->typeArgument(0));

            self::assertSame(Type::string(), Type::fromProperty(Blog::class, 'address')->fieldType('city'));
            self::assertSame('array<int>', (string)Type::fromProperty(Blog::class, 'ids'));
        }

        public function testFromPropertyFallsBackToNativeType(): void
        {
            self::assertSame('?' . Post::class, (string)Type::fromProperty(Blog::class, 'featured'));
            self::assertSame('union', Type::fromProperty(Blog::class, 'key')->getKind());
            self::assertSame(Type::mixed(), Type::fromProperty(Blog::class, 'untyped'));
            self::assertSame(Type::array(), Type::fromProperty(Blog::class, 'broken'));
            self::assertSame(Type::string(), Type::fromProperty(Post::class, 'title'));
        }

        public function testFromPropertyAcceptsObjectsAndGenericSpellings(): void
        {
            self::assertSame(
                (string)Type::fromProperty(Blog::class, 'posts'),
                (string)Type::fromProperty(new Blog(), 'posts'),
            );
            self::assertSame(
                Type::array(),
                Type::fromProperty('\\' . Table::class . '<' . Blog::class . '>', 'rows'),
            );
        }

        public function testFromPropertyIsCached(): void
        {
            self::assertSame(Type::fromProperty(Blog::class, 'posts'), Type::fromProperty(Blog::class, 'posts'));
        }

        public function testFromPropertyThrowsForMissingProperty(): void
        {
            $this->expectException(PropertyNotFoundException::class);
            Type::fromProperty(Blog::class, 'nope');
        }

        public function testErased(): void
        {
            self::assertSame(Type::array(), Type::parse('array<' . Post::class . '>')->erased());
            self::assertSame(
                '?' . Table::class,
                (string)Type::parse('?' . Table::class . '<' . Blog::class . '>')->erased(),
            );
            self::assertSame(Type::array(), Type::parse('struct ' . self::FIXTURES . 'Address')->erased());
            self::assertSame('array|int', (string)Type::parse('array<int>|int')->erased());
            self::assertSame(Type::int(), Type::int()->erased());
        }
    }
}
