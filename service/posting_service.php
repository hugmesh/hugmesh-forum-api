<?php
/**
 * HugMesh Forum API — acts-as-user posting via phpBB submit_post() + content_visibility.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\service;

use hugmesh\forumapi\auth\principal;

/**
 * Performs the WRITE operations through phpBB's own consistency/permission logic.
 *
 * <h3>Acts-as-user</h3>
 * phpBB's {@code submit_post()} attributes ownership through several signals (the
 * {@code $data['poster_id']} field, the {@code $username} argument, and — in places —
 * the SESSION user {@code $user->data['user_id']}). To attribute reliably to an
 * arbitrary mapped user we briefly IMPERSONATE that user's row in {@code $user->data}
 * around the call (saving/restoring the original), so every code path inside
 * {@code submit_post()} sees the target user. The {@code authorSubject} is ALWAYS the
 * validated JWT sub (resolved to this user_id) — never a request-body field.
 *
 * <h3>Moderation</h3>
 * hide/delete go through {@code content.visibility::set_*_visibility()}; lock toggles
 * the topic's {@code topic_status}. Approve/reject (admin queue) reuse the same service.
 */
class posting_service
{
    /**
     * @var \phpbb\db\driver\driver_interface
     */
    private $db;

    /**
     * @var \phpbb\user
     */
    private $user;

    /**
     * @var \phpbb\auth\auth
     */
    private $auth;

    /**
     * @var \phpbb\content_visibility
     */
    private $content_visibility;

    /**
     * @var \phpbb\config\config
     */
    private $config;

    /**
     * @var string
     */
    private $table_prefix;

    /**
     * @var string
     */
    private $root_path;

    /**
     * @var string
     */
    private $php_ext;

    /**
     * @param \phpbb\db\driver\driver_interface $db
     * @param \phpbb\user                       $user
     * @param \phpbb\auth\auth                  $auth
     * @param \phpbb\content_visibility         $content_visibility
     * @param \phpbb\config\config              $config
     * @param string                            $table_prefix
     * @param string                            $root_path
     * @param string                            $php_ext
     */
    public function __construct($db, $user, $auth, $content_visibility, $config, $table_prefix, $root_path, $php_ext)
    {
        $this->db = $db;
        $this->user = $user;
        $this->auth = $auth;
        $this->content_visibility = $content_visibility;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
        $this->root_path = $root_path;
        $this->php_ext = $php_ext;
    }

    /**
     * Create a NEW topic authored by $poster_id in $forum_id.
     *
     * @param int    $forum_id
     * @param int    $poster_id The mapped phpBB user_id (the JWT sub's user).
     * @param string $username  The poster's phpBB username (post attribution display).
     * @param string $title
     * @param string $body
     * @return array{topic_id:int, post_id:int}
     */
    public function create_topic($forum_id, $poster_id, $username, $title, $body)
    {
        $this->require_posting_includes();

        $data = $this->base_post_data($forum_id, $poster_id, $body);
        $data['topic_id'] = 0;
        $data['post_id'] = 0;
        $data['topic_title'] = $title;

        return $this->as_user($poster_id, function () use ($title, $username, $data) {
            $poll = [];
            $local = $data;
            $topic_type = defined('POST_NORMAL') ? POST_NORMAL : 0;
            submit_post('post', $title, $username, $topic_type, $poll, $local);
            return ['topic_id' => (int) $local['topic_id'], 'post_id' => (int) $local['post_id']];
        });
    }

    /**
     * Post a REPLY to $topic_id authored by $poster_id.
     *
     * @param array  $topic_row The existing topic row (from forum_repository::get_topic_row).
     * @param int    $poster_id
     * @param string $username
     * @param string $body
     * @return array{topic_id:int, post_id:int}
     */
    public function create_reply($topic_row, $poster_id, $username, $body)
    {
        $this->require_posting_includes();

        $forum_id = (int) $topic_row['forum_id'];
        $data = $this->base_post_data($forum_id, $poster_id, $body);
        $data['topic_id'] = (int) $topic_row['topic_id'];
        $data['post_id'] = 0;
        $data['topic_title'] = (string) $topic_row['topic_title'];

        return $this->as_user($poster_id, function () use ($topic_row, $username, $data) {
            $poll = [];
            $local = $data;
            $topic_type = defined('POST_NORMAL') ? POST_NORMAL : 0;
            submit_post('reply', (string) $topic_row['topic_title'], $username, $topic_type, $poll, $local);
            return ['topic_id' => (int) $local['topic_id'], 'post_id' => (int) $local['post_id']];
        });
    }

