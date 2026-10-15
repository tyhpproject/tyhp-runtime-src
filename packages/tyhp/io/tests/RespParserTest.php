<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\Io\Exceptions\RedisException;
use Tyhp\Io\Redis\RespParser;

final class RespParserTest extends TestCase
{
    public function testEncodeWritesBulkStringArray(): void
    {
        $this->assertSame("*2\r\n\$3\r\nGET\r\n\$1\r\nk\r\n", RespParser::encode(['GET', 'k']));
    }

    public function testParsesEachReplyType(): void
    {
        $parser = new RespParser();
        $parser->feed("+OK\r\n-ERR bad\r\n:42\r\n\$5\r\nhello\r\n\$-1\r\n*2\r\n:1\r\n-ERR inner\r\n");

        $this->assertSame('OK', $parser->next()->value);
        $error = $parser->next();
        $this->assertNull($error->value);
        $this->assertSame('ERR bad', $error->error);
        $this->assertSame(42, $parser->next()->value);
        $this->assertSame('hello', $parser->next()->value);
        $this->assertNull($parser->next()->value);

        $array = $parser->next()->value;
        $this->assertSame(1, $array[0]);
        $this->assertInstanceOf(RedisException::class, $array[1]);
        $this->assertSame('ERR inner', $array[1]->getMessage());
        $this->assertNull($parser->next());
    }

    public function testWaitsForIncompleteReplies(): void
    {
        $parser = new RespParser();
        $parser->feed("\$10\r\nhello");
        $this->assertNull($parser->next());
        $parser->feed("world\r\n*1\r\n");
        $this->assertSame('helloworld', $parser->next()->value);
        $this->assertNull($parser->next());
        $parser->feed("\$0\r\n\r\n");
        $this->assertSame([''], $parser->next()->value);
    }

    public function testBinarySafeBulkStrings(): void
    {
        $parser = new RespParser();
        $parser->feed("\$4\r\na\r\nb\r\n");
        $this->assertSame("a\r\nb", $parser->next()->value);
    }

    public function testInvalidTypeThrows(): void
    {
        $parser = new RespParser();
        $parser->feed("?nope\r\n");
        $this->expectException(RedisException::class);
        $parser->next();
    }
}
