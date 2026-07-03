<?php
/**
 * Snapdragon Forum API — authenticated principal value object.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\auth;

/**
 * An immutable, validated principal derived from a verified Casdoor JWT.
 *
 * The {@see principal::$subject} is the Casdoor {@code sub} claim (the immutable
 * identity used to key the phpBB user mapping). The {@see principal::$authorities} are
 * RE-DERIVED server-side from the JWT claims by {@see jwt_verifier} — they are NEVER
 * read from a request body. The {@code authorSubject} of any post is ALWAYS this
 * {@code subject}, never a body field.
 */
class principal
{
    public const ROLE_USER  = 'ROLE_USER';
    public const ROLE_MOD   = 'ROLE_MOD';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_FORUM = 'ROLE_FORUM';

    /**
     * @var string The Casdoor sub claim (immutable identity).
     */
    private $subject;

    /**
     * @var string The preferred display name (name / preferred_username / sub fallback).
     */
    private $display_name;

    /**
     * @var string The email claim, if present (used to seed user_add).
     */
    private $email;

    /**
     * @var string The validated token issuer (iss).
     */
    private $issuer;

    /**
     * @var array<int, string> Server-re-derived authority strings (e.g. ROLE_ADMIN).
     */
    private $authorities;

    /**
     * @var array The raw decoded claims (read-only convenience; never used for authority).
     */
    private $claims;

    /**
     * @param string             $subject
     * @param string             $display_name
     * @param string             $email
     * @param string             $issuer
     * @param array<int, string> $authorities
     * @param array              $claims
     */
    public function __construct($subject, $display_name, $email, $issuer, array $authorities, array $claims = [])
    {
        $this->subject = (string) $subject;
        $this->display_name = (string) $display_name;
        $this->email = (string) $email;
        $this->issuer = (string) $issuer;
        $this->authorities = array_values(array_unique($authorities));
        $this->claims = $claims;
    }

    /**
     * @return string The Casdoor sub (the post owner / authorSubject for any write).
     */
    public function get_subject()
    {
        return $this->subject;
    }

    /**
     * @return string
     */
    public function get_display_name()
    {
        return $this->display_name !== '' ? $this->display_name : $this->subject;
    }

    /**
     * @return string
     */
    public function get_email()
    {
        return $this->email;
    }

    /**
     * @return string
     */
    public function get_issuer()
    {
        return $this->issuer;
    }

    /**
     * @return array<int, string>
     */
    public function get_authorities()
    {
        return $this->authorities;
    }

    /**
     * @param string $authority
     * @return bool
     */
    public function has_authority($authority)
    {
        return in_array($authority, $this->authorities, true);
    }

    /**
     * @return bool ROLE_ADMIN re-derived from the token.
     */
    public function is_admin()
    {
        return $this->has_authority(self::ROLE_ADMIN);
    }

    /**
     * A moderator is ROLE_MOD (or higher: an admin is also implicitly allowed to moderate).
     *
     * @return bool
     */
    public function is_moderator()
    {
        return $this->is_admin() || $this->has_authority(self::ROLE_MOD);
    }

    /**
     * @return array The raw claims (diagnostic only — never an authority source).
     */
    public function get_claims()
    {
        return $this->claims;
    }
}
