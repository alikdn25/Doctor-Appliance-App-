# Backups and restore

Everything here runs on the server as the application user (the owner of `/var/www/fieldservice`), from the
application directory. Nothing in this guide writes into the live database except "Restore one company" (section 5)
and "Replace the whole database" (section 6), and both make a fresh backup first.

## 1. What is backed up, where, for how long

| What                                                                                                                       | When              | Where in the bucket (`BACKUP_PREFIX`, default `fieldservice`) | Kept      |
| -------------------------------------------------------------------------------------------------------------------------- | ----------------- | ------------------------------------------------------------- | --------- |
| Whole database                                                                                                             | every hour (:05)  | `database/hourly/YYYY/MM/DD/`                                 | 48 hours  |
| Whole database, first good copy of the day                                                                                 | daily             | `database/daily/YYYY/MM/DD/`                                  | 30 days   |
| Whole database, first good copy of the month                                                                               | monthly           | `database/monthly/YYYY/MM/`                                   | 12 months |
| Whole database before a deployment or a restore                                                                            | each time         | `database/event/YYYY/MM/DD/`                                  | 30 days   |
| Uploaded files: job photos, receipts, signatures, rating plates, logos, PDFs (`storage/app/private`, `storage/app/public`) | daily (03:35 UTC) | `files/daily/YYYY/MM/DD/`                                     | 30 days   |

- Storage: Backblaze B2 (or any S3-compatible bucket), **not** this server.
- Every file is encrypted on the server before upload (libsodium XChaCha20-Poly1305, key `BACKUP_ENCRYPTION_KEY`).
  The storage company sees only encrypted data. A damaged or altered file is detected when it is read.
- Database copies are PostgreSQL custom-format dumps (`pg_dump -Fc`), restored with `pg_restore`.
- After each hourly run `backup:check` checks that the newest database copy is under 90 minutes old and the newest
  files copy under 26 hours. A failed backup or a failed check notifies the platform owner (in the app, push on the
  phone, Telegram if set, email once mail works) and is written to `storage/logs/laravel.log`.
- Every deployment backs up the database before migrations (`deploy/deploy.sh`); if that backup fails, the deployment
  stops before any migration and maintenance mode stays on. Until external storage is set up, the deployment keeps a
  local copy in `storage/app/local-backups` (newest five).

**The encryption key is the only way to read the backups.** Keep a copy outside the server (password manager). If the
server is lost together with `.env` and there is no other copy of the key, the backups cannot be opened.

## 2. Setting it up (once)

1. Bucket: in Backblaze B2 create a **private** bucket, e.g. `doctor-appliance-backups`.
2. Application key for the server, limited to that bucket, with `listBuckets, listFiles, readFiles, writeFiles` and
   **without** `deleteFiles` (recommended: a compromised server or agent then cannot delete backups). With such a key:
    - set `BACKUP_PRUNE=false`, and
    - add the bucket lifecycle rules below, so B2 itself removes expired copies.

    | fileNamePrefix                   | daysFromUploadingToHiding | daysFromHidingToDeleting |
    | -------------------------------- | ------------------------- | ------------------------ |
    | `fieldservice/database/hourly/`  | 2                         | 1                        |
    | `fieldservice/database/daily/`   | 30                        | 1                        |
    | `fieldservice/database/monthly/` | 366                       | 1                        |
    | `fieldservice/database/event/`   | 30                        | 1                        |
    | `fieldservice/files/daily/`      | 30                        | 1                        |

    (Alternative: a key with `deleteFiles` and `BACKUP_PRUNE=true`; the app then deletes expired copies itself.)

3. `.env` on the server (never in Git):

    ```dotenv
    BACKUP_ENCRYPTION_KEY=          # php artisan backup:key
    BACKUP_S3_KEY=                  # keyID
    BACKUP_S3_SECRET=               # applicationKey
    BACKUP_S3_REGION=us-west-004    # the bucket's region
    BACKUP_S3_BUCKET=doctor-appliance-backups
    BACKUP_S3_ENDPOINT=https://s3.us-west-004.backblazeb2.com
    BACKUP_PRUNE=false
    ```

4. `php artisan config:cache`, then `php artisan backup:run` and `php artisan backup:run --files --reason=daily`;
   `php artisan backup:list` shows them. The scheduler (`* * * * * php artisan schedule:run`) does the rest.
