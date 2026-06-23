<?php
/**
 * Test helper — generates an RSA keypair, the matching JWKS document, and signs
 * RS256 JWTs for the verifier unit tests.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi\tests;

use Firebase\JWT\JWT;

/**
 * A throwaway RSA keypair plus helpers to build a JWKS and sign tokens with a known kid.
 */
class rsa_test_keys
{
    /**
     * @var string PEM private key.
     */
    public $private_pem;

    /**
     * @var string PEM public key.
     */
    public $public_pem;

    /**
     * @var array RSA key details (n, e) for the JWKS.
     */
    public $details;

    /**
     * @var string The key id.
     */
    public $kid;

    /**
     * @param string $kid
     */
    public function __construct($kid = 'test-key-1')
    {
        $this->kid = $kid;

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false)
        {
            throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }

        openssl_pkey_export($res, $this->private_pem);
        $detail = openssl_pkey_get_details($res);
        $this->public_pem = $detail['key'];
        $this->details = $detail['rsa'];
    }

    /**
     * Build a JWKS document (array form) containing this key.
     *
     * @param string|null $kid Override the kid in the JWKS entry (for a kid-miss test).
     * @return array
     */
    public function jwks($kid = null)
    {
        $kid = $kid ?? $this->kid;
        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $kid,
                    'n'   => $this->b64url($this->details['n']),
                    'e'   => $this->b64url($this->details['e']),
                ],
            ],
        ];
    }

    /**
     * Sign a JWT with this key (RS256), embedding the kid header.
     *
     * @param array       $claims
     * @param string|null $kid Override the header kid (for a kid-miss test).
     * @return string
     */
    public function sign(array $claims, $kid = null)
    {
        return JWT::encode($claims, $this->private_pem, 'RS256', $kid ?? $this->kid);
    }

    /**
     * @param string $bin
     * @return string base64url (no padding).
     */
    private function b64url($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
