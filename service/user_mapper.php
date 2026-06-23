<?php
/**
 * Snapdragon JSON API — Casdoor sub -> phpBB user resolution / creation / group sync.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi\service;

use snapdragon\jsonapi\auth\principal;

/**
 * Maps the immutable Casdoor {@code sub} to a phpBB {@code user_id} via the
 * {@code phpbb_snapdragon_casdoor_map} side table, lazily creating the phpBB user with
 * {@code user_add()} on first use. Also performs the DECLARATIVE group convergence for
 * {@code PUT /users/{sub}/groups}.
 *
 * phpBB's procedural {@code user_add()} / group functions are pulled in from
 * {@code includes/functions_user.php} using the injected root path + php_ext.
 */
class user_mapper
{
    /**
     * The phpBB special groups this extension ADDS/REMOVES declaratively. Group sync
     * removes a user from any of THESE it should no longer be in (never touches groups
     * outside this managed set, so manual phpBB group membership is preserved).
     *
     * REGISTERED is DELIBERATELY EXCLUDED: it is the default group for every SSO user and
     * the home of their default group_id. Treating it as removable made converge_groups()
     * strip a user out of REGISTERED whenever converging to ADMINISTRATORS /
     * GLOBAL_MODERATORS / NONE, orphaning their default group. REGISTERED is an
     * always-present floor (see {@see REGISTERED_GROUP}), never toggled.
     */
    public const MANAGED_GROUPS = ['ADMINISTRATORS', 'GLOBAL_MODERATORS'];

    /**
     * The default group every SSO user must always remain in. Never removed by group sync.
     */
    public const REGISTERED_GROUP = 'REGISTERED';

    /**
     * @var \phpbb\db\driver\driver_interface
     */
    private $db;

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
     * @param \phpbb\config\config              $config
     * @param string                            $table_prefix
     * @param string                            $root_path
     * @param string                            $php_ext
     */
    public function __construct($db, $config, $table_prefix, $root_path, $php_ext)
    {
        $this->db = $db;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
        $this->root_path = $root_path;
        $this->php_ext = $php_ext;
    }

    /**
     * @return string The fully-qualified mapping table name.
     */
    private function map_table()
    {
        return $this->table_prefix . 'snapdragon_casdoor_map';
    }

    /**
     * Look up an existing phpBB user_id for a Casdoor sub.
     *
     * @param string $sub
     * @return int|null The user_id, or null if unmapped.
     */
    public function find_user_id($sub)
    {
        $sql = 'SELECT user_id FROM ' . $this->map_table() . "
            WHERE casdoor_sub = '" . $this->db->sql_escape((string) $sub) . "'";
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * Resolve the phpBB user_id for a principal, creating the phpBB user + mapping row on
     * first use. This is the "ensure" operation; it is also called lazily before any
     * acts-as-user write.
     *
     * @param principal $p
     * @return int The phpBB user_id.
     */
    public function ensure_user(principal $p)
    {
        $existing = $this->find_user_id($p->get_subject());
        if ($existing !== null)
        {
            return $existing;
        }

        $user_id = $this->create_phpbb_user($p);

        $insert = [
            'casdoor_sub'   => (string) $p->get_subject(),
            'user_id'       => (int) $user_id,
            'casdoor_email' => (string) $p->get_email(),
            'created_at'    => time(),
            'last_synced'   => time(),
        ];
        $sql = 'INSERT INTO ' . $this->map_table() . ' ' . $this->db->sql_build_array('INSERT', $insert);
        $this->db->sql_query($sql);

        return (int) $user_id;
    }

    /**
     * Create a minimal phpBB user via user_add(), placed in REGISTERED by default. SSO is
     * the auth path, so the local password is empty (login is never done against phpBB).
     *
     * @param principal $p
     * @return int The new user_id.
     */
    private function create_phpbb_user(principal $p)
    {
        if (!function_exists('user_add'))
        {
            include($this->root_path . 'includes/functions_user.' . $this->php_ext);
        }

        $registered_group_id = $this->group_id('REGISTERED');

        // USER_NORMAL = 0 (includes/constants.php).
        $user_type = defined('USER_NORMAL') ? USER_NORMAL : 0;

        $user_row = [
            'username'       => $this->unique_username($p),
            'user_password'  => '',
            'user_email'     => (string) $p->get_email(),
            'group_id'       => (int) $registered_group_id,
            'user_type'      => $user_type,
            'user_regdate'   => time(),
            'user_ip'        => '',
            'user_lang'      => isset($this->config['default_lang']) ? $this->config['default_lang'] : 'en',
            'user_timezone'  => isset($this->config['board_timezone']) ? $this->config['board_timezone'] : 'UTC',
            'user_dateformat'=> isset($this->config['default_dateformat']) ? $this->config['default_dateformat'] : 'D M d, Y g:i a',
            'user_style'     => isset($this->config['default_style']) ? (int) $this->config['default_style'] : 1,
        ];

        $user_id = user_add($user_row);

        if (!$user_id)
        {
            throw new \RuntimeException('Snapdragon JSON API: user_add() failed for sub ' . $p->get_subject());
        }

        return (int) $user_id;
    }

    /**
     * Derive a phpBB username that is unique and stable per sub. We prefer the token's
     * display name, falling back to a sub-derived slug, and de-duplicate with a numeric
     * suffix if the username is already taken (phpBB usernames must be unique).
     *
     * @param principal $p
     * @return string
     */
    private function unique_username(principal $p)
    {
        $base = trim($p->get_display_name());
        if ($base === '')
        {
            $base = 'casdoor_' . substr(preg_replace('/[^A-Za-z0-9_]/', '', $p->get_subject()), 0, 24);
        }
        // phpBB usernames are limited; keep within a safe length.
        $base = substr($base, 0, 240);

        $candidate = $base;
        $suffix = 0;
        while ($this->username_exists($candidate))
        {
            $suffix++;
            $candidate = substr($base, 0, 240 - strlen((string) $suffix) - 1) . '_' . $suffix;
        }
        return $candidate;
    }

    /**
     * @param string $username
     * @return bool
     */
    private function username_exists($username)
    {
        $sql = 'SELECT user_id FROM ' . $this->users_table() . "
            WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($username)) . "'";
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (bool) $row;
    }

