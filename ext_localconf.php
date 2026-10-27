<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

defined('TYPO3') or die();

// Personal Agents' metadata documents and key sets, for as long as their
// Cache-Control allows (bounded to a day). Losing an entry costs one fetch.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['koh_poppy'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'groups' => ['system'],
];
