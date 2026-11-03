<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression;
use Tyhp\Expression\BinaryExpression;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\EvalBacker;
use Tyhp\Expression\EvalClosure;
use Tyhp\Expression\ExpressionInterpreter;
use Tyhp\Expression\ExpressionNode;
use Tyhp\Expression\ExpressionSerializer;
use Tyhp\Expression\ExpressionVisitor;
use Tyhp\Expression\LambdaExpression;
use Tyhp\Expression\MethodCallExpression;
use Tyhp\Expression\ParameterExpression;
use Tyhp\Expression\PropertyAccessExpression;

class LambdaExpressionTest extends TestCase
{
    protected function setUp(): void
    {
        EvalClosure::$compilations = 0;
        EvalClosure::$lastSource = '';
    }

    public function testNestedLambdaTreeShape(): void
    {
        [$body, $blog] = self::anyRatingAbove(3);

        self::assertInstanceOf(MethodCallExpression::class, $body);
        self::assertSame('any', $body->method);
        $lambda = $body->arguments[0];
        self::assertInstanceOf(LambdaExpression::class, $lambda);
        self::assertSame('lambda', $lambda->nodeType);
        self::assertSame('Closure', $lambda->type);
        self::assertSame('bool', $lambda->returnType);
        self::assertCount(1, $lambda->parameters);
        self::assertSame('p', $lambda->parameters[0]->name);
        self::assertSame(0, $lambda->parameters[0]->index);
        self::assertInstanceOf(BinaryExpression::class, $lambda->body);
        self::assertSame('b', $blog->name);
    }

    public function testInterpreterEvaluatesNestedLambda(): void
    {
        [$body, $blog] = self::anyRatingAbove(3);

        self::assertTrue(ExpressionInterpreter::evaluate($body, [$blog], self::blog([1, 5])));
        self::assertFalse(ExpressionInterpreter::evaluate($body, [$blog], self::blog([1, 2])));
    }

    public function testCompiledClosureEvaluatesNestedLambda(): void
    {
        [$body, $blog] = self::anyRatingAbove(3);

        $compiled = EvalBacker::tryCompile($body, [$blog]);
        self::assertNotNull($compiled);
        self::assertStringContainsString('(fn ($p) => ', EvalClosure::$lastSource);
        self::assertTrue($compiled(self::blog([1, 5])));
        self::assertFalse($compiled(self::blog([1, 2])));

        $expression = new Expression($body, [$blog], null, 'bool');
        self::assertTrue($expression(self::blog([4])));
        self::assertFalse($expression->compile()(self::blog([0])));
    }

    public function testNestedBodyReadsOuterParameter(): void
    {
        // fn ($b) => $b->posts->any(fn ($p) => $p->rating > $b->minRating)
        $blog = new ParameterExpression('b', LambdaBlog::class, 0);
        $post = new ParameterExpression('p', LambdaPost::class, 0);
        $body = new MethodCallExpression(
            new PropertyAccessExpression($blog, 'posts', LambdaPosts::class),
            'any',
            [
                new LambdaExpression(
                    [$post],
                    new BinaryExpression(
                        new PropertyAccessExpression($post, 'rating', 'int'),
                        '>',
                        new PropertyAccessExpression($blog, 'minRating', 'int'),
                        'bool',
                    ),
                    'bool',
                ),
            ],
            'bool',
        );

        $strict = self::blog([3, 4], minRating: 4);
        $lenient = self::blog([3, 4], minRating: 3);

        self::assertFalse(ExpressionInterpreter::evaluate($body, [$blog], $strict));
        self::assertTrue(ExpressionInterpreter::evaluate($body, [$blog], $lenient));

        $compiled = EvalBacker::tryCompile($body, [$blog]);
        self::assertNotNull($compiled);
        self::assertFalse($compiled($strict));
        self::assertTrue($compiled($lenient));
    }

    public function testInnerParameterShadowsOuterParameterOfTheSameName(): void
    {
        // fn ($x) => $x->posts->sum(fn ($x) => $x->rating)
        $outer = new ParameterExpression('x', LambdaBlog::class, 0);
        $inner = new ParameterExpression('x', LambdaPost::class, 0);
        $body = new MethodCallExpression(
            new PropertyAccessExpression($outer, 'posts', LambdaPosts::class),
            'sum',
            [new LambdaExpression([$inner], new PropertyAccessExpression($inner, 'rating', 'int'), 'int')],
            'int',
        );

        self::assertSame(9, ExpressionInterpreter::evaluate($body, [$outer], self::blog([4, 5])));
        $compiled = EvalBacker::tryCompile($body, [$outer]);
        self::assertNotNull($compiled);
        self::assertSame(9, $compiled(self::blog([4, 5])));
    }

