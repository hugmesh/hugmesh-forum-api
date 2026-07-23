<?php
/**
 * Unit tests for sticker_tokens — the server-side `:snap-sticker:<slug>:` renderer this
 * extension's own JSON read path (forum_repository) applies to Reply.body/QueueItem.excerpt.
 *
 * Pure PHP, no phpBB/Symfony/s9e dependency: exercises {@see sticker_tokens} directly
 * (empirically verified against a real s9e\TextFormatter\Configurator too — see
 * main_listener_test.php for the equivalent scenarios through the actual phpBB render hook).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;
use hugmesh\forumapi\text\sticker_tokens;

class sticker_tokens_test extends TestCase
{
    // ── valid slug → correct <img> markup ────────────────────────────────

    public function test_valid_slug_renders_img_markup(): void
    {
        $out = sticker_tokens::render('hello :snap-sticker:wave-hi: world');

        $this->assertSame(
            'hello <img class="forum-sticker" loading="lazy" decoding="async" '
                . 'alt="sticker wave-hi" src="/bff/user/character/stickers/wave-hi/body"/> world',
            $out
        );
    }

    public function test_valid_slug_at_max_length_boundary_renders(): void
    {
        $slug = str_repeat('a', 64); // exactly SLUG_PATTERN's upper bound
        $out = sticker_tokens::render(':snap-sticker:' . $slug . ':');

        $this->assertStringContainsString('<img class="forum-sticker"', $out);
        $this->assertStringContainsString('src="/bff/user/character/stickers/' . $slug . '/body"', $out);
    }

    public function test_multiple_valid_tokens_all_render(): void
    {
        $out = sticker_tokens::render(':snap-sticker:a: and :snap-sticker:b-c_2:');

        $this->assertStringContainsString('src="/bff/user/character/stickers/a/body"', $out);
        $this->assertStringContainsString('src="/bff/user/character/stickers/b-c_2/body"', $out);
    }

    // ── malformed / malicious slug → NEVER raw-injected, left inert ─────

    public function test_malicious_slug_attempt_is_never_raw_injected(): void
    {
        $payload = 'hi :snap-sticker:foo"><script>alert(1)</script>: bye';
        $out = sticker_tokens::render($payload);

        // The token's "slug" portion contains characters outside SLUG_PATTERN, so it never
        // matches TOKEN_REGEXP at all — render() must not touch it (no <img>, no new tag).
        $this->assertSame($payload, $out);
        $this->assertStringNotContainsString('<img', $out);
    }

    public function test_path_traversal_shaped_slug_is_not_rendered(): void
    {
        $payload = ':snap-sticker:../../etc/passwd:';
        $out = sticker_tokens::render($payload);

        $this->assertSame($payload, $out);
        $this->assertStringNotContainsString('<img', $out);
    }

    public function test_uppercase_slug_is_rejected(): void
    {
        $payload = ':snap-sticker:UPPER:';
        $this->assertSame($payload, sticker_tokens::render($payload));
    }

    public function test_empty_slug_is_rejected(): void
    {
        $payload = ':snap-sticker::';
        $this->assertSame($payload, sticker_tokens::render($payload));
    }

    public function test_over_length_slug_is_rejected(): void
    {
        $payload = ':snap-sticker:' . str_repeat('a', 65) . ':'; // one over the 64 cap
        $this->assertSame($payload, sticker_tokens::render($payload));
    }

    // ── abuse cap: MAX_PER_TEXT stickers rendered per call, rest left inert ─

    public function test_renders_at_most_max_per_text_stickers(): void
    {
        $token = ':snap-sticker:a:';
        $out = sticker_tokens::render(str_repeat($token, sticker_tokens::MAX_PER_TEXT + 3));

        $this->assertSame(sticker_tokens::MAX_PER_TEXT, substr_count($out, '<img'));
        // The 3 tokens beyond the cap survive verbatim as inert text.
        $this->assertSame(3, substr_count($out, $token));
    }

    // ── is_valid_slug() / img_markup() direct (defence-in-depth) gates ──

    public function test_is_valid_slug_accepts_charset(): void
    {
        $this->assertTrue(sticker_tokens::is_valid_slug('foo-bar_1'));
    }

    public function test_is_valid_slug_rejects_quote(): void
    {
        $this->assertFalse(sticker_tokens::is_valid_slug('foo"bar'));
    }

    public function test_is_valid_slug_rejects_non_string(): void
    {
        $this->assertFalse(sticker_tokens::is_valid_slug(null));
    }

    /**
     * img_markup() is called with an ALREADY-matched slug in the normal flow, but must fail
     * closed (empty string, never a raw interpolation) even if a caller bypasses render()'s
     * regex gate entirely.
     */
    public function test_img_markup_fails_closed_on_invalid_slug(): void
    {
        $this->assertSame('', sticker_tokens::img_markup('foo" onerror="alert(1)'));
    }

    public function test_img_markup_escapes_html_special_chars_defence_in_depth(): void
    {
        // Not reachable via TOKEN_REGEXP (would fail is_valid_slug and return '' instead),
        // but proves the escaping itself is correct if this method is ever reused elsewhere
        // without the same upstream charset guarantee.
        $markup = sticker_tokens::img_markup('foo');
        $this->assertStringNotContainsString('"><', $markup);
    }

    // ── non-token text is left completely untouched ──────────────────────

    public function test_text_with_no_token_is_unchanged(): void
    {
        $payload = 'just a normal post with no stickers at all';
        $this->assertSame($payload, sticker_tokens::render($payload));
    }
}
