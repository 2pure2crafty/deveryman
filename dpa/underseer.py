#!/usr/bin/env python3
"""
D'everyman DPA underseer: a generic, config-driven build-pipeline daemon.

Unlike the HDS-specific original, this reads a project.json and runs the pipeline
for ANY project. Nothing HDS is baked in; paths, tmux prefix, git settings, stage
list, and per-agent context all come from the config. See DESIGN.md.

Run:  python3 underseer.py /path/to/project.json
"""

import sys
import time
import json
import shutil
import hashlib
import datetime
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent
AGENT_TEMPLATES = HERE / "agents"   # generic role templates, one dir per stage


# --------------------------------------------------------------------------- #
# Project config + derived paths
# --------------------------------------------------------------------------- #

class Project:
    REQUIRED_KEYS = ("name", "repo_root", "stages")

    def __init__(self, config_path: str):
        self.config_path = Path(config_path).resolve()
        self.cfg = json.loads(self.config_path.read_text())
        # Fail loudly and clearly on a malformed config, rather than crash-looping
        # under systemd with a bare KeyError deep in construction. The dashboard
        # tolerates some of these keys as optional; the daemon genuinely needs them.
        missing = [k for k in self.REQUIRED_KEYS if k not in self.cfg]
        if missing:
            raise ValueError(
                f"{self.config_path}: project.json is missing required key(s): "
                f"{', '.join(missing)}"
            )
        self.name    = self.cfg["name"]
        self.label   = self.cfg.get("label", self.name)
        self.repo    = Path(self.cfg["repo_root"])
        self.root    = Path(self.cfg.get("pipeline_root", str(self.repo / ".pipeline")))
        self.prefix  = self.cfg.get("tmux_prefix", "DPA")
        self.git_user = self.cfg.get("git_user")  # None or a sudo user
        self.cycle_prefix   = self.cfg.get("cycle_branch_prefix", "autonomous/")
        self.feature_prefix = self.cfg.get("feature_branch_prefix", "feature/")
        self.autonomy = int(self.cfg.get("autonomy_level", 3))
        self.poll     = int(self.cfg.get("poll_interval", 30))
        self.stages   = self.cfg["stages"]
        self.kickback = self.cfg.get("kickback_target", {})
        self.context  = self.cfg.get("context", {})

    # derived paths
    @property
    def docs(self):          return self.root / "docs"
    @property
    def state_file(self):    return self.docs / "pipeline-state.md"
    @property
    def queue_file(self):    return self.docs / "build-queue.md"
    @property
    def log_file(self):      return self.root / "underseer.log"
    def agent_dir(self, stage):    return self.root / "agents" / stage
    def tmux(self, stage):          return f"{self.prefix}-{stage}"


def log(p: "Project", msg: str):
    ts = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line, flush=True)
    p.log_file.parent.mkdir(parents=True, exist_ok=True)
    with open(p.log_file, "a") as f:
        f.write(line + "\n")


# --------------------------------------------------------------------------- #
# Workspace instantiation (generic templates + per-project context)
# --------------------------------------------------------------------------- #

def project_md(p: "Project") -> str:
    c = p.context
    return (
        f"# Project context: {p.label}\n\n"
        f"This is per-project context for the pipeline. Your role is defined in\n"
        f"CLAUDE.md; this file tells you what project you are working on.\n\n"
        f"- **Project:** {p.label}\n"
        f"- **Repo:** {p.repo}\n"
        f"- **Summary:** {c.get('project_summary','')}\n"
        f"- **Tech stack:** {c.get('tech_stack','')}\n"
        f"- **Conventions:** {c.get('conventions','')}\n"
        f"- **User types:** {c.get('user_types','')}\n"
        f"- **Design notes:** {c.get('design_notes','')}\n"
    )