    /**
     * DECLARATIVE group convergence: the user ends up in EXACTLY the target managed
     * groups (ADMINISTRATORS / GLOBAL_MODERATORS) derived from the desired group name,
     * and is removed from any OTHER managed group it currently sits in. Non-managed group
     * memberships are untouched.
     *
     * REGISTERED is the always-present floor and is NEVER removed — when converging to
     * ADMINISTRATORS, GLOBAL_MODERATORS, REGISTERED, or NONE, the user is (re)added to
     * REGISTERED so their default group is never orphaned.
     *
     * @param int    $user_id
     * @param string $target_group The phpBB group name to converge to (ADMINISTRATORS,
     *                             GLOBAL_MODERATORS, REGISTERED), or 'NONE'/anything else to
     *                             remove from all elevated managed groups (still REGISTERED).
     * @return void
     */
    public function converge_groups($user_id, $target_group)
    {
        if (!function_exists('group_user_add'))
        {
            include($this->root_path . 'includes/functions_user.' . $this->php_ext);
        }

        $target_group = strtoupper(trim((string) $target_group));
        $desired = in_array($target_group, self::MANAGED_GROUPS, true) ? [$target_group] : [];

        // Only ADD/REMOVE the elevated managed groups; REGISTERED is the floor below.
        foreach (self::MANAGED_GROUPS as $group_name)
        {
            $group_id = $this->group_id($group_name);
            if ($group_id <= 0)
            {
                continue;
            }

            if (in_array($group_name, $desired, true))
            {
                group_user_add($group_id, [(int) $user_id]);
            }
            else
            {
                group_user_del($group_id, [(int) $user_id]);
            }
        }

        // REGISTERED floor: always ensure membership, never remove it. group_user_add is
        // idempotent for an existing member, so this safely re-asserts the default group.
        $registered_id = $this->group_id(self::REGISTERED_GROUP);
        if ($registered_id > 0)
        {
            group_user_add($registered_id, [(int) $user_id]);
        }
    }

    /**
     * Resolve a special-group group_id by name.
     *
     * @param string $group_name e.g. ADMINISTRATORS, GLOBAL_MODERATORS, REGISTERED.
     * @return int The group_id, or 0 if not found.
     */
    public function group_id($group_name)
    {
        // GROUP_SPECIAL = 3 (includes/constants.php).
        $group_special = defined('GROUP_SPECIAL') ? GROUP_SPECIAL : 3;

        $sql = 'SELECT group_id FROM ' . $this->groups_table() . "
            WHERE group_name = '" . $this->db->sql_escape((string) $group_name) . "'
                AND group_type = " . (int) $group_special;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (int) $row['group_id'] : 0;
    }

    /**
     * The phpBB group name a user is highest-mapped to, for the ForumUser.group response.
     *
     * @param int $user_id
     * @return string ADMINISTRATORS | GLOBAL_MODERATORS | REGISTERED | NONE.
     */
    public function highest_group($user_id)
    {
        foreach (['ADMINISTRATORS', 'GLOBAL_MODERATORS', 'REGISTERED'] as $group_name)
        {
            $group_id = $this->group_id($group_name);
            if ($group_id > 0 && $this->in_group($user_id, $group_id))
            {
                return $group_name;
            }
        }
        return 'NONE';
    }

    /**
     * @param int $user_id
     * @param int $group_id
     * @return bool
     */
    private function in_group($user_id, $group_id)
    {
        $sql = 'SELECT user_id FROM ' . $this->table_prefix . 'user_group
            WHERE user_id = ' . (int) $user_id . '
                AND group_id = ' . (int) $group_id . '
                AND user_pending = 0';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (bool) $row;
    }

    /**
     * @return string USERS_TABLE (constant when board is loaded, else prefixed name).
     */
    private function users_table()
    {
        return defined('USERS_TABLE') ? USERS_TABLE : $this->table_prefix . 'users';
    }

    /**
     * @return string GROUPS_TABLE.
     */
    private function groups_table()
    {
        return defined('GROUPS_TABLE') ? GROUPS_TABLE : $this->table_prefix . 'groups';
    }
}
