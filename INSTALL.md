# Installing D'everyman

D'everyman is self-hosted. This guide assumes a **fresh VPS** (Debian/Ubuntu) and
that D'everyman is the first thing you install on it. It is locked down to your
own Tailscale network; it is never exposed to the public internet and is not a
service anyone else logs into.

The shape of it: get the repo onto the box, install the prerequisites, drop in
one config file with your credentials, run the services, and reach it over
Tailscale. Then you import your own projects from the UI.

## 0. Prerequisites

```bash
sudo apt update
sudo apt install -y php-cli tmux git python3 curl
```

Then install the three things apt does not carry:

- **GitHub CLI (`gh`)** so you can pull your repos:
  <https://github.com/cli/cli/blob/trunk/docs/install_linux.md>, then `gh auth login`.
- **Claude Code CLI (`claude`)**: install per Anthropic's instructions and run
  `claude` once to log in (this is your AI credential; the setup screen surfaces
  this too, see step 6).
- **Tailscale**: `curl -fsSL https://tailscale.com/install.sh | sh` then
  `sudo tailscale up`. This is the only network exposure D'everyman uses.

Everything runs as an ordinary user (below, `you`) that owns the files and can
drive `tmux`, `claude`, `git`, and `gh`. Not root.

## 1. Get the repo

```bash
sudo mkdir -p /opt/deveryman && sudo chown "$USER:$USER" /opt/deveryman
gh repo clone <your-fork-or-this-repo> /opt/deveryman
# or: git clone <url> /opt/deveryman
```

## 2. The config file (credentials + settings)

One file holds the shared config for all of D'everyman. The code reads it from
`/etc/default/conductor` (the name is historical; it configures everything).

```bash
sudo cp /opt/deveryman/conductor/conductor.env.example /etc/default/conductor
sudo nano /etc/default/conductor      # set CONDUCTOR_USER/PASS and the paths below
sudo chown you:you /etc/default/conductor
sudo chmod 600 /etc/default/conductor
```

Set at least: `CONDUCTOR_USER` / `CONDUCTOR_PASS` (the login for every dashboard;
generate a password with `openssl rand -hex 8`), `CONDUCTOR_BASE_DIR` (where new
Conductor agents are created), and `CONDUCTOR_TRANSCRIPTS_DIR` (usually
`/home/you/.claude/projects`). The example file documents every key.

## 3. Live registries

```bash
cp /opt/deveryman/conductor/registry.example.json /opt/deveryman/conductor/registry.json
cp /opt/deveryman/projects.example.json /opt/deveryman/projects.json
```

Both are gitignored (they are your live state). Start them minimal; you will add
projects from the UI (the import flow, step 7).

## 4. Services

Copy each unit template, edit `User`/`Group` and the paths to `/opt/deveryman`,
then enable:

```bash
cd /opt/deveryman
for u in conductor/conductor.service.example conductor/conductor-daemon.service.example \
         dpa/dpa-dashboard.service.example launcher/deveryman-launcher.service.example; do
  name=$(basename "$u" .example)
  sudo cp "$u" "/etc/systemd/system/$name"
  sudo nano "/etc/systemd/system/$name"      # set User/Group + ExecStart paths
done
# The per-project pipeline daemon is a template (one instance per project):
sudo cp dpa/dpa-underseer@.service.example /etc/systemd/system/dpa-underseer@.service
# Scoped sudo so the DPA dashboard can start/stop those daemons and nothing else:
sudo cp dpa/deveryman-dpa.sudoers.example /etc/sudoers.d/deveryman-dpa
sudo nano /etc/sudoers.d/deveryman-dpa       # set the web user + systemctl path
sudo chmod 440 /etc/sudoers.d/deveryman-dpa && sudo visudo -c -f /etc/sudoers.d/deveryman-dpa

sudo systemctl daemon-reload
sudo systemctl enable --now conductor conductor-daemon dpa-dashboard deveryman-launcher
```

The four services bind to `127.0.0.1` on ports `7682` (conductor), `7683` (dpa),
`7684` (launcher). Confirm: `systemctl is-active conductor conductor-daemon dpa-dashboard deveryman-launcher`.

## 5. Expose over Tailscale

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

## 6. AI credentials

D'everyman drives the `claude` CLI, so its auth is your AI credential. Run
`claude` once as the service user and log in. The launcher's **Setup / AI
credentials** screen shows the current status and where more providers will slot
in later (Claude is the only one today).

## 7. Open it and import your projects

Open `https://<your-node>.<tailnet>.ts.net:8445` from a device on your tailnet,
log in with `CONDUCTOR_USER` / `CONDUCTOR_PASS`. From there, **Import a repo**
(needs `gh` from step 0) pulls one of your repos into D'everyman's structure,
registers it in `projects.json`, and lets you enable Conductor and/or DPA on it.

## Requirements recap

`php` (8.x CLI), `tmux`, `python3`, `git`, `gh` (authenticated), `claude`
(authenticated), `tailscale` (up). Everything else is in the repo.