def instantiate(p: "Project"):
    """Materialize the per-project pipeline workspace from the generic templates."""
    p.docs.mkdir(parents=True, exist_ok=True)
    (p.docs / "dev-inbox").mkdir(exist_ok=True)
    for stage in p.stages:
        adir = p.agent_dir(stage)
        adir.mkdir(parents=True, exist_ok=True)
        tmpl = AGENT_TEMPLATES / stage / "CLAUDE.md"
        if tmpl.exists():
            shutil.copyfile(tmpl, adir / "CLAUDE.md")
        else:
            (adir / "CLAUDE.md").write_text(f"# {stage.title()} agent\n\n(Generic template missing.)\n")
        (adir / "PROJECT.md").write_text(project_md(p))
        # give each agent the memory-kit wrap-up skill + write permission
        skill_src = HERE / ".." / "shared" / "memory-kit" / "skills" / "wrap-up"
        skdir = adir / ".claude" / "skills"
        skdir.mkdir(parents=True, exist_ok=True)
        if (skill_src / "SKILL.md").exists():
            (skdir / "wrap-up").mkdir(exist_ok=True)
            shutil.copyfile(skill_src / "SKILL.md", skdir / "wrap-up" / "SKILL.md")
        settings = adir / ".claude" / "settings.json"
        settings.write_text(json.dumps({
            "permissions": {"allow": [
                f"Read({p.repo}/**)", f"Write({p.repo}/**)",
                f"Read({p.root}/**)", f"Write({p.root}/**)",
                "Bash(git *)", "Bash(ls *)", "Bash(cat *)", "Bash(grep *)",
                "Bash(find *)", "Bash(mkdir *)", "Bash(node *)", "Bash(npm *)",
            ], "deny": []}
        }, indent=2))
    if not p.state_file.exists():
        p.state_file.write_text(
            "# Pipeline state\n\n"
            "**Current stage:** none\n"
            "**Stage status:** IDLE\n"
            "**Current feature:** none\n"
            "**Current feature id:** \n"
            "**Current cycle:** none\n"
            "**Current branch:** none\n"
            "**Kick-back count:** 0\n"
            "**Waiting for:** none\n"
        )
    if not p.queue_file.exists():
        p.queue_file.write_text(
            "# Build queue\n\n"
            "| ID | Feature | Status | Depends-on |\n"
            "| -- | ------- | ------ | ---------- |\n"
        )


# --------------------------------------------------------------------------- #
# State + queue parsing (markdown)
# --------------------------------------------------------------------------- #

import re

def read_state(p: "Project") -> dict:
    state = {}
    if not p.state_file.exists():
        return state
    for line in p.state_file.read_text().splitlines():
        m = re.match(r'^\*\*(.+?):\*\*\s*(.*)', line)
        if m:
            state[m.group(1).strip().lower().replace(" ", "_").replace("-", "_")] = m.group(2).strip()
    return state


def write_state(p: "Project", updates: dict):
    lines = p.state_file.read_text().splitlines() if p.state_file.exists() else []
    keys = {k: v for k, v in updates.items()}
    out = []
    seen = set()
    for line in lines:
        m = re.match(r'^\*\*(.+?):\*\*', line)
        if m:
            key = m.group(1).strip().lower().replace(" ", "_").replace("-", "_")
            if key in keys:
                label = m.group(1).strip()
                out.append(f"**{label}:** {keys[key]}")
                seen.add(key)
                continue
        out.append(line)
    # Append any updates whose key was not already present in the file, otherwise a
    # brand-new state field (e.g. current_feature_id) would be silently dropped.
    for key, val in keys.items():
        if key not in seen:
            label = key.replace("_", " ").capitalize()
            out.append(f"**{label}:** {val}")
    p.state_file.write_text("\n".join(out) + "\n")


def read_queue(p: "Project") -> list:
    items = []
    if not p.queue_file.exists():
        return items
    for line in p.queue_file.read_text().splitlines():
        if not line.strip().startswith("|"):
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) < 4 or cells[0].lower() in ("id", "--", "---"):
            continue
        if set(cells[0]) <= set("- "):
            continue
        items.append({"id": cells[0], "feature": cells[1], "status": cells[2], "depends_on": cells[3]})
    return items


def update_queue_status(p: "Project", feature_id: str, status: str):
    lines = p.queue_file.read_text().splitlines()
    out = []
    for line in lines:
        if line.strip().startswith("|"):
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            if len(cells) >= 4 and cells[0] == feature_id:
                cells[2] = status
                out.append("| " + " | ".join(cells) + " |")
                continue
        out.append(line)
    p.queue_file.write_text("\n".join(out) + "\n")


