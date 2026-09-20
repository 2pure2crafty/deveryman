# Everyman

Everyman is a personal OS for running Claude Code agents: the shell that sits
above the orchestrators. You log in, pick a project, and operate on it through
whichever capability fits, **Conductor** (manual, on-demand agents you talk to)
or **DPA** (an automated build pipeline). It is the top layer that consolidates
both, with one shared framework, one shared memory discipline, and one aggregate
view of what everything is costing.

> **Note:** this repo is public only briefly and will be taken down soon. See
> `LICENSE`: no usage rights are granted.

## How this came to exist

It started with a smug question: *how am I so efficient with token use?* So I ran
an audit. Turns out I am not efficient at all.

That sent me down a path:

1. **The efficiency discipline.** A convention for how an agent looks after its
   own memory so it stays cheap: a latest-state handoff, an append-only history,
   a condensed digest, and a rule for wrapping down bloated idle sessions before
   their prompt cache expires (reviving a big stale context is the expensive
   part, not idling).
2. **Conductor.** A control panel to spin agents up and down on demand from a
   phone, wrap them down cleanly, and let a daemon enforce the discipline. Built
   because leaving sessions running to be "there when I want them" is exactly the
   inefficiency the audit found.
3. **The consolidation.** Conductor turned out to be one of two orchestrators;
   the other is the DPA, an automated build pipeline. They share a discipline and
   should share a framework and a front door.
4. **Everyman.** The layer above both. The OS.

See `docs/ROADMAP.md` for the structure and the staged build plan.

## Structure (monorepo)

```
everyman/
  shared/framework/   plumbing every dashboard reuses (auth, render, tmux, tokens)
  shared/memory-kit/  the portable discipline, installed into agents
  conductor/          manual, on-demand agents (+ its daemon)
  dpa/                the automated build pipeline (+ the underseer daemon)
  launcher/           Everyman itself: login, project-first UI, aggregate tokens
  projects.json       the canonical project registry, shared by both capabilities
```

The project is the primary entity; Conductor and DPA are capabilities you apply
to it. Both are built to be generic / multi-project.

## Access points (this deployment)

Each app binds to 127.0.0.1 and is exposed on its own Tailscale HTTPS port:

| App               | Local            | Tailscale port |
| ----------------- | ---------------- | -------------- |
| Everyman launcher | 127.0.0.1:7684   | `:8445` (front door) |
| Conductor         | 127.0.0.1:7682   | `:8443`        |
| DPA dashboard     | 127.0.0.1:7683   | `:8444`        |

The launcher is the intended entry point; it links out to the other two.

