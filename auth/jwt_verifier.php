<?php
/**
 * HugMesh Forum API — Casdoor JWT verifier + server-side authority re-derivation.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\auth;

use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

/**
 * Validates the Casdoor JWT and produces a {@see principal} whose authorities are
 * RE-DERIVED from the token claims server-side.
 *
 * <h3>Validation steps (fail-closed)</h3>
 * <ol>
 *   <li>Extract the token from {@code Authorization: Bearer} and/or {@code X-HugMesh-JWT};
 *       both must, when present, carry the SAME token (no header confusion).</li>
 *   <li>RS256 signature verification against the cached Casdoor JWKS
 *       ({@see jwks_provider}); on a {@code kid} miss the key set is refreshed once
 *       (rotation) before failing.</li>
 *   <li>{@code exp} / {@code nbf} are verified by firebase/php-jwt (throws on expiry).</li>
 *   <li>{@code iss} is verified here against the configured issuer (firebase does NOT).</li>
 *   <li>{@code aud} is verified here IFF an audience is configured (optional).</li>
 * </ol>
 *
 * <h3>Authority re-derivation (mirrors hugmesh CasdoorAuthorities EXACTLY)</h3>
 * Three claim sources, in precedence, with a floor of {@code ROLE_USER}:
 * <ol>
 *   <li>{@code roles} array (strings OR objects with a {@code name} field) ->
 *       {@code ROLE_<UPPER>}.</li>
 *   <li>{@code permissions} array -> verbatim, EXCEPT any entry whose value starts with
 *       a RESERVED prefix ({@code ROLE_} / {@code DOMAIN_}) is DROPPED (authority-injection
 *       defense).</li>
 *   <li>{@code isAdmin} boolean -> {@code ROLE_ADMIN}.</li>
 * </ol>
 * The request body is NEVER an authority source; the post {@code authorSubject} is
 * ALWAYS the validated {@code sub}.
 */
class jwt_verifier
{
    /**
     * Authority prefixes RESERVED for controlled logic — a user-influenced
     * {@code permissions} string carrying one of these is dropped (cannot forge a role
     * or a tenant scope). Mirrors CasdoorAuthorities.RESERVED_PREFIXES.
     */
    private const RESERVED_PREFIXES = ['ROLE_', 'DOMAIN_'];

    /**
     * @var jwks_provider
     */
    private $jwks;

    /**
     * @var string The expected issuer (iss). MANDATORY: an empty configured issuer means
     *             the deployment is misconfigured and every verify() fails closed (401) —
     *             we never fall open and skip the iss check.
     */
    private $expected_issuer;

    /**
     * @var string The expected audience (aud). Empty => audience check skipped.
     */
    private $expected_audience;

    /**
     * @param jwks_provider $jwks              The JWKS key provider.
     * @param string        $expected_issuer   The configured Casdoor issuer (HUGMESH_JWT_ISSUER).
     *                                          MANDATORY: if empty, every verify() fails 401.
     * @param string        $expected_audience Optional configured audience (HUGMESH_JWT_AUDIENCE);
     *                                          validated only when set, ignored when empty.
     * @param int           $leeway            Clock-skew leeway in seconds for exp/nbf/iat.
     */
    public function __construct(jwks_provider $jwks, $expected_issuer = '', $expected_audience = '', $leeway = 30)
    {
        $this->jwks = $jwks;
        $this->expected_issuer = (string) $expected_issuer;
        $this->expected_audience = (string) $expected_audience;
        JWT::$leeway = (int) $leeway;
    }

    /**
     * Verify a raw compact JWT string and return the principal.
     *
     * @param string $token The compact JWS (header.payload.signature).
     * @return principal
     *
     * @throws auth_exception 401 on any signature/expiry/issuer/audience failure.
     */
    public function verify($token)
    {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '')
        {
            throw auth_exception::unauthorized('No bearer token supplied.');
        }

        // Issuer is MANDATORY (README contract). If no expected issuer is configured we
        // fail closed for EVERY token rather than silently skipping the iss check — an
        // unset issuer is a misconfiguration, not a permission to trust any issuer.
        if ($this->expected_issuer === '')
        {
            throw auth_exception::unauthorized('Token issuer cannot be verified: no issuer configured.');
        }

        $payload = $this->decode_with_rotation($token);

        // ── iss check (firebase/php-jwt does NOT verify iss) ──────────────
        $iss = isset($payload->iss) ? (string) $payload->iss : '';
        if (!hash_equals($this->expected_issuer, $iss))
        {
            throw auth_exception::unauthorized('Token issuer mismatch.');
        }

        // ── aud check (optional; firebase does NOT verify aud) ────────────
        if ($this->expected_audience !== '' && !$this->audience_matches($payload))
        {
            throw auth_exception::unauthorized('Token audience mismatch.');
        }

        $claims = $this->object_to_array($payload);

        $sub = isset($claims['sub']) ? (string) $claims['sub'] : '';
        if ($sub === '')
        {
            throw auth_exception::unauthorized('Token has no subject (sub) claim.');
        }

        $display = $this->first_string($claims, ['name', 'preferred_username', 'displayName', 'username']);
        $email = isset($claims['email']) ? (string) $claims['email'] : '';
        $iss = isset($claims['iss']) ? (string) $claims['iss'] : '';

        $authorities = $this->derive_authorities($claims);

