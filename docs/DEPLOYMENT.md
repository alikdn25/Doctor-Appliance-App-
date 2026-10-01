# Deployment (production VPS)

Pipeline: **GitHub Actions `deploy` (run manually on `main`) → SSH to the VPS → `deploy/deploy.sh`.**
There is no staging environment. Every push and pull request runs the `tests` workflow.

For now deploys are **manual only**: _Actions → deploy → Run workflow_ (branch `main`).
Once the server is set up, deploys can be made automatic after green tests — see the comment at the top of
`.github/workflows/deploy.yml`.

## 1. One-time server setup (Ubuntu 24.04 on Contabo)

```bash
# as root
apt update && apt install -y nginx postgresql redis-server supervisor git unzip certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs

# deploy user (owns the app, runs deploys and the queue worker)
adduser --disabled-password --gecos "" deploy
usermod -aG www-data deploy

# database
sudo -u postgres psql -c "CREATE USER fieldservice WITH PASSWORD 'CHANGE-ME';"
sudo -u postgres createdb -O fieldservice fieldservice
```

As the `deploy` user:

```bash
# Read-only deploy key so the server can pull the repository
ssh-keygen -t ed25519 -f ~/.ssh/github_repo -N ""
cat ~/.ssh/github_repo.pub   # add in GitHub: repo → Settings → Deploy keys (read-only)
printf "Host github.com\n  IdentityFile ~/.ssh/github_repo\n" >> ~/.ssh/config

sudo mkdir -p /var/www/fieldservice && sudo chown deploy:www-data /var/www/fieldservice
git clone git@github.com:alikdn25/Doctor-Appliance-App-.git /var/www/fieldservice
cd /var/www/fieldservice
cp .env.example .env      # then edit .env, see below
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan storage:link
chmod -R ug+rw storage bootstrap/cache && chgrp -R www-data storage bootstrap/cache

php artisan app:create-super-admin   # your platform account (2FA is required on first sign-in)
```

Production `.env` values that differ from `.env.example`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com
DB_DATABASE=fieldservice
DB_USERNAME=fieldservice
DB_PASSWORD=<the password above>
MAIL_MAILER=smtp            # + MAIL_* of the chosen email provider
SESSION_SECURE_COOKIE=true
```

Web server, worker, scheduler, SSL:

```bash
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/fieldservice   # edit server_name
sudo ln -s /etc/nginx/sites-available/fieldservice /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.example.com

sudo cp deploy/supervisor-worker.conf.example /etc/supervisor/conf.d/fieldservice-worker.conf
sudo supervisorctl reread && sudo supervisorctl update

# Laravel scheduler (crontab -e as deploy)
* * * * * cd /var/www/fieldservice && php artisan schedule:run >> /dev/null 2>&1
```

## 2. SSH key for GitHub Actions

On your computer (not the server):

```bash
ssh-keygen -t ed25519 -f gha_deploy -N "" -C "github-actions-deploy"
ssh-copy-id -i gha_deploy.pub deploy@<server-ip>      # or append to /home/deploy/.ssh/authorized_keys
ssh-keyscan -p 22 <server-ip>                          # output = SSH_KNOWN_HOSTS
```

## 3. GitHub secrets

Repository → **Settings → Environments → New environment → `production`**, then add these **environment secrets**
(optionally add yourself as a required reviewer to approve each deploy):

| Secret            | Value                                                                                |
| ----------------- | ------------------------------------------------------------------------------------ |
| `SSH_HOST`        | Server IP or hostname                                                                |
| `SSH_PORT`        | SSH port (optional, default `22`)                                                    |
| `SSH_USER`        | `deploy`                                                                             |
| `SSH_PRIVATE_KEY` | Full contents of the private key file `gha_deploy` (including the `BEGIN/END` lines) |
| `SSH_KNOWN_HOSTS` | Output of `ssh-keyscan` above (pins the server's host key)                           |
| `APP_PATH`        | `/var/www/fieldservice`                                                              |

Application secrets (database password, mail credentials, later Square/Twilio keys) live **only** in the server's
`.env`, never in GitHub or in the repository.

## 4. What `deploy/deploy.sh` does

1. `php artisan down` (maintenance mode; brought back up automatically if a step fails)
2. `git fetch` + `git reset --hard <tested commit>`
3. `composer install --no-dev`, `npm ci && npm run build`
4. `php artisan migrate --force`, `storage:link`, `optimize`, `queue:restart`
5. `php artisan up`

Rollback: on the server run `DEPLOY_SHA=<older-commit-sha> bash deploy/deploy.sh`
(database migrations are not rolled back automatically).
