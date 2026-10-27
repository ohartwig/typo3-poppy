<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

return [
    'frontend' => [
        'koh/poppy-test/whoami' => [
            'target' => \Koh\Poppy\Typo3\Tests\Functional\Support\WhoAmIMiddleware::class,
            'after' => ['koh/poppy/browser-session', 'koh/poppy/pages'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
    ],
];
