<?php
/**
 * Snapdragon JSON API — read endpoints (any authenticated user).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi\controller;

use snapdragon\jsonapi\auth\authenticator;
use snapdragon\jsonapi\auth\auth_exception;
use snapdragon\jsonapi\service\forum_repository;

/**
 * GET /categories, /topics, /topics/{id}, /topics/{id}/replies, /posts/{id}, /stats.
 * Reads require only an authenticated user (ROLE_USER floor).
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
     */
    public function __construct(authenticator $authenticator, forum_repository $repo)
    {
        parent::__construct($authenticator);
        $this->repo = $repo;
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
