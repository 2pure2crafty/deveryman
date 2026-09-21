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
- [ ] Nail the schema: agent type = `{id, label, role, reads[], writes[],
  done_signal, kickback{target, doc}}`; pipeline template = `{branching, backlog,
  deployment_note, nodes[], flow[], artifacts[], gates[]}`.
- [ ] Make the underseer per-agent contract explicit: pass each agent its input
  file(s), output file, and done-signal via `startup-context.md` (today it is
  implicit convention).
- [ ] Store agent types and templates as user-editable JSON registries, not code
  (generalize the hardcoded `launcher/templates.php`).

### 2. Custom agents (agent-type editor)
- [ ] Save an agent as a reusable type (e.g. an accessibility agent): its role,
  its input, its output, its done-signal.
- [ ] A browsable agent-type library the builder and new-agent pick from.

### 3. User-created pipeline templates
- [ ] Stepping stone: a form-based template editor (pick agent types, order them,
  set kickback + branching) that saves a template.
- [ ] Centerpiece (later): the visual node-graph builder. Agent cards with in/out
  ports connected in sequence; a files lane below wiring output -> file -> reader.
  Each connection carries a required artifact contract; an unconfigured connection
  shows an exclamation mark and, below, "configure the output of A for B". Save
  compiles the graph into the underseer config.

### 4. Import-repo wiring (this branch's namesake)
- [ ] Make import template-driven (like new-project).
- [ ] Add a wiring step for imported repos: which branch is base (their staging),
  which is release (their live), where ux-ui merges, where deploy merges from and
  into, the deployment note. Removes today's limit that DPA standard forces a new
  repo.

### 5. Underseer consumption
- [ ] The underseer reads the generated config only; no new exposed knobs; it
  honors the explicit per-agent I/O from step 1.

## Decisions pinned
- Linear-with-kickbacks (graph compiles to ordered stages + kickback); defer true
  parallel/DAG.
- The visual builder generates the config; users never hand-edit the underseer.
- Build against the working DPA standard first; the model and the underseer evolve
  together (the format is derived from what the DPA actually does).
