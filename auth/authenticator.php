<?php
/**
 * HugMesh Forum API — request authenticator (header extraction + verification + authz).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * The single entry-point controllers use to authenticate a request. It extracts the
 * Casdoor JWT from BOTH {@code Authorization: Bearer} and {@code X-HugMesh-JWT}
 * (cross-checking they agree when both are present), verifies it via {@see jwt_verifier},
 * and exposes per-route authorization helpers (fail-closed).
 */
class authenticator
{
    /**
     * The extra header the forum-bff sends alongside Authorization (PhpbbWebClientConfig).
     */
    public const HUGMESH_JWT_HEADER = 'X-HugMesh-JWT';

    /**
     * @var jwt_verifier
     */
    private $verifier;

    /**
     * @param jwt_verifier $verifier
     */
    public function __construct(jwt_verifier $verifier)
    {
        $this->verifier = $verifier;
    }

    /**
     * Authenticate a Symfony request, returning the verified principal.
     *
     * @param Request $request
     * @return principal
     *
     * @throws auth_exception 401 if no/invalid token, or the two headers disagree.
     */
    public function authenticate(Request $request)
    {
        $bearer = $this->extract_bearer($request);
        $custom = trim((string) $request->headers->get(self::HUGMESH_JWT_HEADER, ''));

        // Both present => they MUST be the same token (no header-confusion downgrade).
        if ($bearer !== '' && $custom !== '' && !hash_equals($bearer, $custom))
        {
            throw auth_exception::unauthorized('Bearer and X-HugMesh-JWT tokens disagree.');
        }

        $token = $bearer !== '' ? $bearer : $custom;
        if ($token === '')
        {
            throw auth_exception::unauthorized('No authentication token supplied.');
        }

        return $this->verifier->verify($token);
    }

    /**
     * @param Request $request
     * @return string The bearer token, or '' if absent/malformed.
     */
    private function extract_bearer(Request $request)
    {
        $header = (string) $request->headers->get('Authorization', '');
        if ($header === '')
        {
            return '';
        }
        if (stripos($header, 'bearer ') === 0)
        {
            return trim(substr($header, 7));
        }
        return '';
    }

    /**
     * Require at least an authenticated user (reads).
     *
     * @param principal $p
     * @return void
     *
     * @throws auth_exception
     */
    public function require_user(principal $p)
    {
        if (!$p->has_authority(principal::ROLE_USER))
        {
            throw auth_exception::forbidden('Authenticated user required.');
        }
    }

    /**
     * Require ROLE_MOD or higher (moderation).
     *
     * @param principal $p
     * @return void
     *
     * @throws auth_exception
     */
    public function require_moderator(principal $p)
    {
        if (!$p->is_moderator())
        {
            throw auth_exception::forbidden('Moderator role required.');
        }
    }

    /**
     * Require ROLE_ADMIN (admin endpoints, ensure, group sync).
     *
     * @param principal $p
     * @return void
     *
     * @throws auth_exception
     */
    public function require_admin(principal $p)
    {
        if (!$p->is_admin())
        {
            throw auth_exception::forbidden('Admin role required.');
        }
    }

    /**
     * Ownership check for edit/delete: the caller must own the post (its authorSubject
     * equals the caller's sub) UNLESS the caller is a moderator or admin.
     *
     * @param principal $p
     * @param string    $owner_subject The post's Casdoor sub (from the DB mapping).
     * @return void
     *
     * @throws auth_exception 403 if not owner and not mod/admin.
     */
    public function require_owner_or_moderator(principal $p, $owner_subject)
    {
        if ($p->is_moderator())
        {
            return;
        }
        if ((string) $owner_subject !== '' && hash_equals((string) $owner_subject, $p->get_subject()))
        {
            return;
        }
        throw auth_exception::forbidden('You may only modify your own posts.');
    }
}
