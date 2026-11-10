<?php
/**
 * Unit tests for POST /api/v1/hugmesh/system/private-messages — the phpBB end of the
 * contract admin-bff's ForumSystemPmClient documents.
 *
 * Runs WITHOUT a live phpBB: the controller, the service, the authenticator and the JWT
 * verifier are the real classes (tokens are real RS256 JWTs from rsa_test_keys); only the
 * three phpBB-bound collaborators are fakes — the casdoor map (user_mapper), the ledger's
 * database (system_pm_store, with begin/commit/rollback semantics) and submit_pm()
 * (pm_sender). Nothing here was run against a real phpBB database.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;
use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\jwks_provider;
use hugmesh\forumapi\auth\jwt_verifier;
use hugmesh\forumapi\auth\principal;
use hugmesh\forumapi\controller\system_controller;
use hugmesh\forumapi\service\pm_sender;
use hugmesh\forumapi\service\system_pm_service;
use hugmesh\forumapi\service\system_pm_store;
use hugmesh\forumapi\service\user_mapper;
use Symfony\Component\HttpFoundation\Request;

/** The casdoor map: subject => phpBB user id. ensure_user() records the sender it mapped. */
class pm_user_mapper_stub extends user_mapper
{
    /** @var array<string, int> */
    public $map;

    /** @var array<int, string> subjects ensure_user() was called for */
    public $ensured = [];

    public function __construct(array $map)
    {
        // Deliberately NOT calling parent::__construct: no phpBB db in a unit test.
        $this->map = $map;
    }

    public function find_user_id($sub)
    {
        return isset($this->map[$sub]) ? $this->map[$sub] : null;
    }

    public function ensure_user(principal $p)
    {
        $this->ensured[] = $p->get_subject();
        if (!isset($this->map[$p->get_subject()]))
        {
            $this->map[$p->get_subject()] = 500 + count($this->map);
        }
        return $this->map[$p->get_subject()];
    }
}

/** An in-memory ledger with real transaction semantics: rollback restores the snapshot. */
class pm_store_fake extends system_pm_store
{
    /** @var array<string, array{request_hash: string, msg_id: int}> committed + pending rows */
    public $rows = [];

    /** @var array|null */
    private $snapshot = null;

    /** @var int */
    public $depth = 0;

    /** @var array<string, array{request_hash: string, msg_id: int}>|null rows another
     *  transaction commits the moment our reserve() runs (a lost race) */
    public $race_winner = null;

    public function __construct()
    {
        // No phpBB db.
    }

    public function begin()
    {
        $this->snapshot = $this->rows;
        $this->depth++;
    }

    public function commit()
    {
        $this->snapshot = null;
        $this->depth--;
    }

    public function rollback()
    {
        if ($this->snapshot !== null)
        {
            $this->rows = $this->snapshot;
        }
        $this->snapshot = null;
        $this->depth--;
    }

    public function find($idem_hash)
    {
        return isset($this->rows[$idem_hash]) ? $this->rows[$idem_hash] : null;
    }

    public function reserve($idem_hash, $request_hash)
    {
        if ($this->race_winner !== null)
        {
            // The other transaction committed first; it survives our rollback.
            $this->snapshot = array_merge((array) $this->snapshot, $this->race_winner);
            $this->rows = array_merge($this->rows, $this->race_winner);
            $this->race_winner = null;
        }
        if (isset($this->rows[$idem_hash]))
        {
            return false;
        }
        $this->rows[$idem_hash] = ['request_hash' => (string) $request_hash, 'msg_id' => 0];
        return true;
    }

    public function complete($idem_hash, $msg_id)
    {
        $this->rows[$idem_hash]['msg_id'] = (int) $msg_id;
    }
}

/** submit_pm(): records each message; can be told to fail the next send. */
class pm_sender_fake extends pm_sender
{
    /** @var array<int, array> */
    public $sent = [];

    /** @var bool */
    public $fail_next = false;

    public function __construct()
    {
        // No phpBB.
    }