    public function testDoublyNestedLambdaReadsEveryEnclosingScope(): void
    {
        // fn ($b) => $b->posts->any(fn ($p) => $p->tags->any(fn ($t) => $t === $b->featuredTag))
        $blog = new ParameterExpression('b', LambdaBlog::class, 0);
        $post = new ParameterExpression('p', LambdaPost::class, 0);
        $tag = new ParameterExpression('t', 'string', 0);
        $innermost = new LambdaExpression(
            [$tag],
            new BinaryExpression($tag, '===', new PropertyAccessExpression($blog, 'featuredTag', 'string'), 'bool'),
            'bool',
        );
        $middle = new LambdaExpression(
            [$post],
            new MethodCallExpression(new PropertyAccessExpression($post, 'tags', LambdaPosts::class), 'any', [$innermost], 'bool'),
            'bool',
        );
        $body = new MethodCallExpression(
            new PropertyAccessExpression($blog, 'posts', LambdaPosts::class),
            'any',
            [$middle],
            'bool',
        );

        $hit = self::blog([1], featuredTag: 'php', tags: ['php', 'go']);
        $miss = self::blog([1], featuredTag: 'rust', tags: ['php', 'go']);

        self::assertTrue(ExpressionInterpreter::evaluate($body, [$blog], $hit));
        self::assertFalse(ExpressionInterpreter::evaluate($body, [$blog], $miss));
        $compiled = EvalBacker::tryCompile($body, [$blog]);
        self::assertNotNull($compiled);
        self::assertTrue($compiled($hit));
        self::assertFalse($compiled($miss));
    }

    public function testLambdaBodyEvaluatesToAClosure(): void
    {
        // fn ($n) => fn ($m) => $n + $m
        $n = new ParameterExpression('n', 'int', 0);
        $m = new ParameterExpression('m', 'int', 0);
        $body = new LambdaExpression([$m], new BinaryExpression($n, '+', $m, 'int'), 'int');

        $walked = ExpressionInterpreter::evaluate($body, [$n], 2);
        self::assertInstanceOf(\Closure::class, $walked);
        self::assertSame(5, $walked(3));

        $compiled = EvalBacker::tryCompile($body, [$n]);
        self::assertNotNull($compiled);
        self::assertSame(5, $compiled(2)(3));
    }

    public function testLambdaWithReservedParameterNameIsWalked(): void
    {
        $outer = new ParameterExpression('b', LambdaBlog::class, 0);
        $reserved = new ParameterExpression('__bound', LambdaPost::class, 0);
        $body = new MethodCallExpression(
            new PropertyAccessExpression($outer, 'posts', LambdaPosts::class),
            'any',
            [new LambdaExpression([$reserved], new BinaryExpression(new PropertyAccessExpression($reserved, 'rating'), '>', new ConstantExpression(3, '3')))],
        );

        self::assertNull(EvalBacker::tryCompile($body, [$outer]));
        self::assertTrue(ExpressionInterpreter::evaluate($body, [$outer], self::blog([4])));
    }

    public function testFluentClosureBuildsLambdaNode(): void
    {
        $expression = Expr::param('b', LambdaBlog::class)
            ->posts
            ->call('any', Expr::closure([Expr::param('p', LambdaPost::class)], fn (Expr $p): Expr => $p->rating->gt(3)));

        $lambda = $expression->body->arguments[0];
        self::assertInstanceOf(LambdaExpression::class, $lambda);
        self::assertSame('p', $lambda->parameters[0]->name);
        self::assertSame('bool', $lambda->returnType);
        self::assertCount(1, $expression->parameters);
        self::assertSame('b', $expression->parameters[0]->name);

        self::assertTrue($expression(self::blog([5])));
        self::assertFalse($expression(self::blog([2])));
    }

