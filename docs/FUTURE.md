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

## Phase 4 remaining (needs the pipeline un-paused)

Shipped this pass: the shared framework (`shared/framework/framework.php`) and a
read-only DPA dashboard (`dpa/`, live on :8444). Still to do, all gated on the
pipeline being un-paused so it can be verified live:

1. **underseer multi-project refactor.** Make the daemon per-project: fix the
   stale paths (`AGENT_ROOT`, `/var/www/hdp/staging/docs/...`), take a per-project
   pipeline config (project path/repo, build queue, cycle state, autonomy), and
   run pipelines for any project, not just HDS. This is the largest piece and
   settles the state model the dashboard reads.
2. **Consolidate the pipeline state surface.** Today `pipeline-state.md` is
   scattered per-stage and `build-queue.md` was not found; the read-only
   dashboard already degrades gracefully, but the canonical files need pinning
   (part of the refactor).
3. **DPA dashboard controls.** Once the state model is settled: the intake form
   as a web form (start/stop cycles -> current-config.md), approve/deny
   escalations, daemon on/off. Read-only first (done), controls second.
4. **Converge Conductor onto the shared framework.** Conductor still carries its
   own copies of the generic helpers (auth, render, run_cmd). It works and was
   left untouched to avoid surgery on a live service; fold it onto
   `shared/framework` deliberately later. Temporary duplication, by design.

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

## Phase 4 progress (multi-project DPA) VERIFIED LIVE

The generic config-driven daemon (`dpa/underseer.py`) drove a full pipeline
(features -> dev -> reviewer) on a fresh non-HDS sandbox project: it wrote an
on-spec build phase, implemented and committed the feature on a branch, passed
review, and the built code runs and passes its own checks. Driven entirely by
`project.json`; nothing HDS baked in.

Remaining to finish multi-project DPA:
- Generic templates for the other 5 stages (acceptance, testing-staging,
  integration-testing, ux-ui, product). Only features/dev/reviewer exist; the
  first run used a 3-stage config to prove the mechanism.
- Run the daemon under a persistent supervisor (per-project systemd service). The
  test used the background task runner; real use needs reboot/shell survival.
- Migrate HDS onto the generic daemon via its own `project.json` (retire the
  hardcoded overseer/underseer.py).
- DPA dashboard controls + per-project state (start/stop cycles, escalations),
  pointed at a project's `.pipeline/docs`.
- Shared `projects.json` so launcher/Conductor/DPA read one registry.
- Minor: agents sometimes write em dashes in their output; a project's PROJECT.md
  can forbid it if it matters.

## Closed off (escalation triage, DPA controls, HDS migration, overseer retirement)

- **Always-on overseer retired** in favour of the trio: deterministic daemon +
  DPA dashboard + one-shot reasoning. No always-on AI supervisor is needed.
- **Escalation triage**: on a double kick-back the generic daemon quarantines the
  feature and calls a one-shot Haiku (`reason()`) to write a triaged escalation
  (what happened, likely cause, options + recommendation). Verified live.
- **DPA write-controls**: start/stop/restart a project's daemon from the
  dashboard, via a scoped sudoers rule (dpa-underseer@ only) + slug validation.
  Verified live on the sandbox.
- **HDS migration, config only (NOT run)**: `/var/www/dpa-projects/hds/project.json`
  carries HDS's full context (stack, Swedish, communes, six user types, palette),
  registered in projects.json so the DPA dashboard shows it read-only. repo_root
  is a deliberate PLACEHOLDER and autonomy is 1, so it cannot run against real HDS
  code until Patch sets the path and starts it deliberately. HDS's own bespoke
  pipeline is untouched. Live HDS, its code, and its GitHub repos were NOT touched.

## Genuinely remaining
- **Actually switching HDS over** (set the real repo path, choose autonomy, start
  the daemon, run a cycle): a deliberate decision for Patch, not done here.
- **Converge Conductor onto the shared framework** (it still carries its own
  helper copies; works, left untouched to avoid live surgery).
