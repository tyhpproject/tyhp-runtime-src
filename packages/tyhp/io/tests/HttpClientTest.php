<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Exceptions\TimeoutException;
use Tyhp\Io\Exceptions\HttpException;
use Tyhp\Io\Exceptions\HttpStatusException;
use Tyhp\Io\Http\HttpClient;
use Tyhp\Promise;

final class HttpClientTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        if (!\extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl is not loaded.');
        }
        $this->baseUrl = HttpTestServer::get()->baseUrl;
    }

    protected function tearDown(): void
    {
        EventLoop::resetInstance();
    }

    public function testGetReturnsStatusHeadersAndBody(): void
    {
        $response = $this->await(HttpClient::shared()->getAsync($this->baseUrl . '/hello'));

        $this->assertSame(200, $response->status);
        $this->assertSame('hello', $response->body);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('text/plain;charset=UTF-8', \str_replace(' ', '', (string) $response->header('Content-Type')));
        $this->assertSame(['one', 'two'], $response->headerValues('x-test'));
    }

    public function testRequestsRunConcurrently(): void
    {
        $client = new HttpClient();
        $started = \microtime(true);
        $responses = $this->await(Promise::all([
            $client->getAsync($this->baseUrl . '/slow?ms=400'),
            $client->getAsync($this->baseUrl . '/slow?ms=400'),
            $client->getAsync($this->baseUrl . '/slow?ms=400'),
        ]));
        $elapsed = \microtime(true) - $started;

        $this->assertCount(3, $responses);
        foreach ($responses as $response) {
            $this->assertSame('slow:400', $response->body);
        }
        $this->assertLessThan(1.0, $elapsed);
    }

    public function testPostJsonSendsBodyAndHeaders(): void
    {
        $response = $this->await(
            HttpClient::shared()->postJsonAsync($this->baseUrl . '/echo', ['a' => 1], ['X-Custom' => 'yes'])
        );
        $echo = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('POST', $echo['method']);
        $this->assertSame('{"a":1}', $echo['body']);
        $this->assertSame('application/json', $echo['contentType']);
        $this->assertSame('yes', $echo['custom']);
    }

    public function testRedirectsAreFollowed(): void
    {
        $response = $this->await(HttpClient::shared()->getAsync($this->baseUrl . '/redirect'));

        $this->assertSame(200, $response->status);
        $this->assertSame('hello', $response->body);
        $this->assertStringEndsWith('/hello', $response->url);
    }

    public function testEnsureSuccessThrowsForErrorStatus(): void
    {
        $response = $this->await(HttpClient::shared()->getAsync($this->baseUrl . '/status/404'));

        $this->assertSame(404, $response->status);
        $this->assertFalse($response->isSuccess());
        try {
            $response->ensureSuccess();
            $this->fail('Expected HttpStatusException.');
        } catch (HttpStatusException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame($response, $e->getResponse());
        }
    }

    public function testTimeoutRejectsWithTimeoutException(): void
    {
        $request = self::request($this->baseUrl . '/slow?ms=2000', ['timeoutMs' => 200]);

        try {
            $this->await(HttpClient::shared()->sendAsync($request));
            $this->fail('Expected TimeoutException.');
        } catch (TimeoutException $e) {
            $this->assertSame(200, $e->getTimeoutMs());
            $this->assertStringContainsString('timed out after 200ms', $e->getMessage());
        }
    }

    public function testConnectTimeoutCitesConnectTimeoutMs(): void
    {
        // 10.255.255.1 is a non-routable address, so the connection attempt hangs until the limit.
        $request = self::request('http://10.255.255.1:81/', ['timeoutMs' => 5000, 'connectTimeoutMs' => 150]);

        try {
            $this->await(HttpClient::shared()->sendAsync($request));
            $this->fail('Expected a rejected request.');
        } catch (TimeoutException $e) {
            $this->assertSame(150, $e->getTimeoutMs());
            $this->assertStringContainsString('timed out connecting after 150ms', $e->getMessage());
            $this->assertStringNotContainsString('5000', $e->getMessage());
        } catch (HttpException $e) {
            $this->markTestSkipped('The network rejected the connection instead of dropping it: ' . $e->getMessage());
        }
    }

    public function testGetJsonSendsAcceptJsonByDefault(): void
    {
        $echo = $this->getJson('/echo');

        $this->assertSame('application/json', $echo['accept']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptHeaderNames(): array
    {
        return [
            'exact' => ['Accept'],
            'lower' => ['accept'],
            'upper' => ['ACCEPT'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptHeaderNames')]
    public function testGetJsonKeepsACallerAcceptHeaderInAnyCase(string $name): void
    {
        $echo = $this->getJson('/echo', [$name => 'application/vnd.test+json']);

        $this->assertSame('application/vnd.test+json', $echo['accept']);
    }

    public function testCancellationRejectsTheRequest(): void
    {
        $source = new CancellationTokenSource();
        $promise = HttpClient::shared()->getAsync($this->baseUrl . '/slow?ms=2000', [], $source->getToken());
        EventLoop::getInstance()->delay(100, static function () use ($source): void {
            $source->cancel();
        });

        $started = \microtime(true);
        try {
            $this->await($promise);
            $this->fail('Expected OperationCancelledException.');
        } catch (OperationCancelledException) {
            $this->assertLessThan(1.5, \microtime(true) - $started);
        }
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->await(HttpClient::shared()->getAsync('file:///etc/passwd'));
    }

    public function testConnectionFailureRejectsWithHttpException(): void
    {
        $this->expectException(HttpException::class);
        $this->await(HttpClient::shared()->getAsync('http://127.0.0.1:' . HttpTestServer::freePort() . '/'));
    }

    public function testHeaderLineBreaksAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->await(HttpClient::shared()->getAsync($this->baseUrl . '/hello', ['X-Bad' => "a\r\nInjected: yes"]));
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $headers = []): array
    {
        $decoded = $this->await(HttpClient::shared()->getJsonAsync($this->baseUrl . $path, $headers));
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function request(string $url, array $overrides = []): array
    {
        return \array_replace([
            'url' => $url,
            'method' => 'GET',
            'headers' => [],
            'body' => '',
            'timeoutMs' => 30000,
            'connectTimeoutMs' => 10000,
            'followRedirects' => true,
            'maxRedirects' => 5,
            'verifyTls' => true,
        ], $overrides);
    }
}
