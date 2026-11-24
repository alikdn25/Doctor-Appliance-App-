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
if test "${DEPLOY_REEXEC:-}" = 1; then
    # Second pass: the new version of this script, already at the commit the first pass checked out.
    deploy_commit="$(git rev-parse --verify "${DEPLOY_SHA:?}^{commit}")"
    test "$(git rev-parse HEAD)" = "$deploy_commit" || { printf 'Re-run is not at %s.\n' "$deploy_commit" >&2; exit 1; }
    printf 'Running the deploy script of %s\n' "$deploy_commit"
else
    git fetch --no-tags origin "$deploy_ref"
    deploy_commit="${DEPLOY_SHA:-FETCH_HEAD}"
    deploy_commit="$(git rev-parse --verify "${deploy_commit}^{commit}")"
    git merge-base --is-ancestor "$deploy_commit" FETCH_HEAD || { printf 'The commit is outside the selected branch.\n' >&2; exit 1; }
    printf 'Deploying %s\n' "$deploy_commit"
fi

if test -f vendor/autoload.php; then
    php artisan down --retry=15 --refresh=15
fi
failed() {
    printf 'Deployment failed. Maintenance mode remains on; inspect the error before reopening.\n' >&2
    if test -f vendor/autoload.php; then php artisan down --retry=15 || true; fi
}
trap failed ERR

if test "${DEPLOY_REEXEC:-}" != 1; then
    previous_commit="$(git rev-parse HEAD)"
    git reset --hard "$deploy_commit"
    # Run the rest with the deploy script of the new commit (once: DEPLOY_REEXEC stops a loop). The lock is taken
    # again by the new script; maintenance mode stays on.
    trap - ERR
    exec 9>&-
    DEPLOY_REEXEC=1 DEPLOY_SHA="$deploy_commit" DEPLOY_PREVIOUS_COMMIT="$previous_commit" exec bash deploy/deploy.sh
fi
previous_commit="${DEPLOY_PREVIOUS_COMMIT:-$deploy_commit}"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
php artisan optimize:clear
php artisan app:deployment-check --before-migrate
npm ci --no-audit --no-fund
npm run build
# The running version: the app offers "Update" when it differs from the one a phone loaded.
printf '%s\n' "$deploy_commit" > storage/app/release
# Back up the database before migrations (external storage, or a local copy until it is set up).
# No backup, no migrations: the deployment stops here and maintenance mode stays on.
if ! php artisan backup:run --reason=deploy; then
    printf 'Database backup failed; deployment stopped before migrations. Previous commit: %s\n' "$previous_commit" >&2
    failed
    exit 1
fi
php artisan migrate --force
if ! test -L public/storage; then php artisan storage:link; fi
# Photo folders made before the private disk had permissions: folders 2775, files 0664 (runs once), as the web
# server user, who owns most photo folders. Needs sudo for the deploy user to run as www-data without a password.
sudo -n -u www-data php artisan app:fix-storage-permissions
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