    /**
     * Edit the body of an existing post, preserving its original author.
     *
     * @param array  $post_row  The existing post row (forum_repository::get_post_row).
     * @param array  $topic_row The owning topic row.
     * @param int    $editor_id The phpBB user_id performing the edit (for post_edit_user).
     * @param string $username  The ORIGINAL poster's username (attribution preserved).
     * @param string $body
     * @return void
     */
    public function edit_post($post_row, $topic_row, $editor_id, $username, $body)
    {
        $this->require_posting_includes();

        $poster_id = (int) $post_row['poster_id'];
        $data = $this->base_post_data((int) $post_row['forum_id'], $poster_id, $body);
        $data['topic_id'] = (int) $post_row['topic_id'];
        $data['post_id'] = (int) $post_row['post_id'];
        $data['topic_title'] = (string) $topic_row['topic_title'];
        $data['post_edit_reason'] = '';
        $data['post_edit_user'] = (int) $editor_id;
        $data['post_time'] = (int) $post_row['post_time'];
        $data['post_visibility'] = (int) $post_row['post_visibility'];
        $data['topic_visibility'] = (int) $topic_row['topic_visibility'];
        $data['topic_posts_approved'] = (int) $topic_row['topic_posts_approved'];
        $data['topic_posts_unapproved'] = (int) $topic_row['topic_posts_unapproved'];
        $data['topic_posts_softdeleted'] = (int) $topic_row['topic_posts_softdeleted'];
        // submit_post('edit', ...) reads these to pick the edit sub-mode (edit_first_post /
        // edit_last_post / edit); omitting them raises "Undefined array key" warnings that
        // emit output before headers and corrupt the JSON response.
        $data['topic_first_post_id'] = (int) $topic_row['topic_first_post_id'];
        $data['topic_last_post_id'] = (int) $topic_row['topic_last_post_id'];

        $this->as_user($poster_id, function () use ($post_row, $username, $data) {
            $poll = [];
            $local = $data;
            $topic_type = defined('POST_NORMAL') ? POST_NORMAL : 0;
            submit_post('edit', (string) $data['topic_title'], $username, $topic_type, $poll, $local);
            return null;
        });
    }

    /**
     * Soft-delete a single post via content_visibility.
     *
     * @param array  $post_row
     * @param array  $topic_row
     * @param int    $actor_id The phpBB user_id recorded as making the change.
     * @param string $reason
     * @return void
     */
    public function delete_post($post_row, $topic_row, $actor_id, $reason = '')
    {
        $item_deleted = defined('ITEM_DELETED') ? ITEM_DELETED : 2;
        $is_starter = ((int) $topic_row['topic_first_post_id'] === (int) $post_row['post_id']);
        $is_latest = ((int) $topic_row['topic_last_post_id'] === (int) $post_row['post_id']);

        $this->content_visibility->set_post_visibility(
            $item_deleted,
            (int) $post_row['post_id'],
            (int) $post_row['topic_id'],
            (int) $post_row['forum_id'],
            (int) $actor_id,
            time(),
            (string) $reason,
            $is_starter,
            $is_latest
        );
    }

    /**
     * Apply a moderation ACTION to a topic: hide (soft-delete topic), lock, or delete.
     *
     * @param array  $topic_row
     * @param string $action hide|lock|delete.
     * @param int    $actor_id
     * @param string $reason
     * @return void
     */
    public function moderate_topic($topic_row, $action, $actor_id, $reason = '')
    {
        $action = strtolower(trim((string) $action));

        if ($action === 'lock')
        {
            $this->set_topic_lock((int) $topic_row['topic_id'], true);
            return;
        }

        // hide and delete both map to a soft-deleted topic visibility (phpBB has no
        // separate "hidden" state; soft-delete IS the hide). A hard delete is out of
        // scope here — soft-delete is reversible and audit-friendly.
        $item_deleted = defined('ITEM_DELETED') ? ITEM_DELETED : 2;
        $this->content_visibility->set_topic_visibility(
            $item_deleted,
            (int) $topic_row['topic_id'],
            (int) $topic_row['forum_id'],
            (int) $actor_id,
            time(),
            (string) $reason
        );
    }

