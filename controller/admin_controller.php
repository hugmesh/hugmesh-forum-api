<?php
/**
 * Snapdragon Forum API — admin endpoints (ROLE_ADMIN required).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\controller;

use snapdragon\forumapi\auth\authenticator;
use snapdragon\forumapi\auth\auth_exception;
use snapdragon\forumapi\service\admin_service;
use snapdragon\forumapi\service\forum_repository;
use snapdragon\forumapi\service\posting_service;
use snapdragon\forumapi\service\user_mapper;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin surface. Every action re-derives ROLE_ADMIN from the verified JWT (fail-closed)
 * — caller-supplied group/role data in the body is NEVER trusted as authority.
 *
 * Endpoints:
 *  - POST   /users/{sub}/ensure              -> ForumUser
 *  - PUT    /users/{sub}/groups              (GroupAssignment) -> 204
 *  - POST   /admin/styles                    (["name"]) -> 204
 *  - POST   /admin/extensions                (["name"]) -> 204
 *  - GET    /admin/users                     -> ForumUser[]
 *  - GET    /admin/users/{sub}               -> ForumUser
 *  - POST   /admin/categories                (CreateCategory) -> Category
 *  - GET    /admin/moderation-queue          -> QueueItem[]
 *  - POST   /admin/moderation/{id}/approve   -> 204
 *  - POST   /admin/moderation/{id}/reject    -> 204
 *  - POST   /admin/users/{sub}/ban           (BanRequest{reason}) -> 204
 *  - DELETE /admin/users/{sub}/ban           -> 204
 */
class admin_controller extends base_controller
{
    /**
     * @var user_mapper
     */
    private $users;

    /**
     * @var forum_repository
     */
    private $repo;

    /**
     * @var admin_service
     */
    private $admin;

    /**
     * @var posting_service
     */
    private $posting;

    /**
     * @param authenticator    $authenticator
     * @param user_mapper      $users
     * @param forum_repository $repo
     * @param admin_service    $admin
     * @param posting_service  $posting
     * @param Request|null     $request The injected phpBB @symfony_request.
     */
    public function __construct(authenticator $authenticator, user_mapper $users, forum_repository $repo, admin_service $admin, posting_service $posting, Request $request = null)
    {
        parent::__construct($authenticator, $request);
        $this->users = $users;
        $this->repo = $repo;
        $this->admin = $admin;
        $this->posting = $posting;
    }

    /**
     * POST /users/{sub}/ensure -> ForumUser. Resolve-or-create keyed by Casdoor sub.
     *
     * Note: the {@code sub} in the PATH is the user being ensured. The CALLER (admin)
     * is authenticated from the JWT; the ensured user is created from the path sub. The
     * resulting phpBB user has only the path sub as identity (no token to derive email
     * from), so email/display are best-effort.
     *
     * @param string $sub
     */
    public function ensure_user($sub)
    {
        return $this->guard(function () use ($sub) {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $sub = (string) $sub;
            if ($sub === '')
            {
                throw auth_exception::bad_request('sub is required.');
            }

            $forum_user = $this->admin->ensure_by_sub($sub);
            return $this->json($forum_user, 201);
        });
    }

    /**
     * PUT /users/{sub}/groups (GroupAssignment{casdoorSubject,group,roles,lastSynced}) -> 204.
     * DECLARATIVE convergence. Authority for the SYNC is the admin JWT; the desired group
     * is derived from the body's roles[] (advisory target), NOT trusted as the caller's
     * own authority.
     *
     * @param string $sub
     */
    public function set_user_groups($sub)
    {
        return $this->guard(function () use ($sub) {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $body = $this->json_body();
            $roles = isset($body['roles']) && is_array($body['roles']) ? $body['roles'] : [];
            $group = isset($body['group']) && is_string($body['group']) ? $body['group'] : '';

            $this->admin->converge_groups_for_sub((string) $sub, $group, $roles);
            return $this->no_content();
        });
    }

    /**
     * POST /admin/styles (["name"]) -> 204.
     */
    public function install_style()
    {
        return $this->guard(function () {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $names = $this->json_body();
            $this->admin->install_styles(is_array($names) ? $names : []);
            return $this->no_content();
        });
    }

