<?php
/**
 * Unit tests for admin_service::ban_user() and admin_service::unban_user().
 *
 * Runs WITHOUT a live phpBB. The phpBB $db driver is replaced by a recording fake that
 * captures every sql_query() call in order. The user_mapper dependency is replaced by a
 * stub subclass whose find_user_id() returns a controlled value (or null for not-found).
 *
 * Test cases:
 *  1. ban_user_addsTobanlistAndSetsUserIgnore        — fresh ban: INSERT + UPDATE users + DELETE groups + DELETE sessions
 *  2. ban_user_idempotent_whenAlreadyBanned          — repeat ban: UPDATE banlist (not INSERT)
 *  3. ban_user_throwsNotFound_whenSubUnknown         — 404 auth_exception when sub unmapped
 *  4. unban_user_removesFromBanlistAndRestoresNormal — DELETE ban + UPDATE users + INSERT REGISTERED + DELETE sessions
 *  5. unban_user_throwsNotFound_whenSubUnknown       — 404 auth_exception when sub unmapped
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

// phpBB's \phpbb\config\config is not a composer dependency (it ships only inside the
// phpBB source tree). We define a minimal stub here so admin_service's typed constructor
// parameter ($config) is satisfied without a live phpBB install.
// Bracketed namespace block so the file can mix namespace declarations cleanly.
namespace phpbb\config
{
    if (!class_exists('\phpbb\config\config'))
    {
        /** Minimal phpBB config stub: just an ArrayAccess over an internal array. */
        class config implements \ArrayAccess
        {
            /** @var array */
            private $data = [];

            public function offsetExists($offset): bool       { return isset($this->data[$offset]); }
            public function offsetGet($offset): mixed         { return $this->data[$offset] ?? null; }
            public function offsetSet($offset, $value): void  { $this->data[$offset] = $value; }
            public function offsetUnset($offset): void        { unset($this->data[$offset]); }
        }
    }
}

namespace snapdragon\jsonapi\tests
{
    use PHPUnit\Framework\TestCase;
    use snapdragon\jsonapi\service\admin_service;
    use snapdragon\jsonapi\auth\auth_exception;

    /**
     * Recording fake phpBB DB driver.
     *
     * Tracks every sql_query() call in order. sql_fetchrow() is seeded per-call via
     * $fetch_sequence: each entry is consumed FIFO and returned by the next sql_fetchrow().
     * An entry of false means "no row found".
     */
    class ban_recording_db
    {
        /** @var string[] SQL strings in the order sql_query() was called. */
        public $queries = [];

        /**
         * Pre-loaded fetch results, consumed FIFO. Each element is the array or false
         * that the next sql_fetchrow() call returns after a sql_query().
         *
         * @var array<int, array|false>
         */
        public $fetch_sequence = [];

        /** @var array|false The row queued for the pending sql_fetchrow(). */
        private $pending_row = false;

        public function sql_escape($str)
        {
            // Minimal escaping sufficient for test SQL assertions (no live DB).
            return str_replace("'", "\\'", (string) $str);
        }

        public function sql_query($sql)
        {
            $this->queries[] = (string) $sql;
            // Consume the next pre-seeded fetch row for this query.
            $this->pending_row = !empty($this->fetch_sequence) ? array_shift($this->fetch_sequence) : false;
            return 'rs';
        }

        public function sql_fetchrow($result)
        {
            $row               = $this->pending_row;
            $this->pending_row = false;
            return $row;
        }

        public function sql_freeresult($result)
        {
            // no-op
        }

        public function sql_build_array($mode, array $data)
        {
            // Produce a deterministic INSERT column-list/values string for assertions.
            $cols = implode(', ', array_keys($data));
            $vals = implode(', ', array_map(function ($v) {
                return is_int($v) ? (string) $v : "'" . $this->sql_escape((string) $v) . "'";
            }, array_values($data)));
            return '(' . $cols . ') VALUES (' . $vals . ')';
        }
    }

    /**
     * Stub user_mapper: extends the real class so the typed constructor param on
     * admin_service is satisfied, but overrides find_user_id() to return a controlled value
     * without hitting the DB.
     *
     * Mirrors the probe-subclass pattern used in user_mapper_test.php.
     */
    class ban_user_mapper_stub extends \snapdragon\jsonapi\service\user_mapper
    {
        /** @var int|null */
        private $stub_user_id;

        public function __construct($user_id)
        {
            // Parent: ($db, $config, $table_prefix, $root_path, $php_ext).
            // ban_user/unban_user only call find_user_id(), which we override, so
            // the parent's DB/config injections are never reached.
            parent::__construct(null, null, 'phpbb_', '/nonexistent/', 'php');
            $this->stub_user_id = $user_id;
        }

