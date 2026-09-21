# Vendored: litegraph.js

The pipeline builder canvas is drawn with **litegraph.js** (Javi Agenjo), a
dependency-free, canvas-based node-graph library, the engine ComfyUI is built on.

- **License:** MIT (see `LICENSE` in this dir). Permissive: free to use, modify, and
  ship in a commercial product; the only obligation is to keep the licence notice,
  which is why `LICENSE` lives here. Not copyleft, so it puts no obligation on our
  own code.
- **Source:** https://github.com/jagenjo/litegraph.js (fetched via
  `https://cdn.jsdelivr.net/npm/litegraph.js/`).
- **Files:** `litegraph.js` (the library) + `litegraph.css` (its base styles). Both
  are served from our own server; nothing is loaded from a CDN at run time, so the
  builder works offline over Tailscale.

## Why it is pinned + how we update

These are a **frozen copy** of one release. A new upstream release cannot reach or
break us, because we never fetch it; we serve this copy. Updating is a deliberate,
reviewed swap: drop in the new files, re-run the canvas against DPA standard (and the
branching example), confirm it still renders and saves our template JSON, then commit.

## Isolation

Nothing in the app calls litegraph directly except our adapter,
`launcher/public/js/pipeline-canvas.js`. If we ever update or swap the library, that
one file absorbs the change. The canvas only ever emits our pipeline-template JSON,
which the server re-validates (`deveryman_validate_template`) and compiles, so the
daemon and the stored config never trust the frontend.
