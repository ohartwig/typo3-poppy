<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use Koh\Poppy\Server\BrowserCookie;
use Koh\Poppy\Server\Scopes;
use Koh\Poppy\Typo3\Poppy\CompanyResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * The website's view of a Personal Agent's browser (Poppy 0.2, section 5).
 *
 * While the browser carries the Session's cookie, the site handles it as that
 * Session: as the website user the Session is signed in to, or as a visitor —
 * whatever TYPO3 login the browser holds besides. The user is set for this
 * request only; no TYPO3 session is created, so nothing outlives the Session,
 * and a sign-out takes effect on the next request.
 *
 * A Session signed in with `poppy:read` alone may look but not change
 * anything: requests other than GET and HEAD are answered 403.
 */
final readonly class BrowserSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CompanyResolver $companies,
        private BrowserCookie $cookie,
        private Context $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $value = $request->getCookieParams()[EndpointsMiddleware::COOKIE] ?? null;
        $site = $request->getAttribute('site');
        $frontendUser = $request->getAttribute('frontend.user');
        if (!is_string($value) || !$site instanceof Site || !$frontendUser instanceof FrontendUserAuthentication) {
            return $handler->handle($request);
        }

        $company = $this->companies->resolve($site);
        $sessionId = $this->cookie->sessionId($value);
        if ($company?->server === null || $company->browserSessionEndpoint === null || $sessionId === null) {
            return $handler->handle($request);
        }

        $sessions = $company->server->browserSessions($company->browserSessionEndpoint, $company->domain);
        $session = $sessions->current($sessionId);
        $accountId = $session !== null ? $sessions->account($session) : null;
        $record = $accountId !== null && ctype_digit($accountId) ? $frontendUser->getRawUserByUid((int) $accountId) : null;

        $frontendUser->user = is_array($record) ? $record : null;
        $frontendUser->fetchGroupData($request);
        $this->context->setAspect('frontend.user', $frontendUser->createUserAspect());

        if (is_array($record)
            && !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && !in_array(Scopes::WRITE, $session->scopes, true)) {
            return new HtmlResponse('This Personal Agent may view the account but not change it.', 403);
        }

        return $handler->handle($request);
    }
}
