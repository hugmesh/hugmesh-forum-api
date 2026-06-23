<?php
/**
 * Snapdragon JSON API — English language strings.
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB'))
{
    exit;
}

if (empty($lang) || !is_array($lang))
{
    $lang = [];
}

// DEVELOPERS PLEASE NOTE: All language files should use UTF-8 (without BOM).
$lang = array_merge($lang, [
    'SNAPDRAGON_JSONAPI'                 => 'Snapdragon JSON API',
    'SNAPDRAGON_JSONAPI_UNAUTHORIZED'    => 'Missing or invalid authentication token.',
    'SNAPDRAGON_JSONAPI_FORBIDDEN'       => 'You do not have permission to perform this action.',
    'SNAPDRAGON_JSONAPI_NOT_FOUND'       => 'The requested resource was not found.',
    'SNAPDRAGON_JSONAPI_BAD_REQUEST'     => 'The request body was malformed or failed validation.',
    'SNAPDRAGON_JSONAPI_SERVER_ERROR'    => 'An internal error occurred while processing the request.',
]);