5. Tools the server needs: `pg_dump`, `pg_restore` and `psql` of the same major version as the database server (or
   newer), `tar`. For test restores the database user needs `CREATEDB` (or create `BACKUP_RESTORE_DATABASE` once).

## 3. Commands

```bash
php artisan backup:list                 # database backups, newest first (--files for file backups)
php artisan backup:run                  # back up the database now (--reason=manual) ; --files for files
php artisan backup:check                # newest backups present and recent?
php artisan backup:restore [path]       # restore into the scratch database (never the live one) and count rows
php artisan backup:restore-company <company id> [path] [--dry-run]
php artisan backup:prune                # delete expired copies (only with a key that may delete)
```

`path` is a path printed by `backup:list`; without it the newest database backup is used.

## 4. Test restore (do it after setting up, then every month)

```bash
php artisan backup:restore
```

It downloads the newest backup, checks and decrypts it, recreates the scratch database (`BACKUP_RESTORE_DATABASE`,
default `<DB_DATABASE>_restore`) and loads the dump there, then prints the number of companies, users, customers,
jobs, estimates, invoices and payments. Compare them with the live app. The scratch database can be dropped
afterwards (`DROP DATABASE ..._restore`).

File backup check:

```bash
php artisan tinker --execute 'app(App\Support\Backups\BackupManager::class)->fetch("<files path from backup:list --files>", storage_path("app/files-check.tar.gz"));'
tar -tzf storage/app/files-check.tar.gz | head && rm storage/app/files-check.tar.gz
```

## 5. Restore one company (other companies untouched)

```bash
php artisan backup:restore-company 3 --dry-run                      # newest backup, only show the plan
php artisan backup:restore-company 3 fieldservice/database/daily/2026/10/14/db-20261014-000502-hourly.dump.enc
```

1. The backup is restored into the scratch database.
2. The command prints, per table: rows that will be **added back** (missing now), rows that will be **put back to
   the backup's values** (changed since), and newer rows that are **kept**. Nothing is deleted: records created after
   the backup stay. Job, invoice and estimate numbering is never moved back.
3. It asks for confirmation (default No). Then it backs up the live database (`database/event/`), and copies the rows
   in one transaction, parents first. A row that no longer fits (for example its unique value is now used by a newer
   row) is skipped and listed, the rest continues.
4. Users missing in the live database are added back; existing users are never changed (they can belong to several
   companies).

To bring back a single deleted customer, job or invoice, first look in the app: **Deleted items** and **Jobs →
Deleted** restore anything deleted in the last 30 days with one button, without touching backups.

## 6. Replace the whole database (disaster)

Only when the live database is lost or unusable, and only with the owner's approval.

```bash
php artisan down
php artisan backup:run --reason=manual || true          # keep what is there, if anything
php artisan backup:restore <path> --database=fieldservice_restored
# check the counts it prints, then point the app at the restored database:
#   DB_DATABASE=fieldservice_restored in .env
php artisan config:cache && php artisan migrate --force && php artisan queue:restart
php artisan up
```

The old database stays as it was (rename or drop it later, after checking). Uploaded files: download the newest
files backup (section 4) and unpack it into `storage/app` (`tar -xzf ... -C storage/app`).

## 7. Without the app (if Laravel itself does not start)

The file format is: `FSBK1`, a 24-byte header, then chunks of [4-byte big-endian length, ciphertext], libsodium
`crypto_secretstream_xchacha20poly1305`. Any PHP 8 with sodium can decrypt it with
`App\Support\Backups\BackupEncryptor` from this repository:

```bash
php -r 'require "vendor/autoload.php"; (new App\Support\Backups\BackupEncryptor("base64:...key..."))->decryptFile("db.dump.enc", "db.dump");'
pg_restore --no-owner --no-privileges -d <empty database> db.dump
```

## 8. Checked on October 14, 2026

In the test suite (`tests/Integration/BackupTest.php`, real `pg_dump`/`pg_restore` on PostgreSQL 16): a backup is
encrypted (no readable data in the file), restores into a separate database with the same rows, one of two
companies is restored while the other's later changes stay, the files archive reads back, a failed backup alerts and
stops a deployment, retention removes exactly the expired copies, and the check alerts on a stale backup. A restore
from the **real** storage on the live server is still to be done once the storage keys are set (section 4).
