#!/bin/sh
set -eu
umask 077

if [ "${APP_ENV:-}" != prod ] || [ "${APP_DEBUG:-}" != 0 ]; then
    echo "This image requires APP_ENV=prod and APP_DEBUG=0." >&2
    exit 1
fi
APP_SECRET=${APP_SECRET:-}
if [ "${#APP_SECRET}" -lt 32 ]; then
    echo "Set APP_SECRET to a random secret of at least 32 characters." >&2
    exit 1
fi
mkdir -p var/cache var/log var/sessions var/config var/data
: > var/access.caddy
if [ -n "${OPENAI_API_KEY:-}" ] || [ -n "${SNAPSHOT_SITE_API_KEY:-}" ]; then
    if [ -z "${OPENAI_API_KEY:-}" ] || [ -z "${SNAPSHOT_SITE_API_KEY:-}" ]; then
        echo "Supply both provider API keys, or leave both empty for demo mode." >&2
        exit 1
    fi
    if ! php -r '
        $user = getenv("REVIEW_BASIC_USER") ?: "";
        $hash = getenv("REVIEW_BASIC_PASSWORD_HASH") ?: "";
        exit(preg_match("/^[A-Za-z0-9_-]{1,64}$/D", $user) === 1
            && (password_get_info($hash)["algoName"] ?? "") === "bcrypt" ? 0 : 1);
    '; then
        echo "Live mode requires REVIEW_BASIC_USER and a bcrypt REVIEW_BASIC_PASSWORD_HASH." >&2
        exit 1
    fi
    cat > var/access.caddy <<'CADDY'
basic_auth {
    {env.REVIEW_BASIC_USER} {env.REVIEW_BASIC_PASSWORD_HASH}
}
CADDY
fi
php bin/console cache:clear --env=prod --no-debug --no-warmup
php bin/console cache:warmup --env=prod --no-debug
exec "$@"
