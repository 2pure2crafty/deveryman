# Privilege migration: run D'everyman as a dedicated non-sudo user

## Why

D'everyman's web apps and daemon pilot Claude Code, tmux, and `gh`. As long as
they run as `patch` (who is `NOPASSWD:ALL`), any web-facing bug is effectively
root on the box. This migration moves the whole runtime onto a dedicated,
**non-sudo** `deveryman` user whose only elevated rights are the scoped
`systemctl` verbs in `/etc/sudoers.d/deveryman`. `patch` stays as the
break-glass human admin, untouched.

After this, a bug in the PHP apps gets an attacker `deveryman` at worst: the
scoped systemctl calls and the app's own agents, never root.

## The hard constraint

The apps derive their power from *being* their runtime user: Claude Code auth
(`~/.claude`), `gh` auth (`~/.config/gh`), and the tmux server that holds the
agents all belong to that user. So the migration is not just "change `User=` in
the unit files": the new user needs its own Claude and `gh` logins, and ttyd
must attach to its tmux. Two steps are therefore interactive and must be done by
you; the rest is scripted (`scripts/migrate-to-deveryman.sh`).

**Do the cutover from a Tailscale SSH session, not from ttyd** — the last step
restarts ttyd and will drop a ttyd-attached shell. Keep an SSH path open so a
ttyd misconfiguration cannot lock you out of a Tailscale-only box.

## Already done (by the assistant, safe + additive)

- `deveryman` user created (non-sudo).
- `/etc/sudoers.d/deveryman` installed and `visudo`-validated: only the scoped
  systemctl verbs + reboot, nothing else.

## Steps you run

### 1. Log in as deveryman, interactively, once

```bash
sudo -iu deveryman
claude            # complete the Claude Code login for this user
gh auth login     # complete the GitHub login (needed for private-repo import)
exit
```

### 2. Decide where Conductor scaffolds new agents

`CONDUCTOR_BASE_DIR` in `/etc/default/conductor` is currently
`/var/www/hdp/agents` (HDS-owned, not writable by `deveryman`). Point it at a
`deveryman`-owned location, e.g.:

```bash
sudo sed -i 's#^CONDUCTOR_BASE_DIR=.*#CONDUCTOR_BASE_DIR=/var/www/deveryman-agents#' /etc/default/conductor
sudo install -d -o deveryman -g deveryman /var/www/deveryman-agents
```

### 3. Preflight, then migrate

```bash
sudo bash /var/www/deveryman/scripts/migrate-to-deveryman.sh preflight   # checks only
sudo bash /var/www/deveryman/scripts/migrate-to-deveryman.sh migrate     # cutover
```

The script backs up the unit files and `/etc/default/conductor` to
`/var/backups/deveryman-migration/` first, then: chowns the app + data dirs to
`deveryman`, repoints `CONDUCTOR_TRANSCRIPTS_DIR` to `deveryman`'s home, rewrites
the four units to `User=deveryman`, `daemon-reload`s, and restarts them.

### 4. Re-point ttyd (severs the ttyd shell — do it over SSH, last)

```bash
sudo sed -i 's/^User=patch/User=deveryman/' /etc/systemd/system/ttyd.service
sudo nano /etc/default/ttyd     # update the -c user:credential to deveryman's
sudo systemctl daemon-reload && sudo systemctl restart ttyd
```

New agents spawned from the dashboards now land in `deveryman`'s tmux, which ttyd
serves. Any agents still in `patch`'s tmux (e.g. an old overseer session) keep
running until they end; start their replacements from the dashboards.

## Verify

```bash
for u in conductor conductor-daemon dpa-dashboard deveryman-launcher; do
  printf '%-22s %s as %s\n' "$u" "$(systemctl is-active $u)" "$(systemctl show -p User --value $u)"
done
sudo -n -l -U deveryman | grep -E 'systemctl|reboot'   # scoped rights only
sudo -u deveryman -l 2>/dev/null | grep -q '(ALL' && echo 'WARNING: deveryman has broad sudo' || echo 'deveryman: no broad sudo (good)'
```

Then load the launcher and confirm the token totals populate (they now read
`deveryman`'s transcripts) and that spinning up an agent works end to end.

## Rollback

```bash
sudo bash /var/www/deveryman/scripts/migrate-to-deveryman.sh rollback
# and revert ttyd's User=patch if you changed it, then restart ttyd
```

## Notes

- Historical `patch` transcripts are not counted after the repoint. To carry the
  history over: `sudo cp -a /home/patch/.claude/projects/. /home/deveryman/.claude/projects/`
  then `sudo chown -R deveryman:deveryman /home/deveryman/.claude/projects` (this
  copies patch's transcript data into deveryman's home; skip if you would rather
  start the counter fresh).
- This does not change `patch`'s own sudo. `patch` remains the human admin.
