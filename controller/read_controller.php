<?php
/**
 * HugMesh Forum API — read endpoints (any authenticated user).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\controller;

use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\auth_exception;
use hugmesh\forumapi\service\forum_repository;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /categories, /topics, /topics/{id}, /topics/{id}/replies, /posts/{id}, /stats.
 * Reads require only an authenticated user (ROLE_USER floor).
 *
 * EXCEPTION: {@see health()} is intentionally UNAUTHENTICATED — it is the Kubernetes
 * readiness/liveness probe target and a probe cannot carry a JWT. It is safe to expose
 * because the phpBB Service is ClusterIP-only with no ingress and a NetworkPolicy that
 * admits only forum-bff / admin-bff / the sync Job, and the response is a static literal
 * that touches neither the DB nor any forum data.
 */
class read_controller extends base_controller
{
    /**
     * @var forum_repository
     */
    private $repo;

    /**
     * @param authenticator    $authenticator
     * @param forum_repository $repo
     * @param Request|null     $request The injected phpBB @symfony_request.
     */
    public function __construct(authenticator $authenticator, forum_repository $repo, Request $request = null)
    {
        parent::__construct($authenticator, $request);
        $this->repo = $repo;
    }

    /**
     * GET /health -> 200 {status:"ok"}. UNAUTHENTICATED liveness/readiness probe.
     *
     * The deployment's readiness/liveness probes hit /api/v1/hugmesh/health, which a
     * kube probe issues with no Authorization header — so this route must NOT authenticate.
     * It proves the PHP/extension/routing stack is alive (a board page would not, since the
     * board is locked down); the DB has its own readiness. The body is a static literal: no
     * DB query, no caller input, nothing that could leak forum state. Still wrapped in
     * guard() so any unexpected error returns the fail-closed JSON envelope rather than a
     * phpBB HTML page.
     */
    public function health()
    {
        return $this->guard(function () {
            return $this->json(['status' => 'ok', 'service' => 'hugmesh-forumapi']);
        });
    }

    /**
     * GET /categories -> Category[].
     */
    public function categories()
    {
        return $this->guard(function () {
            $p = $this->principal();
            $this->authenticator->require_user($p);
            return $this->json($this->repo->list_categories());
        });
    }

    /**
     * GET /topics?categoryId={id} -> Topic[].
     */
    public function topics()
    {
        return $this->guard(function () {
            $p = $this->principal();
            $this->authenticator->require_user($p);
            $category_id = $this->require_int($this->current_request()->query->get('categoryId'), 'categoryId');
            return $this->json($this->repo->list_topics($category_id));
        });
    }

    /**
     * GET /topics/{id} -> Topic.
     *
     * @param int $id
     */
    public function topic($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_user($p);
            $topic = $this->repo->get_topic((int) $id);
            if ($topic === null)
            {
                throw auth_exception::not_found('Topic not found.');
            }
            return $this->json($topic);
        });
    }

    /**
     * GET /topics/{id}/replies -> Reply[].
     *
     * @param int $id
     */
    public function replies($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_user($p);
            if ($this->repo->get_topic((int) $id) === null)
            {
                throw auth_exception::not_found('Topic not found.');
            }
            return $this->json($this->repo->list_replies((int) $id));
        });
    }

    /**
     * GET /posts/{id} -> Reply.
     *
     * @param int $id
     */
    public function post($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_user($p);
            $reply = $this->repo->get_post((int) $id);
            if ($reply === null)
            {
                throw auth_exception::not_found('Post not found.');
            }
            return $this->json($reply);
        });
    }

    /**
     * GET /stats -> Stats.
     */
    public function stats()
    {
        return $this->guard(function () {
            $p = $this->principal();
            $this->authenticator->require_user($p);
            return $this->json($this->repo->stats());
        });
    }
}
