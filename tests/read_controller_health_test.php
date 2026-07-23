<?php
/**
 * Unit tests for read_controller::health() — the UNAUTHENTICATED probe route.
 *
 * The deployment's readiness/liveness probes hit /api/v1/hugmesh/health with NO
 * Authorization header. health() must therefore answer 200 WITHOUT authenticating. These
 * tests assert:
 *   - a static 200 {status:"ok", service:"hugmesh-forumapi"} body;
 *   - that authentication is NEVER invoked — the controller is built with a verifier whose
 *     JWKS fetcher throws if called, so any attempt to authenticate would fail the test;
 *   - that no forum_repository / DB access happens (the repo is constructed without a real
 *     phpBB $db; a query would fatal).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;
use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\jwks_provider;
use hugmesh\forumapi\auth\jwt_verifier;
use hugmesh\forumapi\controller\read_controller;
use hugmesh\forumapi\service\forum_repository;
use Symfony\Component\HttpFoundation\JsonResponse;

class read_controller_health_test extends TestCase
{
    private function controller(): read_controller
    {
        // A JWKS fetcher that EXPLODES if invoked — so if health() ever tried to
        // authenticate, the test would error out instead of passing.
        $provider = new jwks_provider('https://issuer.test/jwks', function () {
            throw new \RuntimeException('health() must not authenticate — JWKS fetched.');
        }, null);
        $verifier = new jwt_verifier($provider, 'https://issuer.test', '', 30);
        // forum_repository built with NO real phpBB $db — health() must not query it.
        $repo = new forum_repository(null, 'phpbb_');
        return new read_controller(new authenticator($verifier), $repo);
    }

    public function test_health_returns_200_without_auth(): void
    {
        $r = $this->controller()->health();

        $this->assertInstanceOf(JsonResponse::class, $r);
        $this->assertSame(200, $r->getStatusCode());
    }

    public function test_health_body_is_static_ok(): void
    {
        $body = json_decode($this->controller()->health()->getContent(), true);

        $this->assertSame('ok', $body['status']);
        $this->assertSame('hugmesh-forumapi', $body['service']);
        // Nothing that could leak forum/DB state.
        $this->assertSame(['status', 'service'], array_keys($body));
    }
}