def next_queued(items: list) -> dict | None:
    done = {i["id"] for i in items if i["status"] in ("COMPLETE",)}
    for it in items:
        if it["status"] != "QUEUED":
            continue
        dep = it["depends_on"].strip().lower()
        if dep in ("", "none") or all(d.strip() in done for d in dep.split(",")):
            return it
    return None


# --------------------------------------------------------------------------- #
# Agent lifecycle
# --------------------------------------------------------------------------- #

def _tmux(*args):
    return subprocess.run(["tmux", *args], capture_output=True, text=True)


def _git(p: "Project", *args):
    """Run a git command in the project repo, as git_user if configured."""
    base = ["git", "-C", str(p.repo), *args]
    if p.git_user:
        base = ["sudo", "-u", p.git_user, *base]
    return subprocess.run(base, capture_output=True, text=True)


def checkout_branch(p: "Project", branch: str) -> tuple[bool, str]:
    """Deterministically put the repo working tree on `branch`, creating it from
    the current HEAD if it does not exist. The daemon owns branch isolation rather
    than trusting each agent to create the branch itself; if this fails we do not
    start the feature on the wrong branch. Returns (ok, message)."""
    if _git(p, "rev-parse", "--verify", branch).returncode == 0:
        r = _git(p, "checkout", branch)
    else:
        r = _git(p, "checkout", "-b", branch)
    return (r.returncode == 0, (r.stderr or r.stdout).strip())


def session_alive(p: "Project", stage: str) -> bool:
    return _tmux("has-session", "-t", p.tmux(stage)).returncode == 0


def write_startup_context(p: "Project", stage: str, feature: str, cycle: str, branch: str, note: str = ""):
    adir = p.agent_dir(stage)
    ctx = (
        f"# Startup Context\n\n"
        f"Agent stage: {stage}\n"
        f"Project: {p.label}\n"
        f"Feature: {feature}\n"
        f"Cycle: {cycle}\n"
        f"Branch: {branch}\n"
        f"Repo: {p.repo}\n"
        f"Pipeline docs: {p.docs}\n\n"
        f"Read your CLAUDE.md (your role) and PROJECT.md (this project's context) first.\n"
        f"Work on the repo at {p.repo} on branch {branch}.\n"
        f"When your stage is done, write your outputs and set the pipeline state:\n"
        f"  - update {p.state_file}: **Stage status:** COMPLETE (or KICKED BACK with a reason)\n"
        f"Then stop.\n"
    )
    if note:
        ctx += f"\n## Note\n\n{note}\n"
    (adir / "startup-context.md").write_text(ctx)


