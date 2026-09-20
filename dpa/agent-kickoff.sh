#!/bin/bash
# Kick off a freshly-spawned pipeline stage agent: wait for Claude to init, pair
# Remote Control, then send the start message. Sends the message only once Remote
# Control reports active (otherwise the Enter gets swallowed mid-connect and the
# message sits unsent). Run backgrounded by underseer's start_agent.

SESSION="$1"
MSG="$2"
[ -z "$SESSION" ] && { echo "usage: agent-kickoff.sh <session> <message>" >&2; exit 1; }

sleep 60
tmux send-keys -t "$SESSION" "/remote-control" Enter

# Wait (bounded) for Remote Control to be active before sending.
for _ in $(seq 1 30); do
    tmux capture-pane -t "$SESSION" -p | grep -q "Remote Control active" && break
    sleep 2
done

sleep 2
tmux send-keys -t "$SESSION" "$MSG"
sleep 2
tmux send-keys -t "$SESSION" Enter
# Redundant submit in case the first Enter raced the UI (empty prompt is a no-op).
sleep 3
tmux send-keys -t "$SESSION" Enter
