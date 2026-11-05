<?php
/**
 * HugMesh Forum API — @system private-message send (idempotent).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\service;

use hugmesh\forumapi\auth\principal;

/**
 * Sends one phpBB private message from the caller (the {@code @system} account, already
 * authorized by the controller) to the phpBB user mapped from a Casdoor subject, at most
 * once per Idempotency-Key.
 *
 * <h3>Order</h3>
 * <ol>
 *   <li>Recipient: the subject's phpBB user from the casdoor map. None => UNKNOWN_RECIPIENT,
 *       and nothing is written (no ledger row, no message).</li>
 *   <li>Sender: the caller's own phpBB user ({@see user_mapper::ensure_user()}), created on
 *       first use. The message is therefore always FROM the authenticated principal; the
 *       body cannot name a sender.</li>
 *   <li>Ledger + send in ONE transaction: an existing row replays its msg_id (same body)
 *       or is refused (different body); otherwise the row is reserved, submit_pm() runs
 *       (its own begin/commit nest inside ours) and the row gets the msg_id. A failure
 *       anywhere rolls back the message and the row together, so a retry sends again and
 *       a committed row always names a committed message.</li>
 * </ol>
 */
class system_pm_service
{
    public const SENT = 'sent';
    public const REPLAYED = 'replayed';
    public const UNKNOWN_RECIPIENT = 'unknown_recipient';
    public const KEY_REUSED = 'idempotency_key_reused';
    public const IN_PROGRESS = 'idempotency_in_progress';

    /**
     * @var user_mapper
     */
    private $users;

    /**
     * @var system_pm_store
     */
    private $store;

    /**
     * @var pm_sender
     */
    private $sender;

    /**
     * @param user_mapper     $users
     * @param system_pm_store $store
     * @param pm_sender       $sender
     */
    public function __construct(user_mapper $users, system_pm_store $store, pm_sender $sender)
    {
        $this->users = $users;
        $this->store = $store;
        $this->sender = $sender;
    }

    /**
     * @param principal $caller     the authorized @system principal
     * @param string    $recipient  the recipient's Casdoor subject
     * @param string    $subject
     * @param string    $text
     * @param string    $idempotency_key
     * @return array{outcome: string, msg_id: int}
     *
     * @throws \Throwable whatever the store or submit_pm() throws (after rollback).
     */
    public function send(principal $caller, $recipient, $subject, $text, $idempotency_key)
    {
        $to_user_id = $this->users->find_user_id((string) $recipient);
        if ($to_user_id === null)
        {
            return ['outcome' => self::UNKNOWN_RECIPIENT, 'msg_id' => 0];
        }

        $from_user_id = (int) $this->users->ensure_user($caller);
        $idem_hash = hash('sha256', $caller->get_subject() . "\0" . (string) $idempotency_key);
        $request_hash = self::request_hash((string) $recipient, (string) $subject, (string) $text);

        $this->store->begin();
        $open = true;
        try
        {
            $existing = $this->store->find($idem_hash);
            if ($existing === null && !$this->store->reserve($idem_hash, $request_hash))
            {
                // Lost a race for the same key (the UNIQUE index made us wait for the
                // winner) or the INSERT failed outright. Either way this transaction is
                // unusable on Postgres: roll it back and read the committed state.
                $open = false;
                $this->store->rollback();
                $existing = $this->store->find($idem_hash);
                if ($existing === null)
                {
                    throw new \RuntimeException('HugMesh Forum API: could not reserve the idempotency key.');
                }
                return $this->replay($existing, $request_hash);
            }
            if ($existing !== null)
            {
                $this->store->commit();
                $open = false;
                return $this->replay($existing, $request_hash);
            }

            $msg_id = $this->sender->send($from_user_id, $caller->get_display_name(), (int) $to_user_id,
                (string) $subject, (string) $text);
            $this->store->complete($idem_hash, $msg_id);
            $this->store->commit();
            $open = false;

            return ['outcome' => self::SENT, 'msg_id' => (int) $msg_id];
        }
        catch (\Throwable $e)
        {
            if ($open)
            {
                $this->store->rollback();
            }
            throw $e;
        }
    }

    /**
     * Length-prefixed, so no two different (recipient, subject, text) triples share an input.
     *
     * @param string $recipient
     * @param string $subject
     * @param string $text
     * @return string
     */
    private static function request_hash($recipient, $subject, $text)
    {
        $input = '';
        foreach ([$recipient, $subject, $text] as $part)
        {
            $input .= strlen($part) . ':' . $part;
        }
        return hash('sha256', $input);
    }

    /**
     * @param array{request_hash: string, msg_id: int} $row
     * @param string                                    $request_hash
     * @return array{outcome: string, msg_id: int}
     */
    private function replay(array $row, $request_hash)
    {
        if (!hash_equals($row['request_hash'], (string) $request_hash))
        {
            return ['outcome' => self::KEY_REUSED, 'msg_id' => 0];
        }
        if ($row['msg_id'] <= 0)
        {
            // Not reachable while the row and the message commit together; kept so a
            // half-written row can never be reported as a delivered message.
            return ['outcome' => self::IN_PROGRESS, 'msg_id' => 0];
        }
        return ['outcome' => self::REPLAYED, 'msg_id' => $row['msg_id']];
    }
}