    public function send($from_user_id, $from_username, $to_user_id, $subject, $text)
    {
        if ($this->fail_next)
        {
            $this->fail_next = false;
            throw new \RuntimeException('submit_pm exploded');
        }
        $this->sent[] = [
            'from' => (int) $from_user_id,
            'from_name' => (string) $from_username,
            'to' => (int) $to_user_id,
            'subject' => (string) $subject,
            'text' => (string) $text,
        ];
        return 900 + count($this->sent);
    }
}

class system_pm_test extends TestCase
{
    private const SYSTEM_SUB = 'casdoor-system-sub';
    private const ALICE_SUB = 'hugmesh/alice';
    private const ALICE_ID = 42;
    private const PATH = '/api/v1/hugmesh/system/private-messages';

    /** @var rsa_test_keys */
    private $keys;

    /** @var string */
    private $issuer = 'https://casdoor.example.test';

    /** @var pm_user_mapper_stub */
    private $users;

    /** @var pm_store_fake */
    private $store;

    /** @var pm_sender_fake */
    private $sender;

    protected function setUp(): void
    {
        $this->keys = new rsa_test_keys('kid-pm');
        $this->users = new pm_user_mapper_stub([self::ALICE_SUB => self::ALICE_ID]);
        $this->store = new pm_store_fake();
        $this->sender = new pm_sender_fake();
    }

    private function token(array $claims): string
    {
        return $this->keys->sign(array_merge([
            'sub' => self::SYSTEM_SUB,
            'iss' => $this->issuer,
            'iat' => time() - 10,
            'exp' => time() + 600,
            'name' => 'system',
        ], $claims));
    }

    private function system_token(): string
    {
        return $this->token(['roles' => ['user', 'system']]);
    }

