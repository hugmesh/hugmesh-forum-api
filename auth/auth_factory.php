<?php
/**
 * Snapdragon Forum API — auth factory (builds the JWKS provider + verifier + authenticator).
 *
 * @copyright (c) 2026 Snapdragon Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace snapdragon\forumapi\auth;

use snapdragon\forumapi\config_provider;

/**
 * Constructs the auth chain from the runtime {@see config_provider} so the
 * (env/config-resolved) JWKS URI, issuer, audience and leeway are read at request time
 * rather than baked into the service definition. Avoids ExpressionLanguage in
 * services.yml (not guaranteed enabled in the phpBB container).
 */
class auth_factory
{
    /**
     * @var config_provider
     */
    private $cfg;

    /**
     * @var \phpbb\cache\driver\driver_interface|null
     */
    private $cache;

    /**
     * @var authenticator|null Memoised authenticator.
     */
    private $authenticator = null;

    /**
     * @param config_provider                          $cfg
     * @param \phpbb\cache\driver\driver_interface|null $cache
     */
    public function __construct(config_provider $cfg, $cache = null)
    {
        $this->cfg = $cfg;
        $this->cache = $cache;
    }

    /**
     * @return jwks_provider
     */
    public function jwks_provider()
    {
        return new jwks_provider($this->cfg->jwks_uri(), null, $this->cache);
    }

    /**
     * @return jwt_verifier
     */
    public function verifier()
    {
        return new jwt_verifier(
            $this->jwks_provider(),
            $this->cfg->issuer(),
            $this->cfg->audience(),
            $this->cfg->leeway()
        );
    }

    /**
     * The single authenticator the controllers consume.
     *
     * @return authenticator
     */
    public function authenticator()
    {
        if ($this->authenticator === null)
        {
            $this->authenticator = new authenticator($this->verifier());
        }
        return $this->authenticator;
    }
}
