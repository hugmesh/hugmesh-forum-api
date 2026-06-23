<?php
/**
 * Unit tests for user_mapper::converge_groups() — the DECLARATIVE phpBB group convergence
 * that must NEVER strip the REGISTERED floor.
 *
 * Runs WITHOUT a live phpBB. phpBB's procedural group_user_add() / group_user_del()
 * (normally pulled from includes/functions_user.php) are replaced by GLOBAL-namespace
 * fakes defined below that record every (group_id, [user_id]) call. Defining them in the
 * GLOBAL namespace makes the service's `function_exists('group_user_add')` (a global-name
 * lookup) return TRUE, so the service SKIPS its include() of functions_user.php, and the
 * service's unqualified group_user_add() calls fall through to these globals. The
 * group-name -> group_id resolution (a $db query) is stubbed in a probe subclass so the
 * assertions target the add/remove LOGIC, not SQL.
 *
 * These guard the fix "never strip the REGISTERED floor on group convergence": REGISTERED
 * is the always-present default group and must never be passed to the remove call, while
 * converging to ADMINISTRATORS / GLOBAL_MODERATORS / NONE must keep it.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace
{
    // GLOBAL phpBB-procedural shims. function_exists('group_user_add') sees these (global
    // lookup), so the service's include() is skipped; the service's unqualified calls fall
    // back here from its namespace. Each forwards into the static recorder the test reads.
    if (!function_exists('group_user_add'))
    {
        function group_user_add($group_id, $user_ids)
        {
            \snapdragon\jsonapi\tests\group_calls::$adds[] = ['group_id' => (int) $group_id, 'user_ids' => $user_ids];
        }
    }
    if (!function_exists('group_user_del'))
    {
        function group_user_del($group_id, $user_ids)
        {
            \snapdragon\jsonapi\tests\group_calls::$dels[] = ['group_id' => (int) $group_id, 'user_ids' => $user_ids];
        }
    }
}

namespace snapdragon\jsonapi\tests
{
    use PHPUnit\Framework\TestCase;
    use snapdragon\jsonapi\service\user_mapper;

    /**
     * Static recorder the global group_user_add/del shims forward into.
     */
    class group_calls
    {
        /** @var array<int, array{group_id:int, user_ids:array}> */
        public static $adds = [];

        /** @var array<int, array{group_id:int, user_ids:array}> */
        public static $dels = [];

        public static function reset(): void
        {
            self::$adds = [];
            self::$dels = [];
        }
    }

    /**
     * Probe that stubs group_id() with a fixed name -> id table so converge_groups()'s
     * add/remove logic is exercised without a $db query.
     */
    class user_mapper_probe extends user_mapper
    {
        /** Stable, distinct ids for the three special groups under test. */
        public const IDS = [
            'ADMINISTRATORS'    => 5,
            'GLOBAL_MODERATORS' => 6,
            'REGISTERED'        => 2,
        ];

        public function group_id($group_name)
        {
            return self::IDS[strtoupper((string) $group_name)] ?? 0;
        }
    }

    class user_mapper_test extends TestCase
    {
        protected function setUp(): void
        {
            group_calls::reset();
        }

        private function mapper(): user_mapper_probe
        {
            // $db is never reached: group_id() is stubbed and the procedural group fns are
            // shimmed, so a null db is sufficient for the constructor wiring.
            return new user_mapper_probe(null, null, 'phpbb_', '/nonexistent/', 'php');
        }

        private function admin_id(): int
        {
            return user_mapper_probe::IDS['ADMINISTRATORS'];
        }

        private function gmod_id(): int
        {
            return user_mapper_probe::IDS['GLOBAL_MODERATORS'];
        }

        private function registered_id(): int
        {
            return user_mapper_probe::IDS['REGISTERED'];
        }

        /** group_ids passed to the ADD call. */
        private function added_group_ids(): array
        {
            return array_column(group_calls::$adds, 'group_id');
        }

        /** group_ids passed to the REMOVE call. */
        private function removed_group_ids(): array
        {
            return array_column(group_calls::$dels, 'group_id');
        }

        // ── converge to ADMINISTRATORS ──────────────────────────────────────

        public function test_converge_to_administrators_adds_admin_and_keeps_registered(): void
        {
            $this->mapper()->converge_groups(77, 'ADMINISTRATORS');

            $added = $this->added_group_ids();
            // ADMINISTRATORS is added.
            $this->assertContains($this->admin_id(), $added);
            // REGISTERED is re-asserted (kept), never removed.
            $this->assertContains($this->registered_id(), $added);
            // GLOBAL_MODERATORS (not desired) is removed; REGISTERED is NOT.
            $this->assertContains($this->gmod_id(), $this->removed_group_ids());
            $this->assertNotContains($this->registered_id(), $this->removed_group_ids());
        }

        // ── converge to GLOBAL_MODERATORS ───────────────────────────────────

        public function test_converge_to_global_moderators_keeps_registered(): void
        {
            $this->mapper()->converge_groups(77, 'GLOBAL_MODERATORS');

            $this->assertContains($this->gmod_id(), $this->added_group_ids());
            $this->assertContains($this->registered_id(), $this->added_group_ids());
            // ADMINISTRATORS (not desired) removed; REGISTERED kept.
            $this->assertContains($this->admin_id(), $this->removed_group_ids());
            $this->assertNotContains($this->registered_id(), $this->removed_group_ids());
        }

        // ── converge to NONE ────────────────────────────────────────────────

        public function test_converge_to_none_removes_elevated_but_keeps_registered(): void
        {
            $this->mapper()->converge_groups(77, 'NONE');

            // Both elevated managed groups are removed.
            $removed = $this->removed_group_ids();
            $this->assertContains($this->admin_id(), $removed);
            $this->assertContains($this->gmod_id(), $removed);
            // REGISTERED is STILL added (kept) and NEVER removed.
            $this->assertContains($this->registered_id(), $this->added_group_ids());
            $this->assertNotContains($this->registered_id(), $removed);
        }

        // ── the floor invariant: REGISTERED is never passed to remove ───────

        public function test_registered_is_never_passed_to_group_remove(): void
        {
            foreach (['ADMINISTRATORS', 'GLOBAL_MODERATORS', 'NONE', 'REGISTERED'] as $target)
            {
                group_calls::reset();
                $this->mapper()->converge_groups(77, $target);
                $this->assertNotContains(
                    $this->registered_id(),
                    $this->removed_group_ids(),
                    "REGISTERED must never be removed when converging to {$target}"
                );
                // And it is always (re)asserted as a member.
                $this->assertContains(
                    $this->registered_id(),
                    $this->added_group_ids(),
                    "REGISTERED floor must be kept when converging to {$target}"
                );
            }
        }
    }
}
