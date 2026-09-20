# Multi-project DPA design

The DPA (the automated build pipeline) is being made project-agnostic so
D'everyman can point it at any project, not just HDS. The HDS underseer at
`/var/www/hdp/agents-archive/overseer/underseer.py` is the reference; the generic
implementation lives here in `dpa/`.

## The two things that were HDS-specific

1. **The daemon** (`underseer.py`): hardcoded paths (`/var/www/hdp/agents`,
   `/var/www/hdp/staging/docs`), the `HDS-` tmux prefix, `sudo -u hdp` git,
   `autonomous/` branch prefix, the staging URL, and an HDS-flavoured
   startup-context template.
2. **The stage agents** (features / acceptance / dev / testing-staging /
   integration-testing / reviewer / ux-ui / product): 20-46% of each CLAUDE.md
   was HDS-specific, workspace paths, tech stack (PHP/MySQL/Leaflet), Swedish
   copy, the six-user-type framework, design palette, test logins.

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
  30% that was HDS-specific.

**Instantiation.** When a project enables the DPA, the daemon materializes a
per-project pipeline workspace: for each stage, an agent dir containing the
generic role CLAUDE.md plus a `PROJECT.md` with that project's context. The stage
agent runs there and operates on the project's own repo (a cycle branch).

```
<project.pipeline_root>/
  agents/<stage>/CLAUDE.md     generic role (copied from dpa/agents/<stage>)
  agents/<stage>/PROJECT.md    this project's context (from config.context)
  agents/<stage>/startup-context.md   per-feature (written each run, as today)
  docs/pipeline-state.md, build-queue.md, product-backlog.md, dev-inbox/, ...
```

Nothing HDS is baked into the daemon or the role templates; it all comes from the
project config. HDS itself becomes just another project config later.

## Scope of the first cut

Prove the mechanism on a fresh sandbox project: a generic, config-driven daemon
drives the generic stage agents through a real (if tiny) feature, on the
sandbox's own git branch. Then HDS migrates onto the same generic daemon by
writing its own `project.json`.
