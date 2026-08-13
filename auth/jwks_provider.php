<?php
/**
 * HugMesh Forum API — JWKS provider.
 *
 * @copyright (c) 2026 HugMesh Systems
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace hugmesh\forumapi\auth;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/**
 * Fetches and CACHES the Casdoor JWKS (the JSON Web Key Set) from the issuer's
 * {@code jwks_uri}, returning {@code firebase/php-jwt} {@see Key} objects keyed by
 * {@code kid} for {@see \Firebase\JWT\JWT::decode()}.
 *
 * The JWKS URI is read from extension config / environment (NEVER committed — see the
 * README). The fetched key set is cached (in the phpBB cache driver when present, else
 * a short in-process memo) to avoid a network round-trip per request, and is refreshed
 * after a TTL or on a forced miss (key rotation).
 *
 * This class deliberately has NO phpBB type dependencies in its hot path beyond the
 * optional cache driver, so it can be unit-tested against an HTTP-fetcher double.
 */
class jwks_provider
{
    /**
     * The cache key under which the parsed JWKS document is stored.
     */
    public const CACHE_KEY = '_hugmesh_forumapi_jwks';

    /**
     * Cache TTL for the JWKS document, in seconds (rotation grace).
     */
    public const CACHE_TTL = 3600;

    /**
     * @var string The absolute jwks_uri (https) to fetch the key set from.
     */
    private $jwks_uri;

    /**
     * @var callable A fetcher: (string $url): string — returns the raw JWKS JSON body.
     *               Injected so unit tests can supply a double instead of a live HTTP call.
     */
    private $fetcher;

    /**
     * @var \phpbb\cache\driver\driver_interface|null Optional phpBB cache driver.
     */
    private $cache;

    /**
     * @var array<string, Key>|null In-process memo of the parsed key set.
     */
    private $memo = null;

    /**
     * @param string                                          $jwks_uri The issuer jwks_uri.
     * @param callable|null                                   $fetcher  Optional HTTP fetcher (string $url): string.
     * @param \phpbb\cache\driver\driver_interface|null       $cache    Optional phpBB cache driver.
     */
    public function __construct($jwks_uri, $fetcher = null, $cache = null)
    {
        $this->jwks_uri = (string) $jwks_uri;
        $this->fetcher = $fetcher ?: [$this, 'http_get'];
        $this->cache = $cache;
    }

    /**
     * Return the parsed JWKS as a map of {@code kid => Key}, suitable for
     * {@see \Firebase\JWT\JWT::decode()}.
     *
     * @param bool $force_refresh Bypass the cache (used on a kid miss / rotation).
     * @return array<string, Key>
     *
     * @throws \RuntimeException When the JWKS cannot be fetched or parsed.
     */
    public function get_keys($force_refresh = false)
    {
        if (!$force_refresh && $this->memo !== null)
        {
            return $this->memo;
        }

        $jwks = $this->load_jwks_document($force_refresh);

        if (!is_array($jwks) || empty($jwks['keys']))
        {
            throw new \RuntimeException('HugMesh Forum API: JWKS document is empty or malformed.');
        }

        // JWK::parseKeySet() converts the JWKS array into a kid => Key map. Pin RS256 as
        // the default alg so any JWKS entry that omits "alg" is treated as RS256 (never an
        // attacker-chosen symmetric alg), and then defence-in-depth: drop any parsed Key
        // whose algorithm is not RS256. Combined with the verifier's per-key alg check in
        // firebase/php-jwt, this makes an alg-confusion (e.g. an HS256 key smuggled into
        // the JWKS) structurally impossible to use against an RS256 token.
        $parsed = JWK::parseKeySet($jwks, 'RS256');

        $keys = [];
        foreach ($parsed as $kid => $key)
        {
            if (!$this->is_rs256_key($key))
            {
                // Reject non-RS256 keys outright rather than caching them.
                continue;
            }
            $keys[$kid] = $key;
        }

        if (empty($keys))
        {
            throw new \RuntimeException('HugMesh Forum API: JWKS contains no usable RS256 keys.');
        }

        $this->memo = $keys;

        return $keys;
    }

