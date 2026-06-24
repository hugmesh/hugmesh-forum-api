<?php
/**
 * Snapdragon JSON API — admin operations (user ensure/list, group sync, category, styles, extensions).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi\service;

use snapdragon\jsonapi\auth\principal;

/**
 * Backs {@see \snapdragon\jsonapi\controller\admin_controller}. Bridges path-keyed
 * Casdoor subs to phpBB users, converges declarative groups, manages forums (categories),
 * and enables styles/extensions via phpBB's own managers.
 *
 * Authority is ALWAYS the caller's verified JWT (checked in the controller). The body's
 * roles[] in a group sync is an ADVISORY target only — it tells us which groups to
 * converge to, it never grants the CALLER authority.
 */
class admin_service
{
    /**
     * @var \phpbb\db\driver\driver_interface
     */
    private $db;

    /**
     * @var user_mapper
     */
    private $user_mapper;

    /**
     * @var forum_repository
     */
    private $repo;

    /**
     * @var \phpbb\config\config
     */
    private $config;

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface|null
     */
    private $container;

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
     * @param user_mapper                       $user_mapper
     * @param forum_repository                  $repo
     * @param \phpbb\config\config              $config
     * @param mixed                             $container Service container (lazy style/ext managers).
     * @param string                            $table_prefix
     * @param string                            $root_path
     * @param string                            $php_ext
     */
    public function __construct($db, user_mapper $user_mapper, forum_repository $repo, $config, $container, $table_prefix, $root_path, $php_ext)
    {
        $this->db = $db;
        $this->user_mapper = $user_mapper;
        $this->repo = $repo;
        $this->config = $config;
        $this->container = $container;
        $this->table_prefix = $table_prefix;
        $this->root_path = $root_path;
        $this->php_ext = $php_ext;
    }

    /**
     * Resolve-or-create a phpBB user for a bare Casdoor sub (admin ensure, no token to
     * mine for email/display — uses sub-derived defaults). Returns the ForumUser shape.
     *
     * @param string $sub
     * @return array ForumUser{casdoorSubject, phpbbUserId, group}.
     */
    public function ensure_by_sub($sub)
    {
        // Build a minimal principal carrying just the sub (no roles -> REGISTERED).
        $p = new principal($sub, '', '', '', [principal::ROLE_USER], []);
        $user_id = $this->user_mapper->ensure_user($p);

        return [
            'casdoorSubject' => (string) $sub,
            'phpbbUserId'    => (int) $user_id,
            'group'          => $this->user_mapper->highest_group($user_id),
        ];
    }

    /**
     * Converge a user's managed groups to the target derived from the desired group name
     * and/or roles[]. The mapping mirrors ForumGroup.forRoles:
     *  - ROLE_ADMIN -> ADMINISTRATORS
     *  - ROLE_MOD + ROLE_FORUM -> GLOBAL_MODERATORS
     *  - ROLE_FORUM -> REGISTERED
     *  - else -> NONE (removed from all managed groups)
     * An explicit body {@code group} (the ForumGroup enum name) takes precedence if valid.
     *
     * @param string        $sub
     * @param string        $group_name The ForumGroup enum value from the body (advisory).
     * @param array<string> $roles      The Casdoor role names from the body (advisory).
     * @return void
     */
    public function converge_groups_for_sub($sub, $group_name, array $roles)
    {
        $p = new principal($sub, '', '', '', [principal::ROLE_USER], []);
        $user_id = $this->user_mapper->ensure_user($p);

        $target = $this->resolve_target_group($group_name, $roles);
        $this->user_mapper->converge_groups($user_id, $target);

        // Stamp last_synced on the mapping row.
        $sql = 'UPDATE ' . $this->table_prefix . 'snapdragon_casdoor_map
            SET last_synced = ' . time() . "
            WHERE casdoor_sub = '" . $this->db->sql_escape((string) $sub) . "'";
        $this->db->sql_query($sql);
    }

