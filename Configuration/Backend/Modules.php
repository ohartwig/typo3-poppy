<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\Poppy\Typo3\Backend\FeedbackController;

return [
    'koh_poppy_feedback' => [
        'parent' => 'site',
        'access' => 'admin',
        'iconIdentifier' => 'module-reports',
        'labels' => 'LLL:EXT:koh_poppy/Resources/Private/Language/locallang_mod.xlf',
        'path' => '/module/site/poppy-feedback',
        'routes' => [
            '_default' => [
                'target' => FeedbackController::class . '::handleRequest',
            ],
        ],
    ],
];
