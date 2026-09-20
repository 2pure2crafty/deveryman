# D'everyman

D'everyman is a personal, self-hosted OS for running Claude Code agents: the shell
that sits above the orchestrators. You log in, pick a project, and operate on it
through whichever capability fits, **Conductor** (manual, on-demand agents you
talk to) or **DPA** (an automated build pipeline). It is the top layer that
consolidates both, with one shared framework, one shared memory discipline, and
one aggregate view of what everything is costing.

It is meant to be **self-hosted on your own VPS** and locked down (Tailscale-only,
no public internet). It is not, and will not be, a shared service you log into.
To use it, you stand up your own instance. See `INSTALL.md`.

> **Note:** this repo is public only briefly and will be taken down soon. See
> `LICENSE`: no usage rights are granted.

## Conductor vs DPA: when to use which

- **Conductor** when *you* want to drive an agent: spin one up, talk to it from
  your phone, wrap it down. Manual, on-demand, one agent at a time.
- **DPA** when you want a feature *built for you* by an automated multi-stage
  pipeline (features -> dev -> review -> ...), hands-off, driven by a build queue.

Same project, two lenses. Reach for Conductor to think with an agent; reach for
DPA to have work done to a spec.

## How this came to exist

It started with a smug question: *how am I so efficient with token use?* So I ran
an audit. Turns out I am not efficient at all.

The specific inefficiency: I kept a **persistent agent running** so it would be
"there when I wanted it". But an agent you leave and come back to has to re-read
its whole accumulated context to get oriented, and if its prompt cache has
expired in the meantime, that revival is paid at full price. The longer it lived,
the more each return cost. The whole memory discipline below exists to fix exactly
that: keep the *information* cheaply, not the *agent*.

That sent me down a path:

1. **The efficiency discipline.** A convention for how an agent looks after its
   own memory so it stays cheap: a latest-state handoff (`SESSION.md`), an
   append-only history (`memory/HISTORY.md`), a condensed digest
   (`memory/DIGEST.md`), and a rule for wrapping down bloated idle sessions before
   their prompt cache expires (reviving a big stale context is the expensive
   part, not idling). The rule has concrete numbers: wrap a session down once it
   has been idle past a timeout (default 240s) AND its context has grown past a
   size gate (default ~100k tokens), timed to fire just under the 5-minute cache
   TTL. See `shared/memory-kit/`, this is the most portable part of the whole
   project and works in any Claude Code agent, not just this one.
2. **Conductor.** A control panel to spin agents up and down on demand from a
   phone, wrap them down cleanly, and let a daemon enforce the discipline. Built
   because leaving sessions running to be "there when I want them" is exactly the
   inefficiency the audit found.
3. **The consolidation.** Conductor turned out to be one of two orchestrators;
   the other is the DPA, an automated build pipeline. They share a discipline and
   should share a framework and a front door.
4. **D'everyman.** The layer above both. The OS.

See `docs/ROADMAP.md` for the structure and the staged build plan.

## Structure (monorepo)

```
deveryman/
  shared/framework/   plumbing every dashboard reuses (auth, render, tmux, tokens)
  shared/memory-kit/  the portable discipline, installed into agents
  conductor/          manual, on-demand agents (+ its daemon)
  dpa/                the automated build pipeline (+ the underseer daemon)
  launcher/           D'everyman itself: login, project-first UI, aggregate tokens
  projects.json       the canonical project registry, shared by both capabilities
```

The project is the primary entity; Conductor and DPA are capabilities you apply
to it. Both are built to be generic / multi-project.

## Access points (example, machine-specific)

The values below are from one deployment and will differ on yours. Each app binds
to `127.0.0.1` and is reached over that host's Tailscale hostname on its own HTTPS
port. On your VPS, the hostname is your tailnet's; the ports are whatever you
choose in the config. `INSTALL.md` covers exposing them.

| App               | Local (fixed)    | Tailscale port (your choice) |
| ----------------- | ---------------- | ---------------------------- |
| D'everyman launcher | 127.0.0.1:7684   | e.g. `:8445` (front door)    |
| Conductor         | 127.0.0.1:7682   | e.g. `:8443`                 |
| DPA dashboard     | 127.0.0.1:7683   | e.g. `:8444`                 |

The launcher is the intended entry point; it links out to the other two.

## Install

D'everyman is self-hosted. See **`INSTALL.md`** to stand up your own instance on a
fresh VPS, and **`docs/USAGE.md`** for how to use it once it is running.

