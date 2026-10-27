<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Functional\Support;

use Koh\Poppy\Client\ClientAuthenticator;
use Koh\Poppy\Jose\Base64Url;

/** A Personal Agent as the specification describes one: published metadata, signing keys, a separate DPoP key. */
final class PersonalAgent
{
    public readonly KeyPair $signingKey;

    public KeyPair $dpopKey;

    public function __construct(
        public readonly string $clientId = 'https://agent.example/agent.json',
        public readonly string $jwksUri = 'https://agent.example/jwks.json',
        public readonly string $redirectUri = 'https://agent.example/oauth/callback',
    ) {
        $this->signingKey = KeyPair::ec(kid: 'sig-1');
        $this->dpopKey = KeyPair::ec();
    }

    /** @param array<string, mixed> $overrides */
    public function publish(FakeHttpClient $http, array $overrides = []): void
    {
        $http->serveJson($this->clientId, $overrides + [
            'client_id' => $this->clientId,
            'client_name' => 'Example Agent',
            'logo_uri' => 'https://agent.example/logo.png',
            'jwks_uri' => $this->jwksUri,
            'redirect_uris' => [$this->redirectUri],
            'token_endpoint_auth_method' => 'private_key_jwt',
        ]);
        $http->serveJson($this->jwksUri, ['keys' => [$this->signingKey->publicJwk + ['use' => 'sig']]]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, string>
     */
    public function clientAuthentication(string $issuer, \DateTimeImmutable $now, array $overrides = []): array
    {
        return [
            'client_id' => $this->clientId,
            'client_assertion_type' => ClientAuthenticator::ASSERTION_TYPE,
            'client_assertion' => $this->signingKey->sign($overrides + [
                'iss' => $this->clientId,
                'sub' => $this->clientId,
                'aud' => $issuer,
                'iat' => $now->getTimestamp(),
                'exp' => $now->getTimestamp() + 60,
                'jti' => self::jti(),
            ]),
        ];
    }

    /** @param array<string, mixed> $overrides */
    public function sessionAssertion(string $issuer, string $userId, \DateTimeImmutable $now, array $overrides = []): string
    {
        return $this->signingKey->sign($overrides + [
            'iss' => $this->clientId,
            'sub' => $userId,
            'aud' => $issuer,
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + 60,
            'jti' => self::jti(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public function dpopProof(string $method, string $htu, \DateTimeImmutable $now, ?string $accessToken = null, array $overrides = [], ?KeyPair $key = null): string
    {
        $claims = $overrides + ['jti' => self::jti(), 'htm' => $method, 'htu' => $htu, 'iat' => $now->getTimestamp()];
        if ($accessToken !== null && !array_key_exists('ath', $overrides)) {
            $claims['ath'] = Base64Url::encode(hash('sha256', $accessToken, true));
        }
        $key ??= $this->dpopKey;

        return $key->sign($claims, ['typ' => 'dpop+jwt', 'jwk' => $key->publicJwk]);
    }

    public function browserAssertion(string $endpoint, string $userId, string $sessionId, string $returnTo, \DateTimeImmutable $now): string
    {
        return $this->signingKey->sign([
            'iss' => $this->clientId,
            'sub' => $userId,
            'aud' => $endpoint,
            'session_id' => $sessionId,
            'return_to' => $returnTo,
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + 60,
            'jti' => self::jti(),
        ], ['typ' => 'poppy-browser+jwt']);
    }

    public static function jti(): string
    {
        return Base64Url::encode(random_bytes(16));
    }
}
