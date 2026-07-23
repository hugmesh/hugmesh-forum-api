<?php
/**
 * HugMesh Forum API — base controller (shared auth + JSON helpers).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\controller;

use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\auth_exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared base for every JSON-API controller: authenticates the request, decodes the
 * JSON body, and emits {@see JsonResponse} (including fail-closed error envelopes for
 * {@see auth_exception}).
 */
abstract class base_controller
{
    /**
     * @var authenticator
     */
    protected $authenticator;

    /**
     * @var Request|null The phpBB-provided Symfony request (@symfony_request). phpBB
     *                   DEACTIVATES the PHP superglobals ($_GET/$_POST/... become
     *                   phpbb\request\deactivated_super_global objects), so
     *                   Request::createFromGlobals() throws a TypeError at runtime; we must
     *                   use phpBB's own request object instead.
     */
    protected $request;

    /**
     * @param authenticator $authenticator
     * @param Request|null  $request The injected phpBB @symfony_request (real GET/POST/
     *                               SERVER/headers). Optional only so the unit tests can
     *                               construct controllers without the phpBB container.
     */
    public function __construct(authenticator $authenticator, Request $request = null)
    {
        $this->authenticator = $authenticator;
        $this->request = $request;
    }

    /**
     * Authenticate the current request, returning the verified principal.
     *
     * @return \hugmesh\forumapi\auth\principal
     *
     * @throws auth_exception 401 on failure.
     */
    protected function principal()
    {
        return $this->authenticator->authenticate($this->current_request());
    }

    /**
     * The current request. Prefer the injected phpBB @symfony_request: phpBB deactivates
     * the PHP superglobals, so {@see Request::createFromGlobals()} throws
     * "Argument #1 ($query) must be of type array, phpbb\request\deactivated_super_global
     * given". createFromGlobals() is only ever a last-resort fallback for a non-phpBB
     * (e.g. unit-test) context where the superglobals are intact.
     *
     * @return Request
     */
    protected function current_request()
    {
        if ($this->request !== null)
        {
            return $this->request;
        }
        return Request::createFromGlobals();
    }

    /**
     * Decode the JSON request body to an associative array (empty array if none).
     *
     * @return array
     *
     * @throws auth_exception 400 on malformed JSON.
     */
    protected function json_body()
    {
        $raw = $this->current_request()->getContent();
        if ($raw === '' || $raw === null)
        {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded))
        {
            throw auth_exception::bad_request('Request body is not a JSON object.');
        }
        return $decoded;
    }

    /**
     * @param mixed $data
     * @param int   $status
     * @return JsonResponse
     */
    protected function json($data, $status = 200)
    {
        return new JsonResponse($data, $status);
    }

    /**
     * A 204 No Content response (writes/moderation/declarative ops).
     *
     * Returns a bare {@see Response} with an EMPTY body — NOT a JsonResponse, which would
     * serialize null to the 2-byte string "{}" and ship a body on a 204 (an RFC 9110
     * §15.3.5 violation: a 204 response must not carry content).
     *
     * @return Response
     */
    protected function no_content()
    {
        return new Response('', 204);
    }

    /**
     * Wrap a controller action so any {@see auth_exception} (or unexpected error) becomes
     * a proper JSON error response with the right status (fail-closed).
     *
     * @param callable $action
     * @return JsonResponse
     */
    protected function guard(callable $action)
    {
        try
        {
            return $action();
        }
        catch (auth_exception $e)
        {
            return $this->error($e->get_status_code(), $e->getMessage());
        }
        catch (\Throwable $e)
        {
            // Never leak internals; log-worthy server error.
            return $this->error(500, 'Internal error.');
        }
    }

    /**
     * @param int    $status
     * @param string $message
     * @return JsonResponse
     */
    protected function error($status, $message)
    {
        return new JsonResponse([
            'error'  => true,
            'status' => (int) $status,
            'message' => (string) $message,
        ], (int) $status);
    }

    /**
     * Require an integer route/query value.
     *
     * @param mixed $value
     * @param string $name
     * @return int
     *
     * @throws auth_exception 400 if not a positive integer.
     */
    protected function require_int($value, $name)
    {
        if ($value === null || $value === '' || !ctype_digit((string) $value))
        {
            throw auth_exception::bad_request($name . ' must be a positive integer.');
        }
        return (int) $value;
    }

    /**
     * Require a non-empty string field from a decoded body.
     *
     * @param array  $body
     * @param string $field
     * @return string
     *
     * @throws auth_exception 400 if missing/blank.
     */
    protected function require_string(array $body, $field)
    {
        if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '')
        {
            throw auth_exception::bad_request($field . ' is required.');
        }
        return (string) $body[$field];
    }
}
