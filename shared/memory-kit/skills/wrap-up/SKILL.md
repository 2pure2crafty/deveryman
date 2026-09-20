---
name: wrap-up
description: Write a session handoff before ending the session (SESSION.md + append to memory/HISTORY.md, git-stamped)
disable-model-invocation: true
---

Wrapping up writes the handoff to two places: the latest-only `SESSION.md`, and
an append-only log at `memory/HISTORY.md`. Do both, and stamp both with the git
state so the history lines up with the repo's commit history.

## 1. Capture the git state

If this directory is inside a git repo, capture (you have bash):

- current branch: `git rev-parse --abbrev-ref HEAD`
- current commit: `git rev-parse --short HEAD`
- commits made this session: `git log --oneline` since the previous handoff's
  commit if you can find it in SESSION.md (look for the last "Git:" line), else
  the last few commits.

You will include this as a `Git:` line in the handoff. If this is not a git repo,
skip the git line.

## 2. Write SESSION.md (overwrite)

Write (overwrite, don't append) `SESSION.md` in the project root: a handoff note
for whoever opens the next session here, written so they can pick up cold with no
other context.

Include, only where it applies:

- What was being worked on and why
- Decisions made this session and the reasoning behind them (the "why" is what
  saves the next session from re-litigating it)
- Current state of any in-progress work: what's done, what's half-done, what's
  untouched
- Open questions or anything blocked on the operator
- The concrete next step, stated plainly
- A `Git:` line: `Git: <branch> @ <short-sha> (this session: <sha list or "no commits">)`

Keep it tight: a few short paragraphs or a bulleted list, not a transcript.
Overwrite the whole file each time; SESSION.md reflects only the most recent
handoff.

## 3. Append to memory/HISTORY.md (never overwrite)

Then prepend this same handoff to `memory/HISTORY.md` as a new dated entry, so the
project keeps a lossless, git-correlated record of every session. Create
`memory/` and the file if they don't exist.

- **Prepend** the new entry at the TOP of the file (newest first), under a
  heading like `## <YYYY-MM-DD HH:MM> - <one-line title>`, and include the same
  `Git:` line so any past state can be found by its sha.
- Never edit or delete existing entries. This log is append-only and is the
  source of truth that condensed summaries (`memory/DIGEST.md`) are derived from.

Do not touch `memory/DIGEST.md` or `memory/archive/` here; those are maintained
separately (by the daemon) from this log.

## 4. Close out

After writing both, note that the session is ready to close.
