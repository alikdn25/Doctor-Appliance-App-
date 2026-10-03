# Deployment (production VPS)

Pipeline: **GitHub Actions `deploy` (run manually on `main`) → SSH to the VPS → `deploy/deploy.sh`.**
There is no staging environment. Every push and pull request runs the `tests` workflow.

For now deploys are **manual only**: _Actions → deploy → Run workflow_ (branch `main`).
Once the server is set up, deploys can be made automatic after green tests — see the comment at the top of
`.github/workflows/deploy.yml`.

## 1. One-time server setup (Ubuntu 24.04 on Contabo)

```bash
# as root
# redis-server and php8.3-redis are optional (see §5)
apt update && apt install -y nginx postgresql supervisor git unzip certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs

# deploy user owns the app and runs deploys; PHP-FPM and the worker use www-data
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

php artisan app:create-super-admin   # your platform account (2FA is optional)
```

Production `.env` values that differ from `.env.example`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.doctor-appliance.ca
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
sudo certbot --nginx -d app.doctor-appliance.ca

sudo cp deploy/supervisor-worker.conf.example /etc/supervisor/conf.d/fieldservice-worker.conf
sudo supervisorctl reread && sudo supervisorctl update

# Laravel scheduler (crontab -e as deploy)
* * * * * cd /var/www/fieldservice && php artisan schedule:run >> /dev/null 2>&1
```

Keep the queue worker and PHP-FPM on the same OS user (`www-data` in these examples), so queued PDFs and emails
can read private photos/signatures created by web requests. If PHP-FPM runs under another user, set that same
user in Supervisor. Before testing camera uploads, configure the application's PHP-FPM pool with
`php_admin_value[upload_max_filesize] = 15M` and `php_admin_value[post_max_size] = 20M`, then reload PHP-FPM;
otherwise PHP's default upload limit can reject receipts before Laravel validates them.

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

1. Verify prerequisites and a clean checkout, lock deployment and resolve the selected tested commit; enter maintenance mode
2. `git fetch` + `git reset --hard <tested commit>`
3. `composer install --no-dev`, clear old config, run `app:deployment-check --before-migrate`, then build assets
4. `php artisan migrate --force`, `storage:link`, `optimize`, `queue:restart`
5. Run `app:deployment-check`, reopen and verify HTTPS `/up` and `/login`. A failed deployment leaves maintenance on for diagnosis

Take and verify a database and media backup before upgrading an existing installation.
For testing a green draft commit, use `DEPLOY_REF=chatgpt/bolt-ui DEPLOY_SHA=<tested-sha> bash deploy/deploy.sh`
on the server; the GitHub production workflow remains limited to `main`.

Recovery: leave maintenance mode on until the failed step is understood. Choose a previously tested revision
whose code supports the installed schema, and use that revision's deployment instructions; older revisions
predating app:deployment-check cannot use the current script unchanged. Database migrations are not rolled
back automatically. Restore a verified backup when recovery requires older schema/data.

## 5. Redis or not?

**Redis is not needed now.** With 1–5 people per company and a few companies, PostgreSQL handles the queue, the cache
and the sessions without trouble:

```
QUEUE_CONNECTION=database   # jobs table (emails, SMS, PDFs, reminders)
CACHE_STORE=database        # cache table; also holds the scheduler's "withoutOverlapping" locks
SESSION_DRIVER=database     # sessions table
```

The tables come with the migrations. What **must** run either way:

- the **queue worker** (`deploy/supervisor-worker.conf.example`, `php artisan queue:work` — it uses `QUEUE_CONNECTION`),
  otherwise no email or SMS goes out;
- the **scheduler** cron (`schedule:run` every minute): visit reminders, strict-arrival reminders, delayed texts,
  Square token refresh, A2P status sync.

`file` also works for the cache on a single server, but not for queues; `database` is the simple choice.

**When to switch to Redis**: many companies, thousands of queued messages a day, or more than one app server. Then:

```bash
sudo apt install -y redis-server php8.3-redis
sudo systemctl enable --now redis-server
```

```
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null        # set requirepass in /etc/redis/redis.conf if the port is not firewalled
REDIS_PORT=6379
```

then `php artisan config:cache && php artisan queue:restart`. Jobs still waiting in the `jobs` table are not moved:
switch when the queue is empty (`php artisan queue:monitor database:default`).

## 6. Google Maps key (address suggestions and calendar map)

1. Google Cloud Console → create a project → **Billing** (Places is billed per session; there is a monthly free
   credit).
2. **APIs & Services → Library**: enable **Maps JavaScript API** and **Places API (New)**.
3. **Credentials → Create credentials → API key**, then **Edit**:
    - Application restrictions: **Websites (HTTP referrers)** → `https://app.doctor-appliance.ca/*`
      (add `http://localhost:8000/*` on a separate development key, never on the production one);
    - API restrictions: **Restrict key** → Maps JavaScript API, Places API (New).