    /**
     * Approve a queued post (admin moderation queue).
     *
     * @param array $post_row
     * @param array $topic_row
     * @param int   $actor_id
     * @return void
     */
    public function approve_post($post_row, $topic_row, $actor_id)
    {
        $item_approved = defined('ITEM_APPROVED') ? ITEM_APPROVED : 1;
        $is_starter = ((int) $topic_row['topic_first_post_id'] === (int) $post_row['post_id']);
        $is_latest = ((int) $topic_row['topic_last_post_id'] === (int) $post_row['post_id']);

        $this->content_visibility->set_post_visibility(
            $item_approved,
            (int) $post_row['post_id'],
            (int) $post_row['topic_id'],
            (int) $post_row['forum_id'],
            (int) $actor_id,
            time(),
            '',
            $is_starter,
            $is_latest
        );
    }

    /**
     * Reject (soft-delete) a queued post.
     *
     * @param array $post_row
     * @param array $topic_row
     * @param int   $actor_id
     * @return void
     */
    public function reject_post($post_row, $topic_row, $actor_id)
    {
        $this->delete_post($post_row, $topic_row, $actor_id, 'rejected from moderation queue');
    }

    // ── internals ──────────────────────────────────────────────────────

    /**
     * Lock/unlock a topic by toggling topic_status.
     *
     * @param int  $topic_id
     * @param bool $locked
     * @return void
     */
    private function set_topic_lock($topic_id, $locked)
    {
        $item_locked = defined('ITEM_LOCKED') ? ITEM_LOCKED : 1;
        $item_unlocked = defined('ITEM_UNLOCKED') ? ITEM_UNLOCKED : 0;
        $status = $locked ? $item_locked : $item_unlocked;

        $sql = 'UPDATE ' . $this->topics_table() . '
            SET topic_status = ' . (int) $status . '
            WHERE topic_id = ' . (int) $topic_id;
        $this->db->sql_query($sql);
    }

    /**
     * Build the common submit_post() $data array for a new/reply post body.
     *
     * @param int    $forum_id
     * @param int    $poster_id
     * @param string $message
     * @return array
     */
    private function base_post_data($forum_id, $poster_id, $message)
    {
        // Generate the storage form (bbcode uid/bitfield) so phpBB renders it later.
        $uid = '';
        $bitfield = '';
        $flags = 7; // bbcode + smilies + urls (OPTION_FLAG_* sum)
        if (function_exists('generate_text_for_storage'))
        {
            generate_text_for_storage($message, $uid, $bitfield, $flags, true, true, true);
        }

        return [
            'forum_id'          => (int) $forum_id,
            'icon_id'           => false,
            'poster_id'         => (int) $poster_id,
            'enable_bbcode'     => true,
            'enable_smilies'    => true,
            'enable_urls'       => true,
            'enable_sig'        => true,
            'message'           => $message,
            'message_md5'       => md5($message),
            'bbcode_bitfield'   => $bitfield,
            'bbcode_uid'        => $uid,
            'post_edit_locked'  => 0,
            'notify_set'        => false,
            'notify'            => false,
            'post_time'         => 0,
            'forum_name'        => '',
            'enable_indexing'   => true,
            // force_approved_state => ITEM_APPROVED (1) so submit_post() does NOT route
            // through the moderation queue. We must NOT set force_visibility at all:
            // phpBB does isset($data['force_visibility']) (true even for the literal
            // false), then (int) false === 0 === ITEM_UNAPPROVED, which OVERRIDES
            // force_approved_state and silently forces every post/reply/edit unapproved
            // (invisible to list_topics/list_replies). See phpBB 3.3.x
            // includes/functions_posting.php submit_post() force_visibility branch.
            'force_approved_state' => 1,
            'post_username'     => '',
        ];
    }

