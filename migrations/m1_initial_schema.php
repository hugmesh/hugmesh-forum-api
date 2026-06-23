<?php
/**
 * Snapdragon JSON API — initial schema migration.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi\migrations;

/**
 * Creates the Casdoor-subject -> phpBB user_id mapping table.
 *
 * We use a SIDE TABLE ({@code phpbb_snapdragon_casdoor_map}) rather than a column on
 * {@code phpbb_users} so the mapping is fully owned by this extension (clean
 * install/uninstall, no core-table coupling, unique-keyed by the immutable Casdoor
 * {@code sub}). The mapping is resolved-or-created on {@code POST /users/{sub}/ensure}
 * and lazily before any acts-as-user write.
 */
class m1_initial_schema extends \phpbb\db\migration\migration
{
    /**
     * Skip if the table already exists (idempotent re-run safety).
     *
     * @return bool
     */
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'snapdragon_casdoor_map');
    }

    /**
     * Depend on a real 3.3 base migration so the migrator orders us correctly.
     *
     * @return array
     */
    public static function depends_on()
    {
        return ['\phpbb\db\migration\data\v330\v330'];
    }

    /**
     * @return array
     */
    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'snapdragon_casdoor_map' => [
                    'COLUMNS' => [
                        'map_id'        => ['UINT', null, 'auto_increment'],
                        'casdoor_sub'   => ['VCHAR:255', ''],
                        'user_id'       => ['UINT', 0],
                        'casdoor_email' => ['VCHAR:255', ''],
                        'created_at'    => ['TIMESTAMP', 0],
                        'last_synced'   => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'map_id',
                    'KEYS' => [
                        // The Casdoor sub is the immutable identity key — unique.
                        'casdoor_sub' => ['UNIQUE', 'casdoor_sub'],
                        'user_id'     => ['INDEX', 'user_id'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array
     */
    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'snapdragon_casdoor_map',
            ],
        ];
    }
}
