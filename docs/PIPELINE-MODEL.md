# Pipeline model (derived from DPA standard)

This is the data model a user's pipeline compiles to, discovered by expressing the
working DPA standard pipeline in it. It is what the visual builder will produce and
what the underseer consumes. No hand-editing of underseer variables: the graph is
the source of truth.

## Concepts

- **Node (agent).** A stage or gate. Has a role (its CLAUDE.md), a set of files it
  **reads**, a set it **writes**, a **done-signal** (how it tells the underseer it
  finished), and an optional **kickback** (a status it can raise, a target node to
  route back to, and the feedback doc it leaves).
- **Flow edge.** A forward hand-off `A -> B`: after A completes, B runs.
- **Artifact wiring.** The files on the edges: a file is **produced by** one node
  and **consumed by** one or more later nodes. This is the "files lane" under the
  graph.
- **Kickback edge.** A backward route `B -> A` taken when B raises `KICKED BACK`.
  Work still moves forward overall; kickbacks are the correction loop.
- **Gate.** A human-gated node (promote, deploy, verify) the underseer never runs
  on its own; it only signals readiness.

### The connection contract (the "exclamation mark")
A flow edge `A -> B` is **configured** only if B declares an input file that A
writes (the artifact that carries the hand-off). An edge with no such declared
artifact is **unconfigured**: in the builder it shows an exclamation mark, and the
files lane prompts "configure the output of A for B: state the file A writes that B
reads." In DPA standard every edge is configured (below), which is why it runs.

## Schema

```
pipeline_template = {
  id, label,
  branching: { base_branch, release_branch, feature_branch_prefix },
  backlog_file, deployment_note,
  nodes: [ {
    id, agent_type, role,                 # role = conductor/agent-templates or dpa/agents CLAUDE.md
    reads:  [ "<file or artifact ref>" ],
    writes: [ "<file or artifact ref>" ],
    done_signal: "pipeline-state Stage status: COMPLETE",
    kickback: { on: "KICKED BACK", target: "<node id>", doc: "<file>" } | null,
    kind: "feeder" | "stage" | "gate"
  } ],
  flow:      [ { from, to } ],            # forward sequence
  artifacts: [ { file, produced_by, consumed_by: [ ... ] } ],
  gates:     [ "<node id>" ]              # human-only nodes
}
```

## DPA standard, as nodes

| Node | kind | reads | writes | kickback -> (doc) |
| --- | --- | --- | --- | --- |
| product | feeder | product-backlog.md (QUEUED) | build-queue.md (QUEUED rows); backlog rows -> PROCESSED | - |
| features | stage | the assigned build-queue item; PROJECT.md | dev-inbox/build-phase.md (spec) | - |
| acceptance | stage | build-phase.md | acceptance/<slug>-criteria.md | features (spec too vague) |
| dev | stage | build-phase.md; any kickback doc | code commits on the feature branch | features (dev-blocked.md) |
| testing-staging | stage | criteria.md; build-phase.md; branch code | acceptance-fixes.md (on fail) | dev (acceptance-fixes.md) |
| integration-testing | stage | spec; criteria; branch code | integration-fixes.md (on fail) | dev (integration-fixes.md) |
| reviewer | stage | build-phase.md; branch diff | reviewer-feedback.md (on fail) | dev (reviewer-feedback.md) |
| ux-ui | stage | spec; built feature on branch | ux-fixes.md (on fail) | dev (ux-fixes.md) |
| deploy | gate | deployment-note.md; release branch | deploy report | - |
| testing-live | gate | acceptance criteria; deployment note; production | verification report | - |

System actions between/after nodes (the underseer, not agents): cut the feature
branch from `base_branch` before `features`; merge it back into `base_branch`
(no-ff) after `ux-ui`; promote `base_branch -> release_branch` at the deploy gate.

## DPA standard, as flow + artifacts

Forward flow:

```
backlog -> product -> build-queue
        -> features -> acceptance -> dev -> testing-staging
        -> integration-testing -> reviewer -> ux-ui
        -> [merge feature into base]
        -> gate:promote (base -> release) -> gate:deploy -> gate:verify
```

Kickback edges: acceptance->features, dev->features, testing-staging->dev,
integration-testing->dev, reviewer->dev, ux-ui->dev.

Artifact wiring (producer -> file -> consumers):

