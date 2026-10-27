<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Http;

use Koh\Poppy\Server\RateLimiter;
use Koh\Poppy\Server\SignIn\AuthorizationError;
use Koh\Poppy\Typo3\Poppy\Company;
use Koh\Poppy\Typo3\Poppy\CompanyResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * The pages a website user sees: Direct Sign-In consent (Poppy 0.2, 4.5),
 * Device Sign-In (4.6) and the list of connected Personal Agents (4.9).
 *
 * The user signs in on the site's own login page — a page with the felogin
 * plugin, named in `poppy.loginPage` — and comes back through `redirect_url`.
 * The validated authorization request waits in the TYPO3 session meanwhile;
 * the redirect URI never travels through the browser.
 */
final readonly class PagesMiddleware implements MiddlewareInterface
{
    private const SESSION_KEY = 'koh_poppy_authorize_';

    public function __construct(
        private CompanyResolver $companies,
        private PageView $view,
        private FormProtectionFactory $formProtection,
        private RateLimiter $rateLimiter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!in_array($path, ['/poppy/authorize', '/poppy/authorize/consent', '/poppy/device', '/poppy/account/agents'], true)) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        $user = $request->getAttribute('frontend.user');
        $company = $site instanceof Site ? $this->companies->resolve($site) : null;
        if ($company?->server === null || !$user instanceof FrontendUserAuthentication) {
            return $handler->handle($request);
        }

        $request = $request->withAttribute(PageView::TEMPLATE_ATTRIBUTE, $company->settings->templateRootPath);

        return match ($path) {
            '/poppy/authorize' => $this->authorize($request, $company, $user),
            '/poppy/authorize/consent' => $this->consent($request, $company, $user),
            '/poppy/device' => $this->device($request, $company, $user),
            default => $this->connections($request, $company, $user),
        };
    }

    private function authorize(ServerRequestInterface $request, Company $company, FrontendUserAuthentication $user): ResponseInterface
    {
        $signIn = $company->server?->directSignIn();
        if ($signIn === null) {
            return $this->error($request, 'Not available.', 404);
        }

        try {
            /** @var array<string, mixed> $query */
            $query = $request->getQueryParams();
            $authorization = $signIn->validate($query);
        } catch (AuthorizationError $error) {
            return $error->isRedirect() ? new RedirectResponse((string) $error->redirectUrl, 303) : $this->error($request, $error->getMessage());
        }

        $id = bin2hex(random_bytes(16));
        $user->setKey('ses', self::SESSION_KEY . $id, $authorization->toArray());

        return new RedirectResponse($company->url('/poppy/authorize/consent?request=' . $id), 303);
    }

    private function consent(ServerRequestInterface $request, Company $company, FrontendUserAuthentication $user): ResponseInterface
    {
        $signIn = $company->server?->directSignIn();
        $id = $request->getQueryParams()['request'] ?? '';
        $stored = is_string($id) && $id !== '' ? $user->getKey('ses', self::SESSION_KEY . $id) : null;
        if ($signIn === null || !is_array($stored)) {
            return $this->error($request, 'The request has expired. Please start the sign-in again in your agent.');
        }

        $accountId = $user->getUserId();
        if ($accountId === null) {
            return $this->login($request, $company);
        }

        try {
            $authorization = $signIn->restore($stored);
        } catch (AuthorizationError $error) {
            $user->setKey('ses', self::SESSION_KEY . $id, null);

            return $error->isRedirect() ? new RedirectResponse((string) $error->redirectUrl, 303) : $this->error($request, $error->getMessage());
        }

        $protection = $this->formProtection->createFromRequest($request);
        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $token = is_string($body['_token'] ?? null) ? $body['_token'] : '';
            if (!$protection->validateToken($token, 'koh_poppy', 'authorize', $id)) {
                return $this->error($request, 'The form was out of date. Please try again.');
            }

            $user->setKey('ses', self::SESSION_KEY . $id, null);
            $approved = ($body['approve'] ?? '') === '1' && is_array($body['scopes'] ?? null)
                ? array_values(array_filter($body['scopes'], 'is_string'))
                : [];

            return new RedirectResponse($approved === []
                ? $signIn->deny($authorization)
                : $signIn->approve($authorization, (string) $accountId, $approved), 303);
        }

        return $this->view->render($request, 'Consent', [
            'agent' => $authorization->client,
            'agentHost' => parse_url($authorization->client->clientId, PHP_URL_HOST),
            'scopes' => $authorization->scopes,
            'company' => $company->document->toArray()['organization'],
            'userName' => $user->user['username'] ?? '',
            'redirectHost' => parse_url($authorization->redirectUri, PHP_URL_HOST),
            'action' => $company->url('/poppy/authorize/consent?request=' . $id),
            'token' => $protection->generateToken('koh_poppy', 'authorize', $id),
        ]);
    }

    private function device(ServerRequestInterface $request, Company $company, FrontendUserAuthentication $user): ResponseInterface
    {
        $server = $company->server;
        if ($server === null || !$server->configuration->hasDeviceSignIn()) {
            return $this->error($request, 'Not available.', 404);
        }

        $accountId = $user->getUserId();
        if ($accountId === null) {
            return $this->login($request, $company);
        }

        $body = $request->getMethod() === 'POST' ? (array) $request->getParsedBody() : [];
        $code = $body['user_code'] ?? $request->getQueryParams()['user_code'] ?? '';
        $code = is_string($code) ? trim($code) : '';
        $view = ['company' => $company->document->toArray()['organization'], 'action' => $company->url('/poppy/device'), 'code' => $code];

        if ($code === '') {
            return $this->view->render($request, 'Device', $view);
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if ($this->rateLimiter->hit('device_page', is_string($ip) ? $ip : '') !== null) {
            return $this->view->render($request, 'Device', $view + ['error' => 'tooMany'], 429);
        }

        $verification = $server->deviceVerification();
        $found = $verification->find($code);
        if ($found === null) {
            return $this->view->render($request, 'Device', $view + ['error' => 'unknown'], 404);
        }
        [$authorization, $agent] = $found;

        $protection = $this->formProtection->createFromRequest($request);
        if (isset($body['decision'])) {
            $token = is_string($body['_token'] ?? null) ? $body['_token'] : '';
            if (!$protection->validateToken($token, 'koh_poppy', 'device', $authorization->userCode)) {
                return $this->error($request, 'The form was out of date. Please try again.');
            }

            if ($body['decision'] === 'approve') {
                $scopes = is_array($body['scopes'] ?? null) ? array_values(array_filter($body['scopes'], 'is_string')) : [];
                $verification->approve($authorization, (string) $accountId, $scopes);
                $outcome = $scopes === [] ? 'denied' : 'approved';
            } else {
                $verification->deny($authorization);
                $outcome = 'denied';
            }

            return $this->view->render($request, 'DeviceDone', $view + ['agent' => $agent, 'outcome' => $outcome]);
        }

        return $this->view->render($request, 'DeviceConsent', $view + [
            'agent' => $agent,
            'agentHost' => parse_url($agent->clientId, PHP_URL_HOST),
            'scopes' => $authorization->requestedScopes,
            'userName' => $user->user['username'] ?? '',
            'token' => $protection->generateToken('koh_poppy', 'device', $authorization->userCode),
        ]);
    }

    private function connections(ServerRequestInterface $request, Company $company, FrontendUserAuthentication $user): ResponseInterface
    {
        $accountId = $user->getUserId();
        if ($accountId === null || $company->server === null) {
            return $this->login($request, $company);
        }

        $disconnector = $company->server->disconnector();
        $protection = $this->formProtection->createFromRequest($request);

        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $token = is_string($body['_token'] ?? null) ? $body['_token'] : '';
            if (!$protection->validateToken($token, 'koh_poppy', 'disconnect')) {
                return $this->error($request, 'The form was out of date. Please try again.');
            }
            $clientId = $body['client_id'] ?? '';
            $disconnector->disconnect((string) $accountId, is_string($clientId) ? $clientId : '');

            return new RedirectResponse($company->url('/poppy/account/agents'), 303);
        }

        $connections = [];
        foreach ($disconnector->connections((string) $accountId) as $clientId => $tokens) {
            $scopes = [];
            $since = null;
            foreach ($tokens as $token) {
                $scopes = [...$scopes, ...$token->scopes];
                $since = $since === null || $token->issuedAt < $since ? $token->issuedAt : $since;
            }
            $connections[] = ['clientId' => $clientId, 'host' => parse_url($clientId, PHP_URL_HOST), 'scopes' => array_values(array_unique($scopes)), 'since' => $since];
        }

        return $this->view->render($request, 'Connections', [
            'connections' => $connections,
            'action' => $company->url('/poppy/account/agents'),
            'token' => $protection->generateToken('koh_poppy', 'disconnect'),
        ]);
    }

    /** To the site's login page, and back here afterwards. */
    private function login(ServerRequestInterface $request, Company $company): ResponseInterface
    {
        if ($company->settings->loginPage <= 0) {
            return $this->error($request, 'Signing in is not set up for this site.', 403);
        }

        $url = (string) $company->site->getRouter()->generateUri($company->settings->loginPage, [
            'redirect_url' => (string) $request->getUri(),
        ]);

        return new RedirectResponse($url, 303);
    }

    private function error(ServerRequestInterface $request, string $message, int $status = 400): ResponseInterface
    {
        return $this->view->render($request, 'Error', ['message' => $message], $status);
    }
}
