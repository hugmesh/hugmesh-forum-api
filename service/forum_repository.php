<?php
/**
 * Snapdragon Forum API — read-side repository mapping phpBB tables to the DTO shapes.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\service;

use snapdragon\forumapi\text\sticker_tokens;

/**
 * Reads phpBB forums/topics/posts/users via the injected DBAL connection and maps rows
 * to the pinned DTO shapes (Category / Topic / Reply / Stats / QueueItem). Timestamps
 * are emitted as ISO-8601 UTC strings (Jackson {@code Instant} compatible).
 *
 * The Casdoor sub for {@code authorSubject} is resolved by joining the poster's user_id
 * back through {@code phpbb_snapdragon_casdoor_map}; a phpBB user with no mapping (a
 * native account) yields an empty {@code authorSubject}.
 *
 * {@code Reply.body} / {@code QueueItem.excerpt} additionally run through
 * {@see \snapdragon\forumapi\text\sticker_tokens::render()}, which renders any
 * `:snap-sticker:<slug>:` token to the same `<img>` markup forum-ui's client-side transform
 * produces — so a raw API consumer (not just forum-ui) sees a rendered sticker rather than
 * the literal token.
 */
class forum_repository
{
    /**
     * @var \phpbb\db\driver\driver_interface
     */
    private $db;

    /**
     * @var string
     */
    private $table_prefix;

    /**
     * @param \phpbb\db\driver\driver_interface $db
     * @param string                            $table_prefix
     */
    public function __construct($db, $table_prefix)
    {
        $this->db = $db;
        $this->table_prefix = $table_prefix;
    }

    /**
     * GET /categories -> Category[].
     * A "category" is a postable phpBB forum (forum_type = FORUM_POST = 1).
     *
     * @return array<int, array>
     */
    public function list_categories()
    {
        $forum_post = defined('FORUM_POST') ? FORUM_POST : 1;

        $sql = 'SELECT forum_id, forum_name, forum_desc, forum_topics_approved
            FROM ' . $this->forums_table() . '
            WHERE forum_type = ' . (int) $forum_post . '
            ORDER BY left_id ASC';
        $result = $this->db->sql_query($sql);

        $out = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $out[] = [
                'id'          => (int) $row['forum_id'],
                'name'        => (string) $row['forum_name'],
                'description' => (string) $row['forum_desc'],
                'topicCount'  => (int) $row['forum_topics_approved'],
            ];
        }
        $this->db->sql_freeresult($result);