- product -> `build-queue.md` -> features (the assigned item)
- features -> `dev-inbox/build-phase.md` -> acceptance, dev, testing-staging, integration-testing, reviewer, ux-ui
- acceptance -> `acceptance/<slug>-criteria.md` -> testing-staging, integration-testing
- dev -> `<feature branch commits>` -> testing-staging, integration-testing, reviewer, ux-ui
- testing-staging -> `dev-inbox/acceptance-fixes.md` -> dev
- integration-testing -> `dev-inbox/integration-fixes.md` -> dev
- reviewer -> `dev-inbox/reviewer-feedback.md` -> dev
- ux-ui -> `dev-inbox/ux-fixes.md` -> dev
- human/dev -> `dev-inbox/deployment-note.md` -> deploy, testing-live

Every forward edge above has a declared artifact, so DPA standard has no
unconfigured connections.

## How it compiles to the underseer config

The current `project.json` is a lossy projection of this model:
- `stages` = the ordered `stage`-kind node ids (features .. ux-ui).
- `kickback_target` = the kickback edges (`{testing-staging: dev, ...}`).
- `base_branch` / `release_branch` / `feature_branch_prefix` / `backlog_file` /
  `deployment_note` = the branching + file config.
- The feeder (`product`) and gates (`deploy`, `testing-live`) are not stages; the
  underseer runs them via the feeder logic and the operator gate actions.

What the current config does NOT yet carry, and step 1 of `CUSTOMIZATION.md` adds:
the per-node **reads/writes** (the artifact wiring). Today each agent's I/O is
convention baked into its CLAUDE.md; making it explicit in the config (and passed
through `startup-context.md`) is what lets a user rewire files between custom
agents without editing prose.

## DPA standard as data (the compiled template)

```json
{
  "id": "dpa-standard",
  "label": "DPA standard",
  "branching": { "base_branch": "staging", "release_branch": "main", "feature_branch_prefix": "feature/" },
  "backlog_file": "product-backlog.md",
  "deployment_note": "dev-inbox/deployment-note.md",
  "nodes": [
    { "id": "product", "kind": "feeder", "reads": ["product-backlog.md"], "writes": ["build-queue.md"], "kickback": null },
    { "id": "features", "kind": "stage", "reads": ["build-queue.item"], "writes": ["dev-inbox/build-phase.md"], "kickback": null },
    { "id": "acceptance", "kind": "stage", "reads": ["dev-inbox/build-phase.md"], "writes": ["acceptance/criteria.md"], "kickback": { "target": "features", "doc": null } },
    { "id": "dev", "kind": "stage", "reads": ["dev-inbox/build-phase.md", "dev-inbox/*-fixes.md", "dev-inbox/reviewer-feedback.md"], "writes": ["<feature-branch commits>"], "kickback": { "target": "features", "doc": "dev-inbox/dev-blocked.md" } },
    { "id": "testing-staging", "kind": "stage", "reads": ["acceptance/criteria.md", "dev-inbox/build-phase.md", "<feature-branch>"], "writes": ["dev-inbox/acceptance-fixes.md"], "kickback": { "target": "dev", "doc": "dev-inbox/acceptance-fixes.md" } },
    { "id": "integration-testing", "kind": "stage", "reads": ["dev-inbox/build-phase.md", "acceptance/criteria.md", "<feature-branch>"], "writes": ["dev-inbox/integration-fixes.md"], "kickback": { "target": "dev", "doc": "dev-inbox/integration-fixes.md" } },
    { "id": "reviewer", "kind": "stage", "reads": ["dev-inbox/build-phase.md", "<feature-branch diff>"], "writes": ["dev-inbox/reviewer-feedback.md"], "kickback": { "target": "dev", "doc": "dev-inbox/reviewer-feedback.md" } },
    { "id": "ux-ui", "kind": "stage", "reads": ["dev-inbox/build-phase.md", "<feature-branch>"], "writes": ["dev-inbox/ux-fixes.md"], "kickback": { "target": "dev", "doc": "dev-inbox/ux-fixes.md" } },
    { "id": "deploy", "kind": "gate", "reads": ["dev-inbox/deployment-note.md", "<release-branch>"], "writes": ["deploy-log/report.md"], "kickback": null },
    { "id": "testing-live", "kind": "gate", "reads": ["acceptance/criteria.md", "dev-inbox/deployment-note.md", "<production>"], "writes": ["deploy-log/verify.md"], "kickback": null }
  ],
  "flow": [
    { "from": "product", "to": "features" },
    { "from": "features", "to": "acceptance" },
    { "from": "acceptance", "to": "dev" },
    { "from": "dev", "to": "testing-staging" },
    { "from": "testing-staging", "to": "integration-testing" },
    { "from": "integration-testing", "to": "reviewer" },
    { "from": "reviewer", "to": "ux-ui" },
    { "from": "ux-ui", "to": "deploy" },
    { "from": "deploy", "to": "testing-live" }
  ],
  "gates": ["deploy", "testing-live"]
}
```
