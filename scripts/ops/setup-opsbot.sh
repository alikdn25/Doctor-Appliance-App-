#!/usr/bin/env bash
# ONE-TIME setup of the server agent's Linux user (prepared, NOT switched on yet: run only after the owner's OK).
# Run as root:  bash scripts/ops/setup-opsbot.sh <app user> <app dir>
#   e.g.        bash scripts/ops/setup-opsbot.sh www-data /var/www/fieldservice
# Creates the user "opsbot" without root rights; it may run only the scripts in scripts/ops, as the app user.
set -euo pipefail

app_user="${1:?app user, e.g. www-data}"
app_dir="${2:?app directory, e.g. /var/www/fieldservice}"
ops="$app_dir/scripts/ops"

[ "$(id -u)" -eq 0 ] || { printf 'Run as root.\n' >&2; exit 1; }
id "$app_user" >/dev/null
test -x "$ops/backup-now"

id opsbot >/dev/null 2>&1 || useradd --create-home --shell /bin/bash opsbot
# No groups with extra rights (sudo, adm, docker, the app's group).
usermod -G '' opsbot

sudoers=/etc/sudoers.d/opsbot
cat >"$sudoers.tmp" <<RULES
# The server agent (Grok) may run only these scripts, as the application user. Nothing else, no root.
Defaults:opsbot !requiretty, log_output
opsbot ALL=($app_user) NOPASSWD: $ops/backup-now, $ops/check-backups, $ops/status, $ops/restore-company
RULES
visudo -cf "$sudoers.tmp"
install -m 0440 "$sudoers.tmp" "$sudoers"
rm -f "$sudoers.tmp"

printf 'opsbot is ready. It runs e.g.: sudo -u %s %s/status\n' "$app_user" "$ops"
