<?php

declare(strict_types=1);

namespace Tyhp\Compiler\Tests;

use Tyhp\Compiler\HttpClient;

final class RecordingHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /**
     * @param array<string, string> $responses url or suffix → body
     */
    public function __construct(
        private array $responses = [],
        private readonly bool $forbid = false,
    ) {
    }

    public function get(string $url, array $headers = []): string
    {
        $this->urls[] = $url;
        if ($this->forbid) {
            throw new \RuntimeException('HTTP was not expected: ' . $url);
        }

        return $this->bodyFor($url);
    }

    public function downloadToFile(string $url, string $destination, array $headers = []): void
    {
        $this->urls[] = $url;
        if ($this->forbid) {
            throw new \RuntimeException('HTTP was not expected: ' . $url);
        }
        if (\file_put_contents($destination, $this->bodyFor($url)) === false) {
            throw new \RuntimeException("Failed to write {$destination}.");
        }
    }

    private function bodyFor(string $url): string
    {
        if (isset($this->responses[$url])) {
            return $this->responses[$url];
        }
        foreach ($this->responses as $key => $body) {
            if (\str_ends_with($url, $key) || \str_contains($url, $key)) {
                return $body;
            }
        }

        throw new \RuntimeException('No fixture response for ' . $url);
    }
}
