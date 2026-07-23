<?php
/**
 * Unit tests for base_controller::guard() — the fail-closed error envelope.
 *
 * The guard() wrapper is the ONLY place a controller turns an auth_exception (or an
 * unexpected Throwable) into an HTTP response, yet no controller path was ever exercised
 * through it. These tests drive guard() directly with thrown auth_exceptions and assert:
 *   - the JSON envelope shape {error:true, status:int, message:string};
 *   - the correct HTTP status for each auth_exception factory (401/403/404/400);
 *   - that get_status_code() (not the non-existent getStatusCode()) is read — a wrong
 *     method name would surface here as a 500 instead of the intended status;
 *   - that a happy action is returned through guard() unchanged;
 *   - that an unexpected Throwable becomes a generic 500 without leaking internals.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;
use hugmesh\forumapi\auth\auth_exception;
use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\jwks_provider;
use hugmesh\forumapi\auth\jwt_verifier;
use hugmesh\forumapi\controller\base_controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A concrete base_controller that re-exposes the protected helpers so guard() can be
 * driven without a live HTTP request / phpBB container.
 */
class guard_probe_controller extends base_controller
{
    public function run_guard(callable $action)
    {
        return $this->guard($action);
    }

    public function ok_json($data, $status = 200)
    {
        return $this->json($data, $status);
    }

    public function ok_no_content()
    {
        return $this->no_content();
    }
}

class base_controller_guard_test extends TestCase
{
    private function controller(): guard_probe_controller
    {
        // The guard tests never authenticate, so a verifier with a dummy (never-called)
        // fetcher is sufficient to satisfy the constructor wiring.
        $provider = new jwks_provider('https://issuer.test/jwks', function () {
            return '{"keys":[]}';
        }, null);
        $verifier = new jwt_verifier($provider, 'https://issuer.test', '', 30);
        return new guard_probe_controller(new authenticator($verifier));
    }

    private function decode(JsonResponse $r): array
    {
        return json_decode($r->getContent(), true);
    }

    public function test_guard_unauthorized_yields_401_envelope(): void
    {
        $r = $this->controller()->run_guard(function () {
            throw auth_exception::unauthorized('No token.');
        });

        $this->assertInstanceOf(JsonResponse::class, $r);
        $this->assertSame(401, $r->getStatusCode());
        $body = $this->decode($r);
        $this->assertSame(true, $body['error']);
        $this->assertSame(401, $body['status']);
        $this->assertSame('No token.', $body['message']);
    }

    public function test_guard_forbidden_yields_403_envelope(): void
    {
        $r = $this->controller()->run_guard(function () {
            throw auth_exception::forbidden('Admin role required.');
        });

        $this->assertSame(403, $r->getStatusCode());
        $body = $this->decode($r);
        $this->assertSame(true, $body['error']);
        $this->assertSame(403, $body['status']);
        $this->assertSame('Admin role required.', $body['message']);
    }

    public function test_guard_not_found_yields_404_envelope(): void
    {
        $r = $this->controller()->run_guard(function () {
            throw auth_exception::not_found('Topic not found.');
        });

        $this->assertSame(404, $r->getStatusCode());
        $this->assertSame(404, $this->decode($r)['status']);
    }

    public function test_guard_bad_request_yields_400_envelope(): void
    {
        $r = $this->controller()->run_guard(function () {
            throw auth_exception::bad_request('title is required.');
        });

        $this->assertSame(400, $r->getStatusCode());
        $this->assertSame(400, $this->decode($r)['status']);
    }

    public function test_guard_unexpected_throwable_becomes_generic_500(): void
    {
        $r = $this->controller()->run_guard(function () {
            throw new \LogicException('secret internal detail that must not leak');
        });

        $this->assertSame(500, $r->getStatusCode());
        $body = $this->decode($r);
        $this->assertSame(500, $body['status']);
        $this->assertSame('Internal error.', $body['message']);
        $this->assertStringNotContainsString('secret internal detail', $r->getContent());
    }

    public function test_guard_returns_happy_response_unchanged(): void
    {
        $r = $this->controller()->run_guard(function () {
            return new JsonResponse(['ok' => true], 201);
        });

        $this->assertSame(201, $r->getStatusCode());
        $this->assertSame(['ok' => true], $this->decode($r));
    }

    public function test_no_content_is_empty_204_body(): void
    {
        $r = $this->controller()->ok_no_content();

        $this->assertInstanceOf(Response::class, $r);
        $this->assertSame(204, $r->getStatusCode());
        // RFC 9110 §15.3.5: a 204 must not carry a body. JsonResponse(null) would emit "{}".
        $this->assertSame('', $r->getContent());
    }
}
