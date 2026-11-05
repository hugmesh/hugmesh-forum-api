<?php
/**
 * HugMesh Forum API — @system machine routes (private messages from the system sender).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\controller;

use hugmesh\forumapi\auth\authenticator;
use hugmesh\forumapi\auth\auth_exception;
use hugmesh\forumapi\service\system_pm_service;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * {@code POST /api/v1/hugmesh/system/private-messages}: the phpBB end of the contract
 * admin-bff's {@code ForumSystemPmClient} documents (forum-bff forwards it unchanged
 * from {@code POST /bff/forum/system/private-messages}).
 *
 * <pre>
 * Authorization: Bearer &lt;@system token&gt;   (ROLE_SYSTEM; nothing else, not admin)
 * Idempotency-Key: &lt;1-255 visible ASCII&gt;  (required)
 * {"recipient": "&lt;casdoor subject&gt;", "subject": "&lt;title&gt;", "text": "&lt;body&gt;"}
 *
 * 200 {"id": &lt;phpBB msg_id&gt;}             sent, or the same key + body replayed
 *                                             (replay adds Idempotent-Replayed: true)
 * 404 {"code": "unknown_recipient"}          the subject maps to no phpBB user
 * 409 {"code": "idempotency_in_progress"}    defensive; see system_pm_service
 * 422 {"code": "idempotency_key_reused"}     same key, different body
 * 400 / 401 / 403 / 500                      the base_controller error envelope
 * </pre>
 *
 * The 404 body is exactly {@code {"code":"unknown_recipient"}} and is the ONLY 404 this
 * route emits on purpose: admin-bff treats that body as "skip this recipient" and every
 * other 404 (route absent) as a retry.
 */
class system_controller extends base_controller
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';
    private const KEY_PATTERN = '/^[\x21-\x7E]{1,255}$/';

    /**
     * @var system_pm_service
     */
    private $pm;

    /**
     * @param authenticator     $authenticator
     * @param system_pm_service $pm
     * @param Request|null      $request
     */
    public function __construct(authenticator $authenticator, system_pm_service $pm, Request $request = null)
    {
        parent::__construct($authenticator, $request);
        $this->pm = $pm;
    }

    /**
     * POST /api/v1/hugmesh/system/private-messages
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function send_private_message()
    {
        return $this->guard(function () {
            $p = $this->principal();
            $this->authenticator->require_system($p);

            $key = trim((string) $this->current_request()->headers->get(self::IDEMPOTENCY_HEADER, ''));
            if (!preg_match(self::KEY_PATTERN, $key))
            {
                throw auth_exception::bad_request(self::IDEMPOTENCY_HEADER . ' is required (1-255 visible ASCII characters).');
            }

            $body = $this->json_body();
            $recipient = trim($this->require_string($body, 'recipient'));
            $subject = $this->optional_string($body, 'subject');
            $text = $this->optional_string($body, 'text');

            $r = $this->pm->send($p, $recipient, $subject, $text, $key);
            switch ($r['outcome'])
            {
                case system_pm_service::SENT:
                    return $this->json(['id' => $r['msg_id']], 200);
                case system_pm_service::REPLAYED:
                    $resp = $this->json(['id' => $r['msg_id']], 200);
                    $resp->headers->set('Idempotent-Replayed', 'true');
                    return $resp;
                case system_pm_service::UNKNOWN_RECIPIENT:
                    return $this->code(404, system_pm_service::UNKNOWN_RECIPIENT);
                case system_pm_service::KEY_REUSED:
                    return $this->code(422, system_pm_service::KEY_REUSED);
                default:
                    return $this->code(409, system_pm_service::IN_PROGRESS);
            }
        });
    }

    /**
     * A {"code": ...} body: the machine-readable shape admin-bff matches on.
     *
     * @param int    $status
     * @param string $code
     * @return JsonResponse
     */
    private function code($status, $code)
    {
        return new JsonResponse(['code' => (string) $code], (int) $status);
    }

    /**
     * A string field that may be absent (treated as "") but, when present, must be a string.
     *
     * @param array  $body
     * @param string $field
     * @return string
     *
     * @throws auth_exception 400 when present and not a string.
     */
    private function optional_string(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null)
        {
            return '';
        }
        if (!is_string($body[$field]))
        {
            throw auth_exception::bad_request($field . ' must be a string.');
        }
        return $body[$field];
    }
}
