<?php
/**
 * Unit tests for the Casdoor JWT verifier + server-side authority re-derivation.
 *
 * Runs WITHOUT a live phpBB. Asserts: valid token accepted, bad signature rejected,
 * expired rejected, wrong-issuer rejected, wrong-audience rejected, authority
 * re-derived from claims (roles/permissions/isAdmin), reserved-prefix injection
 * dropped, and that a body-supplied authorSubject is IGNORED (the principal subject
 * always comes from the token sub).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\tests;

use PHPUnit\Framework\TestCase;
use snapdragon\forumapi\auth\auth_exception;
use snapdragon\forumapi\auth\authenticator;
use snapdragon\forumapi\auth\jwks_provider;
use snapdragon\forumapi\auth\jwt_verifier;
use snapdragon\forumapi\auth\principal;
use Symfony\Component\HttpFoundation\Request;

class jwt_verifier_test extends TestCase
{
    /** @var rsa_test_keys */
    private $keys;

    /** @var string */
    private $issuer = 'https://casdoor.example.test';

    protected function setUp(): void
    {
        $this->keys = new rsa_test_keys('kid-A');
    }

    /**
     * Build a verifier whose JWKS provider is fed a fixed in-memory JWKS document via an
     * injected fetcher (no network). $jwks_doc lets a test simulate a kid mismatch.
     */
    private function verifier(array $jwks_doc = null, $issuer = null, $audience = '', $leeway = 30)
    {
        $doc = $jwks_doc ?? $this->keys->jwks();
        $fetcher = function () use ($doc) {
            return json_encode($doc);
        };
        $provider = new jwks_provider('https://issuer.test/jwks', $fetcher, null);
        return new jwt_verifier($provider, $issuer ?? $this->issuer, $audience, $leeway);
    }

    private function base_claims(array $overrides = [])
    {
        return array_merge([
            'sub' => 'casdoor-sub-123',
            'iss' => $this->issuer,
            'iat' => time() - 10,
            'exp' => time() + 600,
            'name' => 'Alice Example',
            'email' => 'alice@example.test',
        ], $overrides);
    }

    // ── happy path ──────────────────────────────────────────────────

    public function test_valid_token_accepted_and_subject_from_token(): void
    {
        $token = $this->keys->sign($this->base_claims(['roles' => ['user']]));
        $p = $this->verifier()->verify($token);

        $this->assertInstanceOf(principal::class, $p);
        $this->assertSame('casdoor-sub-123', $p->get_subject());
        $this->assertSame('Alice Example', $p->get_display_name());
        $this->assertSame('alice@example.test', $p->get_email());
        $this->assertSame($this->issuer, $p->get_issuer());
        $this->assertTrue($p->has_authority(principal::ROLE_USER));
    }

    // ── signature ───────────────────────────────────────────────────

    public function test_bad_signature_rejected(): void
    {
        // Sign with a DIFFERENT key than the JWKS advertises.
        $other = new rsa_test_keys('kid-A'); // same kid, different key material
        $token = $other->sign($this->base_claims());

        $this->expectException(auth_exception::class);
        try
        {
            $this->verifier()->verify($token);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            throw $e;
        }
    }

    // ── expiry ──────────────────────────────────────────────────────

    public function test_expired_token_rejected(): void
    {
        $token = $this->keys->sign($this->base_claims([
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]));

        $this->expectException(auth_exception::class);
        try
        {
            $this->verifier()->verify($token);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            throw $e;
        }
    }

    // ── issuer ──────────────────────────────────────────────────────

    public function test_wrong_issuer_rejected(): void
    {
        $token = $this->keys->sign($this->base_claims(['iss' => 'https://evil.test']));

        $this->expectException(auth_exception::class);
        try
        {
            $this->verifier()->verify($token);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            $this->assertStringContainsStringIgnoringCase('issuer', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Issuer is MANDATORY: an empty/unset configured issuer must fail closed for EVERY
     * token (401) BEFORE the token is even decoded, rather than silently skipping the iss
     * check. The token here is otherwise perfectly valid (correct signature/issuer/expiry);
     * only the verifier's configured issuer is empty. See jwt_verifier.php:106-109.
     */
    public function test_empty_configured_issuer_fails_closed(): void
    {
        // A token that WOULD verify against a properly-configured issuer.
        $token = $this->keys->sign($this->base_claims());
        // Verifier with an EMPTY configured issuer (misconfiguration, not a wildcard).
        $verifier = $this->verifier(null, '');

        $this->expectException(auth_exception::class);
        try
        {
            $verifier->verify($token);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            $this->assertStringContainsStringIgnoringCase('issuer', $e->getMessage());
            throw $e;
        }
    }

    // ── audience ────────────────────────────────────────────────────

    public function test_wrong_audience_rejected_when_configured(): void
    {
        $verifier = $this->verifier(null, null, 'expected-aud');
        $token = $this->keys->sign($this->base_claims(['aud' => 'some-other-aud']));

        $this->expectException(auth_exception::class);
        $verifier->verify($token);
    }

    public function test_matching_audience_accepted_when_configured(): void
    {
        $verifier = $this->verifier(null, null, 'expected-aud');
        $token = $this->keys->sign($this->base_claims(['aud' => ['x', 'expected-aud']]));
        $p = $verifier->verify($token);
        $this->assertSame('casdoor-sub-123', $p->get_subject());
    }

    // ── authority re-derivation ─────────────────────────────────────

    public function test_roles_claim_maps_to_role_authorities(): void
    {
        // Casdoor default shape: roles as objects with a name field.
        $token = $this->keys->sign($this->base_claims([
            'roles' => [['name' => 'admin'], 'mod'],
        ]));
        $p = $this->verifier()->verify($token);

        $this->assertTrue($p->has_authority('ROLE_ADMIN'));
        $this->assertTrue($p->has_authority('ROLE_MOD'));
        $this->assertTrue($p->is_admin());
        $this->assertTrue($p->is_moderator());
    }

    public function test_isadmin_boolean_grants_role_admin(): void
    {
        $token = $this->keys->sign($this->base_claims(['isAdmin' => true]));
        $p = $this->verifier()->verify($token);
        $this->assertTrue($p->is_admin());
    }

    public function test_permissions_verbatim_but_reserved_prefixes_dropped(): void
    {
        $token = $this->keys->sign($this->base_claims([
            'permissions' => ['forum.read', 'ROLE_SUPERADMIN', 'DOMAIN_other', 'games.play'],
        ]));
        $p = $this->verifier()->verify($token);

        $this->assertTrue($p->has_authority('forum.read'));
        $this->assertTrue($p->has_authority('games.play'));
        // Authority-injection defense: reserved-prefixed permission strings are dropped.
        $this->assertFalse($p->has_authority('ROLE_SUPERADMIN'));
        $this->assertFalse($p->has_authority('DOMAIN_other'));
    }

    public function test_floor_is_role_user(): void
    {
        $token = $this->keys->sign($this->base_claims()); // no roles/permissions
        $p = $this->verifier()->verify($token);
        $this->assertTrue($p->has_authority(principal::ROLE_USER));
        $this->assertFalse($p->is_admin());
    }

    // ── body-supplied authorSubject is NEVER trusted ────────────────

    public function test_body_supplied_authorsubject_is_ignored(): void
    {
        // A token whose claims try to smuggle an authorSubject / different sub source.
        $token = $this->keys->sign($this->base_claims([
            'sub' => 'real-owner-sub',
            'authorSubject' => 'victim-sub',     // attacker-controlled extra claim
            'roles' => ['user'],
        ]));
        $p = $this->verifier()->verify($token);

        // The principal subject is ALWAYS the token sub, never any authorSubject field.
        $this->assertSame('real-owner-sub', $p->get_subject());
        $this->assertNotSame('victim-sub', $p->get_subject());
    }

    // ── authenticator: dual-header handling ─────────────────────────

    public function test_authenticator_accepts_bearer_header(): void
    {
        $token = $this->keys->sign($this->base_claims());
        $auth = new authenticator($this->verifier());

        $request = Request::create('/api/v1/snapdragon/categories', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $p = $auth->authenticate($request);
        $this->assertSame('casdoor-sub-123', $p->get_subject());
    }

    public function test_authenticator_accepts_x_snapdragon_jwt_header(): void
    {
        $token = $this->keys->sign($this->base_claims());
        $auth = new authenticator($this->verifier());

        $request = Request::create('/api/v1/snapdragon/categories', 'GET');
        $request->headers->set('X-Snapdragon-JWT', $token);

        $p = $auth->authenticate($request);
        $this->assertSame('casdoor-sub-123', $p->get_subject());
    }

    public function test_authenticator_rejects_disagreeing_headers(): void
    {
        $token_a = $this->keys->sign($this->base_claims(['sub' => 'a']));
        $token_b = $this->keys->sign($this->base_claims(['sub' => 'b']));
        $auth = new authenticator($this->verifier());

        $request = Request::create('/api/v1/snapdragon/categories', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token_a);
        $request->headers->set('X-Snapdragon-JWT', $token_b);

        $this->expectException(auth_exception::class);
        $auth->authenticate($request);
    }

    public function test_authenticator_rejects_missing_token(): void
    {
        $auth = new authenticator($this->verifier());
        $request = Request::create('/api/v1/snapdragon/categories', 'GET');

        $this->expectException(auth_exception::class);
        try
        {
            $auth->authenticate($request);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            throw $e;
        }
    }

    // ── authorization helpers (role gates) ──────────────────────────

    public function test_require_admin_forbidden_for_plain_user(): void
    {
        $token = $this->keys->sign($this->base_claims(['roles' => ['user']]));
        $auth = new authenticator($this->verifier());
        $p = $this->verifier()->verify($token);

        $this->expectException(auth_exception::class);
        try
        {
            $auth->require_admin($p);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(403, $e->get_status_code());
            throw $e;
        }
    }

    public function test_require_owner_or_moderator_allows_owner(): void
    {
        $token = $this->keys->sign($this->base_claims(['sub' => 'owner-1', 'roles' => ['user']]));
        $auth = new authenticator($this->verifier());
        $p = $this->verifier()->verify($token);

        // Owner of the post — allowed.
        $auth->require_owner_or_moderator($p, 'owner-1');
        $this->assertTrue(true);
    }

    public function test_require_owner_or_moderator_forbids_non_owner_user(): void
    {
        $token = $this->keys->sign($this->base_claims(['sub' => 'user-1', 'roles' => ['user']]));
        $auth = new authenticator($this->verifier());
        $p = $this->verifier()->verify($token);

        $this->expectException(auth_exception::class);
        try
        {
            $auth->require_owner_or_moderator($p, 'someone-else');
        }
        catch (auth_exception $e)
        {
            $this->assertSame(403, $e->get_status_code());
            throw $e;
        }
    }

    public function test_moderator_may_edit_others_posts(): void
    {
        $token = $this->keys->sign($this->base_claims(['sub' => 'mod-1', 'roles' => ['mod']]));
        $auth = new authenticator($this->verifier());
        $p = $this->verifier()->verify($token);

        // A moderator is not the owner but is allowed.
        $auth->require_owner_or_moderator($p, 'someone-else');
        $this->assertTrue(true);
    }

    // ── kid rotation ────────────────────────────────────────────────

    public function test_unknown_kid_then_refresh_still_fails_closed(): void
    {
        // JWKS advertises kid-A but the token is signed with header kid-B and a key the
        // JWKS does not contain -> must fail closed (401), not accept.
        $token = $this->keys->sign($this->base_claims(), 'kid-B');
        $jwks = $this->keys->jwks('kid-A'); // only kid-A present

        $this->expectException(auth_exception::class);
        $this->verifier($jwks)->verify($token);
    }

    // ── algorithm-confusion / "none" attacks (the headline guarantee) ───────

    /**
     * The classic RS256->HS256 algorithm-confusion attack: forge a token with
     * alg=HS256 and compute the HMAC using the JWKS RSA PUBLIC key bytes as the shared
     * secret. A naive verifier that trusts the token's alg header would verify the HMAC
     * against the (public!) RSA key and ACCEPT the forgery. Our verifier pins the JWKS
     * key's algorithm to RS256, so firebase/php-jwt rejects the alg mismatch -> 401.
     */
    public function test_alg_confusion_hs256_signed_with_rsa_public_key_rejected(): void
    {
        $claims = $this->base_claims(['roles' => ['admin'], 'isAdmin' => true]);
        // HMAC secret = the verbatim RSA public-key PEM (what an attacker can read from JWKS).
        $forged = $this->forge_hs256($claims, $this->keys->public_pem, $this->keys->kid);

        $this->expectException(auth_exception::class);
        try
        {
            $this->verifier()->verify($forged);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            throw $e;
        }
    }

    /**
     * An alg=none token (empty signature) must NEVER be accepted — fail closed 401.
     */
    public function test_alg_none_token_rejected(): void
    {
        $forged = $this->forge_none($this->base_claims(['isAdmin' => true]), $this->keys->kid);

        $this->expectException(auth_exception::class);
        try
        {
            $this->verifier()->verify($forged);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            throw $e;
        }
    }

    /**
     * The token header advertises a different algorithm (HS256) than the JWKS key's alg
     * (RS256). Even with a kid that resolves, the per-key alg check must reject it -> 401.
     */
    public function test_header_alg_differs_from_jwks_key_alg_rejected(): void
    {
        // A syntactically valid HS256 token (arbitrary secret) whose kid resolves to the
        // RS256 JWKS key. The alg mismatch alone must fail closed.
        $forged = $this->forge_hs256($this->base_claims(), 'not-the-rsa-public-key-secret', $this->keys->kid);

        $this->expectException(auth_exception::class);
        try
        {
            $this->verifier()->verify($forged);
        }
        catch (auth_exception $e)
        {
            $this->assertSame(401, $e->get_status_code());
            throw $e;
        }
    }

    // ── forging helpers (hand-built JWSs the firebase encoder would refuse to make) ──

    /**
     * Build an alg=HS256 compact JWS over $claims using $secret as the HMAC key, with the
     * given kid header. Used to mount the RS256->HS256 confusion attack.
     */
    private function forge_hs256(array $claims, string $secret, string $kid): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => $kid];
        $signing_input = $this->b64url(json_encode($header)) . '.' . $this->b64url(json_encode($claims));
        $sig = hash_hmac('sha256', $signing_input, $secret, true);
        return $signing_input . '.' . $this->b64url($sig);
    }

    /**
     * Build an alg=none token with an EMPTY signature segment.
     */
    private function forge_none(array $claims, string $kid): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'none', 'kid' => $kid];
        return $this->b64url(json_encode($header)) . '.' . $this->b64url(json_encode($claims)) . '.';
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
