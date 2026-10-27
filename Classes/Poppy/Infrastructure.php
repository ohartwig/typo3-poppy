<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Poppy;

use Koh\Poppy\Dpop\NonceSource;
use Koh\Poppy\Dpop\TimeSlotNonceSource;
use Koh\Poppy\Http\JsonFetcher;
use Koh\Poppy\Http\PublicAddressGuard;
use Koh\Poppy\Server\BrowserCookie;
use Koh\Poppy\Server\RateLimiter;
use Koh\Poppy\Server\SymfonyRateLimiter;
use Koh\Poppy\Storage\Dbal\DbalFeedbackSink;
use Koh\Poppy\Storage\Dbal\DbalReplayGuard;
use Koh\Poppy\Storage\Dbal\DbalStorage;
use Koh\Poppy\Typo3\Http\CacheDocumentCache;
use Koh\Poppy\Typo3\Http\PinnedHttpClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;

/**
 * Builds the koh/poppy ports from TYPO3's services. Referenced as factories
 * from Configuration/Services.yaml.
 *
 * @internal
 */
final class Infrastructure
{
    public const TABLE_PREFIX = 'tx_kohpoppy_';

    public const CACHE = 'koh_poppy';

    public static function storage(ConnectionPool $connections): DbalStorage
    {
        return new DbalStorage($connections->getConnectionForTable(self::TABLE_PREFIX . 'session'), self::TABLE_PREFIX);
    }

    public static function replayGuard(ConnectionPool $connections, ClockInterface $clock): DbalReplayGuard
    {
        return new DbalReplayGuard($connections->getConnectionForTable(self::TABLE_PREFIX . 'replay'), $clock, self::TABLE_PREFIX);
    }

    public static function feedback(ConnectionPool $connections): DbalFeedbackSink
    {
        return new DbalFeedbackSink($connections->getConnectionForTable(self::TABLE_PREFIX . 'feedback'), self::TABLE_PREFIX);
    }

    public static function clock(): ClockInterface
    {
        return new NativeClock();
    }

    public static function fetcher(GuzzleClientFactory $clients, RequestFactory $requests, CacheManager $caches): JsonFetcher
    {
        $proxy = self::configuration('HTTP', 'proxy');
        $resolver = static function (string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }

            return $addresses;
        };

        return new JsonFetcher(
            new PinnedHttpClient($clients->getClient(), $resolver, $proxy !== null && $proxy !== '' && $proxy !== []),
            $requests,
            new PublicAddressGuard($resolver),
            new CacheDocumentCache($caches->getCache(self::CACHE)),
        );
    }

    public static function rateLimiter(CacheManager $caches): RateLimiter
    {
        $storage = new CachingFrameworkStorage($caches);
        $limits = [
            'token' => [60, '1 minute'],
            'revoke' => [30, '1 minute'],
            'device' => [10, '1 minute'],
            'feedback' => [20, '1 hour'],
            'device_page' => [10, '10 minutes'],
        ];

        $factories = [];
        foreach ($limits as $bucket => [$limit, $interval]) {
            $factories[$bucket] = new RateLimiterFactory(['id' => 'koh_poppy_' . $bucket, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval], $storage);
        }

        return new SymfonyRateLimiter($factories);
    }

    public static function nonces(ClockInterface $clock): NonceSource
    {
        return new TimeSlotNonceSource(hash_hmac('sha256', 'koh-poppy-dpop-nonce', self::secret(), true), $clock);
    }

    public static function browserCookie(): BrowserCookie
    {
        return new BrowserCookie(hash_hmac('sha256', 'koh-poppy-browser-cookie', self::secret(), true));
    }

    private static function configuration(string $section, string $key): mixed
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $section = is_array($configuration) ? ($configuration[$section] ?? null) : null;

        return is_array($section) ? ($section[$key] ?? null) : null;
    }

    private static function secret(): string
    {
        $key = self::configuration('SYS', 'encryptionKey');
        if (!is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('koh/typo3-poppy needs $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'encryptionKey\'] (at least 32 characters).');
        }

        return $key;
    }
}
