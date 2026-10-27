<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use Koh\Poppy\Http\DocumentCache;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/** Personal Agents' metadata documents and key sets in the `koh_poppy` cache. */
final readonly class CacheDocumentCache implements DocumentCache
{
    public function __construct(private FrontendInterface $cache) {}

    public function get(string $key): ?array
    {
        $value = $this->cache->get($key);

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $document, int $ttl): void
    {
        $this->cache->set($key, $document, [], $ttl);
    }
}
