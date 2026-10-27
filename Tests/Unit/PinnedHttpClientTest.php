<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Koh\Poppy\Typo3\Http\PinnedHttpClient;
use Koh\Poppy\Typo3\Http\PoppyClientException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PinnedHttpClientTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    #[Test]
    public function connectsToTheCheckedAddressWithoutRedirects(): void
    {
        $client = new PinnedHttpClient($this->guzzle(), static fn(): array => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

        $response = $client->sendRequest(new Request('GET', 'https://agent.example/agent.json'));

        self::assertSame(200, $response->getStatusCode());
        $options = $this->sent[0];
        self::assertFalse($options['allow_redirects']);
        self::assertSame(5, $options['timeout']);
        self::assertSame([CURLOPT_RESOLVE => ['agent.example:443:93.184.216.34']], $options['curl']);
    }

    #[Test]
    public function pinsIpv6InBrackets(): void
    {
        $client = new PinnedHttpClient($this->guzzle(), static fn(): array => ['2606:2800:220:1:248:1893:25c8:1946']);
        $client->sendRequest(new Request('GET', 'https://agent.example/agent.json'));

        self::assertSame([CURLOPT_RESOLVE => ['agent.example:443:[2606:2800:220:1:248:1893:25c8:1946]']], $this->sent[0]['curl']);
    }

    #[Test]
    public function refusesAHostWithAnyPrivateAddress(): void
    {
        $client = new PinnedHttpClient($this->guzzle(), static fn(): array => ['93.184.216.34', '169.254.169.254']);

        $this->expectException(PoppyClientException::class);
        $client->sendRequest(new Request('GET', 'https://agent.example/agent.json'));
    }

    #[Test]
    public function refusesAHostThatDoesNotResolve(): void
    {
        $client = new PinnedHttpClient($this->guzzle(), static fn(): array => []);

        $this->expectException(PoppyClientException::class);
        $client->sendRequest(new Request('GET', 'https://agent.example/agent.json'));
    }

    #[Test]
    public function throughAProxyNothingIsPinned(): void
    {
        $client = new PinnedHttpClient($this->guzzle(), static fn(): array => throw new \LogicException('must not resolve'), proxied: true);
        $client->sendRequest(new Request('GET', 'https://agent.example/agent.json'));

        self::assertArrayNotHasKey('curl', $this->sent[0]);
        self::assertFalse($this->sent[0]['allow_redirects']);
    }

    private function guzzle(): Client
    {
        $handler = function (RequestInterface $request, array $options): FulfilledPromise {
            /** @var array<string, mixed> $options */
            $this->sent[] = $options;

            return new FulfilledPromise(new Response(200, [], '{}'));
        };

        return new Client(['handler' => HandlerStack::create($handler)]);
    }
}
