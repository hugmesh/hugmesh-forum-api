<?php
/**
 * Unit tests for posting_service — the acts-as-user impersonation window + the post-data
 * shape that keeps submit_post() out of the moderation queue.
 *
 * Runs WITHOUT a live phpBB and WITHOUT the real submit_post(): the injected phpBB
 * services ($db / $user / $auth) are lightweight fakes, exactly as the JWT/guard tests
 * mock their injected collaborators. We exercise the two phpBB-mechanics fixes that were
 * previously only source-audited:
 *   - as_user(): session-flag derivation (is_registered / is_bot from user_type), ACL
 *     re-priming for the impersonated user INSIDE the window, and the UNCONDITIONAL
 *     restore of BOTH $user->data and $auth->acl in finally — even when the callback
 *     throws (the cross-request-identity defensive gap that was just closed);
 *   - base_post_data(): force_approved_state=1 and the DELIBERATE ABSENCE of any
 *     'force_visibility' key (re-introducing it silently forces every post unapproved).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi\tests;

use PHPUnit\Framework\TestCase;
use snapdragon\jsonapi\service\posting_service;

/**
 * A fake phpBB $user: just the public ->data property the impersonation window reads and
 * writes. (phpBB's \phpbb\user exposes the session row as a public array property.)
 */
class fake_user
{
    /** @var array|null */
    public $data;

    public function __construct($data = null)
    {
        $this->data = $data;
    }
}

/**
 * A fake phpBB $auth (ACL): records every acl() call so a test can assert WHICH identity
 * the ACL was primed/restored to, and in what order.
 */
class fake_auth
{
    /** @var array<int, mixed> The $user->data passed to each acl() call, in order. */
    public $acl_calls = [];

    public function acl($user_data)
    {
        // Snapshot the value (not a live reference) so later mutations don't rewrite history.
        $this->acl_calls[] = is_array($user_data) ? $user_data : $user_data;
    }
}

/**
 * A fake phpBB $db whose load_user_row() query returns a single, test-controlled row.
 * Only the three methods load_user_row() touches are implemented.
 */
class fake_db
{
    /** @var array|null The row sql_fetchrow() will yield once. */
    private $row;

    /** @var bool */
    private $fetched = false;

    public function __construct($row)
    {
        $this->row = $row;
    }

    public function sql_query($sql)
    {
        $this->fetched = false;
        return 'rs';
    }

    public function sql_fetchrow($result)
    {
        if ($this->fetched)
        {
            return false;
        }
        $this->fetched = true;
        return $this->row === null ? false : $this->row;
    }

    public function sql_freeresult($result)
    {
        // no-op
    }
}

class posting_service_test extends TestCase
{
    /**
     * Build a posting_service over the fakes. The acts-as-user seam (as_user) and the
     * post-data builder (base_post_data) are PRIVATE by design — they must never be part
     * of the public surface — so we drive them through reflection (a read-only test seam
     * that leaves production visibility untouched), rather than the protected-method
     * subclass trick the controller guard tests can use.
     *
     * $row is the user-table row load_user_row() returns; $original is the
     * pre-impersonation $user->data.
     *
     * @return array{0: posting_service, 1: fake_user, 2: fake_auth}
     */
    private function probe($row, $original)
    {
        $user = new fake_user($original);
        $auth = new fake_auth();
        $db = new fake_db($row);

        $svc = new posting_service(
            $db,
            $user,
            $auth,
            null,        // content_visibility — unused by as_user / base_post_data
            null,        // config — unused here
            'phpbb_',
            '/nonexistent/',
            'php'
        );

        return [$svc, $user, $auth];
    }

    /** Invoke the private as_user($poster_id, $fn) via reflection. */
    private function run_as_user(posting_service $svc, int $poster_id, callable $fn)
    {
        $m = new \ReflectionMethod(posting_service::class, 'as_user');
        $m->setAccessible(true);
        return $m->invoke($svc, $poster_id, $fn);
    }

    /** Invoke the private base_post_data($forum_id, $poster_id, $message) via reflection. */
    private function build_post_data(posting_service $svc, int $forum_id, int $poster_id, string $message): array
    {
        $m = new \ReflectionMethod(posting_service::class, 'base_post_data');
        $m->setAccessible(true);
        return $m->invoke($svc, $forum_id, $poster_id, $message);
    }

    private function user_row(int $user_type): array
    {
        return [
            'user_id'   => 999,            // deliberately WRONG; as_user must overwrite with poster_id
            'username'  => 'mapped_user',
            'user_type' => $user_type,
        ];
    }

    // ── session-flag derivation inside the window ───────────────────────────

    public function test_user_normal_is_registered_not_bot(): void
    {
        // USER_NORMAL = 0 (constants.php default in tests).
        [$svc, $user, $auth] = $this->probe($this->user_row(0), ['user_id' => 1, 'is_registered' => false]);

        $seen = null;
        $this->run_as_user($svc, 42, function () use ($user, &$seen) {
            $seen = $user->data; // capture the impersonated row INSIDE the window
            return 'done';
        });

        $this->assertTrue($seen['is_registered']);
        $this->assertFalse($seen['is_bot']);
        $this->assertSame(42, $seen['user_id']); // poster_id overrides the row's user_id
    }

    public function test_user_founder_is_registered_not_bot(): void
    {
        // USER_FOUNDER = 3.
        [$svc, $user] = $this->probe($this->user_row(3), ['user_id' => 1]);

        $seen = null;
        $this->run_as_user($svc, 7, function () use ($user, &$seen) {
            $seen = $user->data;
        });

        $this->assertTrue($seen['is_registered']);
        $this->assertFalse($seen['is_bot']);
    }

