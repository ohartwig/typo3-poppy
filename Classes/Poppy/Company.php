<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Poppy;

use Koh\Poppy\Discovery\PoppyDocument;
use Koh\Poppy\PoppyServer;
use Koh\Poppy\Typo3\Configuration\SiteSettings;
use TYPO3\CMS\Core\Site\Entity\Site;

/** The Company a site is: its document and, when the authorization server runs, the server and its public URLs. */
final readonly class Company
{
    public function __construct(
        public Site $site,
        public SiteSettings $settings,
        public string $baseUrl,
        public string $domain,
        public PoppyDocument $document,
        public ?PoppyServer $server,
        public ?string $browserSessionEndpoint,
        public ?string $feedbackEndpoint,
    ) {}

    /** The public URL of a path on the site's host — what DPoP proofs and assertions name. */
    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