        public function find_user_id($sub)
        {
            return $this->stub_user_id;
        }
    }

    class admin_ban_test extends TestCase
    {
        private const TABLE_PREFIX = 'phpbb_';
        private const PHPBB_ID     = 42;

        /**
         * Build an admin_service wired with the given recording db and mapper stub.
         *
         * ban_user/unban_user do not call forum_repository or phpbb\config methods, so we
         * supply minimal stubs that satisfy the typed constructor parameters.
         *
         * @param ban_recording_db     $db
         * @param ban_user_mapper_stub $mapper
         * @return admin_service
         */
        private function make_service(ban_recording_db $db, ban_user_mapper_stub $mapper): admin_service
        {
            // forum_repository($db, $table_prefix) — db param is untyped, null is fine here.
            $repo   = new \snapdragon\jsonapi\service\forum_repository(null, self::TABLE_PREFIX);
            $config = new \phpbb\config\config();

            return new admin_service(
                $db,
                $mapper,
                $repo,
                $config,
                null,   // container — not reached by ban/unban
                self::TABLE_PREFIX,
                '/nonexistent/',
                'php'
            );
        }

        // ── helpers ──────────────────────────────────────────────────────────

        /** DB where the SELECT ban_id query yields NO existing ban row. */
        private function db_no_existing_ban(): ban_recording_db
        {
            $db = new ban_recording_db();
            // sql_fetchrow() after the first SELECT ban_id returns false (no existing ban).
            $db->fetch_sequence = [false];
            return $db;
        }

        /** DB where the SELECT ban_id query yields an existing ban row. */
        private function db_existing_ban(): ban_recording_db
        {
            $db = new ban_recording_db();
            // sql_fetchrow() after SELECT ban_id returns a row (ban already exists).
            $db->fetch_sequence = [['ban_id' => 7]];
            return $db;
        }

        private function known_mapper(): ban_user_mapper_stub
        {
            return new ban_user_mapper_stub(self::PHPBB_ID);
        }

        private function unknown_mapper(): ban_user_mapper_stub
        {
            return new ban_user_mapper_stub(null);
        }

        // ── ban_user tests ───────────────────────────────────────────────────

        /**
         * Fresh ban (no existing banlist entry):
         * Q0: SELECT ban_id (returns false — no existing ban)
         * Q1: INSERT INTO phpbb_banlist
         * Q2: UPDATE phpbb_users SET user_type=3  (USER_IGNORE)
         * Q3: DELETE FROM phpbb_user_group  (non-REGISTERED groups)
         * Q4: DELETE FROM phpbb_sessions
         */
        public function test_ban_user_addsTobanlistAndSetsUserIgnore(): void
        {
            $db      = $this->db_no_existing_ban();
            $service = $this->make_service($db, $this->known_mapper());

            $service->ban_user('sub-abc', 'Spam');

            $this->assertCount(5, $db->queries, 'ban_user should issue exactly 5 SQL statements');

            // Q0: SELECT to check for an existing ban entry.
            $this->assertStringContainsString('SELECT ban_id', $db->queries[0]);
            $this->assertStringContainsString('phpbb_banlist', $db->queries[0]);
            $this->assertStringContainsString('ban_userid = ' . self::PHPBB_ID, $db->queries[0]);

            // Q1: INSERT because no existing ban was found.
            $this->assertStringContainsString('INSERT INTO phpbb_banlist', $db->queries[1]);
            $this->assertStringContainsString('ban_userid', $db->queries[1]);
            $this->assertStringContainsString('ban_start', $db->queries[1]);
            $this->assertStringContainsString('ban_reason', $db->queries[1]);

            // Q2: set user_type = 3 (USER_IGNORE).
            $this->assertStringContainsString('UPDATE phpbb_users', $db->queries[2]);
            $this->assertStringContainsString('user_type = 3', $db->queries[2]);
            $this->assertStringContainsString('user_id = ' . self::PHPBB_ID, $db->queries[2]);

            // Q3: remove non-REGISTERED group memberships.
            $this->assertStringContainsString('DELETE FROM phpbb_user_group', $db->queries[3]);
            $this->assertStringContainsString('user_id = ' . self::PHPBB_ID, $db->queries[3]);
            $this->assertStringContainsString("group_name = 'REGISTERED'", $db->queries[3]);

            // Q4: kill all active sessions.
            $this->assertStringContainsString('DELETE FROM phpbb_sessions', $db->queries[4]);
            $this->assertStringContainsString('session_user_id = ' . self::PHPBB_ID, $db->queries[4]);
        }

