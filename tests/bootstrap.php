<?php
/**
 * PHPUnit bootstrap for the JWT-validation unit tests.
 *
 * These tests run WITHOUT a live phpBB: they exercise the pure auth chain
 * (jwks_provider -> jwt_verifier -> authenticator) against an in-memory JWKS and
 * locally-signed RS256 tokens. Only composer's autoloader (firebase/php-jwt + the
 * extension's PSR-4 map) is needed.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload))
{
    fwrite(STDERR, "vendor/autoload.php missing — run `composer install` first.\n");
    exit(1);
}
require $autoload;
