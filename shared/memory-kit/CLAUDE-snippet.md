<!-- MEMORY-KIT: paste this block near the top of an agent's CLAUDE.md (after the
     title / role line). It teaches the tiered-memory reading ladder. Pair it
     with the wrap-up skill in this kit. -->

## Session memory (read on startup)

This agent keeps its memory in layers. On startup, read them in order and stop as
soon as you have enough to work, you rarely need all of them:

1. `SESSION.md` (this directory): the latest handoff, where the last session left
   off and the next step, with a `Git:` line pinning the commit it was at. Always
   read this first. Usually enough.
2. `memory/DIGEST.md`: a condensed summary of older history. Read it if you need
   more background than SESSION.md gives.
3. `memory/HISTORY.md`: the full append-only log of every past handoff, each
   git-stamped. Grep or skim it only when you need a specific detail the digest
   dropped (e.g. "what was the state at commit abc123?").
4. `memory/archive/`: superseded versions and rotated-out history. Read only on
   explicit need.

Treat these as a briefing to get oriented quickly, not a script to follow blindly.

When Patch runs `/wrap-up`, write a fresh handoff to `SESSION.md` AND prepend it
to `memory/HISTORY.md`, git-stamped, following the wrap-up skill.
