<?php
/**
 * HugMesh Forum API — idempotency ledger for the @system private-message route.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\migrations;

/**
 * One row per (sender, Idempotency-Key) that produced a private message. The row is
 * written in the SAME database transaction as submit_pm()'s inserts, so a committed row
 * always names a committed message and a rolled-back send leaves no row behind.
 *
 * {@code idem_hash} is sha256(sender sub + NUL + key): fixed width, so the UNIQUE index
 * fits every phpBB database driver whatever the key length. {@code request_hash} is
 * sha256 of the request body fields, so a key re-used for a DIFFERENT message is refused
 * instead of silently replaying the first one.
 */
class m2_system_pm_idempotency extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'hugmesh_system_pm_idem');
    }

    public static function depends_on()
    {
        return ['\hugmesh\forumapi\migrations\m1_initial_schema'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'hugmesh_system_pm_idem' => [
                    'COLUMNS' => [
                        'idem_id'      => ['UINT', null, 'auto_increment'],
                        'idem_hash'    => ['VCHAR:64', ''],
                        'request_hash' => ['VCHAR:64', ''],
                        'msg_id'       => ['UINT', 0],
                        'created_at'   => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'idem_id',
                    'KEYS' => [
                        'idem_hash' => ['UNIQUE', 'idem_hash'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'hugmesh_system_pm_idem',
            ],
        ];
    }
}
