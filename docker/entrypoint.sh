#!/usr/bin/env bash
#
# Snapdragon phpBB entrypoint.
#
# On first boot (when phpBB is installed and a DB is reachable) it enables the
# snapdragon/json-api extension via phpbbcli, so the /api/v1/snapdragon/** routes are
# live. It is idempotent: re-enabling an already-enabled extension is a no-op. If phpBB
# is not yet installed (no config.php), it skips enabling and lets the operator run the
# phpBB installer first.
#
# Required env (injected by the deployment, NEVER baked into the image):
#   SNAPDRAGON_JWKS_URI     the Casdoor jwks_uri
#   SNAPDRAGON_JWT_ISSUER   the expected iss claim
#   SNAPDRAGON_JWT_AUDIENCE (optional) the expected aud claim
#
set -euo pipefail

PHPBB_ROOT="${PHPBB_ROOT:-/var/www/html}"
EXT_NAME="${PHPBB_EXTENSION:-snapdragon/json-api}"

cd "$PHPBB_ROOT"

if [[ -f "config.php" ]]; then
    echo "[snapdragon-entrypoint] phpBB config.php present — ensuring extension '${EXT_NAME}' is enabled"
    # phpbbcli is the supported offline management CLI for 3.3.
    if php bin/phpbbcli.php extension:show 2>/dev/null | grep -q "${EXT_NAME}"; then
        php bin/phpbbcli.php extension:enable "${EXT_NAME}" || \
            echo "[snapdragon-entrypoint] extension:enable returned non-zero (already enabled?) — continuing"
    else
        echo "[snapdragon-entrypoint] extension '${EXT_NAME}' not discovered by phpbbcli — check ext/snapdragon/jsonapi"
    fi
else
    echo "[snapdragon-entrypoint] no config.php yet — phpBB not installed; run the phpBB installer, then restart to enable '${EXT_NAME}'"
fi

# Warn loudly (but do not hard-fail) if the JWT config is absent — the extension fails
# closed (401) at request time without it, so this is just an early operator signal.
if [[ -z "${SNAPDRAGON_JWKS_URI:-}" || -z "${SNAPDRAGON_JWT_ISSUER:-}" ]]; then
    echo "[snapdragon-entrypoint] WARNING: SNAPDRAGON_JWKS_URI / SNAPDRAGON_JWT_ISSUER not set — JWT validation will reject every request (fail-closed)"
fi

exec "$@"
