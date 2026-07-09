#!/bin/sh
set -e

# Pull secrets from Parameter Store into this process environment only.
# Requires the EC2 instance role (IMDSv2) to grant ssm:GetParametersByPath.
# Set SSM_SKIP=1 for local image smoke tests only.
if [ "${SSM_SKIP:-0}" != "1" ]; then
    eval "$(php /var/www/html/artisan ssm:export)"
fi

php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache

exec "$@"