    public function test_user_ignore_is_bot_not_registered(): void
    {
        // USER_IGNORE = 2 (bots).
        [$svc, $user] = $this->probe($this->user_row(2), ['user_id' => 1]);

        $seen = null;
        $this->run_as_user($svc, 7, function () use ($user, &$seen) {
            $seen = $user->data;
        });

        $this->assertFalse($seen['is_registered']);
        $this->assertTrue($seen['is_bot']);
    }

    // ── ACL primed for the impersonated user INSIDE the window ──────────────

    public function test_acl_primed_to_impersonated_user_before_callback(): void
    {
        $original = ['user_id' => 1, 'is_registered' => true];
        [$svc, $user, $auth] = $this->probe($this->user_row(0), $original);

        $acl_at_callback = null;
        $this->run_as_user($svc, 42, function () use ($auth, &$acl_at_callback) {
            // At callback time the LAST acl() call must be the impersonated identity.
            $acl_at_callback = end($auth->acl_calls);
        });

        // First acl() call (inside the window) was the merged impersonated row.
        $this->assertSame(42, $acl_at_callback['user_id']);
        $this->assertTrue($acl_at_callback['is_registered']);
    }

    // ── callback value is returned ──────────────────────────────────────────

    public function test_returns_callback_value(): void
    {
        [$svc] = $this->probe($this->user_row(0), ['user_id' => 1]);

        $result = $this->run_as_user($svc, 42, function () {
            return ['topic_id' => 11, 'post_id' => 22];
        });

        $this->assertSame(['topic_id' => 11, 'post_id' => 22], $result);
    }

    // ── UNCONDITIONAL restore in finally (happy path) ───────────────────────

    public function test_restores_user_and_acl_after_normal_return(): void
    {
        $original = ['user_id' => 1, 'username' => 'session_owner', 'is_registered' => true];
        [$svc, $user, $auth] = $this->probe($this->user_row(0), $original);

        $this->run_as_user($svc, 42, function () {
            return null;
        });

        // $user->data restored to the EXACT original.
        $this->assertSame($original, $user->data);
        // ACL re-primed: the FINAL acl() call is the original identity (restore).
        $this->assertSame($original, end($auth->acl_calls));
        // Two acl() calls total: impersonate (42), then restore (original).
        $this->assertCount(2, $auth->acl_calls);
    }

    // ── UNCONDITIONAL restore in finally EVEN WHEN the callback THROWS ───────

    public function test_restores_user_and_acl_even_when_callback_throws(): void
    {
        $original = ['user_id' => 1, 'username' => 'session_owner', 'is_registered' => true];
        [$svc, $user, $auth] = $this->probe($this->user_row(0), $original);

        $thrown = null;
        try
        {
            $this->run_as_user($svc, 42, function () {
                throw new \RuntimeException('submit_post blew up');
            });
        }
        catch (\RuntimeException $e)
        {
            $thrown = $e;
        }

        // The exception propagated (finally must not swallow it).
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('submit_post blew up', $thrown->getMessage());

        // Identity AND ACL are back to the original despite the throw — the closed gap.
        $this->assertSame($original, $user->data);
        $this->assertSame($original, end($auth->acl_calls));
    }

    /**
     * The defensive-gap regression: when the entry session $user->data is NULL/empty, the
     * impersonation window still restores it to that EXACT original (null) and re-primes
     * the ACL back to null — never leaving the impersonated identity/ACL in place. The OLD
     * `if ($original !== null)` guard skipped both restores here.
     */
    public function test_restores_null_original_identity_and_acl(): void
    {
        [$svc, $user, $auth] = $this->probe($this->user_row(0), null); // null entry session

        $inside = 'unset';
        $this->run_as_user($svc, 42, function () use ($user, &$inside) {
            $inside = $user->data; // impersonated INSIDE the window
        });

        // Inside the window we WERE impersonating (the gap would have meant a leak on exit).
        $this->assertIsArray($inside);
        $this->assertSame(42, $inside['user_id']);

        // After the window: restored to the EXACT original (null), not left impersonated.
        $this->assertNull($user->data);
        // The final acl() call restored to the original (null) identity.
        $this->assertNull(end($auth->acl_calls));
        $this->assertCount(2, $auth->acl_calls); // impersonate + restore
    }

    // ── post-data shape regression (force_approved_state / NO force_visibility) ──

    public function test_post_data_force_approved_state_one_and_no_force_visibility(): void
    {
        [$svc] = $this->probe($this->user_row(0), ['user_id' => 1]);

        $data = $this->build_post_data($svc, 5, 42, 'hello world');

        // ITEM_APPROVED (1) so submit_post() does NOT route through the moderation queue.
        $this->assertArrayHasKey('force_approved_state', $data);
        $this->assertSame(1, $data['force_approved_state']);

        // The regression guard: 'force_visibility' MUST NOT be present at all. phpBB does
        // isset($data['force_visibility']) (true even for literal false) then (int)false === 0
        // === ITEM_UNAPPROVED, OVERRIDING force_approved_state and silently forcing every
        // post unapproved/invisible. Re-introducing the key (even => false) breaks this.
        $this->assertArrayNotHasKey('force_visibility', $data);

        // Sanity: the poster_id is carried through to attribution.
        $this->assertSame(42, $data['poster_id']);
        $this->assertSame(5, $data['forum_id']);
    }
}
