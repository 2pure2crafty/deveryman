# Using D'everyman

Once it is running (see `INSTALL.md`), everything starts from the launcher (the
front door) on its Tailscale port. Log in with your `CONDUCTOR_USER` /
`CONDUCTOR_PASS`.

## The model

A **project** is a codebase. Against it you use one of two lenses:

- **Conductor** to drive an agent yourself (spin up, talk to it from the Claude
  mobile app, wrap it down).
- **DPA** to have a feature built by an automated pipeline (a build queue drives
  agents through stages hands-off).

The launcher lists your projects with a link to whichever lenses each one has.

## Conductor: drive an agent

1. Launcher -> a project -> **Conductor**, or the Conductor dashboard directly.
2. **Spin up agent**: pick an existing agent (turns it on as-is) or create a new
   one (name, a CLAUDE.md instruction, model, permission mode). New projects can
   be created here too (folder, `git init`, optional GitHub repo).
3. It auto-pairs `/remote-control` so you continue in the Claude mobile app.
4. When done, **Wrap up & stop**: it writes a `SESSION.md` + `memory/HISTORY.md`
   handoff, then kills the session. The next spin-up reads that handoff and picks
   up where you left off. The daemon also auto-wraps-down an opted-in agent once
   its context is large and it has gone idle (see the memory kit).

The dashboard also shows a **Needs attention** section for any agent stuck on a
permission prompt (Approve / Deny), per-agent token totals, and a live pane peek.

## DPA: build a feature

1. Launcher -> a project -> **DPA**. You see the pipeline (daemon status, stages,
   build queue, state, log).
2. Add a feature to the project's build queue (a row in `build-queue.md`), or let
   the product stage generate one.
3. **Start daemon**. It drives the feature through the stages
   (features -> ... -> reviewer/ux-ui), committing on a feature branch, kicking
   back and re-routing on failures, and escalating (with a triaged summary) if a
   feature double-kicks-back. Watch progress in the dashboard.
4. **Stop** the daemon when the queue is done.

Pipeline agents follow the same memory discipline: each role keeps a git-stamped
logbook of what it did, so you can correlate history with git and revert cleanly.

## The aggregate view

The launcher shows total tokens used across everything (from Claude Code's
transcripts) and an estimated "saved by auto-wrap-downs" figure. Per-project and
per-agent token totals live on the Conductor project pages.

## Importing your own projects

Launcher -> **Import a repo**: give a repo (a URL or one of your own via `gh`) and
the import bot clones it into D'everyman's projects structure, registers it in
`projects.json`, and lets you enable Conductor and/or DPA on it.
