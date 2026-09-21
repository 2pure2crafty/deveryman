# The pipeline builder canvas: concept -> visual mapping

How each element of the pipeline model (see `PIPELINE-MODEL.md`) is drawn on the
LiteGraph canvas. The canvas is a view/editor over the pipeline template; it emits
the same `pipeline_template` JSON the registry already stores and the daemon runs.
The server resolves a template into a flat "canvas model" (`deveryman_canvas_model`),
and the adapter (`launcher/public/js/pipeline-canvas.js`) draws it. LiteGraph draws
the node cards + pan/zoom/drag; the adapter paints the edges and badges so we control
their meaning and colour.

## Nodes (agent cards)

| kind | look | meaning |
| --- | --- | --- |
| feeder (product) | slate card, "feeder" chip | turns backlog into build-queue rows; the front |
| stage | blue-title card | a pipeline stage the daemon runs |
| escalation stage | amber-title card, sits on a row ABOVE the main line | runs only when a feature is escalated |
| gate (deploy, testing-live) | dashed border, "gate" chip | human-triggered; the daemon only signals readiness |
| merge | green diamond-ish card | the deterministic rejoin/end; the daemon merges here |

Inside each card the adapter paints its **reads** (blue) and **writes** (green) file
lists, a **tagger star** if it assigns the routing tag, and any **exclamation badge**
(below).

## Edges (wires, painted by the adapter)

| edge | colour | shape | carries |
| --- | --- | --- | --- |
| forward flow `A -> B` | blue | bezier, arrow into B | the hand-off sequence |
| guarded flow (tag fork) | blue with a **tag chip** on the wire | bezier | "take this branch when tag = X" |
| kickback `B -> A` | red | bezier curving back/under | the correction loop; labelled with its feedback doc |
| escalation | amber | bezier up to the escalation row | "after N same-spot fails, go here" |

## The files lane (artifact contract)

The artifact wiring is shown two ways: the reads/writes lists painted in each node
(always visible), and, on a flow edge, the file that carries the hand-off. A flow
edge is **configured** when the target reads a file some upstream node writes; an
edge with no such artifact is **unconfigured** and gets the exclamation mark.

## The exclamation contract (protection, drawn in red)

A red `!` badge appears where something is wired but not yet complete, the design-time
guard rails:
- a flow edge whose target reads nothing any upstream node writes (unconfigured
  hand-off);
- a review/test node that can kick back but whose kickback has no target;
- a tag fork whose pipeline has no tagger stage (nothing sets the tag).

The badge is a warning, not a block; the daemon escalates at run time if something
slips through. It mirrors the same checks `deveryman_validate_template` runs on save.

## Layout

Auto-placed from the graph: x by forward depth (longest path from the head along flow
edges, ignoring kickback/escalation), y by chain (main line on the baseline, the
escalation chain a row above, tag branches on their own rows between the fork and the
merge). Gates trail after the merge. Nodes stay draggable; layout is just the initial
placement.

## Editing + save (built)

The builder is editable:
- **Forward flow** is wired by dragging between the in/out ports (native litegraph
  links). Fan-in (several branches into one merge) is handled by auto-growing input
  slots, so a node can receive many flow edges.
- **Add node** from the palette (any agent type); **delete** the selected node.
- The **properties panel** edits the selected node: id, chain (main/escalation), the
  tag it is reached by (branch guard, from the vocabulary), the tagger flag, kickback
  target + doc, escalation target + threshold, and per-pipeline extra instructions.
  Kickback/escalation/tag are node properties drawn as overlays, not hand-wired.
- The **pipeline panel** edits name, branches, backlog, deployment note, autonomy.
- **Node positions are saved** (`pos` on each template node) so a layout persists.

**Save** serialises the graph to the `pipeline_template` JSON and POSTs it to
`pipeline-save.php`, which re-runs `deveryman_validate_template` server-side and only
then writes it via `deveryman_save_pipeline_template`. The canvas never writes the
daemon config directly; it only produces the template we already compile, and the
server is the authority (a bad graph is rejected with the same errors the form editor
shows). Verified end to end: a crafted save round-trips (positions + kickback), and an
invalid graph and a missing CSRF token are both rejected.
