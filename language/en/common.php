<?php
/**
 * HugMesh Forum API — English language strings.
 *
 * @copyright (c) 2026 HugMesh Systems
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
    'HUGMESH_FORUMAPI'                 => 'HugMesh Forum API',
    'HUGMESH_FORUMAPI_UNAUTHORIZED'    => 'Missing or invalid authentication token.',
    'HUGMESH_FORUMAPI_FORBIDDEN'       => 'You do not have permission to perform this action.',
    'HUGMESH_FORUMAPI_NOT_FOUND'       => 'The requested resource was not found.',
    'HUGMESH_FORUMAPI_BAD_REQUEST'     => 'The request body was malformed or failed validation.',
    'HUGMESH_FORUMAPI_SERVER_ERROR'    => 'An internal error occurred while processing the request.',
]);