4. In the server's `.env`: `GOOGLE_MAPS_BROWSER_KEY=<the key>`. Create a JavaScript map ID in Google Cloud
   Map Management and set `GOOGLE_MAPS_MAP_ID=<your map ID>` for the calendar map. The built-in demo ID is for
   initial testing. Run `php artisan config:cache` after changing either setting.

The key is sent to the browser (that is how the Maps JavaScript API works), which is why the referrer and API
restrictions matter. Without a key, address fields are typed by hand.

## 7. `.env` reference

Required in production:

| Variable                                                                                                         | Notes                                                                                  |
| ---------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`                                                              |                                                                                        |
| `APP_KEY`                                                                                                        | `php artisan key:generate` once; never change it (encrypted tokens depend on it)       |
| `APP_URL`                                                                                                        | `https://app.doctor-appliance.ca` — used in links, webhooks, OAuth callbacks           |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`                         | PostgreSQL                                                                             |
| `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`                                                          |                                                                                        |
| `QUEUE_CONNECTION`, `CACHE_STORE`                                                                                | `database` (or `redis`, §5)                                                            |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | transactional email provider; `MAIL_FROM_ADDRESS` on a verified domain                 |
| Optional 2FA                                                                                                     | Enabled by each user in Security settings. Legacy AUTH_REQUIRE_TWO_FACTOR is ignored.  |
| `MEDIA_DISK=public`, `PRIVATE_MEDIA_DISK=local`                                                                  | private media (photos, signatures, receipts) stay in `storage/app/private`; back it up |

Required for the features that use them (empty = the feature is off):

| Variable                                                                                                                                               | Feature                                                                           |
| ------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------- |
| `SQUARE_ENVIRONMENT`, `SQUARE_APPLICATION_ID`, `SQUARE_APPLICATION_SECRET`, `SQUARE_WEBHOOK_SIGNATURE_KEY`, `SQUARE_WEBHOOK_URL`, `SQUARE_API_VERSION` | online payments, deposits, refunds (`production` + production app keys when live) |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`                                                                                                              | SMS in Automatic mode                                                             |
| `GOOGLE_MAPS_BROWSER_KEY`                                                                                                                              | address suggestions (§6)                                                          |
| `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`, `REDIS_CLIENT`                                                                                           | only with Redis (§5)                                                              |
| `AWS_*`                                                                                                                                                | only when media move to S3-compatible storage                                     |

Optional with sensible defaults: `DEFAULT_COMPANY_COUNTRY` (US), `SMS_REMINDER_HOUR` (17), `AUTH_PASSWORD_RESET_EXPIRE`
(1440), `TZDATA_ZONEINFO_FILE`, `LOG_CHANNEL`/`LOG_LEVEL`, `APP_LOCALE` (en).

Backups: the PostgreSQL database **and** `storage/app/private` (job photos, signatures, cash receipts, supplier
receipts — the latter must be kept 6+ years for the bookkeeper).

## Public registration and first-run setup

Users create their own account at `/register` and set up their first company at `/onboarding/company`. The initial Owner and brand are automatic. Account mail readiness is an explicit server setting: keep `AUTH_EMAIL_DELIVERY_ENABLED=false` while mail is not connected. In this mode, registration and ordinary working access do not require a letter, emails remain unverified, and password recovery/resend explain that mail is unavailable. New staff and administrator-created Owners receive an initial confirmed password through the Team/company form; existing accounts keep their passwords. Company/role restrictions still apply.

Configure real SMTP and the queue, send a controlled test using the actual provider, and verify receipt before setting `AUTH_EMAIL_DELIVERY_ENABLED=true` and `AUTH_EMAIL_VERIFICATION_REQUIRED=true`. Refresh configuration and restart queue workers after changing the settings. In this mode, unverified accounts must confirm their email; the confirmation screen sends their first signed link, and resend is rate-limited. A valid invitation/password-reset token also confirms the address. The log mailer is not delivery, and the server never automatically turns confirmation off on an outage. Existing confirmed 2FA stays enabled until the user disables it in Security settings; enrollment is optional for every role, even when an old server .env contains AUTH_REQUIRE_TWO_FACTOR=true.

Set APP_NAME="Doctor Appliance" and VITE_APP_NAME="${APP_NAME}" before building if the old installation still uses Field Service. Preserve APP_KEY and all data. Test signup and the first customer/job from a private browser on desktop and mobile in the selected mail mode. When enabling delivery, separately verify receipt in a controlled inbox, confirmation and later company access. Optional email-based login codes are not implemented.