    /**
     * Whether a parsed {@see Key} is an RS256 key (the only signing alg this verifier
     * accepts). firebase/php-jwt's Key carries the algorithm it was parsed with; RS256
     * implies an RSA public key, so the alg check is sufficient to also guarantee kty=RSA
     * (a symmetric/oct key parses as HS*, an EC key as ES*).
     *
     * @param mixed $key
     * @return bool
     */
    private function is_rs256_key($key)
    {
        if (!$key instanceof Key)
        {
            return false;
        }
        return $key->getAlgorithm() === 'RS256';
    }

    /**
     * Load the raw JWKS document (array form), via cache when available.
     *
     * @param bool $force_refresh
     * @return array
     *
     * @throws \RuntimeException
     */
    private function load_jwks_document($force_refresh)
    {
        if (!$force_refresh && $this->cache !== null)
        {
            $cached = $this->cache->get(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached['keys']))
            {
                return $cached;
            }
        }

        if ($this->jwks_uri === '')
        {
            throw new \RuntimeException('HugMesh Forum API: no JWKS URI configured (HUGMESH_JWKS_URI).');
        }

        $raw = call_user_func($this->fetcher, $this->jwks_uri);
        $doc = json_decode((string) $raw, true);

        if (!is_array($doc))
        {
            throw new \RuntimeException('HugMesh Forum API: JWKS endpoint did not return valid JSON.');
        }

        if ($this->cache !== null)
        {
            $this->cache->put(self::CACHE_KEY, $doc, self::CACHE_TTL);
        }

        return $doc;
    }

    /**
     * Default HTTP fetcher. Uses cURL when available, else file_get_contents with a
     * short timeout. HTTPS only (the JWKS is an internal cluster URL in deployment, but
     * we still refuse plain http to avoid a downgrade fetch).
     *
     * @param string $url
     * @return string
     *
     * @throws \RuntimeException
     */
    public function http_get($url)
    {
        // HTTPS-ONLY. The docblock says so; enforce it here. A plain-http JWKS fetch is a
        // signing-key downgrade vector (an on-path attacker could serve a JWKS of their
        // own keys), so we refuse anything that is not https:// outright.
        if (stripos((string) $url, 'https://') !== 0)
        {
            // Same dev-lane escape as config_provider::jwks_uri, and ONLY that:
            // an explicit HUGMESH_JWKS_ALLOW_INSECURE_HTTP=1 permits an http://
            // URL (the compose lane's in-network plain-http Casdoor). https://
            // fetches below keep full VERIFYPEER/VERIFYHOST pinning regardless
            // of the knob — it can never weaken a verified TLS fetch.
            if (getenv('HUGMESH_JWKS_ALLOW_INSECURE_HTTP') !== '1'
                || stripos((string) $url, 'http://') !== 0)
            {
                throw new \RuntimeException('HugMesh Forum API: JWKS URI must be an absolute https:// URL.');
            }
        }

        if (function_exists('curl_init'))
        {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            // Verify the TLS chain + hostname — never accept an unverified JWKS endpoint.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false || $code < 200 || $code >= 300)
            {
                throw new \RuntimeException('HugMesh Forum API: JWKS fetch failed (HTTP ' . $code . ') ' . $err);
            }

            return (string) $body;
        }

        // file_get_contents fallback: pin TLS verification on the https stream context.
        $context = stream_context_create([
            'http' => [
                'timeout'       => 5,
                'ignore_errors' => false,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        if ($body === false)
        {
            throw new \RuntimeException('HugMesh Forum API: JWKS fetch failed via file_get_contents.');
        }

        return (string) $body;
    }
}
