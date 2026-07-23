<?php
/**
 * HugMesh Forum API — write endpoints (acts-as-user; author = JWT sub).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\controller;

use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\auth_exception;
use hugmesh\forumapi\config_provider;
use hugmesh\forumapi\service\forum_repository;
use hugmesh\forumapi\service\posting_service;
use hugmesh\forumapi\service\user_mapper;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /topics, POST /topics/{id}/replies, PUT /posts/{id}, DELETE /posts/{id},
 * POST /moderation/topics/{id}.
 *
 * The post {@code authorSubject} is ALWAYS the validated JWT sub (resolved to a phpBB
 * user via {@see user_mapper}) — NEVER a request-body field. Edit/delete enforce
 * ownership unless the caller is a moderator/admin. Moderation requires ROLE_MOD+.
 */
class write_controller extends base_controller
{
    /**
     * @var forum_repository
     */
    private $repo;

    /**
     * @var posting_service
     */
    private $posting;

    /**
     * @var user_mapper
     */
    private $users;

    /**
     * @var config_provider
     */
    private $cfg;

    /**
     * @param authenticator    $authenticator
     * @param forum_repository $repo
     * @param posting_service  $posting
     * @param user_mapper      $users
     * @param config_provider  $cfg
     * @param Request|null     $request The injected phpBB @symfony_request.
     */
    public function __construct(authenticator $authenticator, forum_repository $repo, posting_service $posting, user_mapper $users, config_provider $cfg, Request $request = null)
    {
        parent::__construct($authenticator, $request);
        $this->repo = $repo;
        $this->posting = $posting;
        $this->users = $users;
        $this->cfg = $cfg;
    }

    /**
     * POST /topics (CreateTopic{categoryId,title,body}) -> Topic.
     */
    public function create_topic()
    {
        return $this->guard(function () {
            $p = $this->principal();
            $this->authenticator->require_user($p);

            $body = $this->json_body();
            $title = $this->require_string($body, 'title');
            $message = $this->require_string($body, 'body');
            $forum_id = isset($body['categoryId']) && ctype_digit((string) $body['categoryId'])
                ? (int) $body['categoryId'] : 0;
            if ($forum_id <= 0)
            {
                $forum_id = $this->cfg->default_forum_id();
            }

            // Lazily resolve/create the phpBB user for the JWT sub (acts-as-user).
            $poster_id = $this->users->ensure_user($p);

            $ids = $this->posting->create_topic($forum_id, $poster_id, $p->get_display_name(), $title, $message);

            $topic = $this->repo->get_topic((int) $ids['topic_id']);
            if ($topic === null)
            {
                throw auth_exception::not_found('Topic creation did not yield a readable topic.');
            }
            return $this->json($topic, 201);
        });
    }

    /**
     * POST /topics/{id}/replies (CreateReply{body}) -> Reply.
     *
     * @param int $id Topic id.
     */
    public function create_reply($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_user($p);

            $topic_row = $this->repo->get_topic_row((int) $id);
            if ($topic_row === null)
            {
                throw auth_exception::not_found('Topic not found.');
            }

            $body = $this->json_body();
            $message = $this->require_string($body, 'body');

            $poster_id = $this->users->ensure_user($p);
            $ids = $this->posting->create_reply($topic_row, $poster_id, $p->get_display_name(), $message);

            $reply = $this->repo->get_post((int) $ids['post_id']);
            if ($reply === null)
            {
                throw auth_exception::not_found('Reply creation did not yield a readable post.');
            }
            return $this->json($reply, 201);
        });
    }

    /**
     * PUT /posts/{id} (EditPost{body}) -> Reply. Ownership enforced unless MOD/ADMIN.
     *
     * @param int $id Post id.
     */
    public function edit_post($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_user($p);

            $post_row = $this->repo->get_post_row((int) $id);
            if ($post_row === null)
            {
                throw auth_exception::not_found('Post not found.');
            }
            $topic_row = $this->repo->get_topic_row((int) $post_row['topic_id']);
            if ($topic_row === null)
            {
                throw auth_exception::not_found('Owning topic not found.');
            }

            // Ownership: the post's authorSubject must equal the caller sub (or MOD/ADMIN).
            $owner_subject = $this->repo->subject_for_user((int) $post_row['poster_id']);
            $this->authenticator->require_owner_or_moderator($p, $owner_subject);

            $body = $this->json_body();
            $message = $this->require_string($body, 'body');

            $editor_id = $this->users->ensure_user($p);
            // The ORIGINAL poster's username preserves attribution on edit.
            $username = $this->username_of((int) $post_row['poster_id'], $p->get_display_name());

            $this->posting->edit_post($post_row, $topic_row, $editor_id, $username, $message);

            $reply = $this->repo->get_post((int) $id);
            return $this->json($reply);
        });
    }

    /**
     * DELETE /posts/{id} -> 204. Ownership enforced unless MOD/ADMIN.
     *
     * @param int $id Post id.
     */
    public function delete_post($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_user($p);

            $post_row = $this->repo->get_post_row((int) $id);
            if ($post_row === null)
            {
                throw auth_exception::not_found('Post not found.');
            }
            $topic_row = $this->repo->get_topic_row((int) $post_row['topic_id']);
            if ($topic_row === null)
            {
                throw auth_exception::not_found('Owning topic not found.');
            }

            $owner_subject = $this->repo->subject_for_user((int) $post_row['poster_id']);
            $this->authenticator->require_owner_or_moderator($p, $owner_subject);

            $actor_id = $this->users->ensure_user($p);
            $this->posting->delete_post($post_row, $topic_row, $actor_id, 'deleted by ' . $p->get_subject());

            return $this->no_content();
        });
    }

    /**
     * POST /moderation/topics/{id} (ModerationAction{action,reason}) -> 204.
     * Requires ROLE_MOD+.
     *
     * @param int $id Topic id.
     */
    public function moderate_topic($id)
    {
        return $this->guard(function () use ($id) {
            $p = $this->principal();
            $this->authenticator->require_moderator($p);

            $topic_row = $this->repo->get_topic_row((int) $id);
            if ($topic_row === null)
            {
                throw auth_exception::not_found('Topic not found.');
            }

            $body = $this->json_body();
            $action = strtolower($this->require_string($body, 'action'));
            if (!in_array($action, ['hide', 'lock', 'delete'], true))
            {
                throw auth_exception::bad_request('action must be one of hide|lock|delete.');
            }
            $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : '';

            $actor_id = $this->users->ensure_user($p);
            $this->posting->moderate_topic($topic_row, $action, $actor_id, $reason);

            return $this->no_content();
        });
    }

    /**
     * @param int    $user_id
     * @param string $fallback
     * @return string The phpBB username, or the fallback display name.
     */
    private function username_of($user_id, $fallback)
    {
        // Reuse the repository's DB to look up the username.
        $username = $this->repo->get_username($user_id);
        return $username !== '' ? $username : $fallback;
    }
}
