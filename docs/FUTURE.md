# Future / to-review

Things that surfaced while building. For review at the end, not blockers.

## From Phase 1 (memory-kit)

- **overseer has a nested empty git repo.** `/var/www/hdp/agents-archive/overseer/.git`
  exists but has no commits, so it shadows the parent `agents-archive` repo and
  `git rev-parse HEAD` fails from the overseer dir. Effect: the wrap-up git-stamp
  no-ops for the overseer specifically (it degrades gracefully, just omits the
  `Git:` line). Fix: remove the nested `.git` so overseer resolves to the
  agents-archive repo, or leave it if the overseer is meant to be its own repo
  (then give it an initial commit).
- **Write permissions for standalone-launched manual agents.** The wrap-up writes
  `SESSION.md` + `memory/**`. Agents spun up via Conductor get a permission mode
  that allows this; an agent launched by hand in plain mode would prompt. Not an
  issue for the Conductor/daemon path, noted for completeness.

## From Phase 2 (pipeline wrap-up)

- **underseer AGENT_ROOT is a stale path.** `underseer.py` uses
  `AGENT_ROOT = /var/www/hdp/agents`, but the pipeline agents now live in
  `/var/www/hdp/agents-archive/`. underseer is paused and would not find its
  agents as-is. The pipeline wrap-up change is consistent with AGENT_ROOT (both
  move together), so it is correct once the root is fixed. Fix AGENT_ROOT (and
  the other `/var/www/hdp/agents/...` and `/var/www/hdp/staging/docs/...` paths in
  underseer + the overseer CLAUDE.md) as part of the DPA multi-project refactor
  (Phase 4). Until then, **the pipeline wrap-up is untested live** (no cycle has
  run); the logic compiles and mirrors the proven Conductor wrap-down path.
