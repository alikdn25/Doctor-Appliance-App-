# Server agent (Grok): what it may do

Status: **prepared, not switched on.** The owner (Alex) decides when the agent gets this access. Until then Grok keeps
working as described in SERVER_HANDOFF.md.

## The rules

**Allowed** (no need to ask):

- `status` — health of the installation (read-only).
- `check-backups` — are recent backups there (read-only).
- `backup-now` — make an extra encrypted database backup.
- `restore-company <id>` **without** `--approved` — show what a restore would change (changes nothing).
- Reading data with the read-only database user `opsbot_ro` (it cannot change anything).

**Only after the owner's written OK** (quote it):

- `restore-company <id> <exact backup path> --approved "<the owner's message>"`.
- Anything that changes `.env`, deploys, runs migrations, restarts services.

**Never**:

- `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `migrate:rollback`, `db:wipe`, `db:seed` (production refuses
  them anyway), `DROP`, `DELETE`, `TRUNCATE`, `ALTER` on the live database.
- Deleting backups, files in `storage/`, or anything in the bucket.
- Printing or sending `.env`, keys, passwords or tokens. Ask the owner for keys through secure fields only.
- Logging in to the app as a person, or using sudo for anything other than the four scripts.

## Ready commands

The scripts run as the application user (here `www-data`; use the real one) and write every action to
`storage/logs/ops-agent.log` (time, who, command, result).

```bash
cd /var/www/fieldservice
sudo -u www-data scripts/ops/status
sudo -u www-data scripts/ops/check-backups
sudo -u www-data scripts/ops/backup-now
sudo -u www-data scripts/ops/restore-company 3                       # plan only
sudo -u www-data scripts/ops/restore-company 3 fieldservice/database/daily/2026/10/14/db-20261014-000502-hourly.dump.enc \
    --approved "Alex, 14 Oct 18:20: yes, restore company 3 from that backup"
```

Read-only database access:

```bash
PGPASSWORD=... psql -h 127.0.0.1 -U opsbot_ro -d fieldservice -c 'select count(*) from service_jobs'
```

## What to report

After every action: the command, its output (without secrets), and anything that looked wrong. For a restore plan:
the table with "Add back / Put back / Newer rows kept", and the exact backup path, so the owner can approve that plan.

## Switching it on (owner's decision; as root, once)

```bash
bash /var/www/fieldservice/scripts/ops/setup-opsbot.sh www-data /var/www/fieldservice
psql -d fieldservice -v pw="'<long random password>'" -f /var/www/fieldservice/scripts/ops/opsbot-db.sql
```

- Linux user `opsbot`: no root, no groups; `sudo` only for the four scripts, as the application user
  (`/etc/sudoers.d/opsbot`, with sudo's own output log).
- Database user `opsbot_ro`: `SELECT` only, sessions read-only.
- Backup storage: if the agent ever needs its own key, create it with `listBuckets, listFiles, readFiles, writeFiles`
  only (no `deleteFiles`), limited to the backup bucket:
  `b2 key create --bucket <bucket> opsbot listBuckets,listFiles,readFiles,writeFiles`.
- Give Grok the SSH key of `opsbot` only, never the application user's or root's.

To switch it off again: `userdel -r opsbot; rm /etc/sudoers.d/opsbot; DROP ROLE opsbot_ro;` and delete its B2 key.
