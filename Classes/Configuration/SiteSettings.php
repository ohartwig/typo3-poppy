<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Configuration;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Typed access to the settings of the koh/poppy set.
 *
 * A site that does not include the set has none of these settings, and that —
 * not the value of poppy.enabled — is what keeps it out: installing the
 * extension must not make every site of an installation speak for itself.
 */
final readonly class SiteSettings
{
    /**
     * @param list<string> $directScopes
     * @param list<string> $deviceScopes
     * @param list<string> $allowClients
     * @param list<string> $blockClients
     */
    public function __construct(
        public bool $enabled,
        public bool $server,
        public string $organizationName,
        public array $directScopes,
        public array $deviceScopes,
        public int $loginPage,
        public bool $browserSession,
        public bool $feedback,
        public int $sessionTokenTtl,
        public int $accountTokenTtl,
        public array $allowClients,
        public array $blockClients,
        public bool $dpopNonces,
        public string $templateRootPath = '',
    ) {}

    public static function includesSet(Site $site): bool
    {
        return $site->getSettings()->has('poppy.enabled');
    }

    public static function fromSite(Site $site): self
    {
        $name = self::string($site, 'poppy.organizationName');
        if ($name === '') {
            $title = $site->getConfiguration()['websiteTitle'] ?? '';
            $name = is_string($title) && trim($title) !== '' ? trim($title) : $site->getIdentifier();
        }

        $loginPage = (int) self::string($site, 'poppy.loginPage');

        return new self(
            enabled: self::includesSet($site) && self::bool($site, 'poppy.enabled', true),
            server: self::bool($site, 'poppy.server', true),
            organizationName: $name,
            // Sign-in needs a page where a website user can sign in. Without
            // one the scopes are ignored, and the site offers signed-out
            // Sessions only, which is a complete deployment on its own.
            directScopes: $loginPage > 0 ? self::csv(self::string($site, 'poppy.signIn.directScopes')) : [],
            deviceScopes: $loginPage > 0 ? self::csv(self::string($site, 'poppy.signIn.deviceScopes')) : [],
            loginPage: $loginPage,
            browserSession: self::bool($site, 'poppy.browserSession', true),
            feedback: self::bool($site, 'poppy.feedback', true),
            sessionTokenTtl: max(60, min(86400, (int) (self::string($site, 'poppy.sessionTokenTtl') ?: 3600))),
            accountTokenTtl: max(0, (int) (self::string($site, 'poppy.accountTokenTtl') ?: 7776000)),
            allowClients: self::csv(self::string($site, 'poppy.clients.allow')),
            blockClients: self::csv(self::string($site, 'poppy.clients.block')),
            dpopNonces: self::bool($site, 'poppy.dpopNonces', false),
            templateRootPath: self::string($site, 'poppy.templateRootPath'),
        );
    }

    private static function bool(Site $site, string $identifier, bool $default): bool
    {
        return (bool) $site->getSettings()->get($identifier, $default);
    }

    private static function string(Site $site, string $identifier): string
    {
        $value = $site->getSettings()->get($identifier, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }
}
