#!/usr/bin/env bash
# Run as the application owner. DEPLOY_REF defaults to main; DEPLOY_SHA pins a tested commit.
set -euo pipefail

cd "$(dirname "$0")/.."
for command in php composer npm git curl flock; do
    command -v "$command" >/dev/null || { printf 'Missing command: %s\n' "$command" >&2; exit 1; }
done
test -f .env || { printf 'Configure the server .env first.\n' >&2; exit 1; }
test -z "$(git status --porcelain --untracked-files=no)" || { printf 'Tracked local changes must be saved before deployment.\n' >&2; exit 1; }
mkdir -p storage/app/private storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
exec 9>storage/deploy.lock
flock -n 9 || { printf 'Another deployment is running.\n' >&2; exit 1; }

deploy_ref="${DEPLOY_REF:-main}"
git fetch --no-tags origin "$deploy_ref"
deploy_commit="${DEPLOY_SHA:-FETCH_HEAD}"
deploy_commit="$(git rev-parse --verify "${deploy_commit}^{commit}")"
git merge-base --is-ancestor "$deploy_commit" FETCH_HEAD || { printf 'The commit is outside the selected branch.\n' >&2; exit 1; }
printf 'Deploying %s\n' "$deploy_commit"

if test -f vendor/autoload.php; then
    php artisan down --retry=15 --refresh=15
fi
failed() {
    printf 'Deployment failed. Maintenance mode remains on; inspect the error before reopening.\n' >&2
    if test -f vendor/autoload.php; then php artisan down --retry=15 || true; fi
}
trap failed ERR

git reset --hard "$deploy_commit"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
php artisan optimize:clear
php artisan app:deployment-check --before-migrate
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force
if ! test -L public/storage; then php artisan storage:link; fi
php artisan optimize
php artisan app:deployment-check
php artisan queue:restart
php artisan up

# Read the configured URL without sourcing .env or printing any secret.
deploy_url="$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo rtrim(config("app.url"), "/");')"
curl --fail --silent --show-error --max-time 20 "${deploy_url}/up" >/dev/null
curl --fail --silent --show-error --max-time 20 "${deploy_url}/login" >/dev/null
trap - ERR
printf 'Deployed %s; health and login respond.\n' "$deploy_commit"
