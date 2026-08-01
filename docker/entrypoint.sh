#!/bin/sh
set -e

# APP_KEY encrypts SystemSetting's stored provider API keys (GenMax, image-gen).
# Auto-generating it here would silently make every previously-saved encrypted
# value undecryptable on the next redeploy — refuse to start instead, so the
# key is always a deliberate, one-time, persisted value set in the Portainer
# stack's environment variables.
if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set."
    echo "Generate one once (e.g. run 'php artisan key:generate --show' locally"
    echo "or in a throwaway container) and set it as a fixed APP_KEY environment"
    echo "variable in this stack's Portainer configuration before deploying."
    echo "Never regenerate it on an already-deployed stack."
    exit 1
fi

php artisan storage:link 2>/dev/null || true

# Deferred from the build stage (see Dockerfile's vendor stage comment) —
# real environment variables are available now, so any boot-time config
# guard (e.g. the production SEPAY_WEBHOOK_TOKEN check) evaluates correctly.
php artisan package:discover --ansi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
