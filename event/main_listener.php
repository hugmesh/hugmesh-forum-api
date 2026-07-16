<?php
/**
 * Snapdragon Forum API — phpBB s9e\TextFormatter hook: server-side sticker-token rendering.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\event;

use snapdragon\forumapi\text\sticker_tokens;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers `:snap-sticker:<slug>:` as a real phpBB/s9e\TextFormatter tag via the documented
 * phpBB extension point for customizing the s9e configurator:
 * `core.text_formatter_s9e_configure_after` (`phpbb/textformatter/s9e/factory.php`,
 * `get_configurator()`) — the SAME event core phpBB itself fires right after registering its
 * own default BBCodes/smilies/censor/autolink plugins, immediately before the configurator
 * is finalized and cached. This is the standard, documented phpBB way for an extension to
 * add a custom tag to the renderer (phpBB's own `@since 3.2.0-a1` event annotation).
 *
 * Uses the built-in `s9e\TextFormatter\Plugins\Preg` plugin (`$configurator->Preg->replace()`)
 * — the s9e mechanism for "a pattern-based replacement": a regexp with a NAMED capture group
 * becomes a tag attribute that is auto-filtered to that SAME regexp at parse time (s9e's own
 * defence in depth, independent of the escaping this extension already does — see
 * {@see sticker_tokens}), and the template's `$1` backreference compiles to that attribute's
 * value in the final XSL/HTML template. This mirrors how phpBB's own factory registers e.g.
 * smiley/emoji tags, just via the regexp-driven plugin instead of a literal BBCode.
 *
 * NOTE: this extension's own JSON read path (`forum_repository`) does NOT currently route
 * post text through phpBB's s9e renderer at all (see that class's `strip_text()` — a
 * deliberately lean, plain-text read path). So THIS listener alone does not change what the
 * `/topics/{id}/replies` / `/posts/{id}` endpoints return; {@see sticker_tokens::render()}
 * is called there directly for that. This listener exists so the SAME token grammar renders
 * correctly through phpBB's OWN standard rendering pipeline too (e.g. if the board itself
 * ever displays these posts, or a future richer read path adopts real s9e rendering) —
 * without a second, drifting implementation of the tag.
 */
class main_listener implements EventSubscriberInterface
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            'core.text_formatter_s9e_configure_after' => 'configure_sticker_tag',
        );
    }

    /**
     * @param mixed $event The phpBB event object (ArrayAccess: ['configurator'] =>
     *                      s9e\TextFormatter\Configurator).
     */
    public function configure_sticker_tag($event)
    {
        $configurator = $event['configurator'];

        // Same-origin, cookie-authed sticker asset URL — mirrors forum-ui's client-side
        // transform verbatim (see sticker_tokens' class doc for the exact source/line refs).
        // $1 is the ONLY capture group in TOKEN_REGEXP (the named "slug" group); s9e compiles
        // it to that auto-created, auto-filtered tag attribute's value.
        $template = '<img class="forum-sticker" loading="lazy" decoding="async" '
            . 'alt="sticker $1" src="' . sticker_tokens::API_BASE . '/$1/body"/>';

        $configurator->Preg->replace(sticker_tokens::TOKEN_REGEXP, $template, 'SNAP_STICKER');
    }
}
