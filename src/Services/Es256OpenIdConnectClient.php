<?php

namespace BBS\Services;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use stdClass;

/**
 * OIDC client with ES256 (ECDSA P-256) ID-token verification.
 *
 * jumbojett/openid-connect-php v1.0.2 only verifies RSA (RS256/PS256/…)
 * and HMAC (HS256/…) signatures; providers that sign ES256-only (e.g.
 * Kanidm) fail with "No support for signature type: ES256". This subclass
 * adds exactly that case and delegates everything else to the parent, so a
 * vendor bump that gains native ES256 keeps working unchanged (the
 * override only fires for alg === 'ES256').
 *
 * Security notes (mirror the behaviour of the lib's RSA path):
 * - Embedded JWKs are rejected: the key must come from the provider's
 *   jwks_uri (alg-confusion via attacker-supplied jwk header is refused).
 * - Only P-256 keys with a matching kid are accepted.
 * - JWS carries raw R || S (64 bytes); OpenSSL verifies DER, converted
 *   without branching on secret content beyond fixed sizes.
 */
class Es256OpenIdConnectClient extends OpenIDConnectClient
{
    public function verifyJWTSignature(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (!isset($parts[0])) {
            throw new OpenIDConnectClientException('Error missing part 0 in token');
        }
        $header = $this->decodeJWT($jwt);
        if (!is_object($header)) {
            throw new OpenIDConnectClientException('Error decoding JSON from token header');
        }
        if (!isset($header->alg)) {
            throw new OpenIDConnectClientException('Error missing signature type in token header');
        }
        if ($header->alg !== 'ES256') {
            return parent::verifyJWTSignature($jwt);
        }

        if (isset($header->jwk)) {
            throw new OpenIDConnectClientException('Embedded JWK not supported for ES256');
        }
        $jwksUri = $this->getProviderConfigValue('jwks_uri');
        if (!$jwksUri) {
            throw new OpenIDConnectClientException('Unable to verify signature due to no jwks_uri being defined');
        }

        $jwks = json_decode($this->fetchURL($jwksUri), false);
        if ($jwks === null || !isset($jwks->keys) || !is_array($jwks->keys)) {
            throw new OpenIDConnectClientException('Error decoding JSON from jwks_uri');
        }
        $jwk = $this->getEcKeyForHeader($jwks->keys, $header);

        $signature = $this->decodeSignature($parts);
        $payload = implode('.', array_slice($parts, 0, 2));
        return $this->verifyECJWTSignature($jwk, $payload, $signature);
    }

    private function decodeSignature(array $parts): string
    {
        if (count($parts) < 3) {
            throw new OpenIDConnectClientException('Error missing signature in token');
        }
        $b64 = $parts[2];
        $padding = strlen($b64) % 4;
        if ($padding > 0) {
            $b64 .= str_repeat('=', 4 - $padding);
        }
        $signature = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($signature === false || $signature === '') {
            throw new OpenIDConnectClientException('Error decoding signature from token');
        }
        return $signature;
    }

    /**
     * Select the P-256 EC key for a header, mirroring the lib's
     * getKeyForHeader matching (alg + kid equality when a kid is set).
     */
    private function getEcKeyForHeader(array $keys, stdClass $header): stdClass
    {
        foreach ($keys as $key) {
            if (!is_object($key) || ($key->kty ?? null) !== 'EC') {
                continue;
            }
            if (($key->crv ?? null) !== 'P-256') {
                continue;
            }
            if (isset($header->kid)) {
                if (($key->kid ?? null) === $header->kid && ($key->alg ?? 'ES256') === 'ES256') {
                    return $key;
                }
                continue;
            }
            if (!isset($key->kid) && ($key->alg ?? 'ES256') === 'ES256') {
                return $key;
            }
        }
        if (isset($header->kid)) {
            throw new OpenIDConnectClientException('Unable to find a P-256 key for kid: ' . $header->kid);
        }
        throw new OpenIDConnectClientException('Unable to find a P-256 key');
    }

    private function verifyECJWTSignature(stdClass $key, string $payload, string $signature): bool
    {
        if (!(property_exists($key, 'x') && property_exists($key, 'y'))) {
            throw new OpenIDConnectClientException('Malformed key object');
        }
        // ES256 signatures are exactly 64 bytes (raw R || S).
        if (strlen($signature) !== 64) {
            return false;
        }
        $pubKey = openssl_pkey_get_public(self::ecJwkToPem($key->x, $key->y));
        if ($pubKey === false) {
            throw new OpenIDConnectClientException('Invalid EC key');
        }
        // JWS carries raw R || S, but OpenSSL verifies DER. Convert first.
        $result = openssl_verify($payload, self::rawSigToDer($signature), $pubKey, OPENSSL_ALGO_SHA256);
        if ($result === -1) {
            throw new OpenIDConnectClientException('EC signature verification error');
        }
        return $result === 1;
    }

    private static function rawSigToDer(string $sig): string
    {
        $r = ltrim(substr($sig, 0, 32), "\x00");
        $s = ltrim(substr($sig, 32, 32), "\x00");
        if ($r === '') {
            $r = "\x00";
        }
        if ($s === '') {
            $s = "\x00";
        }
        if ((ord($r[0]) & 0x80) !== 0) {
            $r = "\x00" . $r;
        }
        if ((ord($s[0]) & 0x80) !== 0) {
            $s = "\x00" . $s;
        }
        // Lengths stay under 128 here (33 content bytes max), so short form.
        return "\x30" . chr(strlen($r) + strlen($s) + 4)
            . "\x02" . chr(strlen($r)) . $r
            . "\x02" . chr(strlen($s)) . $s;
    }

    private static function ecJwkToPem(string $x, string $y): string
    {
        $pad = static function (string $s): string {
            $s = strtr($s, '-_', '+/');
            $rem = strlen($s) % 4;
            return $rem === 0 ? $s : $s . str_repeat('=', 4 - $rem);
        };
        $xb = base64_decode($pad($x), true);
        $yb = base64_decode($pad($y), true);
        if ($xb === false || $yb === false || strlen($xb) > 32 || strlen($yb) > 32) {
            throw new OpenIDConnectClientException('Malformed EC key coordinates');
        }
        $xb = str_pad($xb, 32, "\x00", STR_PAD_LEFT);
        $yb = str_pad($yb, 32, "\x00", STR_PAD_LEFT);
        // P-256 SubjectPublicKeyInfo: SEQ{ SEQ{ OID ecPublicKey, OID prime256v1 }, BITSTRING(0x00 || 0x04 || X || Y) }
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04" . $xb . $yb;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
