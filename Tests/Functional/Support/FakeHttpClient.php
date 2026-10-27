<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Functional\Support;

use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Serves canned documents by URL and counts what was asked for. */
final class FakeHttpClient implements ClientInterface
{
    /** @var array<string, ResponseInterface> */
    private array $responses = [];

    /** @var list<string> */
    public array $requested = [];

    /** @param array<mixed> $document */
    public function serveJson(string $url, array $document, string $cacheControl = 'max-age=300'): void
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->responses[$url] = new Response($body, 200, ['Content-Type' => 'application/json', 'Cache-Control' => $cacheControl]);
    }

    public function serve(string $url, ResponseInterface $response): void
    {
        $this->responses[$url] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;

        $response = $this->responses[$url] ?? new Response('php://temp', 404);
        $response->getBody()->rewind();

        return $response;
    }
}