    /**
     * Resolve the single target phpBB group name from the advisory group/roles.
     *
     * @param string        $group_name
     * @param array<string> $roles
     * @return string ADMINISTRATORS | GLOBAL_MODERATORS | REGISTERED | NONE.
     */
    private function resolve_target_group($group_name, array $roles)
    {
        $group_name = strtoupper(trim((string) $group_name));
        $valid = ['ADMINISTRATORS', 'GLOBAL_MODERATORS', 'REGISTERED', 'NONE'];
        if (in_array($group_name, $valid, true))
        {
            return $group_name;
        }

        // Fall back to role-derivation (mirrors ForumGroup.forRoles).
        $names = [];
        foreach ($roles as $r)
        {
            if (is_string($r))
            {
                $names[] = 'ROLE_' . strtoupper(trim($r));
            }
        }
        if (in_array('ROLE_ADMIN', $names, true))
        {
            return 'ADMINISTRATORS';
        }
        $has_forum = in_array('ROLE_FORUM', $names, true);
        if (in_array('ROLE_MOD', $names, true) && $has_forum)
        {
            return 'GLOBAL_MODERATORS';
        }
        if ($has_forum)
        {
            return 'REGISTERED';
        }
        return 'NONE';
    }

    /**
     * GET /admin/users -> ForumUser[] (every mapped user).
     *
     * @return array<int, array>
     */
    public function list_users()
    {
        $sql = 'SELECT casdoor_sub, user_id FROM ' . $this->table_prefix . 'snapdragon_casdoor_map
            ORDER BY user_id ASC';
        $result = $this->db->sql_query($sql);

        $out = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $out[] = [
                'casdoorSubject' => (string) $row['casdoor_sub'],
                'phpbbUserId'    => (int) $row['user_id'],
                'group'          => $this->user_mapper->highest_group((int) $row['user_id']),
            ];
        }
        $this->db->sql_freeresult($result);

