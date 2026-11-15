<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Koh\Poppy\Exception\FetchFailed;
use Koh\Poppy\Http\PublicAddressGuard;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches Personal Agents' metadata documents and key sets through TYPO3's
 * Guzzle client — so the installation's HTTP settings, a proxy included,
 * apply — with redirects off, short timeouts and the address pinned.
 *
 * Pinning is what closes the gap PublicAddressGuard leaves on its own: the
 * name is resolved once, every address is checked, and cURL is told to connect
 * to exactly that address (CURLOPT_RESOLVE), so a DNS answer that changes
 * between check and connect changes nothing. Through a proxy the proxy
 * resolves and cannot be pinned; the pre-flight check still applies there.
 *
 * $proxy is the fetcher's own proxy (KOH_POPPY_PROXY), set as the request's
 * proxy for both schemes so it wins over TYPO3's HTTP settings and over
 * HTTPS_PROXY: an egress proxy that admits only listed hosts cannot admit an
 * agent's domain, which is whatever the agent says it is.
 */
final readonly class PinnedHttpClient implements ClientInterface
{
    /** @param \Closure(string): list<string> $resolver */
    public function __construct(
        private GuzzleClient $guzzle,
        private \Closure $resolver,
        private bool $proxied = false,
        private ?string $proxy = null,
    ) {}

    /**
     * Reads the fetcher's own proxy (the value of KOH_POPPY_PROXY): an
     * http:// or https:// URL. Unset or empty means null, so TYPO3's HTTP
     * settings apply as before. Anything else is refused, so a typo cannot
     * silently route the fetch elsewhere.
     */
    public static function ownProxyFrom(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new \InvalidArgumentException('KOH_POPPY_PROXY must be an http:// or https:// URL.');
        }

        return $value;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $options = [
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => 5,
            RequestOptions::CONNECT_TIMEOUT => 3,
            RequestOptions::HEADERS => ['User-Agent' => 'koh-typo3-poppy (+https://github.com/ohartwig/typo3-poppy)'],
        ];

        if ($this->proxy !== null) {
            $options[RequestOptions::PROXY] = ['http' => $this->proxy, 'https' => $this->proxy];
        }

        $host = $request->getUri()->getHost();
        if (!$this->proxied && $this->proxy === null && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $addresses = ($this->resolver)($host);
            foreach ($addresses as $address) {
                if (!PublicAddressGuard::isPublic($address)) {
                    throw new PoppyClientException(FetchFailed::forbiddenAddress());
                }
            }
            if ($addresses === []) {
                throw new PoppyClientException(FetchFailed::unresolvable());
            }
            $address = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => [$host . ':443:' . $address]];
        }

        // Guzzle's exceptions are PSR-18 client exceptions already.
        return $this->guzzle->send($request, $options);
    }
}
