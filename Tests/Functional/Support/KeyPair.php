<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Tests\Functional\Support;

use Koh\Poppy\Jose\Base64Url;

/**
 * A real key pair and a JWS signer, so the tests verify signatures openssl and
 * sodium actually produced rather than ones this package made up.
 */
final class KeyPair
{
    /** @param array<string, string> $publicJwk */
    private function __construct(
        public readonly string $alg,
        public readonly array $publicJwk,
        private readonly \OpenSSLAsymmetricKey|string $private,
    ) {}

    public static function ec(string $curve = 'P-256', ?string $kid = null): self
    {
        $name = $curve === 'P-256' ? 'prime256v1' : 'secp384r1';
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $name]);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $details = openssl_pkey_get_details($key);
        \assert(is_array($details));
        $length = $curve === 'P-256' ? 32 : 48;
        \assert(is_array($details['ec']) && is_string($details['ec']['x']) && is_string($details['ec']['y']));
        $jwk = [
            'kty' => 'EC',
            'crv' => $curve,
            'x' => Base64Url::encode(str_pad($details['ec']['x'], $length, "\x00", STR_PAD_LEFT)),
            'y' => Base64Url::encode(str_pad($details['ec']['y'], $length, "\x00", STR_PAD_LEFT)),
        ];
        if ($kid !== null) {
            $jwk['kid'] = $kid;
        }

        return new self($curve === 'P-256' ? 'ES256' : 'ES384', $jwk, $key);
    }

    public static function rsa(int $bits = 2048): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $details = openssl_pkey_get_details($key);
        \assert(is_array($details) && is_array($details['rsa']) && is_string($details['rsa']['n']) && is_string($details['rsa']['e']));

        return new self('RS256', ['kty' => 'RSA', 'n' => Base64Url::encode($details['rsa']['n']), 'e' => Base64Url::encode($details['rsa']['e'])], $key);
    }

    public static function ed25519(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self('EdDSA', ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => Base64Url::encode(sodium_crypto_sign_publickey($pair))], sodium_crypto_sign_secretkey($pair));
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header extra header members
     */
    public function sign(array $claims, array $header = []): string
    {
        $header = ['alg' => $this->alg] + $header;
        $input = Base64Url::encode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            . '.' . Base64Url::encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $input . '.' . Base64Url::encode($this->signature($input));
    }

    private function signature(string $input): string
    {
        if (is_string($this->private)) {
            \assert($this->private !== '');

            return sodium_crypto_sign_detached($input, $this->private);
        }

        $digest = $this->alg === 'ES384' ? OPENSSL_ALGO_SHA384 : OPENSSL_ALGO_SHA256;
        openssl_sign($input, $der, $this->private, $digest);
        \assert(is_string($der));

        return $this->alg === 'RS256' ? $der : self::derToRaw($der, $this->alg === 'ES256' ? 32 : 48);
    }

    private static function derToRaw(string $der, int $length): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }

        $raw = '';
        for ($i = 0; $i < 2; ++$i) {
            $intLength = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $intLength), "\x00");
            $raw .= str_pad($int, $length, "\x00", STR_PAD_LEFT);
            $offset += 2 + $intLength;
        }

        return $raw;
    }
}
