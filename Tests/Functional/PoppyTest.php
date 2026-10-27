<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Functional;

use Koh\Poppy\Jose\Base64Url;
use Koh\Poppy\Server\Model\AccountToken;
use Koh\Poppy\Server\Model\Session;
use Koh\Poppy\Server\Storage\PoppyStorage;
use Koh\Poppy\Typo3\Http\EndpointsMiddleware;
use Koh\Poppy\Typo3\Tests\Functional\Support\PersonalAgent;
use Koh\Poppy\Typo3\Tests\Functional\Support\TestDoubles;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The extension inside a real TYPO3: site sets, the middleware stack, the
 * database. Personal Agents' documents come from TestDoubles; nothing else is
 * replaced.
 */
final class PoppyTest extends FunctionalTestCase
{
    private const BASE = 'https://acme.example';

    private const ISSUER = 'https://acme.example/poppy';

    protected array $testExtensionsToLoad = [
        'koh/typo3-poppy',
        __DIR__ . '/Fixtures/Extensions/poppy_test',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['encryptionKey' => 'f00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeedf00dfeed'],
    ];

    private PersonalAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_users.csv');
        TestDoubles::reset();
        $this->agent = new PersonalAgent();
        \assert(TestDoubles::$http !== null);
        $this->agent->publish(TestDoubles::$http);
    }

    #[Test]
    public function aSiteWithTheSetPublishesItsDocumentAndIssuerMetadata(): void
    {
        $this->writeSite(['poppy.organizationName' => 'ACME']);

        $document = $this->json($this->fetch('/.well-known/poppy.json'));
        self::assertSame('0.2', $document['protocol_version']);
        self::assertSame(['name' => 'ACME', 'domain' => 'acme.example'], $document['organization']);
        self::assertSame(['issuer' => self::ISSUER], $document['auth'], 'no login page, no sign-in');
        self::assertSame(['browser_session_endpoint' => self::ISSUER . '/browser-session'], $document['web']);
        self::assertSame(['endpoint' => self::ISSUER . '/feedback'], $document['feedback']);

        $metadata = $this->json($this->fetch('/.well-known/oauth-authorization-server/poppy'));
        self::assertSame(self::ISSUER, $metadata['issuer']);
        self::assertSame(['acme.example'], $metadata['poppy_domains']);
        self::assertArrayNotHasKey('authorization_endpoint', $metadata);
    }

    #[Test]
    public function signInIsOfferedOnlyWithALoginPage(): void
    {
        $this->writeSite(['poppy.loginPage' => 2, 'poppy.signIn.directScopes' => 'poppy:read, poppy:write', 'poppy.signIn.deviceScopes' => 'poppy:read']);

        $auth = $this->json($this->fetch('/.well-known/poppy.json'))['auth'];
        self::assertSame(['issuer' => self::ISSUER, 'direct' => ['scopes' => ['poppy:read', 'poppy:write']], 'device' => ['scopes' => ['poppy:read']]], $auth);
    }

    #[Test]
    public function withoutTheServerTheWebsiteAloneIsDeclared(): void
    {
        $this->writeSite(['poppy.server' => false]);

        $document = $this->json($this->fetch('/.well-known/poppy.json'));
        self::assertArrayNotHasKey('auth', $document);
        self::assertSame([], $document['web']);
    }

    #[Test]
    public function aSessionStartsAtTheTokenEndpoint(): void
    {
        $this->writeSite();

        $body = $this->startSession('usr_1');
        self::assertSame('DPoP', $body['token_type']);
        self::assertFalse($body['signed_in']);
        self::assertIsString($body['session_id']);
        self::assertNotNull($this->service(PoppyStorage::class)->findSession($body['session_id']));
    }

    #[Test]
    public function aReplayedAssertionIsRefusedThroughTheDatabase(): void
    {
        $this->writeSite();
        $now = $this->now();
        $form = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->agent->sessionAssertion(self::ISSUER, 'usr_1', $now),
        ];

        self::assertSame(200, $this->post('/poppy/token', $form + $this->agent->clientAuthentication(self::ISSUER, $now), $this->agent->dpopProof('POST', self::ISSUER . '/token', $now))->getStatusCode());
        $replay = $this->post('/poppy/token', $form + $this->agent->clientAuthentication(self::ISSUER, $now), $this->agent->dpopProof('POST', self::ISSUER . '/token', $now));
        self::assertSame('invalid_grant', $this->json($replay)['error']);
    }

    #[Test]
    public function feedbackIsStoredWithASessionToken(): void
    {
        $this->writeSite();
        $token = $this->startSession('usr_1')['access_token'];
        self::assertIsString($token);

        $request = (new InternalRequest(self::BASE . '/poppy/feedback'))
            ->withMethod('POST')
            ->withHeader('Authorization', 'DPoP ' . $token)
            ->withHeader('DPoP', $this->agent->dpopProof('POST', self::ISSUER . '/feedback', $this->now(), $token))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->stream('{"kind":"missing","task":"Book a meeting room","description":"No booking form."}'));

        self::assertSame(202, $this->executeFrontendSubRequest($request)->getStatusCode());
        self::assertSame('Book a meeting room', $this->getConnectionPool()->getConnectionForTable('tx_kohpoppy_feedback')->fetchOne('SELECT task FROM tx_kohpoppy_feedback'));
    }

    #[Test]
    public function aBrowserJoinsItsSessionWithTheSitesOwnCookie(): void
    {
        $this->writeSite();
        $sessionId = $this->startSession('usr_1')['session_id'];
        self::assertIsString($sessionId);

        $response = $this->post('/poppy/browser-session', [
            'assertion' => $this->agent->browserAssertion(self::ISSUER . '/browser-session', 'usr_1', $sessionId, self::BASE . '/', $this->now()),
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(self::BASE . '/', $response->getHeaderLine('Location'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith(EndpointsMiddleware::COOKIE . '=', $cookie);
        self::assertStringContainsString('Secure; HttpOnly; SameSite=Lax', $cookie);

        $refused = $this->post('/poppy/browser-session', ['assertion' => 'not-a-jwt']);
        self::assertSame(400, $refused->getStatusCode());
        self::assertSame('', $refused->getHeaderLine('Set-Cookie'));
    }

    #[Test]
    public function theAgentsBrowserIsTheSessionsWebsiteUserAndNothingMore(): void
    {
        $this->writeSite();
        $sessionId = $this->startSession('usr_1')['session_id'];
        self::assertIsString($sessionId);

        $join = $this->post('/poppy/browser-session', [
            'assertion' => $this->agent->browserAssertion(self::ISSUER . '/browser-session', 'usr_1', $sessionId, self::BASE . '/', $this->now()),
        ]);
        preg_match('/^' . EndpointsMiddleware::COOKIE . '=([^;]+)/', $join->getHeaderLine('Set-Cookie'), $match);
        $cookie = rawurldecode($match[1] ?? '');
        self::assertNotSame('', $cookie);

        // Signed out: a visitor.
        self::assertSame(['loggedIn' => false, 'id' => 0], $this->whoAmI($cookie));

        // Signed in with poppy:read: the website user, who may look but not change anything.
        $storage = $this->service(PoppyStorage::class);
        $session = $storage->findSession($sessionId);
        self::assertNotNull($session);
        $token = new AccountToken('act_1', hash('sha256', 'pat_x'), $this->agent->clientId, 'usr_1', '7', ['poppy:read'], $this->now());
        $storage->saveAccountToken($token);
        $storage->saveSession($session->signIn($token, ['poppy:read']));

        self::assertSame(['loggedIn' => true, 'id' => 7], $this->whoAmI($cookie));
        self::assertSame(403, $this->whoAmIResponse($cookie, 'POST')->getStatusCode());

        // A forged cookie is nobody.
        self::assertSame(['loggedIn' => false, 'id' => 0], $this->whoAmI($sessionId . '.forged'));

        // Signed out again: the next request is a visitor's.
        $storage->saveAccountToken($token->revoke($this->now()));
        $storage->signOutSessions('act_1');
        self::assertSame(['loggedIn' => false, 'id' => 0], $this->whoAmI($cookie));
    }

    #[Test]
    public function theAccountPageListsAndDisconnectsAgents(): void
    {
        $this->writeSite(['poppy.loginPage' => 2]);
        $storage = $this->service(PoppyStorage::class);
        $now = $this->now();
        $session = new Session('ses_1', $this->agent->clientId, 'usr_1', $now, $now->modify('+1 day'));
        $token = new AccountToken('act_1', hash('sha256', 'pat_x'), $this->agent->clientId, 'usr_1', '7', ['poppy:read'], $now);
        $storage->saveAccountToken($token);
        $storage->saveSession($session->signIn($token, ['poppy:read']));

        // Without a signed-in website user: to the login page, and back afterwards.
        $login = $this->fetch('/poppy/account/agents');
        self::assertSame(303, $login->getStatusCode());
        self::assertStringStartsWith(self::BASE . '/login?redirect_url=', $login->getHeaderLine('Location'));
    }

    #[Test]
    public function directSignInStartsWithAValidatedRequestAndNeverRedirectsToAnUnknownUri(): void
    {
        $this->writeSite(['poppy.loginPage' => 2, 'poppy.signIn.directScopes' => 'poppy:read']);
        $verifier = Base64Url::encode(random_bytes(32));
        $query = [
            'response_type' => 'code',
            'client_id' => $this->agent->clientId,
            'redirect_uri' => $this->agent->redirectUri,
            'scope' => 'poppy:read',
            'code_challenge' => Base64Url::encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];

        $start = $this->fetch('/poppy/authorize?' . http_build_query($query));
        self::assertSame(303, $start->getStatusCode());
        self::assertStringStartsWith(self::ISSUER . '/authorize/consent?request=', $start->getHeaderLine('Location'));

        $unknown = $this->fetch('/poppy/authorize?' . http_build_query(['redirect_uri' => 'https://evil.example/cb'] + $query));
        self::assertSame(400, $unknown->getStatusCode());
        self::assertSame('', $unknown->getHeaderLine('Location'));
        self::assertSame('DENY', $unknown->getHeaderLine('X-Frame-Options'));
    }

    #[Test]
    public function directSignInFromConsentToSignedInSession(): void
    {
        $this->writeSite(['poppy.loginPage' => 2, 'poppy.signIn.directScopes' => 'poppy:read, poppy:write']);
        $sessionId = $this->startSession('usr_1')['session_id'];
        self::assertIsString($sessionId);
        $login = $this->loginCookie(7);
        $verifier = Base64Url::encode(random_bytes(32));

        $start = $this->fetchAs($login, '/poppy/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->agent->clientId,
            'redirect_uri' => $this->agent->redirectUri,
            'scope' => 'poppy:read poppy:write',
            'state' => 'st',
            'code_challenge' => Base64Url::encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]));
        self::assertSame(303, $start->getStatusCode());
        $consentPath = substr($start->getHeaderLine('Location'), strlen(self::BASE));

        $page = $this->fetchAs($login, $consentPath);
        self::assertSame(200, $page->getStatusCode(), (string) $page->getBody());
        $html = (string) $page->getBody();
        self::assertStringContainsString('Example Agent', $html);
        self::assertStringContainsString('agent.example', $html);
        self::assertSame('DENY', $page->getHeaderLine('X-Frame-Options'));

        $forged = $this->postAs($login, $consentPath, ['_token' => 'wrong', 'approve' => '1', 'scopes' => ['poppy:read']]);
        self::assertSame(400, $forged->getStatusCode());

        $approved = $this->postAs($login, $consentPath, ['_token' => $this->formToken($html), 'approve' => '1', 'scopes' => ['poppy:read']]);
        self::assertSame(303, $approved->getStatusCode(), (string) $approved->getBody());
        $redirect = $approved->getHeaderLine('Location');
        self::assertStringStartsWith($this->agent->redirectUri . '?code=', $redirect);
        self::assertStringContainsString('iss=' . rawurlencode(self::ISSUER), $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        self::assertIsString($query['code'] ?? null);

        $now = $this->now();
        $tokens = $this->json($this->post('/poppy/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $this->agent->redirectUri,
            'code_verifier' => $verifier,
            'session_id' => $sessionId,
        ] + $this->agent->clientAuthentication(self::ISSUER, $now), $this->agent->dpopProof('POST', self::ISSUER . '/token', $now)));
        self::assertTrue($tokens['signed_in']);
        self::assertSame('poppy:read', $tokens['scope']);
        self::assertIsString($tokens['refresh_token']);

        // Sign-out through the revocation endpoint.
        $revoked = $this->post('/poppy/revoke', ['token' => $tokens['refresh_token']] + $this->agent->clientAuthentication(self::ISSUER, $this->now()));
        self::assertSame(200, $revoked->getStatusCode());
        self::assertFalse($this->service(PoppyStorage::class)->findSession($sessionId)?->isSignedIn() ?? true);
    }

    #[Test]
    public function declinedConsentGoesBackWithAccessDenied(): void
    {
        $this->writeSite(['poppy.loginPage' => 2, 'poppy.signIn.directScopes' => 'poppy:read']);
        $login = $this->loginCookie(7);
        $start = $this->fetchAs($login, '/poppy/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->agent->clientId,
            'redirect_uri' => $this->agent->redirectUri,
            'scope' => 'poppy:read',
            'code_challenge' => Base64Url::encode(hash('sha256', str_repeat('v', 43), true)),
            'code_challenge_method' => 'S256',
        ]));
        $consentPath = substr($start->getHeaderLine('Location'), strlen(self::BASE));
        $html = (string) $this->fetchAs($login, $consentPath)->getBody();

        $declined = $this->postAs($login, $consentPath, ['_token' => $this->formToken($html), 'approve' => '0']);
        self::assertStringStartsWith($this->agent->redirectUri . '?error=access_denied', $declined->getHeaderLine('Location'));

        $expired = $this->fetchAs($login, '/poppy/authorize/consent?request=unknown');
        self::assertSame(400, $expired->getStatusCode());
    }

    #[Test]
    public function deviceSignInFromCodeToSignedInSession(): void
    {
        $this->writeSite(['poppy.loginPage' => 2, 'poppy.signIn.deviceScopes' => 'poppy:read']);
        $sessionId = $this->startSession('usr_1')['session_id'];
        self::assertIsString($sessionId);
        $device = $this->json($this->post('/poppy/device_authorization', ['scope' => 'poppy:read'] + $this->agent->clientAuthentication(self::ISSUER, $this->now())));
        self::assertIsString($device['user_code']);
        self::assertIsString($device['device_code']);
        $login = $this->loginCookie(7);

        self::assertSame(303, $this->fetch('/poppy/device?user_code=' . $device['user_code'])->getStatusCode(), 'signed out: to the login page');
        self::assertSame(200, $this->fetchAs($login, '/poppy/device')->getStatusCode());
        self::assertSame(404, $this->fetchAs($login, '/poppy/device?user_code=BCDF-GHJK')->getStatusCode());

        $consent = $this->fetchAs($login, '/poppy/device?user_code=' . strtolower($device['user_code']));
        self::assertSame(200, $consent->getStatusCode());
        $html = (string) $consent->getBody();
        self::assertStringContainsString('Example Agent', $html);

        self::assertSame(400, $this->postAs($login, '/poppy/device', ['user_code' => $device['user_code'], 'decision' => 'approve', '_token' => 'wrong'])->getStatusCode());
        $done = $this->postAs($login, '/poppy/device', ['user_code' => $device['user_code'], 'decision' => 'approve', 'scopes' => ['poppy:read'], '_token' => $this->formToken($html)]);
        self::assertSame(200, $done->getStatusCode());

        $this->clock()->advance(6);
        $now = $this->now();
        $tokens = $this->json($this->post('/poppy/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $device['device_code'],
            'session_id' => $sessionId,
        ] + $this->agent->clientAuthentication(self::ISSUER, $now), $this->agent->dpopProof('POST', self::ISSUER . '/token', $now)));
        self::assertTrue($tokens['signed_in']);
    }

    #[Test]
    public function aSignedInUserSeesAndDisconnectsAgents(): void
    {
        $this->writeSite(['poppy.loginPage' => 2]);
        $storage = $this->service(PoppyStorage::class);
        $now = $this->now();
        $session = new Session('ses_1', $this->agent->clientId, 'usr_1', $now, $now->modify('+1 day'));
        $token = new AccountToken('act_1', hash('sha256', 'pat_x'), $this->agent->clientId, 'usr_1', '7', ['poppy:read'], $now);
        $storage->saveAccountToken($token);
        $storage->saveSession($session->signIn($token, ['poppy:read']));
        $login = $this->loginCookie(7);

        $page = $this->fetchAs($login, '/poppy/account/agents');
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString($this->agent->clientId, $html);

        self::assertSame(400, $this->postAs($login, '/poppy/account/agents', ['client_id' => $this->agent->clientId, '_token' => 'wrong'])->getStatusCode());
        $done = $this->postAs($login, '/poppy/account/agents', ['client_id' => $this->agent->clientId, '_token' => $this->formToken($html)]);
        self::assertSame(303, $done->getStatusCode());
        self::assertNotNull($storage->findAccountToken('act_1')?->revokedAt);
    }

    #[Test]
    public function thePurgeCommandRemovesExpiredRecords(): void
    {
        $storage = $this->service(PoppyStorage::class);
        $now = $this->now();
        $storage->saveSession(new Session('ses_old', 'c', 'u', $now->modify('-2 days'), $now->modify('-1 day')));

        $tester = new \Symfony\Component\Console\Tester\CommandTester($this->service(\Koh\Poppy\Typo3\Command\PurgeCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertNull($storage->findSession('ses_old'));
    }

    private function loginCookie(int $userId): string
    {
        $manager = \TYPO3\CMS\Core\Session\UserSessionManager::create('FE');
        $session = $manager->elevateToFixatedUserSession($manager->createAnonymousSession(), $userId);

        return $session->getJwt();
    }

    private function fetchAs(string $login, string $path): ResponseInterface
    {
        return $this->executeFrontendSubRequest((new InternalRequest(self::BASE . $path))->withCookieParams(['fe_typo_user' => $login]));
    }

    /** @param array<string, mixed> $form */
    private function postAs(string $login, string $path, array $form): ResponseInterface
    {
        return $this->executeFrontendSubRequest((new InternalRequest(self::BASE . $path))
            ->withMethod('POST')
            ->withCookieParams(['fe_typo_user' => $login])
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($form)
            ->withBody($this->stream(http_build_query($form))));
    }

    private function formToken(string $html): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $html, $match);
        self::assertArrayHasKey(1, $match, $html);

        return html_entity_decode($match[1]);
    }

    private function clock(): \Koh\Poppy\Typo3\Tests\Functional\Support\FrozenClock
    {
        $clock = TestDoubles::clock();
        self::assertInstanceOf(\Koh\Poppy\Typo3\Tests\Functional\Support\FrozenClock::class, $clock);

        return $clock;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /** @return array<string, mixed> */
    private function whoAmI(string $cookie): array
    {
        return $this->json($this->whoAmIResponse($cookie));
    }

    private function whoAmIResponse(string $cookie, string $method = 'GET'): ResponseInterface
    {
        return $this->executeFrontendSubRequest((new InternalRequest(self::BASE . '/__poppy_test/whoami'))
            ->withMethod($method)
            ->withCookieParams([EndpointsMiddleware::COOKIE => $cookie]));
    }

    /** @param array<string, mixed> $settings */
    private function writeSite(array $settings = []): void
    {
        $this->service(SiteWriter::class)->write('acme', [
            'rootPageId' => 1,
            'base' => self::BASE . '/',
            'websiteTitle' => 'ACME',
            'dependencies' => ['koh/poppy'],
            'settings' => $settings,
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ],
        ]);
        $this->service(SiteFinder::class)->getAllSites(false);
    }

    /** @return array<string, mixed> */
    private function startSession(string $userId): array
    {
        $now = $this->now();
        $response = $this->post('/poppy/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->agent->sessionAssertion(self::ISSUER, $userId, $now),
        ] + $this->agent->clientAuthentication(self::ISSUER, $now), $this->agent->dpopProof('POST', self::ISSUER . '/token', $now));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function fetch(string $path): ResponseInterface
    {
        return $this->executeFrontendSubRequest(new InternalRequest(self::BASE . $path));
    }

    /** @param array<string, string> $form */
    private function post(string $path, array $form, ?string $dpop = null): ResponseInterface
    {
        $request = (new InternalRequest(self::BASE . $path))
            ->withMethod('POST')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->stream(http_build_query($form, '', '&', PHP_QUERY_RFC3986)));

        return $this->executeFrontendSubRequest($dpop !== null ? $request->withHeader('DPoP', $dpop) : $request);
    }

    private function stream(string $content): Stream
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($content);
        $stream->rewind();

        return $stream;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, (string) $response->getBody());

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function now(): \DateTimeImmutable
    {
        return TestDoubles::clock()->now();
    }
}
