<?php
/**
 * HugMesh Forum API — transaction + idempotency ledger for @system private messages.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\service;

/**
 * The only class that touches the {@code hugmesh_system_pm_idem} table (migration m2).
 *
 * Every query runs with phpBB's {@code sql_return_on_error(true)} so a database error
 * becomes a PHP exception the controller guard turns into a JSON 500, instead of
 * phpBB's HTML error page. A failed INSERT is NOT assumed to be a duplicate: the caller
 * re-reads the row and treats "no row" as an error (fail closed, nothing sent).
 */
class system_pm_store
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
        $this->table_prefix = (string) $table_prefix;
    }

    /**
     * @return string
     */
    private function table()
    {
        return $this->table_prefix . 'hugmesh_system_pm_idem';
    }

    public function begin()
    {
        $this->db->sql_transaction('begin');
    }

    public function commit()
    {
        $this->db->sql_transaction('commit');
    }

    public function rollback()
    {
        $this->db->sql_transaction('rollback');
    }

    /**
     * The ledger row for an idempotency hash, or null.
     *
     * @param string $idem_hash
     * @return array{request_hash: string, msg_id: int}|null
     *
     * @throws \RuntimeException on a database error.
     */
    public function find($idem_hash)
    {
        $sql = 'SELECT request_hash, msg_id FROM ' . $this->table() . "
            WHERE idem_hash = '" . $this->db->sql_escape((string) $idem_hash) . "'";
        $result = $this->query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return null;
        }
        return ['request_hash' => (string) $row['request_hash'], 'msg_id' => (int) $row['msg_id']];
    }

    /**
     * Insert the ledger row (msg_id 0) inside the open transaction. On a database with a
     * concurrent holder of the same key, the UNIQUE index makes this wait for that
     * transaction and then fail.
     *
     * @param string $idem_hash
     * @param string $request_hash
     * @return bool false when the INSERT failed (a duplicate, or any other DB error).
     */
    public function reserve($idem_hash, $request_hash)
    {
        $sql = 'INSERT INTO ' . $this->table() . ' ' . $this->db->sql_build_array('INSERT', [
            'idem_hash'    => (string) $idem_hash,
            'request_hash' => (string) $request_hash,
            'msg_id'       => 0,
            'created_at'   => time(),
        ]);
        $this->db->sql_return_on_error(true);
        try
        {
            return $this->db->sql_query($sql) !== false;
        }
        finally
        {
            $this->db->sql_return_on_error(false);
        }
    }

    /**
     * Record the message the reserved key produced.
     *
     * @param string $idem_hash
     * @param int    $msg_id
     * @return void
     *
     * @throws \RuntimeException on a database error.
     */
    public function complete($idem_hash, $msg_id)
    {
        $sql = 'UPDATE ' . $this->table() . '
            SET msg_id = ' . (int) $msg_id . "
            WHERE idem_hash = '" . $this->db->sql_escape((string) $idem_hash) . "'";
        $this->query($sql);
    }

    /**
     * @param string $sql
     * @return mixed the driver's result handle.
     *
     * @throws \RuntimeException when the driver reports an error.
     */
    private function query($sql)
    {
        $this->db->sql_return_on_error(true);
        try
        {
            $result = $this->db->sql_query($sql);
        }
        finally
        {
            $this->db->sql_return_on_error(false);
        }
        if ($result === false)
        {
            throw new \RuntimeException('HugMesh Forum API: system PM ledger query failed.');
        }
        return $result;
    }
}
