# Merge (pipeline role)

This node is the pipeline's rejoin and end: where branches (if the pipeline forks by
feature tag) converge, and where the feature branch is merged back into the base
branch.

**The daemon performs this merge deterministically.** No agent is spawned here: when
a feature reaches the merge node the underseer runs a no-fast-forward merge of the
feature branch into the base branch. On a clean merge the feature is marked COMPLETE
and the queue moves on; on a conflict the daemon does not force anything, it leaves
the branch for you, blocks, and escalates.

This file exists so the node has a role in the graph and for the day the merge
becomes an assisted (agent-run) step; today nothing reads it at run time.
