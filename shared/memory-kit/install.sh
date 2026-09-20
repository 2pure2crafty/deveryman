#!/bin/bash
# Install the memory kit into any Claude Code agent directory. The kit is just
# files, so this works in ANY agent, inside D'everyman or not.
#
#   ./install.sh /path/to/agent-dir
#
# It copies the wrap-up skill into <agent-dir>/.claude/skills/wrap-up/ and inserts
# the tiered-memory reading ladder near the top of <agent-dir>/CLAUDE.md (creating
# CLAUDE.md if absent). Idempotent: safe to re-run.

set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
DIR="$1"
[ -z "$DIR" ] && { echo "usage: install.sh <agent-dir>" >&2; exit 1; }
[ -d "$DIR" ] || { echo "no such directory: $DIR" >&2; exit 1; }

# 1. the wrap-up skill
mkdir -p "$DIR/.claude/skills"
cp -r "$HERE/skills/wrap-up" "$DIR/.claude/skills/"
echo "installed wrap-up skill -> $DIR/.claude/skills/wrap-up/"

# 2. the reading-ladder snippet into CLAUDE.md
CLAUDE="$DIR/CLAUDE.md"
[ -f "$CLAUDE" ] || printf '# %s\n' "$(basename "$DIR")" > "$CLAUDE"
if grep -q "Session memory (read on startup)" "$CLAUDE"; then
    echo "reading ladder already present in $CLAUDE"
else
    SNIPPET="$(sed -n '/-->/,$p' "$HERE/CLAUDE-snippet.md" | sed '1d')"
    python3 - "$CLAUDE" "$HERE/CLAUDE-snippet.md" <<'PY'
import sys
claude, snippet = sys.argv[1], sys.argv[2]
lines = open(claude).read().split("\n")
snip = open(snippet).read().strip()
snip = snip.split("-->", 1)[1].strip() if "-->" in snip else snip
out, inserted = [], False
for l in lines:
    out.append(l)
    if not inserted and l.strip():
        out.append(""); out.append(snip); inserted = True
open(claude, "w").write("\n".join(out))
PY
    echo "inserted reading ladder -> $CLAUDE"
fi

echo "done. Ensure the agent's .claude/settings.json (or permission mode) allows"
echo "writing SESSION.md and memory/** so /wrap-up can run without prompting."
