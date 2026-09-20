# Action plan: responding to the first external review

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

## Needs Patch's input (not code)

- **The origin-story number.** The reviewer asked what actually triggered the
  "I'm not efficient at all" realization, "was there a number that surprised you?"
  If there is a real number, it would sharpen the README origin story. Patch to
  provide.

## Proposed order

1. **E1** convergence (foundational cleanup; makes the framework the real base).
2. **A5 + A4 + A3 + A6** the documentation quick-wins that surface things that
   already exist (thresholds, memory discipline, when-to-use, registries).
3. **B1** package the memory-kit as liftable.
4. **A1 + A2** the full install + usage guide (the biggest doc piece).
5. **C1** the credentials/setup screen.
6. **D1 + D2** the "more at a glance" pass (ongoing).
