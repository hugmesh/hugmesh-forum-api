<?php
/**
 * HugMesh Forum API — runtime configuration provider (env-first, never committed).
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi;

/**
 * Resolves the extension's deployment configuration from environment variables, with an
 * optional fallback to the phpBB config table. NO secret value is ever committed — the
 * deployment (hugmesh-generator forum/phpbb.yaml) injects these as env vars / a
 * Secret. The JWKS URI / issuer are NON-secret references, but kept out of the repo so
 * the same image serves every brand/domain.
 *
 * Recognised keys:
 *  - HUGMESH_JWKS_URI       (required) the Casdoor jwks_uri to fetch signing keys.
 *  - HUGMESH_JWT_ISSUER     (required) the expected iss claim.
 *  - HUGMESH_JWT_AUDIENCE   (optional) the expected aud claim (validated if set).
 *  - HUGMESH_JWT_LEEWAY     (optional) clock-skew leeway seconds (default 30).
 *  - HUGMESH_DEFAULT_FORUM_ID (optional) the fallback phpBB forum_id for new topics
 *                              when CreateTopic.categoryId is 0/absent (default 2).
 */
class config_provider
{
    /**
     * @var \phpbb\config\config|null
     */
    private $config;

    /**
     * @param \phpbb\config\config|null $config Optional phpBB config for table fallback.
     */
    public function __construct($config = null)
    {
        $this->config = $config;
    }

    /**
     * The Casdoor jwks_uri to fetch signing keys from. MUST be https:// — a plain-http
     * JWKS is a signing-key downgrade vector, so a non-https (or empty) configured value
     * fails closed here rather than silently fetching keys over an unverified channel.
     *
     * @return string
     *
     * @throws \RuntimeException When configured and not an absolute https:// URL.
     */
    public function jwks_uri()
    {
        $uri = $this->resolve('HUGMESH_JWKS_URI', 'hugmesh_forumapi_jwks_uri', '');
        if ($uri !== '' && stripos($uri, 'https://') !== 0)
        {
            throw new \RuntimeException('HugMesh Forum API: HUGMESH_JWKS_URI must be an absolute https:// URL.');
        }
        return $uri;
    }

    /**
     * @return string
     */
    public function issuer()
    {
        return $this->resolve('HUGMESH_JWT_ISSUER', 'hugmesh_forumapi_issuer', '');
    }

    /**
     * @return string
     */
    public function audience()
    {
        return $this->resolve('HUGMESH_JWT_AUDIENCE', 'hugmesh_forumapi_audience', '');
    }

    /**
     * @return int
     */
    public function leeway()
    {
        return (int) $this->resolve('HUGMESH_JWT_LEEWAY', 'hugmesh_forumapi_leeway', '30');
    }

    /**
     * @return int
     */
    public function default_forum_id()
    {
        return (int) $this->resolve('HUGMESH_DEFAULT_FORUM_ID', 'hugmesh_forumapi_default_forum_id', '2');
    }

    /**
     * Resolve env first, then phpBB config table, then default.
     *
     * @param string $env_key
     * @param string $config_key
     * @param string $default
     * @return string
     */
    private function resolve($env_key, $config_key, $default)
    {
        $env = getenv($env_key);
        if ($env !== false && trim((string) $env) !== '')
        {
            return trim((string) $env);
        }
        if ($this->config !== null && isset($this->config[$config_key]) && (string) $this->config[$config_key] !== '')
        {
            return (string) $this->config[$config_key];
        }
        return $default;
    }
}
