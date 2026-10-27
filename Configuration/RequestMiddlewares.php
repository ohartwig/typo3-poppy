<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\Poppy\Typo3\Http\BrowserSessionMiddleware;
use Koh\Poppy\Typo3\Http\EndpointsMiddleware;
use Koh\Poppy\Typo3\Http\PagesMiddleware;

return [
    'frontend' => [
        // The documents and the machine endpoints: right after the site is
        // known, before any frontend session is looked up or started.
        'koh/poppy/endpoints' => [
            'target' => EndpointsMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/static-route-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        // The agent's browser: after TYPO3 knows the website user, so it can
        // replace that user with the Session's, and before any page or access
        // check reads it.
        'koh/poppy/browser-session' => [
            'target' => BrowserSessionMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'koh/poppy/pages',
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/static-route-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        // Sign-in, device and connected agents: they need the website user.
        'koh/poppy/pages' => [
            'target' => PagesMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/static-route-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
