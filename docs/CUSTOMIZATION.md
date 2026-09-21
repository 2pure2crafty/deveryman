# Customization: user-composed pipelines

The `customization` branch. The north star: **users compose their own pipelines,
and the composition GENERATES the underseer config, so nobody hand-edits
`project.json` or "underseer variables."** A pipeline is a graph of agents wired by
the files they pass between them; saving it compiles down to the config the
underseer already runs. The fancy visual builder is the centerpiece but comes
last; first we settle the data model by reverse-engineering the one pipeline we
already have working (DPA standard).

The model in one line: **nodes (agents) + flow edges (sequence) + file-artifact
wiring (who writes what, who reads it) + kickback edges** compile to the
underseer's ordered stages, `kickback_target`, and per-agent I/O. See
`PIPELINE-MODEL.md` for the schema and DPA standard expressed in it.

The pipeline stays **linear-forward**: kickbacks and circular-looking routes
(reviewer -> dev -> reviewer) still move work in one direction overall; the graph
compiles to an ordered stage list plus kickback targets. True parallel/DAG
execution is deferred.

## To-do (order: 1 -> 2 -> 4 -> 3, then 5 throughout)

### 1. Foundation: the data model + explicit agent contract
- [x] Reverse-engineer DPA standard into the model (see `PIPELINE-MODEL.md`).
- [x] Nail the schema: agent type = `{id, label, kind, role, reads[], writes[],
  done_signal, kickback{target, doc}}`; pipeline template = `{branching, backlog,
  deployment_note, nodes[], flow[], gates[]}`. See `launcher/registry.php` (the
  schema, seeds, `deveryman_compile_template`, and `deveryman_validate_template`).
- [x] Make the underseer per-agent contract explicit: each agent gets its input
  file(s), output file(s), and done-signal via the read-only
  `pipeline-instructions.md` overlay (Safeguard 1), pointed to from
  `startup-context.md`.
- [x] Store agent types and templates as user-editable JSON registries, not code
  (`launcher/registries/agent-types.json` + `pipeline-templates.json`, seeded from
  the builtins; `launcher/templates.php` is now a thin adapter that compiles a
  template down to the project.json the daemon runs).

### 2. Custom agents (agent-type editor)
- [x] Save an agent as a reusable type (e.g. an accessibility agent): its role
  (summary/do/do-not/free-form), its inputs, its outputs, its done-signal, an
  optional kickback. Form: `launcher/public/new-agent-type.php`.
- [x] A browsable agent-type library the builder and new-agent pick from:
  `launcher/public/agent-types.php`. A user type materializes its role into a
  per-project `pipeline/roles/<node>/CLAUDE.md` override that the daemon's
  `materialize_agent` prefers over the shared `dpa/agents` template.

### 3. User-created pipeline templates
- [x] Stepping stone: a form-based template editor (pick agent types, order them,
  set kickback + branching) that saves a template. `launcher/public/new-template.php`
  + the library `templates.php`. A saved template flows straight into new-project
  and import (both read `deveryman_templates()`), and validates against the same
  wiring checks the daemon runs. Node id differs from agent type, so the same type
  can sit in more than one spot (a second reviewer as `reviewer-final`); the visual
  builder makes the graph visible but the model already supports repeats.
- [ ] Centerpiece (later, NOT in this pass): the visual node-graph builder. Agent
  cards with in/out ports connected in sequence; a files lane below wiring
  output -> file -> reader. Each connection carries a required artifact contract; an
  unconfigured connection shows an exclamation mark and, below, "configure the
  output of A for B". Save compiles the graph into the underseer config. THIS is the
  agreed stop line for the current pass.

### 4. Import-repo wiring (this branch's namesake)
- [x] Make import template-driven (like new-project): `import.php` now picks a
  pipeline template and shares the applier's `deveryman_finalize_project` tail.
- [x] Add a wiring step for imported repos: base branch (their staging, where
  features branch off and ux-ui merges back), release branch (their live, the
  deploy-gate promote target), feature-branch prefix, and the deployment note.
  Branches are discovered without cloning (`gh api` / `git ls-remote`), so the repo
  is only cloned on final confirm. DPA standard no longer forces a fresh repo.

### 5. Underseer consumption
- [x] The underseer reads the generated config only; no new exposed knobs. It
  honors the explicit per-agent I/O (the compiled `io` map, delivered via the
  read-only `pipeline-instructions.md` overlay) and sources a custom role from the
  per-project `pipeline/roles/<node>/CLAUDE.md` override (a workspace file, not a
  config field), falling back to the shared `dpa/agents` template for builtins.

## Next block (emerging from review; not yet built)

### 6. Escalation chains (reroute on repeated failure) [BUILT]
A kickback can carry an **escalation route**, so when a feature fails at the same
spot too many times, instead of being quarantined it is routed onto a **second chain
of agents in the same pipeline** (the escalation chain); only if it also fails there
does it escalate to a human.
- [x] Model: a node's kickback gained `{ escalation_target, fail_threshold }`, and a
  node gained `chain: main | escalation`. Compile emits `escalation_stages` (the
  chain, ordered) and `escalation` (`stage -> {target, threshold}`); both are omitted
  when unused, so a plain pipeline compiles byte-identically. Still
  linear-with-kickbacks, not a DAG.
- [x] Underseer: per-spot kickback counting (`kick_back_by_stage` in state);
  `kickback_route()` (pure, unit-tested) routes a stage onto its escalation chain
  once its spot passes `threshold`; the escalation chain gets its own fresh per-spot
  budget and quarantines only if it also keeps failing. `next_stage` advances within
  whichever chain the feature is on; escalation stages are materialized and wiring-
  validated. Plain pipelines behave exactly as before.
- [x] Form editor: an "Escalation chain" section + a threshold; the form wires every
  kickback-capable main stage to the chain head. The full per-node routing lives in
  the template JSON for the visual builder to drive.
- Builder (later): draw the kickback line, branch off it for "fails here N times ->
  escalation node"; the exclamation mark sits on the kickback until its criteria are
  filled.

### 7. Per-pipeline agent tweaks
A predefined agent type pulled into a pipeline may need a small tweak for that one
pipeline (extra instructions), without forking the whole library.
- Option A: per-node "extra instructions" appended to that pipeline's read-only
  `pipeline-instructions.md` overlay; the library type is untouched.
- Option B: "customize and save as a new agent type" (fork into a new library entry).
- OPEN: A, B, or both (inline tweak by default, with a "save as new type" button).
  (Asked.)

### Kickback contract (for the visual builder)
Every review/test agent CAN kick back (it advertises a `kickback_doc`), but nothing
kicks back until wired. In the visual builder, drawing a kickback line raises the
exclamation mark until its target (and, if used, its escalation branch + criteria)
are filled. No kickbacks are wired by default.

## Decisions pinned
- Linear-with-kickbacks (graph compiles to ordered stages + kickback); defer true
  parallel/DAG. Escalation chains are additional wired routes, still not a DAG.
- Kickbacks are wired per pipeline on the template node, never defaulted on the agent
  type; a type only advertises the doc it leaves (`kickback_doc`).
- Node id is distinct from agent type, so a type can appear in multiple spots.
- The visual builder generates the config; users never hand-edit the underseer.
- Build against the working DPA standard first; the model and the underseer evolve
  together (the format is derived from what the DPA actually does).
- No example registry files are shipped; builtins live in code and seed the live
  (gitignored) registries on first save. Example templates/projects come later.