def start_agent(p: "Project", stage: str, feature: str, cycle: str, branch: str, note: str = ""):
    session = p.tmux(stage)
    adir = str(p.agent_dir(stage))
    write_startup_context(p, stage, feature, cycle, branch, note)
    _tmux("kill-session", "-t", session)
    time.sleep(1)
    r = _tmux("new-session", "-d", "-s", session, "-c", adir)
    if r.returncode != 0:
        log(p, f"ERROR: could not start tmux session {session}: {r.stderr}")
        return False
    today = datetime.datetime.now().strftime("%Y-%m-%d")
    _tmux("rename-window", "-t", f"{session}:0", f"{session}-{today}")
    _tmux("send-keys", "-t", session, "claude --permission-mode auto", "Enter")
    # Background startup: wait for init + Remote Control active, then send the
    # kickoff (agent-kickoff.sh handles the timing so the Enter isn't swallowed).
    kickoff = str(HERE / "agent-kickoff.sh")
    msg = "Read startup-context.md, then your CLAUDE.md and PROJECT.md, and begin your stage."
    subprocess.Popen(["nohup", "bash", kickoff, session, msg],
                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    log(p, f"Started {stage} for feature '{feature}' (session {session})")
    return True


def _digest(path: Path) -> str | None:
    """Content fingerprint of a file, or None if it does not exist. Detecting a
    change by content (not just mtime) is immune to second-granularity clocks and
    same-second rewrites."""
    try:
        return hashlib.md5(path.read_bytes()).hexdigest()
    except FileNotFoundError:
        return None


def wrap_up_agent(p: "Project", stage: str, timeout: int = 90):
    if not session_alive(p, stage):
        return
    session_md = p.agent_dir(stage) / "SESSION.md"
    before = _digest(session_md)
    _tmux("send-keys", "-t", p.tmux(stage), "/wrap-up", "Enter")
    deadline = time.time() + timeout
    while time.time() < deadline:
        now = _digest(session_md)
        if now is not None and now != before:
            log(p, f"Wrap-up handoff written for {stage}")
            return
        time.sleep(2)
    log(p, f"Wrap-up for {stage} did not confirm within {timeout}s; killing anyway")


def kill_agent(p: "Project", stage: str, wrap_up: bool = True):
    if wrap_up:
        wrap_up_agent(p, stage)
    _tmux("kill-session", "-t", p.tmux(stage))
    log(p, f"Killed {stage}")


# --------------------------------------------------------------------------- #
# Reasoning + escalation (the deterministic supervisor, with one-shot judgment)
# --------------------------------------------------------------------------- #

def reason(prompt: str, stdin: str = "", timeout: int = 120) -> str | None:
    """One-shot headless reasoning (Haiku by default). Returns text or None.

    This is how the deterministic daemon does the little judgment the old
    always-on AI overseer used to do: spin up a cheap one-shot model only when
    something non-mechanical happens, instead of paying for an always-on agent.
    """
    model = p_reason_model()
    try:
        r = subprocess.run(["claude", "--model", model, "-p", prompt],
                           input=stdin, capture_output=True, text=True, timeout=timeout)
    except Exception:
        return None
    out = (r.stdout or "").strip()
    return out or None


def p_reason_model() -> str:
    return "haiku"


def escalate(p: "Project", reason_str: str, detail: str = ""):
    """Write a triaged escalation for Patch and block. The daemon runs a one-shot
    reasoning pass to summarize what happened and propose options, so what reaches
    Patch is already triaged (not a raw dump)."""
    inbox = p.docs / "dev-inbox"
    ctx = (f"Reason: {reason_str}\nDetail: {detail}\n\n"
           f"Pipeline state:\n{p.state_file.read_text() if p.state_file.exists() else ''}\n")
    if inbox.exists():
        for f in sorted(inbox.glob("*.md")):
            ctx += f"\n===== {f.name} =====\n{f.read_text()[:4000]}\n"
    triage = reason(
        "You are triaging a stuck automated build pipeline for a human (Patch). "
        "From the situation below, write a short escalation: (1) what happened, in "
        "one or two sentences; (2) the most likely cause; (3) two or three concrete "
        "options with a recommendation. Concise and practical. Markdown, no preamble.",
        ctx)
    body = triage or f"(Triage unavailable.)\n\nReason: {reason_str}\nDetail: {detail}"
    stamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M")
    esc = p.docs / "escalation.md"
    entry = f"## Escalation - {stamp}\n\n{body}\n\n**Status:** AWAITING PATCH\n\n---\n\n"
    prev = esc.read_text() if esc.exists() else ""
    esc.write_text(entry + prev)
    log(p, f"ESCALATED (triaged): {reason_str}")


# --------------------------------------------------------------------------- #
# State machine
# --------------------------------------------------------------------------- #

def next_stage(p: "Project", stage: str) -> str | None:
    if stage in p.stages:
        i = p.stages.index(stage)
        if i + 1 < len(p.stages):
            return p.stages[i + 1]
    return None


def start_feature(p: "Project", item: dict):
    feature = item["feature"]
    safe = re.sub(r'[^a-z0-9]+', '-', feature.lower()).strip('-')[:40].strip('-')
    cycle = "cycle-001"
    branch = f"{p.feature_prefix}{item['id']}-{safe}"
    # Create/switch to the feature branch ourselves before any agent runs, so work
    # is isolated deterministically. If it fails, escalate instead of proceeding on
    # whatever branch happens to be checked out.
    ok, msg = checkout_branch(p, branch)
    if not ok:
        log(p, f"ERROR: could not checkout branch {branch}: {msg}")
        escalate(p, f"Could not create feature branch for '{feature}'",
                 f"git checkout of {branch} failed: {msg}")
        return
    update_queue_status(p, item["id"], "ACTIVE")
    first = p.stages[0]
    write_state(p, {
        "current_stage": first, "stage_status": "IN PROGRESS",
        "current_feature": feature, "current_feature_id": item["id"],
        "current_cycle": cycle, "current_branch": branch,
        "kick_back_count": "0", "waiting_for": "none",
    })
    log(p, f"Starting feature '{feature}' (id {item['id']}) at stage {first} on branch {branch}")
    start_agent(p, first, feature, cycle, branch)


def handle(p: "Project", state: dict, items: list):
    stage  = state.get("current_stage", "none")
    status = state.get("stage_status", "IDLE").upper()
    feature = state.get("current_feature", "none")
    feature_id = state.get("current_feature_id", "")
    cycle   = state.get("current_cycle", "cycle-001")
    branch  = state.get("current_branch", "none")

    def mark_active_feature(new_status: str):
        """Update the queue row for the feature currently in progress. Prefer the
        id recorded in state; fall back to the sole ACTIVE row only if state predates
        the id being tracked. Using the recorded id avoids mislabelling the wrong
        row when more than one is somehow ACTIVE."""
        fid = feature_id
        if not fid:
            active = [i for i in items if i["status"] == "ACTIVE"]
            fid = active[0]["id"] if active else None
        if fid:
            update_queue_status(p, fid, new_status)

    if status in ("IDLE", "") or stage == "none":
        nxt = next_queued(items)
        if nxt:
            start_feature(p, nxt)
        return

    if status == "IN PROGRESS":
        return  # agent is working; nothing to do

    if status == "COMPLETE":
        nxt = next_stage(p, stage)
        if nxt is None:
            # end of pipeline for this feature
            mark_active_feature("COMPLETE")
            log(p, f"Feature '{feature}' complete (finished stage {stage}).")
            kill_agent(p, stage)
            write_state(p, {"current_stage": "none", "stage_status": "IDLE",
                            "current_feature": "none", "current_feature_id": "",
                            "waiting_for": "none"})
            return
        if p.autonomy >= 3:
            kill_agent(p, stage)
            write_state(p, {"current_stage": nxt, "stage_status": "IN PROGRESS"})
            start_agent(p, nxt, feature, cycle, branch)
            log(p, f"Advanced {stage} -> {nxt} for '{feature}'")
        else:
            write_state(p, {"stage_status": "BLOCKED", "waiting_for": "PATCH"})
            log(p, f"Stage {stage} complete; autonomy {p.autonomy} < 3, waiting for Patch")
        return

    if status in ("KICKED BACK", "KICKBACK"):
        count = int(state.get("kick_back_count", "0") or "0") + 1
        kill_agent(p, stage)
        if count >= 2:
            # Double kick-back: stop looping. Quarantine the feature and escalate
            # to Patch with a triaged summary (the one-shot judgment step).
            mark_active_feature("QUARANTINED")
            escalate(p, f"Feature '{feature}' double kicked-back at {stage}",
                     f"Kicked back {count} times; quarantined pending your call.")
            write_state(p, {"current_stage": "none", "stage_status": "BLOCKED",
                            "current_feature": "none", "current_feature_id": "",
                            "kick_back_count": str(count), "waiting_for": "PATCH"})
            return
        target = p.kickback.get(stage, p.stages[0])
        note = f"Kicked back from {stage}. See the feedback in {p.docs}/dev-inbox/."
        write_state(p, {"current_stage": target, "stage_status": "IN PROGRESS",
                        "kick_back_count": str(count)})
        start_agent(p, target, feature, cycle, branch, note)
        log(p, f"Kicked back {stage} -> {target} (count {count}) for '{feature}'")
        return


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #

def main():
    if len(sys.argv) < 2:
        print("usage: underseer.py /path/to/project.json", file=sys.stderr)
        sys.exit(1)
    try:
        p = Project(sys.argv[1])
    except (ValueError, json.JSONDecodeError, FileNotFoundError) as e:
        # A clear one-line diagnostic in the journal beats a bare traceback in a
        # systemd restart loop. Exit non-zero; the config must be fixed first.
        print(f"FATAL: cannot load project config: {e}", file=sys.stderr)
        sys.exit(2)
    instantiate(p)
    log(p, f"underseer starting for project '{p.name}' "
           f"(stages={p.stages}, autonomy={p.autonomy}, poll={p.poll}s)")
    while True:
        try:
            state = read_state(p)
            items = read_queue(p)
            handle(p, state, items)
        except Exception as e:
            log(p, f"ERROR {e}")
        time.sleep(p.poll)


if __name__ == "__main__":
    main()
