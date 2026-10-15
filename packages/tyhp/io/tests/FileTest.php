<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\EventLoop;
use Tyhp\Io\Exceptions\IoException;
use Tyhp\Io\File\File;
use Tyhp\Promise;

final class FileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/tyhp-io-file-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->dir);
        EventLoop::resetInstance();
    }

    public function testWriteAppendAndRead(): void
    {
        $path = $this->dir . '/a.txt';
        $this->await(File::writeAsync($path, 'hello'));
        $this->await(File::appendAsync($path, ' world'));

        $this->assertSame('hello world', $this->await(File::readAsync($path)));
    }

    public function testLargeFilesRoundTrip(): void
    {
        $path = $this->dir . '/big.bin';
        $contents = \random_bytes(1_000_000);
        $this->await(File::writeAsync($path, $contents));

        $this->assertSame($contents, $this->await(File::readAsync($path)));
    }

    public function testCopyAndHash(): void
    {
        $from = $this->dir . '/from.bin';
        $to = $this->dir . '/to.bin';
        $contents = \random_bytes(600_000);
        \file_put_contents($from, $contents);

        $this->await(File::copyAsync($from, $to));

        $this->assertSame($contents, \file_get_contents($to));
        $this->assertSame(\hash('sha256', $contents), $this->await(File::hashAsync($to)));
        $this->assertSame(\md5($contents), $this->await(File::hashAsync($to, 'md5')));
    }

    public function testLinesYieldsEachLineWithoutTheNewline(): void
    {
        $path = $this->dir . '/lines.txt';
        \file_put_contents($path, "one\r\ntwo\n\nthree");

        $lines = [];
        $iterator = File::linesAsync($path)->getAsyncIterator();
        while ($this->await($iterator->next())) {
            $lines[] = $this->await($iterator->current());
        }

        $this->assertSame(['one', 'two', '', 'three'], $lines);
    }

    public function testReadingAMissingFileThrows(): void
    {
        $this->expectException(IoException::class);
        $this->await(File::readAsync($this->dir . '/missing.txt'));
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
