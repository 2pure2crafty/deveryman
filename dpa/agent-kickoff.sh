#!/bin/bash
# Kick off a freshly-spawned pipeline stage agent. Order matters: send the work
# message FIRST (when Claude is idle and ready, where we can see it land), then
# pair Remote Control LAST. Remote Control is only so Patch can watch; it can
# hang briefly while connecting and gives no completion signal, so it must not
# sit between us and getting the kickoff submitted. Run backgrounded by
# underseer's start_agent.

SESSION="$1"
MSG="$2"
[ -z "$SESSION" ] && { echo "usage: agent-kickoff.sh <session> <message>" >&2; exit 1; }

# Wait (bounded) for Claude to finish starting up and be idle at its prompt.
# "for agents" shows in the idle status bar in every permission mode (incl. auto).
for _ in $(seq 1 90); do
    tmux capture-pane -t "$SESSION" -p | grep -q "for agents" && break
    sleep 1
done

# Send the kickoff and submit (text, pause, Enter, redundant Enter).
sleep 1
tmux send-keys -t "$SESSION" "$MSG"
sleep 1
tmux send-keys -t "$SESSION" Enter
sleep 3
tmux send-keys -t "$SESSION" Enter

# Now pair Remote Control (last), so Patch can watch. May hang briefly; the work
# message is already in, so it doesn't matter.
sleep 2
tmux send-keys -t "$SESSION" "/remote-control" Enter
