# Installing D'everyman

D'everyman is self-hosted. This guide assumes a **fresh VPS** (Debian/Ubuntu) and
that D'everyman is the first thing you install on it. It is locked down to your
own Tailscale network; it is never exposed to the public internet and is not a
service anyone else logs into.

The shape of it: create a dedicated service user, get the repo onto the box,
install the prerequisites, drop in one config file with your credentials, run the
services as that user, and reach it over Tailscale. Then you import your own
projects from the UI.

## 0. Prerequisites

```bash
sudo apt update
sudo apt install -y php-cli tmux git python3 curl
```

Then install the three things apt does not carry (system-wide, so any user can
run them):

- **GitHub CLI (`gh`)** so you can pull your repos:
  <https://github.com/cli/cli/blob/trunk/docs/install_linux.md>.
- **Claude Code CLI (`claude`)**: install per Anthropic's instructions.
- **Tailscale**: `curl -fsSL https://tailscale.com/install.sh | sh` then
  `sudo tailscale up`. This is the only network exposure D'everyman uses.

You log in to `gh` and `claude` in step 6, **as the service user**, not now.

## 1. Create the service user

D'everyman's apps and daemon pilot Claude Code, tmux, and `gh`, so whatever user
they run as can run code as that user. Run them as a **dedicated, non-sudo user**
(`deveryman` here), never as yourself or any user with broad sudo: that way a bug
in a web-facing app is confined to `deveryman` and its scoped `systemctl` rights,
never root. You stay the human admin.

```bash
sudo useradd -m -s /bin/bash -c "D'everyman service user" deveryman
```

From here on, commands that set up or run D'everyman are done **as `deveryman`**
(shown with `sudo -u deveryman …`, or drop into a shell with `sudo -iu deveryman`).

## 2. Get the repo

```bash
sudo install -d -o deveryman -g deveryman /opt/deveryman
sudo -u deveryman gh repo clone <your-fork-or-this-repo> /opt/deveryman
# or: sudo -u deveryman git clone <url> /opt/deveryman
```

Also create a directory `deveryman` owns for the projects it imports and the
agents Conductor scaffolds:

```bash
sudo install -d -o deveryman -g deveryman /var/www/dpa-projects /var/www/deveryman-agents
```

## 3. The config file (credentials + settings)

One file holds the shared config for all of D'everyman. The code reads it from
`/etc/default/conductor` (the name is historical; it configures everything).

```bash
sudo cp /opt/deveryman/conductor/conductor.env.example /etc/default/conductor
sudo nano /etc/default/conductor      # set the keys below
sudo chown deveryman:deveryman /etc/default/conductor
sudo chmod 600 /etc/default/conductor
```

Set at least:

- `CONDUCTOR_USER` / `CONDUCTOR_PASS` — the login for every dashboard
  (`openssl rand -hex 8` makes a good password).
- `CONDUCTOR_BASE_DIR=/var/www/deveryman-agents` — where new Conductor agents are
  created (a `deveryman`-owned path).
- `CONDUCTOR_TRANSCRIPTS_DIR=/home/deveryman/.claude/projects` — where the
  service user's Claude Code transcripts live (this drives the token totals).
- `DEVERYMAN_PROJECTS_DIR=/var/www/dpa-projects` — where imported repos land.

The example file documents every key.

## 4. Live registries

```bash
sudo -u deveryman cp /opt/deveryman/conductor/registry.example.json /opt/deveryman/conductor/registry.json
sudo -u deveryman cp /opt/deveryman/projects.example.json /opt/deveryman/projects.json
```

Both are gitignored (they are your live state). Start them minimal; you will add
projects from the UI (the import flow, step 8).

## 5. Services

Copy each unit template, set `User=deveryman` / `Group=deveryman` and the paths
to `/opt/deveryman`, then enable. The scoped sudoers grants `deveryman` only the
`systemctl` verbs it needs (start/stop its pipeline daemons, restart the core
services, reboot) and nothing else.

