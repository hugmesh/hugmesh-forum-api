<?php
/**
 * Snapdragon Forum API — auth/authorization exception carrying an HTTP status.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\auth;

/**
 * A fail-closed authentication/authorization error. Carries the HTTP status code the
 * controller should return: 401 (no/invalid token), 403 (authenticated but wrong role
 * or ownership), 404 (resource missing — used so an unauthorized probe cannot tell a
 * forbidden resource from a missing one when desired).
 */
class auth_exception extends \RuntimeException
{
    /**
     * @var int
     */
    private $status_code;

    /**
     * @param string $message
     * @param int    $status_code
     */
    public function __construct($message, $status_code)
    {
        parent::__construct($message);
        $this->status_code = (int) $status_code;
    }

    /**
     * @return int
     */
    public function get_status_code()
    {
        return $this->status_code;
    }

    /**
     * @param string $message
     * @return self 401 Unauthorized.
     */
    public static function unauthorized($message = 'Unauthorized')
    {
        return new self($message, 401);
    }

    /**
     * @param string $message
     * @return self 403 Forbidden.
     */
    public static function forbidden($message = 'Forbidden')
    {
        return new self($message, 403);
    }

    /**
     * @param string $message
     * @return self 404 Not Found.
     */
    public static function not_found($message = 'Not Found')
    {
        return new self($message, 404);
    }

    /**
     * @param string $message
     * @return self 400 Bad Request.
     */
    public static function bad_request($message = 'Bad Request')
    {
        return new self($message, 400);
    }
}