        return $out;
    }

    /**
     * GET /admin/users/{sub} -> ForumUser, or null if unmapped.
     *
     * @param string $sub
     * @return array|null
     */
    public function get_user($sub)
    {
        $user_id = $this->user_mapper->find_user_id($sub);
        if ($user_id === null)
        {
            return null;
        }
        return [
            'casdoorSubject' => (string) $sub,
            'phpbbUserId'    => (int) $user_id,
            'group'          => $this->user_mapper->highest_group($user_id),
        ];
    }

    /**
     * POST /admin/users/{sub}/ban -> 204. Bans a user by Casdoor subject.
     *
     * Idempotent: if the user is already banned the banlist entry is updated in-place
     * (reason refreshed, ban_start reset). Steps:
     *  1. Resolve phpBB user_id from casdoor_map (404 if unmapped).
     *  2. Upsert phpbb_banlist: INSERT if no entry exists, UPDATE reason+start otherwise.
     *  3. Set user_type = 3 (USER_IGNORE) on phpbb_users.
     *  4. Remove non-REGISTERED group memberships from phpbb_user_group.
     *  5. Kill active sessions from phpbb_sessions.
     *
     * @param string $casdoor_sub
     * @param string $reason       Human-readable ban reason (may be empty).
     * @return void
     *
     * @throws \RuntimeException 404 if no phpBB user is mapped to that sub.
     */
    public function ban_user($casdoor_sub, $reason)
    {
        // Step 1: resolve phpBB user_id — 404 if not yet mapped (user must have been ensured first).
        $phpbb_id = $this->user_mapper->find_user_id((string) $casdoor_sub);
        if ($phpbb_id === null)
        {
            throw \snapdragon\jsonapi\auth\auth_exception::not_found('No phpBB user mapped for that subject.');
        }

        $banlist_table    = $this->table_prefix . 'banlist';
        $users_table      = $this->table_prefix . 'users';
        $user_group_table = $this->table_prefix . 'user_group';
        $groups_table     = $this->table_prefix . 'groups';
        $sessions_table   = $this->table_prefix . 'sessions';

        // Step 2: upsert phpbb_banlist.
        // phpBB's banlist has no unique constraint on ban_userid, so we SELECT then INSERT/UPDATE.
        $sql = 'SELECT ban_id FROM ' . $banlist_table . '
            WHERE ban_userid = ' . (int) $phpbb_id;
        $result   = $this->db->sql_query($sql);
        $ban_row  = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $reason_escaped = $this->db->sql_escape((string) $reason);
        $now            = time();

        if ($ban_row)
        {
            // Already banned: refresh reason and start timestamp.
            $sql = 'UPDATE ' . $banlist_table . "
                SET ban_reason = '" . $reason_escaped . "', ban_start = " . (int) $now . "
                WHERE ban_userid = " . (int) $phpbb_id;
            $this->db->sql_query($sql);
        }
        else
        {
            // New ban entry. ban_end = 0 means permanent (phpBB convention).
            $ban_data = [
                'ban_userid'      => (int) $phpbb_id,
                'ban_ip'          => '',
                'ban_email'       => '',
                'ban_exclude'     => 0,
                'ban_check_pm'    => 0,
                'ban_start'       => (int) $now,
                'ban_end'         => 0,
                'ban_reason'      => (string) $reason,
                'ban_give_reason' => '',
            ];
            $sql = 'INSERT INTO ' . $banlist_table . ' ' . $this->db->sql_build_array('INSERT', $ban_data);
            $this->db->sql_query($sql);
        }

        // Step 3: set user_type = 3 (USER_IGNORE) so phpBB treats this user as banned.
        $sql = 'UPDATE ' . $users_table . '
            SET user_type = 3
            WHERE user_id = ' . (int) $phpbb_id;
        $this->db->sql_query($sql);

        // Step 4: remove the user from every group EXCEPT REGISTERED (the floor).
        // We keep REGISTERED membership so unban can restore cleanly without a full re-sync.
        $sql = 'DELETE FROM ' . $user_group_table . '
            WHERE user_id = ' . (int) $phpbb_id . "
                AND group_id NOT IN (
                    SELECT group_id FROM " . $groups_table . "
                    WHERE group_name = 'REGISTERED'
                )";
        $this->db->sql_query($sql);

        // Step 5: kill all active sessions so the ban takes effect immediately.
        $sql = 'DELETE FROM ' . $sessions_table . '
            WHERE session_user_id = ' . (int) $phpbb_id;
        $this->db->sql_query($sql);
    }

    /**
     * DELETE /admin/users/{sub}/ban -> 204. Lifts a ban by Casdoor subject.
     *
     * Idempotent: if the user is not banned the steps are no-ops. Steps:
     *  1. Resolve phpBB user_id from casdoor_map (404 if unmapped).
     *  2. DELETE from phpbb_banlist.
     *  3. Restore user_type = 0 (USER_NORMAL) on phpbb_users.
     *  4. Ensure REGISTERED group membership (floor) via INSERT … ON CONFLICT DO NOTHING.
     *  5. Kill stale sessions to force a clean re-login.
     *
     * Higher-role groups (ADMINISTRATORS / GLOBAL_MODERATORS) are NOT restored here;
     * admin-bff issues a set_groups sync after unban to restore them if appropriate.
     *
     * @param string $casdoor_sub
     * @return void
     *
     * @throws \RuntimeException 404 if no phpBB user is mapped to that sub.
     */
    public function unban_user($casdoor_sub)
    {
        // Step 1: resolve phpBB user_id.
        $phpbb_id = $this->user_mapper->find_user_id((string) $casdoor_sub);
        if ($phpbb_id === null)
        {
            throw \snapdragon\jsonapi\auth\auth_exception::not_found('No phpBB user mapped for that subject.');
        }

        $banlist_table    = $this->table_prefix . 'banlist';
        $users_table      = $this->table_prefix . 'users';
        $user_group_table = $this->table_prefix . 'user_group';
        $groups_table     = $this->table_prefix . 'groups';
        $sessions_table   = $this->table_prefix . 'sessions';

        // Step 2: remove all ban entries for this user (there should be at most one,
        // but we delete by user_id so the operation is safe even if multiple exist).
        $sql = 'DELETE FROM ' . $banlist_table . '
            WHERE ban_userid = ' . (int) $phpbb_id;
        $this->db->sql_query($sql);

        // Step 3: restore user_type = 0 (USER_NORMAL).
        $sql = 'UPDATE ' . $users_table . '
            SET user_type = 0
            WHERE user_id = ' . (int) $phpbb_id;
        $this->db->sql_query($sql);

        // Step 4: ensure REGISTERED group membership as the floor.
        // INSERT … SELECT avoids a hard-coded group_id and is idempotent via ON CONFLICT.
        $sql = 'INSERT INTO ' . $user_group_table . ' (group_id, user_id, user_pending)
            SELECT group_id, ' . (int) $phpbb_id . ", 0
            FROM " . $groups_table . "
            WHERE group_name = 'REGISTERED'
            ON CONFLICT DO NOTHING";
        $this->db->sql_query($sql);

        // Step 5: kill stale sessions so the user gets a clean re-login after unban.
        $sql = 'DELETE FROM ' . $sessions_table . '
            WHERE session_user_id = ' . (int) $phpbb_id;
        $this->db->sql_query($sql);
    }

    /**
     * POST /admin/categories -> Category. Creates a postable phpBB forum.
     *
     * @param string $name
     * @param string $description
     * @param int    $parent_id The parent forum/category id (0 = top level).
     * @return array Category{id,name,description,topicCount}.
     */
    public function create_category($name, $description, $parent_id)
    {
        // Use the acp forum manager for nested-set correctness.
        if (!class_exists('\acp_forums'))
        {
            include($this->root_path . 'includes/acp/acp_forums.' . $this->php_ext);
        }
        // acp_forums::update_forum_data() calls validate_range() (and other ACP helpers)
        // from includes/functions_acp.php, which is NOT autoloaded in the headless API
        // request path — without it the call fatals with "Call to undefined function
        // validate_range()". The phpBB request globals it also relies on ($db/$user/$cache/
        // $phpbb_container/$phpbb_dispatcher/$phpbb_log/$request) ARE populated inside an
        // app.php request, so loading this function file is the only missing dependency.
        if (!function_exists('validate_range'))
        {
            include($this->root_path . 'includes/functions_acp.' . $this->php_ext);
        }

        $forum_post = defined('FORUM_POST') ? FORUM_POST : 1;

        // acp_forums::update_forum_data() reads the FULL forum_data key set that the ACP
        // "add forum" form posts; any missing key raises "Undefined array key" warnings
        // (which emit output before headers and 500 the JSON response). We must seed every
        // key it touches with the same defaults the ACP form uses (acp_forums.php "add").
        $forum_data = [
            'parent_id'               => (int) $parent_id,
            'forum_type'              => (int) $forum_post,
            'type_action'             => '',
            'forum_status'            => defined('ITEM_UNLOCKED') ? ITEM_UNLOCKED : 0,
            'forum_parents'           => '',
            'forum_name'              => (string) $name,
            'forum_link'              => '',
            'forum_link_track'        => false,
            'forum_desc'              => (string) $description,
            'forum_desc_uid'          => '',
            'forum_desc_options'      => 7,
            'forum_desc_bitfield'     => '',
            'forum_rules'             => '',
            'forum_rules_uid'         => '',
            'forum_rules_options'     => 7,
            'forum_rules_bitfield'    => '',
            'forum_rules_link'        => '',
            'forum_image'             => '',
            'forum_style'             => 0,
            'display_subforum_list'   => true,
            'display_subforum_limit'  => false,
            'display_on_index'        => true,
            'forum_topics_per_page'   => 0,
            'enable_indexing'         => true,
            'enable_icons'            => true,
            'enable_prune'            => false,
            'enable_post_review'      => true,
            'enable_quick_reply'      => false,
            'enable_shadow_prune'     => false,
            'show_active'             => false,
            'prune_days'              => 7,
            'prune_viewed'            => 7,
            'prune_freq'              => 1,
            'prune_old_polls'         => false,
            'prune_announce'          => false,
            'prune_sticky'            => false,
            'prune_shadow_days'       => 7,
            'prune_shadow_freq'       => 1,
            'forum_password'          => '',
            'forum_password_confirm'  => '',
            'forum_password_unset'    => false,
        ];

        $manager = new \acp_forums();
        // update_forum_data() returns an array of errors (empty on success) and writes
        // forum_id back into $forum_data by reference.
        $errors = $manager->update_forum_data($forum_data);

        if (!empty($errors))
        {
            throw new \RuntimeException('Snapdragon JSON API: forum create failed: ' . implode('; ', (array) $errors));
        }

        $forum_id = (int) $forum_data['forum_id'];

        return [
            'id'          => $forum_id,
            'name'        => (string) $name,
            'description' => (string) $description,
            'topicCount'  => 0,
        ];
    }

    /**
     * POST /admin/styles (["name"]) -> 204. Installs styles by directory name via the
     * phpBB style acp helper.
     *
     * @param array<string> $names
     * @return void
     */
    public function install_styles(array $names)
    {
        if (!class_exists('\acp_styles'))
        {
            include($this->root_path . 'includes/acp/acp_styles.' . $this->php_ext);
        }
        // The acp_styles installer scans the styles/ dir; we trigger an install per name.
        // Implementation note: phpBB's style install is ACP-form driven; the headless
        // path here registers the style row for an already-present styles/<name>/ dir.
        foreach ($names as $name)
        {
            $name = trim((string) $name);
            if ($name === '')
            {
                continue;
            }
            $this->register_style($name);
        }
    }

    /**
     * POST /admin/extensions (["name"]) -> 204. Enables extensions via the extension
     * manager (composer-name keyed, e.g. "vendor/package").
     *
     * @param array<string> $names
     * @return void
     */
    public function enable_extensions(array $names)
    {
        if ($this->container === null)
        {
            throw new \RuntimeException('Snapdragon JSON API: no container for extension manager.');
        }
        /** @var \phpbb\extension\manager $ext_manager */
        $ext_manager = $this->container->get('ext.manager');

        foreach ($names as $name)
        {
            $name = trim((string) $name);
            if ($name === '')
            {
                continue;
            }
            if (!$ext_manager->is_enabled($name))
            {
                $ext_manager->enable($name);
            }
        }
    }

    /**
     * Register a style row for a styles/<name>/ directory if not already present.
     *
     * @param string $name
     * @return void
     */
    private function register_style($name)
    {
        $sql = 'SELECT style_id FROM ' . $this->styles_table() . "
            WHERE style_path = '" . $this->db->sql_escape($name) . "'";
        $result = $this->db->sql_query($sql);
        $exists = (bool) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        if ($exists)
        {
            return;
        }

        $row = [
            'style_name'      => $name,
            'style_copyright' => '',
            'style_active'    => 1,
            'style_path'      => $name,
            'bbcode_bitfield' => 'kNg=',
            'style_parent_id' => 0,
            'style_parent_tree' => '',
        ];
        $sql = 'INSERT INTO ' . $this->styles_table() . ' ' . $this->db->sql_build_array('INSERT', $row);
        $this->db->sql_query($sql);
    }

    private function styles_table()
    {
        return defined('STYLES_TABLE') ? STYLES_TABLE : $this->table_prefix . 'styles';
    }
}