```bash
cd /opt/deveryman
for u in conductor/conductor.service.example conductor/conductor-daemon.service.example \
         dpa/dpa-dashboard.service.example launcher/deveryman-launcher.service.example; do
  name=$(basename "$u" .example)
  sudo cp "$u" "/etc/systemd/system/$name"
  sudo nano "/etc/systemd/system/$name"      # set User=deveryman/Group=deveryman + ExecStart paths
done
# The per-project pipeline daemon is a template (one instance per project):
sudo cp dpa/dpa-underseer@.service.example /etc/systemd/system/dpa-underseer@.service
# Scoped sudo for the service user (edit <web-user> -> deveryman):
sudo cp dpa/deveryman-dpa.sudoers.example /etc/sudoers.d/deveryman
sudo sed -i 's/<web-user>/deveryman/' /etc/sudoers.d/deveryman
sudo chmod 440 /etc/sudoers.d/deveryman && sudo visudo -c -f /etc/sudoers.d/deveryman

sudo systemctl daemon-reload
sudo systemctl enable --now conductor conductor-daemon dpa-dashboard deveryman-launcher
```

The four services bind to `127.0.0.1` on ports `7682` (conductor), `7683` (dpa),
`7684` (launcher). Confirm they are up and running as the right user:

```bash
for u in conductor conductor-daemon dpa-dashboard deveryman-launcher; do
  printf '%-22s %s as %s\n' "$u" "$(systemctl is-active $u)" "$(systemctl show -p User --value $u)"
done
```

`enable --now` means they come back on reboot. DPA pipeline daemons are enabled
per project when you start them from the dashboard, so a running pipeline also
survives a reboot.

## 6. AI credentials (log in as the service user)

D'everyman drives `claude` and `gh`, so their logins must belong to `deveryman`:

```bash
sudo -iu deveryman
claude          # complete the Claude Code login
gh auth login   # complete the GitHub login (needed for private-repo import)
exit
```

The launcher's **Setup / AI credentials** screen shows the current status and
where more providers will slot in later (Claude is the only one today).

## 7. Expose over Tailscale

Give the launcher (the front door) and the two dashboards each their own HTTPS
port on your tailnet:

```bash
sudo tailscale serve --bg --https=8445 http://127.0.0.1:7684   # launcher (front door)
sudo tailscale serve --bg --https=8443 http://127.0.0.1:7682   # conductor
sudo tailscale serve --bg --https=8444 http://127.0.0.1:7683   # dpa
```

Then set these in `/etc/default/conductor` so the launcher can link out:

```
CONDUCTOR_DASHBOARD_URL=https://<your-node>.<tailnet>.ts.net:8443
DEVERYMAN_CONDUCTOR_URL=https://<your-node>.<tailnet>.ts.net:8443
DEVERYMAN_DPA_URL=https://<your-node>.<tailnet>.ts.net:8444
```

Do NOT use `tailscale serve --set-path`: it registers an exact path, not a
subtree, and multi-page apps 404. Own ports (above) is the supported way.

## 8. Open it and import your projects

Open `https://<your-node>.<tailnet>.ts.net:8445` from a device on your tailnet,
log in with `CONDUCTOR_USER` / `CONDUCTOR_PASS`. From there, **Import a repo**
(uses the `deveryman` `gh` login from step 6) pulls one of your repos into
D'everyman's structure, registers it in `projects.json`, and lets you enable
Conductor and/or DPA on it. The **System** page shows service health and lets you
restart services or reboot the host.

## Already running as a privileged user?

If you installed an earlier build as `patch` (or another sudoer),
`docs/PRIVILEGE-MIGRATION.md` and `scripts/migrate-to-deveryman.sh` move the whole
runtime onto the dedicated `deveryman` user without a reinstall.

## Requirements recap

`php` (8.x CLI), `tmux`, `python3`, `git`, `gh` (authenticated as the service
user), `claude` (authenticated as the service user), `tailscale` (up), and a
dedicated non-sudo service user to run it all. Everything else is in the repo.
