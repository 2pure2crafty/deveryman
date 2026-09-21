# Multi-project DPA design

The DPA (the automated build pipeline) is being made project-agnostic so
D'everyman can point it at any project. It was generalized from an original
bespoke pipeline built for a single project; that pipeline's underseer is the
reference, and the generic implementation lives here in `dpa/`.

## The two things that were project-specific in the original bespoke pipeline

1. **The daemon** (`underseer.py`): hardcoded paths (the project's agent and
   pipeline-docs roots), the `DEV-` tmux prefix, `sudo -u deveryman` git, an
   `autonomous/` branch prefix, the staging URL, and a project-flavoured
   startup-context template.
2. **The stage agents** (features / acceptance / dev / testing-staging /
   integration-testing / reviewer / ux-ui / product): 20-46% of each CLAUDE.md
   was project-specific, workspace paths, tech stack, domain copy, the user-type
   framework, design palette, test logins.

## The generic model

**A project is described by one config file** (`project.json`, see
`project.example.json`). It carries the paths, the tmux prefix, the git settings,
the autonomy level, the stage list, and a `context` block (project summary, tech
stack, conventions, user types, design notes). The daemon reads it and runs a
pipeline for that project.

**Stage agents split in two:**
- **Generic role template** (`dpa/agents/<stage>/CLAUDE.md`): the project-agnostic
  role and discipline, ~70% of the original. Same for every project.
- **Per-project context**: injected at instantiation from the config's `context`
  block, plus per-feature detail via `startup-context.md` (as today). This is the
  30% that was project-specific.

**Instantiation.** When a project enables the DPA, the daemon materializes a
per-project pipeline workspace: for each stage, an agent dir containing the
generic role CLAUDE.md plus a `PROJECT.md` with that project's context. The stage
agent runs there and operates on the project's own repo (a feature branch cut
from the base branch).

```
<project.pipeline_root>/
  agents/<stage>/CLAUDE.md     generic role (copied from dpa/agents/<stage>)
  agents/<stage>/PROJECT.md    this project's context (from config.context)
  agents/<stage>/startup-context.md   per-feature (written each run, as today)
  docs/pipeline-state.md, build-queue.md, product-backlog.md, dev-inbox/, ...
```

Nothing project-specific is baked into the daemon or the role templates; it all
comes from the project config. Each real project becomes just another project
config.

## The end-to-end model

The pipeline runs from a rough idea to a shipped, verified release. One boundary
is deliberate: **the DPA automates the development middle; idea-generation at the
front and deployment at the back are human-gated and never autonomous, at any
autonomy level.** The daemon only signals that a gate is ready; it never crosses
one itself.

```
ideas (human)  ->  product feeder  ->  build queue  ->  per-feature pipeline
                                                        (features .. ux-ui)
   ->  merge feature into base (staging)
   ->  [human gate] promote base to release (main)
   ->  [human gate] deploy release to production
   ->  [human gate] verify production
```

**Front (human-initiated).**
- `ideas` is a human-run brainstorm agent. It never starts on its own. Approved
  ideas are appended to the backlog file (`backlog_file`, default
  `product-backlog.md`) as QUEUED rows.
- `product` is a feeder, not a pipeline stage. When the build queue has nothing
  runnable and the backlog has QUEUED items, the daemon runs product to turn them
  into build-queue features (only at the top autonomy level; below that it just
  signals "run the product feeder?").

**Middle (automated).** The stage list runs per feature as described above.

**Back (human-gated).**
- `deploy` and `testing-live` are generic templates; the actual deploy procedure
  is project-specific and lives in the project's deployment note
  (`deployment_note`), never guessed. Deploy refuses if no note exists.
- The daemon signals when `base_branch` is ahead of `release_branch`, but promote,
  deploy, and verify run only when the operator triggers them (the dashboard Gates
  buttons, or `underseer.py <config> --promote|--deploy|--verify`).

## Branching

- **`base_branch`** (default `staging`): the long-lived integration branch. Each
  feature is cut FROM it (`feature/<id>-<slug>`), and on passing the last stage the
  feature is merged BACK into it (`--no-ff`) and the feature branch is deleted. The
  daemon creates `base_branch` from the repo's default branch if it does not exist.
- **`release_branch`** (default `main`): the promotion target. `promote` merges
  `base_branch` into it (`--no-ff`), human-gated.
- **Merge conflicts** (feature-into-base or base-into-release) abort cleanly
  (`git merge --abort`, base/release left intact), escalate, and halt for the
  operator. Never force, never `-X ours/theirs`.
- **Single-branch repos**: set `base_branch == release_branch`; features still
  merge into it and `promote` becomes a logged no-op.
- All daemon git is local (no push/pull); a project that needs a push does it as
  part of its deployment note, so the daemon stays remote-agnostic.

## Config reference (project.json keys)

Branching and gates, all with backward-compatible defaults:

- `base_branch` (`"staging"`), `release_branch` (`"main"`), `feature_branch_prefix`
  (`"feature/"`).
- `backlog_file` (`"product-backlog.md"`): the front feeder's input.
- `deployment_note` (`"dev-inbox/deployment-note.md"`, relative to docs): the
  project's deploy procedure the deploy agent follows.
- `production_ref` (`""`): optional production label/URL for verification.
- `autonomy_level`: gates how far the development middle auto-advances (and, at the
  top level, whether the product feeder auto-runs). It never gates the human-only
  steps, which are always manual.

## Scope of the first cut

Prove the mechanism on a fresh sandbox project: a generic, config-driven daemon
drives the generic stage agents through a real (if tiny) feature, cut from the
base branch and merged back on completion. Then a real project migrates onto the
same generic daemon by writing its own `project.json`.
