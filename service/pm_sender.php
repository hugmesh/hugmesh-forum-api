<?php
/**
 * HugMesh Forum API — phpBB private-message sender (wraps submit_pm()).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\service;

/**
 * Sends one phpBB private message through phpBB's own {@code submit_pm('post', ...)}
 * (includes/functions_privmsgs.php, phpBB 3.3.11), so the message, its recipient row,
 * the recipient's unread counters and the PM notification are written exactly as the
 * UCP compose form writes them.
 *
 * The data array carries every key submit_pm() reads in 'post' mode. The text is plain
 * (BBCode and smilies OFF, magic URLs ON): a notification body can quote user-supplied
 * text, and must not be able to render markup in the recipient's inbox. No copy goes to
 * the sender's outbox: the {@code @system} account has no reader.
 *
 * Kept apart from {@see system_pm_service} so the unit tests replace exactly this one
 * phpBB-only call and nothing else.
 */
class pm_sender
{
    /**
     * @var \phpbb\user|null
     */
    private $user;

    /**
     * @var string
     */
    private $root_path;

    /**
     * @var string
     */
    private $php_ext;

    /**
     * @param \phpbb\user|null $user
     * @param string           $root_path
     * @param string           $php_ext
     */
    public function __construct($user, $root_path, $php_ext)
    {
        $this->user = $user;
        $this->root_path = (string) $root_path;
        $this->php_ext = (string) $php_ext;
    }

    /**
     * @param int    $from_user_id  the sender's phpBB user id (the mapped @system account)
     * @param string $from_username the sender's display name
     * @param int    $to_user_id    the recipient's phpBB user id
     * @param string $subject
     * @param string $text
     * @return int the new phpBB msg_id.
     *
     * @throws \RuntimeException when submit_pm() returns no message id.
     */
    public function send($from_user_id, $from_username, $to_user_id, $subject, $text)
    {
        $this->require_includes();

        $message = (string) $text;
        $uid = '';
        $bitfield = '';
        $flags = 0;
        generate_text_for_storage($message, $uid, $bitfield, $flags, false, true, false);

        $data = [
            'address_list'    => ['u' => [(int) $to_user_id => 'to']],
            'from_user_id'    => (int) $from_user_id,
            'from_username'   => (string) $from_username,
            'from_user_ip'    => ($this->user !== null && isset($this->user->ip)) ? (string) $this->user->ip : '',
            'icon_id'         => 0,
            'enable_bbcode'   => false,
            'enable_smilies'  => false,
            'enable_urls'     => true,
            'enable_sig'      => false,
            'message'         => $message,
            'bbcode_bitfield' => $bitfield,
            'bbcode_uid'      => $uid,
            'attachment_data' => [],
        ];

        $msg_id = submit_pm('post', (string) $subject, $data, false);
        if (!$msg_id)
        {
            throw new \RuntimeException('HugMesh Forum API: submit_pm() returned no message id.');
        }
        return (int) $msg_id;
    }

    private function require_includes()
    {
        if (!function_exists('generate_text_for_storage'))
        {
            include($this->root_path . 'includes/functions_content.' . $this->php_ext);
        }
        if (!function_exists('submit_pm'))
        {
            include($this->root_path . 'includes/functions_privmsgs.' . $this->php_ext);
        }
    }
}
