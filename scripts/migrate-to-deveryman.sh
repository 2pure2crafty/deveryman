#!/usr/bin/env bash
#
# Migrate D'everyman's runtime from the `patch` user to the dedicated, non-sudo
# `deveryman` service user, so nothing web-facing runs as a full sudoer.
#
# WHY: the web apps (conductor, dpa dashboard, launcher) and the conductor daemon
# pilot Claude Code, tmux and gh. While they run as `patch` (NOPASSWD:ALL), any
# web bug is effectively root. After this migration they run as `deveryman`,
# which can do ONLY the scoped systemctl verbs in /etc/sudoers.d/deveryman and
# drive its own agents. `patch` stays as the break-glass human admin, untouched.
#
# RUN THIS FROM A TAILSCALE SSH SESSION, AS ROOT (sudo), *NOT* from ttyd or the
# tmux window this rehomes -- the cutover restarts ttyd and will drop that
# connection. Idempotent: safe to re-run. Use `rollback` to revert.
#
#   sudo bash migrate-to-deveryman.sh preflight   # check readiness, change nothing
#   sudo bash migrate-to-deveryman.sh migrate     # do the cutover
#   sudo bash migrate-to-deveryman.sh rollback    # restore the pre-migration state
#
set -euo pipefail

SVC_USER=deveryman
APP=/var/www/deveryman
DATA=/var/www/dpa-projects
CONF=/etc/default/conductor
UNITS=(conductor conductor-daemon dpa-dashboard deveryman-launcher)
BAKDIR=/var/backups/deveryman-migration
NEW_TRANSCRIPTS=/home/${SVC_USER}/.claude/projects

log()  { printf '\033[1;36m[migrate]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fatal]\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run as root (sudo)."

preflight() {
    log "Preflight checks (no changes made):"
    id "$SVC_USER" >/dev/null 2>&1 && log "  user $SVC_USER exists" || die "user $SVC_USER missing"
    [ -f /etc/sudoers.d/${SVC_USER} ] && log "  scoped sudoers present" || die "/etc/sudoers.d/${SVC_USER} missing"
    # Interactive auth must already be done AS deveryman (this script cannot do it):
    if sudo -u "$SVC_USER" test -d /home/${SVC_USER}/.claude; then
        log "  ~${SVC_USER}/.claude present (Claude Code logged in?)"
    else
        warn "  ~${SVC_USER}/.claude MISSING -- run 'sudo -iu ${SVC_USER}' then 'claude' to log in first"
    fi
    if sudo -u "$SVC_USER" test -f /home/${SVC_USER}/.config/gh/hosts.yml; then
        log "  ~${SVC_USER}/.config/gh present (gh logged in?)"
    else
        warn "  ~${SVC_USER} gh auth MISSING -- run 'sudo -iu ${SVC_USER}' then 'gh auth login' (needed for repo import)"
    fi
    # CONDUCTOR_BASE_DIR must be writable by deveryman for Conductor to scaffold agents.
    local base; base=$(grep -oP '^CONDUCTOR_BASE_DIR=\K.*' "$CONF" 2>/dev/null | tr -d '"'"'"'' || true)
    [ -n "$base" ] && warn "  CONDUCTOR_BASE_DIR=$base -- ensure $SVC_USER can write there (see runbook)"
    log "Preflight done."
}

backup() {
    mkdir -p "$BAKDIR"
    local stamp; stamp=$(date +%Y%m%d-%H%M%S)
    for u in "${UNITS[@]}"; do
        cp -n "/etc/systemd/system/${u}.service" "${BAKDIR}/${u}.service.orig" 2>/dev/null || true
    done
    cp -n "$CONF" "${BAKDIR}/conductor.orig" 2>/dev/null || true
    echo "$stamp" > "${BAKDIR}/last-migrate.stamp"
    log "Backed up units + config to $BAKDIR (originals preserved)."
}

migrate() {
    preflight
    backup
    log "Phase A: ownership of app + data to $SVC_USER"
    chown -R "${SVC_USER}:${SVC_USER}" "$APP" "$DATA"

    log "Phase A: point transcripts at ${SVC_USER}'s home"
    if grep -q '^CONDUCTOR_TRANSCRIPTS_DIR=' "$CONF"; then
        sed -i "s#^CONDUCTOR_TRANSCRIPTS_DIR=.*#CONDUCTOR_TRANSCRIPTS_DIR=${NEW_TRANSCRIPTS}#" "$CONF"
    else
        echo "CONDUCTOR_TRANSCRIPTS_DIR=${NEW_TRANSCRIPTS}" >> "$CONF"
    fi

    log "Phase A: rewrite units to User/Group=$SVC_USER"
    for u in "${UNITS[@]}"; do
        sed -i "s/^User=patch/User=${SVC_USER}/; s/^Group=patch/Group=${SVC_USER}/" "/etc/systemd/system/${u}.service"
    done
    systemctl daemon-reload

    log "Phase B: restart services as $SVC_USER"
    systemctl restart "${UNITS[@]}"
    sleep 2
    for u in "${UNITS[@]}"; do
        printf '  %-22s %s (as %s)\n' "$u" "$(systemctl is-active "$u")" \
            "$(systemctl show -p User --value "$u")"
    done

    # The patch-scoped copy is now redundant (services run as deveryman).
    [ -f /etc/sudoers.d/deveryman-dpa ] && { rm -f /etc/sudoers.d/deveryman-dpa; log "Removed redundant /etc/sudoers.d/deveryman-dpa"; }

    cat <<EOF

$(log "Services migrated. TWO MANUAL STEPS REMAIN (they sever ttyd, so do them last, over SSH):")
  1. Re-point ttyd to ${SVC_USER}'s tmux: edit /etc/systemd/system/ttyd.service
     (User=${SVC_USER}) and its /etc/default/ttyd credential, then
       systemctl daemon-reload && systemctl restart ttyd
  2. Any long-lived agents still in patch's tmux (e.g. the overseer) are left
     running; start new ones via the dashboards (they now land in ${SVC_USER}'s tmux).

Rollback anytime:  sudo bash $0 rollback
EOF
}

rollback() {
    [ -d "$BAKDIR" ] || die "no backup dir at $BAKDIR"
    log "Restoring units + config from $BAKDIR"
    for u in "${UNITS[@]}"; do
        [ -f "${BAKDIR}/${u}.service.orig" ] && cp "${BAKDIR}/${u}.service.orig" "/etc/systemd/system/${u}.service"
    done
    [ -f "${BAKDIR}/conductor.orig" ] && cp "${BAKDIR}/conductor.orig" "$CONF"
    chown -R patch:patch "$APP" "$DATA"
    systemctl daemon-reload
    systemctl restart "${UNITS[@]}"
    log "Rolled back to patch. (ttyd: revert User=patch manually if you changed it.)"
}

case "${1:-preflight}" in
    preflight) preflight ;;
    migrate)   migrate ;;
    rollback)  rollback ;;
    *) die "usage: $0 {preflight|migrate|rollback}" ;;
esac
