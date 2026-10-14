#!/usr/bin/env bash
# Shared by the scripts in scripts/ops. Run them as the application user (sudo -u <app user>), never as root.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOG_FILE="${OPS_LOG_FILE:-$APP_DIR/storage/logs/ops-agent.log}"
cd "$APP_DIR"

if [ "$(id -u)" -eq 0 ]; then
    printf 'Run this as the application user, not root.\n' >&2
    exit 1
fi

# Every action is written to the agent's own log: when, who (the sudo caller), what, and the result.
ops_log() {
    mkdir -p "$(dirname "$LOG_FILE")"
    printf '%s\tcaller=%s\t%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${SUDO_USER:-$(id -un)}" "$*" >>"$LOG_FILE"
}

ops_run() {
    local name="$1"
    shift
    ops_log "start $name $*"
    local status=0
    "$@" || status=$?
    ops_log "end $name exit=$status"
    return "$status"
}
