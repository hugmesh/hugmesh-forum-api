<?php
/**
 * Snapdragon JSON API — phpBB 3.3 headless JSON API extension.
 *
 * Installs at ext/snapdragon/jsonapi/ (composer name snapdragon/jsonapi — MUST equal the
 * directory path and contain no hyphen, or phpBB cannot enable it).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\jsonapi;

/**
 * The extension metadata / lifecycle class.
 *
 * Extends {@see \phpbb\extension\base}, which provides the default enable/disable/purge
 * steps (running and reverting the migrations under {@code migrations/}). We only need
 * {@see ext::is_enableable()} to guard the minimum phpBB and PHP versions.
 */
class ext extends \phpbb\extension\base
{
    /**
     * The minimum supported phpBB version (inclusive).
     */
    public const PHPBB_MIN = '3.3.0';

    /**
     * The first UNsupported phpBB major (exclusive upper bound).
     */
    public const PHPBB_MAX = '4.0.0-dev';

    /**
     * The minimum supported PHP version (inclusive).
     */
    public const PHP_MIN = '8.1.0';

    /**
     * Guard min phpBB 3.3 and PHP 8.1. Returning false makes the ACP refuse to enable
     * the extension on an unsupported platform (fail-closed install).
     *
     * @return bool
     */
    public function is_enableable()
    {
        $phpbb_ok = phpbb_version_compare(PHPBB_VERSION, self::PHPBB_MIN, '>=')
            && phpbb_version_compare(PHPBB_VERSION, self::PHPBB_MAX, '<');

        $php_ok = phpbb_version_compare(PHP_VERSION, self::PHP_MIN, '>=');

        return $phpbb_ok && $php_ok;
    }
}
