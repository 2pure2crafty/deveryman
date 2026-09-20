# Everyman

Everyman is the top layer: the OS / shell that sits above the orchestrators. You
log into Everyman and from there you operate on your projects through whichever
capability fits, Conductor (manual, on-demand agents) or DPA (the automated
build pipeline). This repo is the monorepo that holds all of it.

> Status: design + roadmap. Nothing here is built yet. Conductor already exists
> and runs (currently at /var/www/hdp/agents/conductor); it migrates into this
> repo during the build. This doc is the agreed structure to build toward.

## The mental model

**The project is the primary entity.** A project is a codebase (a directory,
usually a git repo). Against a project you can apply one or both capabilities:

- **Conductor**: manual, on-demand agents you talk to directly (ideas, planning,
  product, ad-hoc work), with the memory discipline and the auto-wrap-down
  daemon.
- **DPA**: the automated build pipeline (features -> acceptance -> dev ->
  testing -> reviewer -> ux-ui ...), driven by the underseer daemon.

So navigation is **project-first**:

```
Everyman (login)
  -> Projects
       -> HDS
            -> Conductor  (talk to the ideas agent, spin up/down, ...)
            -> DPA        (run the build pipeline, watch cycles, ...)
       -> <other project>
            -> Conductor
            -> DPA
  -> (aggregate view: tokens used + tokens saved across everything)
```

Everyman is the shell; Conductor and DPA are capabilities; projects are what you
point them at. Both capabilities must be **generic / multi-project** (Conductor
already is; DPA is not yet, see below).

## Architecture (monorepo layout)

```
everyman/
  shared/
    framework/     PHP plumbing both dashboards reuse: auth, render/CSS,
                   run_cmd, tmux status, config, the transcript/token reader.
    memory-kit/    The portable discipline: the wrap-up skill (with git commit
                   cross-referencing), the CLAUDE.md reading-ladder snippet, and
                   the convention doc. Installed INTO agents.
  conductor/       The Conductor dashboard + daemon (manual agents). Migrates
                   here from its current location.
  dpa/             The DPA dashboard + the (now multi-project) underseer daemon.
  launcher/        Everyman itself: login, the project-first UI, and the
                   aggregate token view.
  projects.json    Canonical project registry (shared source of truth).
```

### Shared project registry

One `projects.json` is the source of truth for what projects exist:

```
{ "projects": {
    "hds": { "label": "HDS", "path": "...", "repo": "...", "description": "...",
             "capabilities": { "conductor": {...}, "dpa": {...} } }
} }
```

`capabilities.conductor` holds that project's manual agents (what Conductor's
registry holds today). `capabilities.dpa` holds that project's pipeline config
(stages, cycle state, autonomy). Conductor and DPA both read this file and only
touch their own section. Defining a project once makes it visible to both.

## Two portable shared pieces (this is the "shared repo" instinct, resolved)

There are two cross-cutting things, and they factor out cleanly:

1. **The memory-kit** installs into *agents*: the wrap-up skill + reading ladder
   + convention. Any agent (Conductor or DPA) can follow it.
2. **The framework** is reused by *dashboards*: auth, rendering, run_cmd, tmux
   status, the token/transcript reader. Conductor, DPA, and the launcher are
   three thin apps on this one framework.

Both live under `shared/` in the monorepo. No separate little repos.

## The discipline: one skill, different invokers

Decided: apply the memory discipline to the manual agents AND to the pipeline
stages (via their wrap-up), but never let the auto-wrap-down killer touch
DPA-owned agents.

- **Manual agents** (ideas, product, planning, overseer): you or the Conductor
  daemon invoke `/wrap-up`. Auto-wrap-down (big + idle) applies here.
- **Pipeline stages** (features, dev, testing, reviewer, ux-ui): the underseer
  daemon invokes `/wrap-up` at stage handoff, just before it kills the agent.
  Auto-wrap-down never applies (they're not in Conductor's registry, never carry
  the flag). Pipeline agents run in `--permission-mode auto`, so the SESSION.md
  write won't stall on a permission prompt.

Same skill; the orchestrator that owns the lifecycle is the one that pulls the
trigger. That is the clean separation.

