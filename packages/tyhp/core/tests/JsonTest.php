<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\Exceptions\IncompatibleTypeException;
use Tyhp\Json;
use Tyhp\Type;

final class JsonTest extends TestCase
{
    public function testDecodeStructPayloadMatchesWithOrWithoutTheExtension(): void
    {
        $type = self::payloadType();
        $value = self::payloadValue();
        $json = \json_encode($value);
        self::assertIsString($json);

        $decoded = Json::decode($type)($json);

        self::assertSame($value, $decoded);
    }

    public function testDecodeInvalidJsonThrowsJsonException(): void
    {
        try {
            Json::decode(self::payloadType())('{');
            self::fail('Expected JsonException.');
        } catch (\JsonException $e) {
            self::assertSame('Syntax error', $e->getMessage());
        }
    }

    public function testDecodeMismatchThrowsIncompatibleTypeException(): void
    {
        try {
            Json::decode(self::payloadType())('{"items":[{"id":"no"}]}');
            self::fail('Expected IncompatibleTypeException.');
        } catch (IncompatibleTypeException $e) {
            self::assertSame(
                'Incompatible type: expected struct anonymous, got array.',
                $e->getMessage(),
            );
        }
    }

    public function testTryDecodeFollowsDecode(): void
    {
        $type = self::payloadType();
        $value = self::payloadValue();
        $json = \json_encode($value);
        self::assertIsString($json);

        $out = [];
        self::assertTrue(Json::tryDecode($type)($json, $out));
        self::assertSame($value, $out);

        $kept = ['sentinel' => true];
        self::assertFalse(Json::tryDecode($type)('{', $kept));
        self::assertSame(['sentinel' => true], $kept);

        self::assertFalse(Json::tryDecode($type)('{"items":[{"id":"no"}]}', $kept));
        self::assertSame(['sentinel' => true], $kept);
    }

    private static function payloadType(): Type
    {
        return Type::struct([
            'items' => Type::list(Type::struct([
                'id' => Type::int(),
                'name' => Type::string(),
                'active' => Type::bool(),
                'score' => Type::float(),
                'tags' => Type::list(Type::string()),
                'address' => Type::struct([
                    'city' => Type::string(),
                    'zip' => Type::string(),
                ]),
            ])),
        ]);
    }

    /**
     * @return array{items: list<array{id: int, name: string, active: bool, score: float, tags: list<string>, address: array{city: string, zip: string}}>}
     */
    private static function payloadValue(): array
    {
        $items = [];
        for ($i = 0; $i < 32; $i++) {
            $items[] = [
                'id' => $i,
                'name' => 'person-' . $i,
                'active' => $i % 2 === 0,
                'score' => $i + 0.5,
                'tags' => ['a' . $i, 'b' . $i],
                'address' => [
                    'city' => 'city-' . $i,
                    'zip' => \str_pad((string) $i, 5, '0', \STR_PAD_LEFT),
                ],
            ];
        }

        return ['items' => $items];
    }
}
