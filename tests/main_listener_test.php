<?php
/**
 * Integration-style tests for main_listener — proves the s9e\TextFormatter hook itself (not
 * just sticker_tokens' own logic) renders `:snap-sticker:<slug>:` correctly and safely,
 * through a REAL s9e\TextFormatter\Configurator/Parser/Renderer, exactly the mechanism phpBB
 * uses for its own BBCode/smiley pipeline (core.text_formatter_s9e_configure_after).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\tests;

use PHPUnit\Framework\TestCase;
use s9e\TextFormatter\Configurator;
use hugmesh\forumapi\event\main_listener;

class main_listener_test extends TestCase
{
    /**
     * Build a configurator exactly the way phpBB's own
     * phpbb\textformatter\s9e\factory::get_configurator() sets it up (PHP rendering engine —
     * no ext-xsl needed), run OUR listener against it (as core.text_formatter_s9e_configure_after
     * would), finalize, then parse+render $text through the resulting real parser/renderer.
     *
     * @param string $text
     * @return string
     */
    private function render_through_real_pipeline(string $text): string
    {
        $configurator = new Configurator();
        $configurator->rendering->engine = 'PHP';

        $listener = new main_listener();
        // Stand-in for phpBB's real \phpbb\event\data (ArrayAccess); the listener only ever
        // reads $event['configurator'].
        $event = new \ArrayObject(['configurator' => $configurator]);
        $listener->configure_sticker_tag($event);

        $objects = $configurator->finalize();
        $xml = $objects['parser']->parse($text);

        return $objects['renderer']->render($xml);
    }

    public function test_subscribes_to_the_documented_phpbb_hook(): void
    {
        $this->assertSame(
            ['core.text_formatter_s9e_configure_after' => 'configure_sticker_tag'],
            main_listener::getSubscribedEvents()
        );
    }

    public function test_valid_slug_renders_the_same_img_markup_as_forum_ui(): void
    {
        $html = $this->render_through_real_pipeline('hello :snap-sticker:wave-hi: world');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="forum-sticker"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringContainsString('alt="sticker wave-hi"', $html);
        $this->assertStringContainsString('src="/bff/user/character/stickers/wave-hi/body"', $html);
    }

    /**
     * The malicious "slug" (with a quote/angle-bracket break-out attempt) never matches the
     * tag's charset, so the whole token is parsed as ordinary text — and ordinary text is
     * HTML-escaped by the renderer itself, so the embedded `<script>` can only ever reach the
     * output as an inert, escaped entity, never as a live element.
     */
    public function test_malicious_slug_attempt_is_never_raw_injected(): void
    {
        $html = $this->render_through_real_pipeline(
            'hi :snap-sticker:foo"><script>alert(1)</script>: bye'
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
        // The dangerous fragment survives only in its HTML-escaped, inert form.
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_path_traversal_shaped_slug_is_not_rendered(): void
    {
        $html = $this->render_through_real_pipeline(':snap-sticker:../../etc/passwd:');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(':snap-sticker:../../etc/passwd:', $html);
    }

    public function test_uppercase_and_over_length_slugs_are_not_rendered(): void
    {
        $html = $this->render_through_real_pipeline(
            ':snap-sticker:UPPER: and :snap-sticker:' . str_repeat('a', 65) . ':'
        );

        $this->assertStringNotContainsString('<img', $html);
    }
}
