<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Functional\Support;

use Koh\Poppy\Http\JsonFetcher;
use Koh\Poppy\Http\PublicAddressGuard;
use Psr\Clock\ClockInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Static test doubles, because every frontend sub-request of the testing
 * framework boots a container of its own: the test configures these, the
 * container of the sub-request hands them out (poppy_test's Services.yaml).
 */
final class TestDoubles
{
    public static ?FakeHttpClient $http = null;

    public static ?FrozenClock $clock = null;

    public static function reset(): void
    {
        self::$http = new FakeHttpClient();
        self::$clock = new FrozenClock();
    }

    public static function fetcher(RequestFactory $requests): JsonFetcher
    {
        return new JsonFetcher(self::$http ??= new FakeHttpClient(), $requests, new PublicAddressGuard(static fn(): array => ['93.184.216.34']));
    }

    public static function clock(): ClockInterface
    {
        return self::$clock ??= new FrozenClock();
    }
}