        return $out;
    }

    /**
     * GET /admin/categories list also reuses list_categories().
     *
     * GET /topics?categoryId={id} -> Topic[].
     *
     * @param int $category_id The phpBB forum_id.
     * @return array<int, array>
     */
    public function list_topics($category_id)
    {
        $item_approved = defined('ITEM_APPROVED') ? ITEM_APPROVED : 1;

        $sql = 'SELECT topic_id, forum_id, topic_title, topic_poster, topic_posts_approved, topic_time
            FROM ' . $this->topics_table() . '
            WHERE forum_id = ' . (int) $category_id . '
                AND topic_visibility = ' . (int) $item_approved . '
            ORDER BY topic_last_post_time DESC';
        $result = $this->db->sql_query($sql);

        $out = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $out[] = $this->map_topic_row($row);
        }
        $this->db->sql_freeresult($result);

        return $out;
    }

    /**
     * GET /topics/{id} -> Topic.
     *
     * @param int $topic_id
     * @return array|null
     */
    public function get_topic($topic_id)
    {
        $sql = 'SELECT topic_id, forum_id, topic_title, topic_poster, topic_posts_approved, topic_time
            FROM ' . $this->topics_table() . '
            WHERE topic_id = ' . (int) $topic_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? $this->map_topic_row($row) : null;
    }

    /**
     * GET /topics/{id}/replies -> Reply[]. Returns every approved post in the topic
     * (including the opening post), oldest first.
     *
     * @param int $topic_id
     * @return array<int, array>
     */
    public function list_replies($topic_id)
    {
        $item_approved = defined('ITEM_APPROVED') ? ITEM_APPROVED : 1;

        $sql = 'SELECT post_id, topic_id, poster_id, post_text, post_time
            FROM ' . $this->posts_table() . '
            WHERE topic_id = ' . (int) $topic_id . '
                AND post_visibility = ' . (int) $item_approved . '
            ORDER BY post_time ASC, post_id ASC';
        $result = $this->db->sql_query($sql);

        $out = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $out[] = $this->map_post_row($row);
        }
        $this->db->sql_freeresult($result);

        return $out;
    }

    /**
     * GET /posts/{id} -> Reply.
     *
     * @param int $post_id
     * @return array|null
     */
    public function get_post($post_id)
    {
        $sql = 'SELECT post_id, topic_id, poster_id, post_text, post_time, forum_id, post_visibility
            FROM ' . $this->posts_table() . '
            WHERE post_id = ' . (int) $post_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? $this->map_post_row($row) : null;
    }

    /**
     * The raw post row (incl. forum_id, poster_id, visibility) for edit/delete/moderation
     * decisions — NOT the DTO. Returns null if missing.
     *
     * @param int $post_id
     * @return array|null
     */
    public function get_post_row($post_id)
    {
        $sql = 'SELECT post_id, topic_id, forum_id, poster_id, post_text, post_subject, post_time, post_visibility
            FROM ' . $this->posts_table() . '
            WHERE post_id = ' . (int) $post_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: null;
    }

    /**
     * The raw topic row for moderation/edit decisions.
     *
     * @param int $topic_id
     * @return array|null
     */
    public function get_topic_row($topic_id)
    {
        $sql = 'SELECT topic_id, forum_id, topic_title, topic_poster, topic_first_post_id,
                    topic_last_post_id, topic_visibility, topic_time,
                    topic_posts_approved, topic_posts_unapproved, topic_posts_softdeleted
            FROM ' . $this->topics_table() . '
            WHERE topic_id = ' . (int) $topic_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: null;
    }

    /**
     * Resolve the Casdoor sub for a phpBB user_id (empty string if unmapped/native).
     *
     * @param int $user_id
     * @return string
     */
    public function subject_for_user($user_id)
    {
        $sql = 'SELECT casdoor_sub FROM ' . $this->table_prefix . 'snapdragon_casdoor_map
            WHERE user_id = ' . (int) $user_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (string) $row['casdoor_sub'] : '';
    }

    /**
     * The phpBB username for a user_id (empty string if missing).
     *
     * @param int $user_id
     * @return string
     */
    public function get_username($user_id)
    {
        $sql = 'SELECT username FROM ' . $this->users_table() . '
            WHERE user_id = ' . (int) $user_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ? (string) $row['username'] : '';
    }

    /**
     * GET /stats -> Stats. Live COUNT queries; source = "live".
     *
     * @return array
     */
    public function stats()
    {
        $item_approved = defined('ITEM_APPROVED') ? ITEM_APPROVED : 1;
        $item_unapproved = defined('ITEM_UNAPPROVED') ? ITEM_UNAPPROVED : 0;
        $item_reapprove = defined('ITEM_REAPPROVE') ? ITEM_REAPPROVE : 3;
        $user_normal = defined('USER_NORMAL') ? USER_NORMAL : 0;

        $topics = $this->count_query('SELECT COUNT(topic_id) AS c FROM ' . $this->topics_table()
            . ' WHERE topic_visibility = ' . (int) $item_approved);
        $posts = $this->count_query('SELECT COUNT(post_id) AS c FROM ' . $this->posts_table()
            . ' WHERE post_visibility = ' . (int) $item_approved);
        $active_users = $this->count_query('SELECT COUNT(user_id) AS c FROM ' . $this->users_table()
            . ' WHERE user_type = ' . (int) $user_normal);
        $queue_depth = $this->count_query('SELECT COUNT(post_id) AS c FROM ' . $this->posts_table()
            . ' WHERE post_visibility IN (' . (int) $item_unapproved . ', ' . (int) $item_reapprove . ')');

        return [
            'topics'               => $topics,
            'posts'                => $posts,
            'activeUsers'          => $active_users,
            'moderationQueueDepth' => $queue_depth,
            'source'               => 'live',
        ];
    }

    /**
     * GET /admin/moderation-queue -> QueueItem[]. The unapproved/reapprove posts.
     *
     * @return array<int, array>
     */
    public function moderation_queue()
    {
        $item_unapproved = defined('ITEM_UNAPPROVED') ? ITEM_UNAPPROVED : 0;
        $item_reapprove = defined('ITEM_REAPPROVE') ? ITEM_REAPPROVE : 3;

        $sql = 'SELECT post_id, topic_id, poster_id, post_text, post_time, post_visibility
            FROM ' . $this->posts_table() . '
            WHERE post_visibility IN (' . (int) $item_unapproved . ', ' . (int) $item_reapprove . ')
            ORDER BY post_time ASC';
        $result = $this->db->sql_query($sql);

        $out = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $excerpt = $this->strip_text((string) $row['post_text']);
            if (function_exists('mb_substr'))
            {
                $excerpt = mb_substr($excerpt, 0, 200);
            }
            else
            {
                $excerpt = substr($excerpt, 0, 200);
            }
            // Render AFTER truncation: a token cut in half by the 200-char cap simply fails
            // to match (no dangling/broken <img> markup from a truncated attribute).
            $excerpt = sticker_tokens::render($excerpt);

            $out[] = [
                'id'            => (string) $row['post_id'],
                'topicId'       => (int) $row['topic_id'],
                'authorSubject' => $this->subject_for_user((int) $row['poster_id']),
                'excerpt'       => $excerpt,
                'state'         => ((int) $row['post_visibility'] === (int) $item_reapprove) ? 'reapprove' : 'unapproved',
                'createdAt'     => $this->iso8601((int) $row['post_time']),
            ];
        }
        $this->db->sql_freeresult($result);

        return $out;
    }

    // ── mapping helpers ────────────────────────────────────────────────

    /**
     * @param array $row
     * @return array Topic DTO shape.
     */
    private function map_topic_row(array $row)
    {
        return [
            'id'            => (int) $row['topic_id'],
            'categoryId'    => (int) $row['forum_id'],
            'title'         => (string) $row['topic_title'],
            'authorSubject' => $this->subject_for_user((int) $row['topic_poster']),
            // replyCount excludes the opening post (replies = posts - 1, floored at 0).
            'replyCount'    => max(0, (int) $row['topic_posts_approved'] - 1),
            'createdAt'     => $this->iso8601((int) $row['topic_time']),
        ];
    }

    /**
     * @param array $row
     * @return array Reply DTO shape.
     */
    private function map_post_row(array $row)
    {
        return [
            'id'            => (int) $row['post_id'],
            'topicId'       => (int) $row['topic_id'],
            'authorSubject' => $this->subject_for_user((int) $row['poster_id']),
            'body'          => sticker_tokens::render($this->strip_text((string) $row['post_text'])),
            'createdAt'     => $this->iso8601((int) $row['post_time']),
        ];
    }

    /**
     * @param string $sql
     * @return int
     */
    private function count_query($sql)
    {
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ? (int) $row['c'] : 0;
    }

    /**
     * A best-effort plain-text view of phpBB stored post_text (which carries bbcode_uid
     * markers). Strips bbcode-uid tokens and decodes entities; a richer render would use
     * generate_text_for_display(), which needs the bbcode bitfield/uid (not loaded on the
     * read path here).
     *
     * @param string $text
     * @return string
     */
    private function strip_text($text)
    {
        // Remove ":<uid>" bbcode-uid suffixes phpBB appends to tags.
        $text = preg_replace('/:[a-z0-9]{5,}\]/', ']', $text);
        $text = preg_replace('/<.*?>/', '', $text);
        return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param int $unix_ts
     * @return string ISO-8601 UTC (e.g. 2026-06-22T13:45:00Z).
     */
    private function iso8601($unix_ts)
    {
        $dt = new \DateTime('@' . (int) $unix_ts);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    }

    private function forums_table()
    {
        return defined('FORUMS_TABLE') ? FORUMS_TABLE : $this->table_prefix . 'forums';
    }

    private function topics_table()
    {
        return defined('TOPICS_TABLE') ? TOPICS_TABLE : $this->table_prefix . 'topics';
    }

    private function posts_table()
    {
        return defined('POSTS_TABLE') ? POSTS_TABLE : $this->table_prefix . 'posts';
    }

    private function users_table()
    {
        return defined('USERS_TABLE') ? USERS_TABLE : $this->table_prefix . 'users';
    }
}
