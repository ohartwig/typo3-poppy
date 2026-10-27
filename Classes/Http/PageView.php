<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the pages a website user sees, from Fluid templates a site can
 * replace: set `poppy.templateRootPath` in the site settings to a directory
 * with templates of the same names.
 *
 * Every page is uncacheable and unframeable: a consent page in a frame is a
 * clickjacking target, and one in a shared cache shows one user's request to
 * the next.
 *
 * @internal
 */
final readonly class PageView
{
    public const TEMPLATE_ATTRIBUTE = 'koh_poppy.templateRootPath';

    private const TEMPLATES = 'EXT:koh_poppy/Resources/Private/Templates/Pages';

    private const PARTIALS = 'EXT:koh_poppy/Resources/Private/Partials';

    private const LAYOUTS = 'EXT:koh_poppy/Resources/Private/Layouts';

    public function __construct(private ViewFactoryInterface $views) {}

    /** @param array<string, mixed> $variables */
    public function render(ServerRequestInterface $request, string $template, array $variables, int $status = 200): ResponseInterface
    {
        $override = $request->getAttribute(self::TEMPLATE_ATTRIBUTE);
        $view = $this->views->create(new ViewFactoryData(
            templateRootPaths: is_string($override) && $override !== '' ? [self::TEMPLATES, $override] : [self::TEMPLATES],
            partialRootPaths: [self::PARTIALS],
            layoutRootPaths: [self::LAYOUTS],
            request: $request,
        ));
        $view->assignMultiple($variables);

        return new HtmlResponse($view->render($template), $status, [
            'Cache-Control' => 'no-store, private',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ]);
    }
}
