<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Backend;

use Koh\Poppy\Storage\Dbal\DbalFeedbackSink;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

/**
 * Feedback from Personal Agents (Poppy 0.2, section 8): tasks they could not
 * do on the sites of this installation.
 *
 * The text is the agents' own, unverified and possibly written to mislead.
 * Fluid escapes it; nothing here acts on it.
 */
#[AsController]
final readonly class FeedbackController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplates,
        private DbalFeedbackSink $feedback,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplates->create($request);
        $view->assign('reports', $this->feedback->latest(200));

        return $view->renderResponse('Backend/Feedback');
    }
}