        /**
         * Idempotent ban (user already in banlist):
         * SELECT returns an existing row → we UPDATE the banlist entry instead of INSERTing.
         * Must return cleanly (no exception).
         */
        public function test_ban_user_idempotent_whenAlreadyBanned(): void
        {
            $db      = $this->db_existing_ban();
            $service = $this->make_service($db, $this->known_mapper());

            // Must not throw.
            $service->ban_user('sub-abc', 'Repeat ban');

            // Still exactly 5 queries (SELECT + UPDATE banlist + UPDATE users + DELETE groups + DELETE sessions).
            $this->assertCount(5, $db->queries);

            // Q0: SELECT.
            $this->assertStringContainsString('SELECT ban_id', $db->queries[0]);

            // Q1: UPDATE banlist (NOT INSERT because ban already exists).
            $this->assertStringContainsString('UPDATE phpbb_banlist', $db->queries[1]);
            $this->assertStringContainsString('ban_reason', $db->queries[1]);
            $this->assertStringContainsString('ban_start', $db->queries[1]);
            $this->assertStringNotContainsString('INSERT', $db->queries[1]);

            // Q2–Q4 are identical to the fresh-ban path.
            $this->assertStringContainsString('UPDATE phpbb_users', $db->queries[2]);
            $this->assertStringContainsString('user_type = 3', $db->queries[2]);
            $this->assertStringContainsString('DELETE FROM phpbb_user_group', $db->queries[3]);
            $this->assertStringContainsString('DELETE FROM phpbb_sessions', $db->queries[4]);
        }

        /**
         * ban_user throws a 404 auth_exception when the Casdoor sub has no phpBB mapping.
         * No DB queries should be issued (early exit after mapper miss).
         */
        public function test_ban_user_throwsNotFound_whenSubUnknown(): void
        {
            $db      = new ban_recording_db();
            $service = $this->make_service($db, $this->unknown_mapper());

            $this->expectException(auth_exception::class);

            try
            {
                $service->ban_user('sub-unknown', '');
            }
            catch (auth_exception $e)
            {
                $this->assertSame(404, $e->get_status_code(), 'Should be a 404 Not Found');
                $this->assertEmpty($db->queries, 'No SQL should be issued when sub is unmapped');
                throw $e;
            }
        }

        // ── unban_user tests ─────────────────────────────────────────────────

        /**
         * Unban: lifts the ban, restores USER_NORMAL, and ensures REGISTERED floor.
         * Q0: DELETE FROM phpbb_banlist
         * Q1: UPDATE phpbb_users SET user_type=0  (USER_NORMAL)
         * Q2: INSERT INTO phpbb_user_group … SELECT REGISTERED … ON CONFLICT DO NOTHING
         * Q3: DELETE FROM phpbb_sessions
         */
        public function test_unban_user_removesFromBanlistAndRestoresNormal(): void
        {
            $db      = new ban_recording_db();
            $service = $this->make_service($db, $this->known_mapper());

            $service->unban_user('sub-abc');

            $this->assertCount(4, $db->queries, 'unban_user should issue exactly 4 SQL statements');

            // Q0: remove all ban entries for this user.
            $this->assertStringContainsString('DELETE FROM phpbb_banlist', $db->queries[0]);
            $this->assertStringContainsString('ban_userid = ' . self::PHPBB_ID, $db->queries[0]);

            // Q1: restore USER_NORMAL (user_type = 0).
            $this->assertStringContainsString('UPDATE phpbb_users', $db->queries[1]);
            $this->assertStringContainsString('user_type = 0', $db->queries[1]);
            $this->assertStringContainsString('user_id = ' . self::PHPBB_ID, $db->queries[1]);

            // Q2: ensure REGISTERED group membership as the floor.
            $this->assertStringContainsString('INSERT INTO phpbb_user_group', $db->queries[2]);
            $this->assertStringContainsString("group_name = 'REGISTERED'", $db->queries[2]);
            $this->assertStringContainsString('ON CONFLICT DO NOTHING', $db->queries[2]);

            // Q3: kill stale sessions (force re-login after unban).
            $this->assertStringContainsString('DELETE FROM phpbb_sessions', $db->queries[3]);
            $this->assertStringContainsString('session_user_id = ' . self::PHPBB_ID, $db->queries[3]);
        }

        /**
         * unban_user throws a 404 auth_exception when the Casdoor sub has no phpBB mapping.
         * No DB queries should be issued (early exit after mapper miss).
         */
        public function test_unban_user_throwsNotFound_whenSubUnknown(): void
        {
            $db      = new ban_recording_db();
            $service = $this->make_service($db, $this->unknown_mapper());

            $this->expectException(auth_exception::class);

            try
            {
                $service->unban_user('sub-unknown');
            }
            catch (auth_exception $e)
            {
                $this->assertSame(404, $e->get_status_code(), 'Should be a 404 Not Found');
                $this->assertEmpty($db->queries, 'No SQL should be issued when sub is unmapped');
                throw $e;
            }
        }
    }
}