        return new principal($sub, $display, $email, $iss, $authorities, $claims);
    }

    /**
     * Verify the token's signature/exp against the JWKS, refreshing the key set ONCE on
     * a kid miss (key rotation) before giving up.
     *
     * @param string $token
     * @return object The decoded payload (stdClass).
     *
     * @throws auth_exception
     */
    private function decode_with_rotation($token)
    {
        try
        {
            return JWT::decode($token, $this->jwks->get_keys(false));
        }
        catch (ExpiredException $e)
        {
            throw auth_exception::unauthorized('Token expired.');
        }
        catch (BeforeValidException $e)
        {
            throw auth_exception::unauthorized('Token not yet valid.');
        }
        catch (SignatureInvalidException $e)
        {
            // Could be a rotated key the cache hasn't seen — refresh once, then retry.
            try
            {
                return JWT::decode($token, $this->jwks->get_keys(true));
            }
            catch (\Exception $inner)
            {
                throw auth_exception::unauthorized('Token signature invalid.');
            }
        }
        catch (\UnexpectedValueException $e)
        {
            // Unknown kid / malformed header. Try a one-shot refresh for rotation.
            try
            {
                return JWT::decode($token, $this->jwks->get_keys(true));
            }
            catch (\Exception $inner)
            {
                throw auth_exception::unauthorized('Token could not be verified.');
            }
        }
        catch (\Exception $e)
        {
            throw auth_exception::unauthorized('Token could not be verified.');
        }
    }

    /**
     * Re-derive the authority list from the token claims. Floor = ROLE_USER.
     *
     * MIRRORS hugmesh CasdoorAuthorities: roles -> ROLE_<UPPER>; permissions verbatim
     * minus RESERVED-prefixed entries; isAdmin -> ROLE_ADMIN. NEVER reads a request body.
     *
     * @param array $claims
     * @return array<int, string>
     */
    public function derive_authorities(array $claims)
    {
        $authorities = [];
        $authorities[] = principal::ROLE_USER; // floor

        // (1) roles -> ROLE_<UPPER>
        if (isset($claims['roles']) && is_array($claims['roles']))
        {
            foreach ($claims['roles'] as $role)
            {
                $name = $this->role_name_of($role);
                if ($name !== '')
                {
                    // Always ROLE_-prefixed, so a roles entry can never inject DOMAIN_.
                    $authorities[] = 'ROLE_' . strtoupper(trim($name));
                }
            }
        }

        // (2) permissions verbatim, minus RESERVED-prefixed (authority-injection defense)
        if (isset($claims['permissions']) && is_array($claims['permissions']))
        {
            foreach ($claims['permissions'] as $perm)
            {
                if ($perm === null)
                {
                    continue;
                }
                $perm = trim((string) $perm);
                if ($perm === '' || $this->is_reserved($perm))
                {
                    continue;
                }
                $authorities[] = $perm;
            }
        }

        // (3) isAdmin boolean -> ROLE_ADMIN
        if (isset($claims['isAdmin']) && $claims['isAdmin'] === true)
        {
            $authorities[] = principal::ROLE_ADMIN;
        }

        return array_values(array_unique($authorities));
    }

    /**
     * @param string $authority
     * @return bool
     */
    private function is_reserved($authority)
    {
        $upper = strtoupper(trim($authority));
        foreach (self::RESERVED_PREFIXES as $prefix)
        {
            if (strpos($upper, $prefix) === 0)
            {
                return true;
            }
        }
        return false;
    }

    /**
     * A role-claim item may be a string OR an object with a {@code name} field (Casdoor's
     * default token shape).
     *
     * @param mixed $item
     * @return string
     */
    private function role_name_of($item)
    {
        if (is_string($item))
        {
            return $item;
        }
        if (is_array($item) && isset($item['name']) && is_string($item['name']) && trim($item['name']) !== '')
        {
            return $item['name'];
        }
        if (is_object($item) && isset($item->name) && is_string($item->name) && trim($item->name) !== '')
        {
            return $item->name;
        }
        return '';
    }

    /**
     * Audience may be a string or an array of strings (RFC 7519). Match if the configured
     * audience appears.
     *
     * @param object $payload
     * @return bool
     */
    private function audience_matches($payload)
    {
        if (!isset($payload->aud))
        {
            return false;
        }
        $aud = $payload->aud;
        if (is_string($aud))
        {
            return hash_equals($this->expected_audience, $aud);
        }
        if (is_array($aud))
        {
            foreach ($aud as $a)
            {
                if (is_string($a) && hash_equals($this->expected_audience, $a))
                {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array        $claims
     * @param array<string> $keys
     * @return string The first non-empty string claim among $keys.
     */
    private function first_string(array $claims, array $keys)
    {
        foreach ($keys as $k)
        {
            if (isset($claims[$k]) && is_string($claims[$k]) && trim($claims[$k]) !== '')
            {
                return $claims[$k];
            }
        }
        return '';
    }

    /**
     * Recursively normalise a decoded stdClass payload to an associative array.
     *
     * @param mixed $value
     * @return mixed
     */
    private function object_to_array($value)
    {
        if (is_object($value))
        {
            $value = (array) $value;
        }
        if (is_array($value))
        {
            $out = [];
            foreach ($value as $k => $v)
            {
                $out[$k] = $this->object_to_array($v);
            }
            return $out;
        }
        return $value;
    }
}