    /**
     * POST /admin/extensions (["name"]) -> 204.
     */
    public function install_extension()
    {
        return $this->guard(function () {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $names = $this->json_body();
            $this->admin->enable_extensions(is_array($names) ? $names : []);
            return $this->no_content();
        });
    }

    /**
     * GET /admin/users -> ForumUser[].
     */
    public function list_users()
    {
        return $this->guard(function () {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);
            return $this->json($this->admin->list_users());
        });
    }

    /**
     * GET /admin/users/{sub} -> ForumUser.
     *
     * @param string $sub
     */
    public function get_user($sub)
    {
        return $this->guard(function () use ($sub) {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);
            $forum_user = $this->admin->get_user((string) $sub);
            if ($forum_user === null)
            {
                throw auth_exception::not_found('No phpBB user mapped for that subject.');
            }
            return $this->json($forum_user);
        });
    }

    /**
     * POST /admin/categories (CreateCategory{name,description,parentId?}) -> Category.
     */
    public function create_category()
    {
        return $this->guard(function () {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $body = $this->json_body();
            $name = $this->require_string($body, 'name');
            $description = isset($body['description']) && is_string($body['description']) ? $body['description'] : '';
            $parent_id = isset($body['parentId']) && ctype_digit((string) $body['parentId']) ? (int) $body['parentId'] : 0;

            $category = $this->admin->create_category($name, $description, $parent_id);
            return $this->json($category, 201);
        });
    }

    /**
     * GET /admin/moderation-queue -> QueueItem[].
     */
    public function moderation_queue()
    {
        return $this->guard(function () {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);
            return $this->json($this->repo->moderation_queue());
        });
    }

    /**
     * POST /admin/moderation/{id}/approve -> 204.
     *
     * @param int $id Post id in the queue.
     */
    public function approve($id)
    {
        return $this->guard(function () use ($id) {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $post_row = $this->repo->get_post_row((int) $id);
            if ($post_row === null)
            {
                throw auth_exception::not_found('Queued post not found.');
            }
            $topic_row = $this->repo->get_topic_row((int) $post_row['topic_id']);
            if ($topic_row === null)
            {
                throw auth_exception::not_found('Owning topic not found.');
            }
            $actor_id = $this->users->ensure_user($caller);
            $this->posting->approve_post($post_row, $topic_row, $actor_id);
            return $this->no_content();
        });
    }

    /**
     * POST /admin/moderation/{id}/reject -> 204.
     *
     * @param int $id Post id in the queue.
     */
    public function reject($id)
    {
        return $this->guard(function () use ($id) {
            $caller = $this->principal();
            $this->authenticator->require_admin($caller);

            $post_row = $this->repo->get_post_row((int) $id);
            if ($post_row === null)
            {
                throw auth_exception::not_found('Queued post not found.');
            }
            $topic_row = $this->repo->get_topic_row((int) $post_row['topic_id']);
            if ($topic_row === null)
            {
                throw auth_exception::not_found('Owning topic not found.');
            }
            $actor_id = $this->users->ensure_user($caller);
            $this->posting->reject_post($post_row, $topic_row, $actor_id);
            return $this->no_content();
        });
    }

    /**
     * POST /admin/users/{sub}/ban (BanRequest{reason?}) -> 204.
     *
     * Adds the user to phpbb_banlist, sets user_type=3 (USER_IGNORE), removes
     * non-REGISTERED group memberships, and kills active sessions. Idempotent on repeat.
     *
     * @param string $sub The Casdoor subject of the user to ban.
     */
    public function ban_user($sub)
    {
        return $this->guard(function () use ($sub) {
            $this->authenticator->require_admin($this->principal());
            $body   = $this->json_body();
            $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : '';
            $this->admin->ban_user((string) $sub, $reason);
            return $this->no_content();
        });
    }

    /**
     * DELETE /admin/users/{sub}/ban -> 204.
     *
     * Lifts the ban: removes the banlist entry, restores user_type=0 (USER_NORMAL),
     * ensures REGISTERED-group floor membership, and kills stale sessions (force re-login).
     * Higher-role groups are restored on the next set_groups sync from admin-bff.
     *
     * @param string $sub The Casdoor subject of the user to unban.
     */
    public function unban_user($sub)
    {
        return $this->guard(function () use ($sub) {
            $this->authenticator->require_admin($this->principal());
            $this->admin->unban_user((string) $sub);
            return $this->no_content();
        });
    }
}