    public function testFluentClosureCarriesOuterParametersReadOnlyInItsBody(): void
    {
        $expression = Expr::param('b', LambdaBlog::class, 0)
            ->posts
            ->call('any', Expr::closure(
                [Expr::param('p', LambdaPost::class, 0)],
                Expr::param('p', LambdaPost::class, 0)->rating->gt(Expr::param('min', 'int', 1)),
            ));

        self::assertCount(2, $expression->parameters);
        self::assertSame(['b', 'min'], \array_map(
            static fn (ParameterExpression $p): string => $p->name,
            $expression->parameters,
        ));
        self::assertTrue($expression(self::blog([5]), 4));
        self::assertFalse($expression(self::blog([5]), 5));
    }

    public function testFluentClosureRejectsNonParameterBuilders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Expr::closure([Expr::param('p', LambdaPost::class)->rating], 1);
    }

    public function testSerializerWritesLambdaNodes(): void
    {
        [$body, $blog] = self::anyRatingAbove(3);
        $expression = new Expression($body, [$blog], null, 'bool');

        $decoded = \json_decode(ExpressionSerializer::toJson($expression), true, 512, \JSON_THROW_ON_ERROR);
        $lambda = $decoded['body']['arguments'][0];

        self::assertSame('lambda', $lambda['nodeType']);
        self::assertSame('Closure', $lambda['type']);
        self::assertSame('bool', $lambda['returnType']);
        self::assertSame('parameter', $lambda['parameters'][0]['nodeType']);
        self::assertSame('p', $lambda['parameters'][0]['name']);
        self::assertSame(0, $lambda['parameters'][0]['index']);
        self::assertSame('binary', $lambda['body']['nodeType']);
        self::assertSame(3, $lambda['body']['right']['value']);
    }

    public function testSerializerEqualityComparesNestedLambdas(): void
    {
        [$leftBody, $leftBlog] = self::anyRatingAbove(3);
        [$sameBody, $sameBlog] = self::anyRatingAbove(3);
        [$otherBody, $otherBlog] = self::anyRatingAbove(4);

        $left = new Expression($leftBody, [$leftBlog], null, 'bool');
        $same = new Expression($sameBody, [$sameBlog], null, 'bool');
        $other = new Expression($otherBody, [$otherBlog], null, 'bool');

        self::assertSame(ExpressionSerializer::toJson($left), ExpressionSerializer::toJson($same));
        self::assertTrue($left->equals($same));
        self::assertFalse($left->equals($other));
    }

    public function testVisitorDefaultReturnsNull(): void
    {
        [$body] = self::anyRatingAbove(3);
        $visitor = new class extends ExpressionVisitor {
        };

        self::assertNull($body->arguments[0]->accept($visitor));
    }

    /**
     * fn ($b) => $b->posts->any(fn ($p) => $p->rating > $min)
     *
     * @return array{MethodCallExpression, ParameterExpression}
     */
    private static function anyRatingAbove(int $min): array
    {
        $blog = new ParameterExpression('b', LambdaBlog::class, 0);
        $post = new ParameterExpression('p', LambdaPost::class, 0);
        $body = new MethodCallExpression(
            new PropertyAccessExpression($blog, 'posts', LambdaPosts::class),
            'any',
            [
                new LambdaExpression(
                    [$post],
                    new BinaryExpression(
                        new PropertyAccessExpression($post, 'rating', 'int'),
                        '>',
                        new ConstantExpression($min, (string) $min),
                        'bool',
                    ),
                    'bool',
                ),
            ],
            'bool',
        );

        return [$body, $blog];
    }

    /**
     * @param array<int> $ratings
     * @param array<string> $tags
     */
    private static function blog(array $ratings, int $minRating = 0, string $featuredTag = '', array $tags = []): LambdaBlog
    {
        $posts = [];
        foreach ($ratings as $rating) {
            $posts[] = new LambdaPost($rating, new LambdaPosts($tags));
        }

        return new LambdaBlog(new LambdaPosts($posts), $minRating, $featuredTag);
    }
}

class LambdaBlog
{
    public function __construct(
        public LambdaPosts $posts,
        public int $minRating = 0,
        public string $featuredTag = '',
    ) {
    }
}

class LambdaPost
{
    public function __construct(public int $rating, public LambdaPosts $tags)
    {
    }
}

class LambdaPosts
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(public array $items)
    {
    }

    public function any(callable $predicate): bool
    {
        foreach ($this->items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    public function sum(callable $selector): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $selector($item);
        }

        return $total;
    }
}
