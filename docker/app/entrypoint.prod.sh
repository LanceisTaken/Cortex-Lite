#!/bin/sh
set -e

# Pull secrets from Parameter Store into this process environment only.
# Requires the EC2 instance role (IMDSv2) to grant ssm:GetParametersByPath.
# Set SSM_SKIP=1 for local image smoke tests only.
if [ "${SSM_SKIP:-0}" != "1" ]; then
    # Capture separately so a failed fetch aborts the boot. A failure inside
    # `eval "$(...)"` does NOT trip `set -e` (the command substitution's exit
    # status is discarded), which previously let a failed/empty fetch fall
    # through and let `config:cache` bake a blank APP_KEY into the config cache
    # that then persisted across `restart: unless-stopped`.
    ssm_exports="$(php /var/www/html/artisan ssm:export)" || {
        echo "FATAL: ssm:export failed; refusing to boot with blank secrets" >&2
        exit 1
    }
    eval "$ssm_exports"
    # Guard against a silent empty fetch (wrong path / zero parameters):
    # refuse to cache config unless the encryption key actually loaded.
    : "${APP_KEY:?FATAL: APP_KEY not populated from Parameter Store; aborting boot}"
fi

php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache

exec "$@"
