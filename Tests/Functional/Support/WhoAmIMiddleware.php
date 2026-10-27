<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Functional\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/** `/__poppy_test/whoami`: which website user TYPO3 sees for this request, after koh/poppy had its say. */
final readonly class WhoAmIMiddleware implements MiddlewareInterface
{
    public function __construct(private Context $context) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/__poppy_test/whoami') {
            return $handler->handle($request);
        }

        return new JsonResponse([
            'loggedIn' => (bool) $this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn'),
            'id' => $this->context->getPropertyFromAspect('frontend.user', 'id'),
        ]);
    }
}