**Granularity note:** a pipeline stage's `HISTORY.md` becomes that *role's*
logbook (every `dev` run across every feature, accumulating), not a per-feature
record. That is the intended value: each pipeline role builds institutional
memory the daemon can digest.

### Git commit cross-referencing (new, in the memory-kit)

Every wrap-up stamps the handoff with git state so the agent's history lines up
with the repo's history. Concretely, each `SESSION.md` / `HISTORY.md` entry
includes a "Git state" line: current branch, `git rev-parse --short HEAD`, and
the commits made this session (`git log --oneline <prev>..HEAD`). If the working
dir is not a git repo, this is skipped gracefully.

Why: to revert a branch and pick up where you left off, you find the commit,
find the matching history entry by its sha, and read that handoff. It makes
"context history" and "git history" a single correlated timeline.

## Token accounting (Conductor, DPA, and the Everyman aggregate)

The transcript/token reader (already built in Conductor) is the shared
primitive: it reads Claude Code's per-project transcripts and totals tokens.
Because DPA agents also run `claude`, the same reader totals their usage too.

- Conductor view: tokens used by its manual agents (per project).
- DPA view: tokens used by the pipeline (per project).
- **Everyman aggregate:** the combination, per project and globally, plus an
  estimated **tokens saved** figure. "Saved" is a guesstimate: sum the context
  size at each auto-wrap-down times ~1.25 (the cold cache rebuild avoided by
  resetting to a small SESSION.md). Labelled as an estimate, not billing truth.

## Making the DPA multi-project (the big lift)

underseer today is HDS-specific. To be a capability Everyman can point at any
project, it needs a per-project pipeline config (project path/repo, build queue,
cycle state, autonomy level) and to run pipelines per project (one underseer
managing several project-pipelines, or an instance per project). This is the
largest single piece of the whole endeavour and gets its own phase.

## Roadmap (staged)

Ordered to deliver small wins first, then the big dashboard/multi-project arc.

**Phase 0 - This repo.** Everyman repo + this structure doc. (Done, local.)

**Phase 1 - Memory-kit + manual agents.** Extract the wrap-up skill + reading
ladder + convention into `shared/memory-kit/`, add git commit cross-referencing,
install into the manual agents (ideas, product, planning, overseer). Small,
independent, high value.

**Phase 2 - Pipeline wrap-up.** Put the wrap-up skill into the pipeline stage
agents; modify underseer to send `/wrap-up` and wait before `kill-session`
(kill sites already located). Each role gets its git-stamped logbook.

**Phase 3 - Framework extraction + Conductor migration.** Factor Conductor's
plumbing into `shared/framework/` (extract-on-second-use). Migrate the live
Conductor into `everyman/conductor/` carefully (systemd unit paths, config path,
Tailscale), same discipline as the router->conductor rename. Introduce
`projects.json` as the shared registry.

**Phase 4 - DPA multi-project + DPA dashboard.** Refactor underseer to be
per-project. Build the DPA dashboard on the shared framework: read-only first
(pipeline state, build queue, cycles, escalations), then the controls (the
intake form as a web form -> start/stop cycles, approve/deny escalations, daemon
on/off). This turns the "overseer AI as the pipeline's interface" into a real UI,
and makes retiring the always-on overseer natural.

**Phase 5 - The Everyman launcher.** The project-first shell: login, the project
list, per-project Conductor/DPA entry points, and the aggregate token view (used
+ estimated saved across both capabilities).

## Open decisions (to confirm before/along the way)

1. **Nav spine: project-first** (Everyman -> project -> Conductor/DPA) is the
   agreed primary. A secondary "by tool" view (all Conductor agents; all DPA
   pipelines) can come later if useful.
2. **Everyman location:** `/var/www/everyman` (above HDS, since it is the OS over
   all projects). Conductor moves under it in Phase 3.
3. **Read-only-first for the DPA dashboard:** strongly recommended, since the
   control buttons drive real pipeline runs.
4. **Repo visibility:** Conductor is public-temporarily with a restrictive
   license. Everyman (holding Conductor) presumably follows the same, confirm
   before pushing to GitHub.
5. **One underseer or per-project instances** for the multi-project DPA: to be
   decided in Phase 4 design.
