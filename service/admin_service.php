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

        $forum_post = defined('FORUM_POST') ? FORUM_POST : 1;

        $forum_data = [
            'parent_id'              => (int) $parent_id,
            'forum_type'             => (int) $forum_post,
            'forum_name'             => (string) $name,
            'forum_desc'             => (string) $description,
            'forum_status'           => defined('ITEM_UNLOCKED') ? ITEM_UNLOCKED : 0,
            'forum_parents'          => '',
            'forum_password'         => '',
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
