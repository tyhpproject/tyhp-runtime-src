<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression\FluentSupport;
use Tyhp\InvalidTypeException;
use Tyhp\PropertyPath;
use Tyhp\Type;

class MemberTypeTest extends TestCase
{
    private const NS = 'Tyhp\\Tests\\Lambda\\';

    public static function setUpBeforeClass(): void
    {
        try {
            Type::register(self::NS . 'MemberAddress', static fn (): Type => Type::struct(
                null,
                ['city' => Type::string()],
                ['city'],
            ));
        } catch (InvalidTypeException) {
            // Registered by an earlier run in the same process.
        }
    }

    public function testPropertyNodeCarriesTheStampedType(): void
    {
        $posts = Expr::param('b', MemberBlog::class)->posts->backedBy()(fn (MemberBlog $b) => $b->posts);

        self::assertSame('array<' . self::NS . 'MemberPost>', $posts->body->type);
        self::assertSame('array<' . self::NS . 'MemberPost>', $posts->returnType);
    }

    public function testErasedClosureReturnTypeKeepsTheFullBodyType(): void
    {
        $posts = Expr::param('b', MemberBlog::class)->posts->backedBy()(fn (MemberBlog $b): array => $b->posts);

        self::assertSame('array<' . self::NS . 'MemberPost>', $posts->returnType);
    }

    public function testDifferentClosureReturnTypeWins(): void
    {
        $posts = Expr::param('b', MemberBlog::class)->posts->backedBy()(fn (MemberBlog $b): iterable => $b->posts);

        self::assertSame('iterable', $posts->returnType);
    }

    public function testNativePropertyTypesAndNullSafeHops(): void
    {
        $title = Expr::param('b', MemberBlog::class)->featured->nullSafe()->title
            ->backedBy()(fn (MemberBlog $b) => $b->featured?->title);

        self::assertSame('?' . self::NS . 'MemberPost', $title->body->object->type);
        self::assertSame('?string', $title->body->type);
    }

    public function testGenericAndStructReceivers(): void
    {
        self::assertSame(
            '?' . self::NS . 'MemberTable<' . self::NS . 'MemberBlog>',
            FluentSupport::memberType(self::NS . 'MemberBlog', 'blogs', false),
        );
        self::assertSame(
            'string',
            FluentSupport::memberType(self::NS . 'MemberTable<' . self::NS . 'MemberBlog>', 'name', false),
        );
        self::assertSame(
            '?string',
            FluentSupport::memberType('?' . self::NS . 'MemberTable<' . self::NS . 'MemberBlog>', 'name', false),
        );

        $city = Expr::param('b', MemberBlog::class)->address->city->backedBy()(fn (MemberBlog $b) => $b->address['city']);
        self::assertSame('struct ' . self::NS . 'MemberAddress', $city->body->object->type);
        self::assertSame('string', $city->body->type);
    }

    public function testUnknownReceiverOrMemberIsMixed(): void
    {
        self::assertSame('mixed', FluentSupport::memberType('object', 'anything', false));
        self::assertSame('mixed', FluentSupport::memberType('mixed', 'anything', false));
        self::assertSame('mixed', FluentSupport::memberType(self::NS . 'MemberBlog', 'missing', false));
        self::assertSame('mixed', FluentSupport::memberType(self::NS . 'MemberBlog', 'untyped', false));
    }

    public function testPropertyPathResultTypeUsesTheStamp(): void
    {
        $inferred = PropertyPath::from()(MemberBlog::class, ['posts'], fn (MemberBlog $b) => $b->posts);
        self::assertSame('array<' . self::NS . 'MemberPost>', $inferred->returnType);

        $declared = PropertyPath::from()(MemberBlog::class, ['posts'], fn (MemberBlog $b): array => $b->posts);
        self::assertSame('array<' . self::NS . 'MemberPost>', $declared->returnType);

        $nested = PropertyPath::from()(
            MemberBlog::class,
            ['featured', 'title'],
            fn (MemberBlog $b) => $b->featured?->title,
            [false, true],
        );
        self::assertSame('?string', $nested->returnType);
    }
}

class MemberPost
{
    public string $title = '';
}

class MemberTable
{
    public string $name = '';
}

class MemberBlog
{
    #[\Tyhp\PropertyType('array<Tyhp\\Tests\\Lambda\\MemberPost>')]
    public array $posts = [];

    #[\Tyhp\PropertyType('?Tyhp\\Tests\\Lambda\\MemberTable<Tyhp\\Tests\\Lambda\\MemberBlog>')]
    public ?MemberTable $blogs = null;

    #[\Tyhp\PropertyType('struct Tyhp\\Tests\\Lambda\\MemberAddress')]
    public array $address = ['city' => ''];

    public ?MemberPost $featured = null;

    public $untyped;
}
