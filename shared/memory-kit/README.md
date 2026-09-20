# Memory kit

The portable token-efficiency / persistent-memory discipline, as a kit you
install into an agent. **This is the most portable, reusable part of the whole
project.** It is just files, so it works in ANY Claude Code agent, whether it runs
inside D'everyman or not. If you take one thing from this repo, take this.

## The problem it solves

A long-lived agent gets expensive to return to: it has to re-read its whole
accumulated context to get oriented, and once its prompt cache has expired
(default 5-minute TTL) that revival is paid at full price. The fix is to keep the
*information* cheaply, not the *agent*: a small durable handoff you can revive
from, a lossless log so nothing is lost, and a rule for wrapping a session down
before it becomes expensive to revive.

## The rule, with concrete numbers

Wrap a session down once BOTH hold: it has been **idle past a timeout** (default
240s) AND its **context has grown past a size gate** (default ~100k tokens). The
240s is chosen to fire just under the 5-minute cache TTL, so you snapshot to a
small handoff instead of paying a cold rebuild of a large stale context later.
Below the size gate, a short idle session is left alone (not worth the wrap-up).
(These are the Conductor daemon's `CONDUCTOR_IDLE_TIMEOUT` /
`CONDUCTOR_WRAPDOWN_MIN_CONTEXT`.)

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

## Install into any agent

```
./install.sh /path/to/agent-dir
```

That copies the wrap-up skill into `<agent-dir>/.claude/skills/wrap-up/` and
inserts the reading-ladder snippet near the top of the agent's `CLAUDE.md`
(creating it if absent). Idempotent. Or do it by hand:

```
cp -r skills/wrap-up <agent-dir>/.claude/skills/
# then paste CLAUDE-snippet.md near the top of <agent-dir>/CLAUDE.md
```

The agent's `.claude/settings.json` must allow writing `SESSION.md` and
`memory/**` (agents run in acceptEdits/auto mode do this without prompting).
