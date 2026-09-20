# Memory kit

The portable token-efficiency / persistent-memory discipline, as a kit you
install into an agent. It is just files, so any agent (Conductor-run or DPA-run)
can follow it, independent of who orchestrates that agent.

## What it is

- **`skills/wrap-up/SKILL.md`** - the wrap-up skill. On `/wrap-up`, writes the
  latest handoff to `SESSION.md` and prepends it to an append-only
  `memory/HISTORY.md`, both stamped with the git branch + commit so agent history
  correlates with repo history.
- **`CLAUDE-snippet.md`** - the reading-ladder block to paste near the top of an
  agent's `CLAUDE.md` (SESSION -> DIGEST -> HISTORY -> archive).

## The tiered files (created in the agent's own dir at runtime)

```
SESSION.md            latest handoff only (overwritten). Tier 1.
memory/HISTORY.md     append-only, every handoff, newest first, git-stamped. Tier 3.
memory/DIGEST.md      condensed summary of older history (daemon-maintained). Tier 2.
memory/archive/       rotated-out history + version summaries. Tier 4.
```

## The rule that keeps fidelity

Always derive summaries (DIGEST) from the lossless log (HISTORY), never from a
previous summary. Compress from source, not from the last compression, so fidelity
does not compound away.

## Who invokes wrap-up

The skill is inert until something calls `/wrap-up`. That invoker differs by
orchestrator:

- **Conductor** (manual agents): Patch, or the Conductor daemon on the big+idle
  timer.
- **DPA** (pipeline stages): the underseer daemon, at stage handoff before it
  kills the agent.

The automatic big+idle wrap-down (the killer) only ever applies to
Conductor-owned agents. DPA agents get the skill (so they leave a handoff), but
their lifecycle stays with underseer.

## Install into an agent

```
cp -r skills/wrap-up <agent-dir>/.claude/skills/
# then paste CLAUDE-snippet.md near the top of <agent-dir>/CLAUDE.md
```

The agent's `.claude/settings.json` must allow writing `SESSION.md` and
`memory/**` (agents run in acceptEdits/auto do this without prompting).
