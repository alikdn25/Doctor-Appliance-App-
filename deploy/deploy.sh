#!/usr/bin/env bash
# Production deploy. Runs on the VPS from the application directory
# (called by .github/workflows/deploy.yml, or manually: bash deploy/deploy.sh).
set -euo pipefail

cd "$(dirname "$0")/.."

SHA="${DEPLOY_SHA:-origin/main}"

echo "==> Deploying ${SHA}"

php artisan down --retry=15 --refresh=15 || true
trap 'echo "!! Deploy failed, bringing the app back up"; php artisan up' ERR

git fetch --prune origin main
git reset --hard "${SHA}"

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

npm ci --no-audit --no-fund
npm run build

php artisan migrate --force
php artisan storage:link 2>/dev/null || true
php artisan optimize
php artisan queue:restart

php artisan up
trap - ERR

echo "==> Deployed $(git rev-parse --short HEAD)"
