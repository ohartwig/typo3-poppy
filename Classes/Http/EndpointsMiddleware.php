<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use Koh\Poppy\Discovery\AuthorizationServerMetadata;
use Koh\Poppy\Exception\PoppyError;
use Koh\Poppy\Feedback\FeedbackSink;
use Koh\Poppy\Server\BrowserCookie;
use Koh\Poppy\Typo3\Poppy\Company;
use Koh\Poppy\Typo3\Poppy\CompanyResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The documents and the machine-facing endpoints of Poppy 0.2:
 * `/.well-known/poppy.json`, the issuer metadata, and token, revocation,
 * device authorization, feedback and browser-session under /poppy.
 *
 * Runs right after the site is known and before the frontend user is: none of
 * these keeps a TYPO3 session or sets a TYPO3 cookie, and a Personal Agent's
 * request should cost no session lookup.
 */
final readonly class EndpointsMiddleware implements MiddlewareInterface
{
    public const COOKIE = 'koh_poppy_browser';

    private const DOCUMENT_MAX_AGE = 300;

    public function __construct(
        private CompanyResolver $companies,
        private FeedbackSink $feedback,
        private BrowserCookie $cookie,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/.well-known/poppy.json' && !str_starts_with($path, '/.well-known/oauth-authorization-server/') && !str_starts_with($path, CompanyResolver::ISSUER_PATH . '/')) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        $company = $site instanceof Site ? $this->companies->resolve($site) : null;
        if ($company === null) {
            return $handler->handle($request);
        }

        if ($path === '/.well-known/poppy.json') {
            return $this->document($company->document->toArray());
        }

        $server = $company->server;
        if ($server === null) {
            return $handler->handle($request);
        }

        if ($path === AuthorizationServerMetadata::path($server->configuration->issuer)) {
            return $this->document($server->metadata());
        }

        return match ($request->getMethod() . ' ' . $path) {
            'POST /poppy/token' => $server->tokenEndpoint()->handle($request),
            'POST /poppy/revoke' => $server->revocationEndpoint()->handle($request),
            'POST /poppy/device_authorization' => $server->deviceAuthorizationEndpoint()->handle($request),
            'POST /poppy/feedback' => $company->feedbackEndpoint !== null
                ? $server->feedbackEndpoint($company->feedbackEndpoint, $this->feedback)->handle($request)
                : $handler->handle($request),
            'POST /poppy/browser-session' => $this->browserSession($request, $company),
            default => $handler->handle($request),
        };
    }

    /**
     * `POST {web.browser_session_endpoint}` (section 5): the agent's browser
     * posts its assertion and gets the site's own cookie for the Session.
     * SameSite=Lax, so the browser sends it on the 303 that follows.
     */
    private function browserSession(ServerRequestInterface $request, Company $company): ResponseInterface
    {
        if ($company->server === null || $company->browserSessionEndpoint === null) {
            return new HtmlResponse('', 404);
        }

        $body = $request->getParsedBody();
        $assertion = is_array($body) && is_string($body['assertion'] ?? null) ? $body['assertion'] : '';

        try {
            [$session, $returnTo] = $company->server->browserSessions($company->browserSessionEndpoint, $company->domain)->join($assertion);
        } catch (PoppyError $error) {
            return new HtmlResponse('<!doctype html><title>Poppy</title><p>' . htmlspecialchars($error->getMessage()) . '</p>', 400);
        }

        $cookie = sprintf(
            '%s=%s; Expires=%s; Path=/; Secure; HttpOnly; SameSite=Lax',
            self::COOKIE,
            rawurlencode($this->cookie->value($session->id)),
            gmdate('D, d M Y H:i:s \G\M\T', $session->expiresAt->getTimestamp()),
        );

        return (new RedirectResponse($returnTo, 303))->withHeader('Set-Cookie', $cookie)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $data */
    private function document(array $data): ResponseInterface
    {
        return new JsonResponse($data, 200, [
            'Cache-Control' => 'public, max-age=' . self::DOCUMENT_MAX_AGE,
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