    private function call(?string $token, ?string $key, $body)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null)
        {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($key !== null)
        {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $content = is_string($body) ? $body : json_encode($body);
        $request = Request::create(self::PATH, 'POST', [], [], [], $server, $content);

        $doc = $this->keys->jwks();
        $provider = new jwks_provider('https://issuer.test/jwks', function () use ($doc) {
            return json_encode($doc);
        }, null);
        $auth = new authenticator(new jwt_verifier($provider, $this->issuer, '', 30));
        $service = new system_pm_service($this->users, $this->store, $this->sender);

        return (new system_controller($auth, $service, $request))->send_private_message();
    }

    private function body(string $recipient = self::ALICE_SUB, string $subject = 'Title', string $text = 'Body'): array
    {
        return ['recipient' => $recipient, 'subject' => $subject, 'text' => $text];
    }

    // ── success ─────────────────────────────────────────────────────────────

    public function test_system_token_sends_one_pm_from_its_own_user_to_the_mapped_recipient(): void
    {
        $r = $this->call($this->system_token(), 'hm-row-1', $this->body());

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('{"id":901}', $r->getContent());
        $this->assertFalse($r->headers->has('Idempotent-Replayed'));
        $this->assertCount(1, $this->sender->sent);
        $sent = $this->sender->sent[0];
        $this->assertSame(self::ALICE_ID, $sent['to']);
        $this->assertSame($this->users->map[self::SYSTEM_SUB], $sent['from'], 'sender is the CALLER\'s phpBB user');
        $this->assertSame('system', $sent['from_name']);
        $this->assertSame('Title', $sent['subject']);
        $this->assertSame('Body', $sent['text']);
        $this->assertSame([self::SYSTEM_SUB], $this->users->ensured);
        $this->assertCount(1, $this->store->rows);
        $this->assertSame(901, array_values($this->store->rows)[0]['msg_id']);
        $this->assertSame(0, $this->store->depth, 'transaction closed');
    }

    public function test_the_casdoor_role_spelling_ROLE_SYSTEM_is_accepted_too(): void
    {
        // A Casdoor role named ROLE_SYSTEM (admin-bff's ROLE_<UPPER> spelling) reaches the
        // verifier as ROLE_ROLE_SYSTEM, and the role-object form must work the same.
        foreach ([['ROLE_SYSTEM'], [['name' => 'ROLE_SYSTEM']], [['name' => 'system']]] as $i => $roles)
        {
            $r = $this->call($this->token(['roles' => $roles]), 'k-' . $i, $this->body());
            $this->assertSame(200, $r->getStatusCode(), json_encode($roles));
        }
        $this->assertCount(3, $this->sender->sent);
    }

    // ── unknown recipient ───────────────────────────────────────────────────

    public function test_unknown_recipient_is_404_with_exactly_the_contract_body_and_writes_nothing(): void
    {
        $r = $this->call($this->system_token(), 'hm-row-2', $this->body('hugmesh/ghost'));

        $this->assertSame(404, $r->getStatusCode());
        $this->assertSame('{"code":"unknown_recipient"}', $r->getContent());
        $this->assertSame([], $this->sender->sent);
        $this->assertSame([], $this->store->rows);
    }

    // ── unauthorized callers ────────────────────────────────────────────────

    public function test_no_token_is_401_and_sends_nothing(): void
    {
        $r = $this->call(null, 'k', $this->body());

        $this->assertSame(401, $r->getStatusCode());
        $this->assertSame([], $this->sender->sent);
    }

    public function test_a_forged_token_is_401(): void
    {
        $other = new rsa_test_keys('kid-pm');
        $forged = $other->sign(['sub' => self::SYSTEM_SUB, 'iss' => $this->issuer, 'iat' => time(),
            'exp' => time() + 600, 'roles' => ['system']]);

        $this->assertSame(401, $this->call($forged, 'k', $this->body())->getStatusCode());
        $this->assertSame([], $this->sender->sent);
    }

    /**
     * @return array<string, array{0: array}>
     */
    public function non_system_principals(): array
    {
        return [
            'plain user'                    => [['roles' => ['user']]],
            'forum user'                    => [['roles' => ['user', 'forum']]],
            'moderator'                     => [['roles' => ['user', 'mod']]],
            'admin role'                    => [['roles' => ['admin']]],
            'isAdmin claim'                 => [['roles' => ['user'], 'isAdmin' => true]],
            'superadmin'                    => [['roles' => ['superadmin']]],
            'service machine role'          => [['roles' => ['ROLE_SERVICE']]],
            'the @system matrix/mastodon set' => [['roles' => ['user', 'matrix', 'mastodon']]],
            'ROLE_SYSTEM via permissions'   => [['permissions' => ['ROLE_SYSTEM', 'ROLE_ROLE_SYSTEM']]],
            'name claim system, no role'    => [['name' => 'system', 'roles' => ['user']]],
        ];
    }

    /**
     * @dataProvider non_system_principals
     */
    public function test_every_non_system_principal_is_403_and_sends_nothing(array $claims): void
    {
        $r = $this->call($this->token($claims), 'k', $this->body());

        $this->assertSame(403, $r->getStatusCode());
        $this->assertSame([], $this->sender->sent);
        $this->assertSame([], $this->store->rows);
        $this->assertSame([], $this->users->ensured, 'no phpBB user is created for a refused caller');
    }

    // ── idempotency ─────────────────────────────────────────────────────────

    public function test_a_replayed_key_returns_the_same_body_and_posts_nothing_new(): void
    {
        $first = $this->call($this->system_token(), 'hm-outbox-9', $this->body());
        $second = $this->call($this->system_token(), 'hm-outbox-9', $this->body());

        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));
        $this->assertCount(1, $this->sender->sent, 'same Idempotency-Key => replayed, not re-posted');

        $third = $this->call($this->system_token(), 'hm-outbox-10', $this->body());
        $this->assertSame('{"id":902}', $third->getContent(), 'a NEW key sends again');
        $this->assertCount(2, $this->sender->sent);
    }

    public function test_the_same_key_with_a_different_message_is_422_not_a_silent_replay(): void
    {
        $this->call($this->system_token(), 'hm-outbox-11', $this->body());
        $r = $this->call($this->system_token(), 'hm-outbox-11', $this->body(self::ALICE_SUB, 'Title', 'OTHER'));

        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('{"code":"idempotency_key_reused"}', $r->getContent());
        $this->assertCount(1, $this->sender->sent);
    }

    public function test_keys_are_per_sender(): void
    {
        $this->call($this->system_token(), 'shared-key', $this->body());
        $r = $this->call($this->token(['sub' => 'other-domain-system', 'roles' => ['system']]), 'shared-key', $this->body());

        $this->assertSame(200, $r->getStatusCode());
        $this->assertFalse($r->headers->has('Idempotent-Replayed'));
        $this->assertCount(2, $this->sender->sent);
    }

    public function test_a_failed_send_rolls_the_key_back_so_the_retry_sends_once(): void
    {
        $this->sender->fail_next = true;
        $failed = $this->call($this->system_token(), 'hm-outbox-12', $this->body());

        $this->assertSame(500, $failed->getStatusCode());
        $this->assertStringNotContainsString('exploded', $failed->getContent());
        $this->assertSame([], $this->store->rows, 'no ledger row survives a failed send');
        $this->assertSame(0, $this->store->depth);

        $retry = $this->call($this->system_token(), 'hm-outbox-12', $this->body());
        $this->assertSame(200, $retry->getStatusCode());
        $this->assertFalse($retry->headers->has('Idempotent-Replayed'));
        $this->assertCount(1, $this->sender->sent);
    }

    public function test_losing_the_race_for_a_key_replays_the_winner_without_sending(): void
    {
        // Another request with the same key commits msg 777 while ours waits on the index.
        $winner_hash = hash('sha256', self::SYSTEM_SUB . "\0" . 'hm-race');
        $probe = new \ReflectionMethod(system_pm_service::class, 'request_hash');
        $probe->setAccessible(true);
        $this->store->race_winner = [$winner_hash => [
            'request_hash' => $probe->invoke(null, self::ALICE_SUB, 'Title', 'Body'),
            'msg_id' => 777,
        ]];

        $r = $this->call($this->system_token(), 'hm-race', $this->body());

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('{"id":777}', $r->getContent());
        $this->assertSame('true', $r->headers->get('Idempotent-Replayed'));
        $this->assertSame([], $this->sender->sent);
        $this->assertSame(0, $this->store->depth);
    }

    // ── request validation ──────────────────────────────────────────────────

    public function test_a_missing_or_malformed_idempotency_key_is_400_and_sends_nothing(): void
    {
        foreach ([null, '', '   ', "has space", str_repeat('k', 256)] as $key)
        {
            $r = $this->call($this->system_token(), $key, $this->body());
            $this->assertSame(400, $r->getStatusCode(), var_export($key, true));
        }
        $this->assertSame([], $this->sender->sent);
    }

    public function test_a_missing_recipient_or_a_non_string_field_is_400(): void
    {
        $this->assertSame(400, $this->call($this->system_token(), 'k1', ['subject' => 'T', 'text' => 'B'])->getStatusCode());
        $this->assertSame(400, $this->call($this->system_token(), 'k2', $this->body('  '))->getStatusCode());
        $this->assertSame(400, $this->call($this->system_token(), 'k3',
            ['recipient' => self::ALICE_SUB, 'subject' => 'T', 'text' => ['x']])->getStatusCode());
        $this->assertSame(400, $this->call($this->system_token(), 'k4', '{not json')->getStatusCode());
        $this->assertSame([], $this->sender->sent);
    }

    public function test_the_exact_body_admin_bff_sends_is_accepted(): void
    {
        // Byte-for-byte what ForumSystemPmClient.post() serialises (Jackson ObjectNode field order).
        $r = $this->call($this->system_token(), 'hm-row-1',
            '{"recipient":"hugmesh/alice","subject":"Title","text":"Body"}');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('application/json', $r->headers->get('Content-Type'));
    }
}
