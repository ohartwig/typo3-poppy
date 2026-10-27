<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Poppy;

use Koh\Poppy\Client\ListClientPolicy;
use Koh\Poppy\Discovery\PoppyDocument;
use Koh\Poppy\Dpop\NonceSource;
use Koh\Poppy\Http\JsonFetcher;
use Koh\Poppy\Http\Url;
use Koh\Poppy\PoppyServer;
use Koh\Poppy\Replay\ReplayGuard;
use Koh\Poppy\Server\Endpoints;
use Koh\Poppy\Server\RateLimiter;
use Koh\Poppy\Server\ServerConfiguration;
use Koh\Poppy\Server\Storage\PoppyStorage;
use Koh\Poppy\Typo3\Configuration\SiteSettings;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The Company of a site. One issuer per site — https://{site host}/poppy —
 * because a TYPO3 installation often hosts sites of different owners, and an
 * issuer must never stand for more than one of them (Poppy 0.2, 3.2).
 *
 * The configuration follows from what is enabled: no server, no `auth`; no
 * login page, no sign-in. A site whose base is not an HTTPS URL on a host of
 * its own — a relative base, a development host on http — declares the
 * website alone, because an issuer must be an HTTPS URL.
 */
final class CompanyResolver
{
    public const ISSUER_PATH = '/poppy';

    /** @var array<string, PoppyServer> */
    private array $servers = [];

    public function __construct(
        private readonly PoppyStorage $storage,
        private readonly ReplayGuard $replayGuard,
        private readonly JsonFetcher $fetcher,
        private readonly ClockInterface $clock,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RateLimiter $rateLimiter,
        private readonly NonceSource $nonces,
    ) {}

    public function resolve(Site $site): ?Company
    {
        $settings = SiteSettings::fromSite($site);
        if (!$settings->enabled) {
            return null;
        }

        $base = rtrim((string) $site->getBase(), '/');
        $domain = (string) Url::host($base);
        if ($domain === '') {
            return null;
        }

        $issuer = $base . self::ISSUER_PATH;
        $path = (string) parse_url($base, PHP_URL_PATH);
        if (!$settings->server || !Url::isHttps($issuer) || $path !== '' || parse_url($base, PHP_URL_PORT) !== null) {
            // A site below a path or on a port shares its host with others, and
            // the issuer would not be the site's alone.
            return new Company($site, $settings, $base, $domain, new PoppyDocument($settings->organizationName, $domain), null, null, null);
        }

        $configuration = new ServerConfiguration(
            $issuer,
            [$domain],
            Endpoints::below($issuer),
            directScopes: $settings->directScopes,
            deviceScopes: $settings->deviceScopes,
            sessionTokenTtl: $settings->sessionTokenTtl,
            accountTokenTtl: $settings->accountTokenTtl > 0 ? $settings->accountTokenTtl : null,
        );

        $key = $issuer . '|' . md5(serialize($settings));
        $server = $this->servers[$key] ??= new PoppyServer(
            $configuration,
            $this->storage,
            $this->replayGuard,
            new ListClientPolicy($settings->allowClients, $settings->blockClients),
            $this->fetcher,
            $this->clock,
            $this->responseFactory,
            $this->streamFactory,
            $this->rateLimiter,
            $settings->dpopNonces ? $this->nonces : null,
        );

        $browserSession = $settings->browserSession ? $issuer . '/browser-session' : null;
        $feedback = $settings->feedback ? $issuer . '/feedback' : null;

        return new Company(
            $site,
            $settings,
            $base,
            $domain,
            new PoppyDocument($settings->organizationName, $domain, $configuration, browserSessionEndpoint: $browserSession, feedbackEndpoint: $feedback),
            $server,
            $browserSession,
            $feedback,
        );
    }
}
