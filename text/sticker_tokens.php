<?php
/**
 * Snapdragon Forum API — server-side `:snap-sticker:<slug>:` token rendering.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\text;

/**
 * Renders `:snap-sticker:<slug>:` post tokens into the SAME `<img>` markup forum-ui's
 * client-side DOM transform produces (`transformStickerTokens()` in
 * `snapdragon-forum-ui/src/app/stickers.ts`, merged Snapdragon-Systems/snapdragon-forum-ui#22,
 * commit 549d7d1), so a raw API consumer — anything that is NOT forum-ui, which currently
 * only renders stickers in the browser after the fact — gets an equivalent visual result
 * straight from this extension's JSON responses instead of the literal token text.
 *
 * Mirrors, verbatim, forum-ui's `stickers.ts` contract:
 *   - token grammar   `:snap-sticker:<slug>:`
 *   - slug charset    `[a-z0-9_-]{1,64}`       ({@see self::SLUG_PATTERN})
 *   - asset URL       `{API_BASE}/<slug>/body` (same-origin — {@see self::API_BASE})
 *   - markup          `<img class="forum-sticker" loading="lazy" decoding="async"
 *                       alt="sticker <slug>" src="...">`
 *   - abuse cap       {@see self::MAX_PER_TEXT} stickers rendered per call (forum-ui's
 *                       `MAX_STICKERS_PER_POST`); tokens beyond the cap are left as inert
 *                       text, exactly like a malformed token.
 *
 * KNOWN PLATFORM GAP (not introduced by, or fixable within, this class): as of the merge
 * above, `snapdragon-user-bff-api`'s `StickerController` does NOT actually implement a
 * `GET {API_BASE}/{slug}/body` route — its own class doc states "There is deliberately NO
 * BFF serve endpoint for the sticker image" (stickers resolve via a Matrix `mxc://` fetched
 * direct-to-homeserver, keyed by `id`/`expression`, not by `slug`, which does not exist as a
 * sticker concept server-side). This class intentionally mirrors forum-ui's EXISTING,
 * documented convention rather than inventing a different one — see the PR description for
 * the cross-repo follow-up this implies.
 *
 * SECURITY: the slug is matched ONLY against {@see self::SLUG_PATTERN} — a bounded,
 * lowercase alnum/hyphen/underscore charset that cannot contain `"`, `<`, `>`, `/`, `.`,
 * whitespace, or any other markup/URL-breaking character. A token whose slug portion
 * contains ANY other character (a crafted `"><script>...`, a path-traversal-shaped
 * `../../etc/passwd`, etc.) simply does not match the token regexp at all and is left as
 * inert, un-rendered text — it is NEVER interpolated into the output markup. The matched
 * slug is additionally HTML-attribute-escaped before interpolation as defence in depth, in
 * case this helper is ever reused somewhere the charset guarantee alone would not be enough
 * on its own.
 *
 * Framework-independent (no phpBB/Symfony dependency) so it is directly unit-testable and
 * reusable from two call sites:
 *   - {@see \snapdragon\forumapi\event\main_listener}, which registers the equivalent
 *     transform as a real phpBB/s9e\TextFormatter custom tag (the phpBB extension
 *     mechanism, `core.text_formatter_s9e_configure_after`) for any board-rendered path;
 *   - {@see \snapdragon\forumapi\service\forum_repository}, the path that ACTUALLY produces
 *     this extension's `Reply.body` / `QueueItem.excerpt` JSON output today — a headless
 *     plain-text read path that does not itself invoke phpBB's s9e renderer (see
 *     `forum_repository::strip_text()`) — so THIS is the call site that closes the gap raw
 *     API consumers actually hit.
 */
class sticker_tokens
{
    /**
     * The sticker slug charset — mirrors forum-ui's `STICKER_SLUG_RE`
     * (`snapdragon-forum-ui/src/app/stickers.ts`).
     */
    const SLUG_PATTERN = '[a-z0-9_-]{1,64}';

    /**
     * Same-origin base for the user-bff character/sticker asset endpoint — mirrors
     * forum-ui's `STICKER_API_BASE` (`snapdragon-forum-ui/src/app/stickers.ts`).
     */
    const API_BASE = '/bff/user/character/stickers';

    /**
     * Abuse cap: at most this many stickers are rendered per {@see self::render()} call —
     * mirrors forum-ui's `MAX_STICKERS_PER_POST`.
     */
    const MAX_PER_TEXT = 8;

    /**
     * The full token-match regexp: `:snap-sticker:<slug>:` with a NAMED `slug` capture (the
     * same name the s9e Preg plugin's auto-generated tag attribute uses).
     */
    const TOKEN_REGEXP = '#:snap-sticker:(?<slug>' . self::SLUG_PATTERN . '):#';

    /**
     * The bare, anchored slug-validation regexp.
     */
    const SLUG_REGEXP = '#^' . self::SLUG_PATTERN . '$#D';

    /**
     * Static-only helper — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Replace every valid `:snap-sticker:<slug>:` token in $text with its `<img>` markup, up
     * to {@see self::MAX_PER_TEXT} replacements. Tokens beyond the cap, and any token whose
     * slug does not match {@see self::SLUG_PATTERN} (so never reaches the regexp capture at
     * all), are left untouched as inert text.
     *
     * @param string $text
     * @return string
     */
    public static function render($text)
    {
        $remaining = self::MAX_PER_TEXT;

        return (string) preg_replace_callback(
            self::TOKEN_REGEXP,
            function (array $m) use (&$remaining)
            {
                if ($remaining <= 0)
                {
                    // Cap hit: leave this (and every subsequent) token as inert text,
                    // exactly like a malformed token.
                    return $m[0];
                }
                $remaining--;
                return self::img_markup($m['slug']);
            },
            (string) $text
        );
    }

    /**
     * Build the `<img>` markup for one ALREADY-MATCHED slug. {@see self::is_valid_slug()} is
     * re-checked here as a second, independent gate — defence in depth, never trust a single
     * check alone.
     *
     * @param string $slug
     * @return string The `<img>` tag, or '' if $slug somehow fails validation (unreachable
     *                 via {@see self::render()}, since TOKEN_REGEXP already constrains the
     *                 charset — kept fail-closed regardless).
     */
    public static function img_markup($slug)
    {
        if (!self::is_valid_slug($slug))
        {
            return '';
        }

        // Belt-and-suspenders: the charset is already bounded to [a-z0-9_-], which cannot
        // contain a double-quote, angle bracket, slash, or any other markup-breaking
        // character — but we escape anyway rather than ever trust a single layer alone.
        $safe_slug = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');

        return '<img class="forum-sticker" loading="lazy" decoding="async" alt="sticker '
            . $safe_slug . '" src="' . self::API_BASE . '/' . $safe_slug . '/body"/>';
    }

    /**
     * @param mixed $slug
     * @return bool
     */
    public static function is_valid_slug($slug)
    {
        return is_string($slug) && preg_match(self::SLUG_REGEXP, $slug) === 1;
    }
}