    /**
     * Pull in the phpBB posting + message-parser helpers once.
     *
     * @return void
     */
    private function require_posting_includes()
    {
        if (!function_exists('submit_post'))
        {
            include($this->root_path . 'includes/functions_posting.' . $this->php_ext);
        }
        if (!function_exists('generate_text_for_storage'))
        {
            include($this->root_path . 'includes/functions_content.' . $this->php_ext);
        }
    }

    /**
     * Run a callback while phpBB's $user->data is impersonating the target user, then
     * restore the original session row AND the original ACL. This is the acts-as-user
     * mechanism: every ownership/last-post/notification AND permission (acl_get) path
     * inside submit_post() must see $poster_id.
     *
     * Two subtleties phpBB imposes:
     *  - is_registered / is_bot are SESSION-computed flags (set at session creation from
     *    user_type), NOT plain user-table columns, so loading the user row does not carry
     *    them. submit_post()/acl paths read $user->data['is_registered']; without it the
     *    impersonated user would look like the anonymous guest. We set them explicitly
     *    from user_type (FOUNDER/NORMAL => registered; bot only for USER_IGNORE bots).
     *  - $auth (the ACL) is primed for the ORIGINAL session user. We must re-prime it for
     *    the target so acl_get() inside submit_post() evaluates the impersonated user's
     *    permissions, then restore the original ACL afterwards (even on exception).
     *
     * @param int      $poster_id
     * @param callable $fn
     * @return mixed
     */
    private function as_user($poster_id, callable $fn)
    {
        // Capture the ORIGINAL session identity verbatim — even if it is null/unset — so the
        // finally can restore the EXACT value we entered with. A cross-request-identity path
        // must never be left in the impersonated state, regardless of how shallow the entry
        // session was. (isset() yields null both when data is unset AND when it is literally
        // null; either way the captured original is what we restore to.)
        $original = isset($this->user->data) ? $this->user->data : null;
        $can_acl = ($this->auth !== null && method_exists($this->auth, 'acl'));

        $row = $this->load_user_row((int) $poster_id);
        if ($row !== null)
        {
            // Merge over a copy so we keep session keys submit_post() may read.
            $merged = array_merge((array) $original, $row);

            // Re-derive the SESSION-only flags from user_type (they are not columns).
            $user_normal = defined('USER_NORMAL') ? USER_NORMAL : 0;
            $user_founder = defined('USER_FOUNDER') ? USER_FOUNDER : 3;
            $user_ignore = defined('USER_IGNORE') ? USER_IGNORE : 2;
            $user_type = isset($row['user_type']) ? (int) $row['user_type'] : $user_normal;

            $merged['is_registered'] = ($user_type === $user_normal || $user_type === $user_founder);
            $merged['is_bot'] = ($user_type === $user_ignore);
            $merged['user_id'] = (int) $poster_id;

            $this->user->data = $merged;

            // Re-prime the ACL for the impersonated user so submit_post()'s acl_get()
            // evaluates as $poster_id, not as the original (often anonymous) session.
            if ($can_acl)
            {
                $this->auth->acl($this->user->data);
            }
        }

        try
        {
            return $fn();
        }
        finally
        {
            // UNCONDITIONAL restore: always put back the EXACT original $user->data we
            // captured (even when it was null), and always re-prime $auth->acl to that same
            // original identity — even on exception, and even if we never entered the
            // impersonation branch above. Never leave the impersonated identity/ACL in place.
            $this->user->data = $original;
            if ($can_acl)
            {
                $this->auth->acl($original);
            }
        }
    }

    /**
     * @param int $user_id
     * @return array|null
     */
    private function load_user_row($user_id)
    {
        $sql = 'SELECT * FROM ' . $this->users_table() . ' WHERE user_id = ' . (int) $user_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ?: null;
    }

    private function topics_table()
    {
        return defined('TOPICS_TABLE') ? TOPICS_TABLE : $this->table_prefix . 'topics';
    }

    private function users_table()
    {
        return defined('USERS_TABLE') ? USERS_TABLE : $this->table_prefix . 'users';
    }
}
