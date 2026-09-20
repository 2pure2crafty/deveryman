# Action plan: responding to the first external review

> **Status: all themes A-F actioned and pushed.** Remaining under themes are
> ongoing/noted (dashboard token totals with a cache, richer summaries). The one
> item needing Patch is the origin-story number, now answered and in the README.


A reviewer (playing "the cousin who asked how Patch is so economical with tokens",
the person this was built to be shared with) reviewed the public repo a few
commits back. Most points still stand. This maps each to an action.

A recurring theme: several of the sharpest points are things already built but not
surfaced. Those are documentation/packaging wins, not new engineering.

## Theme A: Documentation (the biggest gap)

The review's strongest signal: no install or usage docs, so a stranger can't run
it or even tell what's machine-specific.

- **A1. Real installation guide, fresh-VPS assumption.** A top-level `INSTALL.md`
  (and ideally an `install.sh`) that assumes D'everyman is the first thing on a
  clean VPS: prerequisites (php 8.x, tmux, claude CLI, git, gh, python3,
  tailscale), clone, per-app setup (conductor, dpa, launcher), config files,
  systemd services, and private exposure. Call out explicitly what is
  machine-specific (Tailscale host, `127.0.0.1` ports) vs. portable.
  (Addresses: no-install-docs, Tailscale-is-machine-specific, fresh-VPS,
  download-and-set-it-up.)
- **A2. Usage guide.** Once running: the actual flows (spin up an agent, wrap it
  down, run a DPA cycle, read the aggregate). A `USAGE.md` or README section.
- **A3. "Conductor vs DPA: when to use which."** README says what they are, not
  when to reach for each. Add a short decision paragraph: Conductor = you drive a
  manual agent; DPA = an automated multi-stage build pipeline.
- **A4. Elevate the memory discipline as the centerpiece.** The reviewer called it
  "the most interesting and portable part". Make `shared/memory-kit` unmissable:
  a strong README on the four parts (latest-state SESSION.md, append-only HISTORY,
  condensed DIGEST, wrap-down-before-cache-expiry) and why it saves tokens.
- **A5. Document the concrete thresholds (already exist).** The
  wrap-down-before-cache-expiry rule HAS numbers: `CONDUCTOR_IDLE_TIMEOUT` (240s)
  AND `CONDUCTOR_WRAPDOWN_MIN_CONTEXT` (~100k tokens), gated on the 5-minute cache
  TTL. Surface this in the memory-kit docs so it reads as a concrete rule, not a
  vibe. (Addresses: "is there a concrete number".)
- **A6. Clarify onboarding + registries.** Document the access model (self-host
  your own instance vs. log into Patch's) and that `projects.json` is
  human-edited today (the launcher reads it, does not manage it), with UI
  management as a future item. (Addresses: onboarding-path, projects.json
  human-edited-or-managed.)

## Theme B: Package the memory-kit as a portable standalone

The reviewer: "if it's portable on its own, that might be worth pulling out as its
own thing." It already is just files.

- **B1. Make the memory-kit self-installing into any Claude Code agent.** A small
  `memory-kit/install.sh <agent-dir>` that drops in the wrap-up skill and prints
  the CLAUDE.md snippet, plus docs framing it as usable in ANY agent, not just
  ones inside this launcher. Decide later whether it graduates to its own repo;
  for now make it cleanly liftable.

## Theme C: Credentials / first-run setup screen (new feature)

- **C1. A setup screen framed as "AI credentials".** Early in setup, capture
  credentials generically (not Claude-specific): Claude as the first/only option
  now, with a visible "more AI models and services: planned" line. Ties into the
  onboarding path (A6) and the install flow (A1). Modest new UI on the framework.

## Theme D: More useful info at a glance (ongoing direction)

The reviewer's general steer: every layer of the dashboard should surface more at
a glance. Concrete first steps:

- **D1. Dashboard-level token totals** (deferred earlier for performance): add
  them with a cache so the launcher/Conductor dashboards show spend per project
  without reading every transcript on each load.
- **D2. Richer per-layer summaries**: e.g. the launcher showing each project's
  live agent count + DPA daemon state inline; Conductor's dashboard showing more
  status. Treat as an ongoing pass, not a one-off.

## Theme E: Confirmed engineering cleanup

- **E1. Converge Conductor onto the shared framework.** Confirmed with Patch.
  Fold Conductor's duplicated helpers (auth, render, run_cmd, token reader) onto
  `shared/framework`, carefully, keeping the live service working.

## Theme F: Import a repo (new, from the user-journey discussion)

Self-hosting means a user arrives with a fresh VPS and their own projects. Once
they have `gh` + credentials, they should be able to bring a repo IN.

- **F1. Import-repo flow + bot.** A button that takes a git repo (URL, or one of
  the user's own via `gh`), and a deterministic import handler that clones it into
  D'everyman's projects structure, registers it in `projects.json`, and lets the
  user enable Conductor and/or DPA on it. A one-shot reasoning call can inspect
  the clone and pre-fill a DPA `project.json` context (tech stack, conventions).
  "Our own bot that makes sure it lands in the file structure correctly."

## Resolved from the user-journey discussion

- **Origin story:** the real trigger was a PERSISTENT agent that forces a full
  context re-read every time you come back to it. That inefficiency is exactly
  what the memory discipline fixes. Use this in the README (done in Theme A).
- **Self-host only.** It is never a hosted service others log into (private,
  locked down). The install story is: fresh VPS -> get the repo on it -> run ->
  enter AI credentials -> import your projects. Install docs target that, no
  "use my instance" path.
- **Docker:** a future consideration (Patch is asking the reviewer about it). Not
  built now; revisit as a packaging option later.

## Proposed order (revised: front-load the shareable/onboarding story, do the
## risky live refactor last)

1. **A5 + A4 + A3 + A6** documentation quick-wins (surface what already exists:
   thresholds, memory discipline, when-to-use, self-host/registry clarity).
2. **B1** package the memory-kit as liftable.
3. **A1 + A2** the full install + usage guide (the reviewer's loudest concern).
4. **C1** the credentials / first-run setup screen.
5. **F1** the import-repo flow + bot.
6. **E1** converge Conductor onto the shared framework (careful, live service; do
   it once the additive work is stable).
7. **D1 + D2** the "more at a glance" pass (ongoing).
