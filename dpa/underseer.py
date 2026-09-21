#!/usr/bin/env python3
"""
D'everyman DPA underseer: a generic, config-driven build-pipeline daemon.

Unlike the bespoke pipeline this was generalized from, it reads a project.json
and runs the pipeline for ANY project. Nothing project-specific is baked in;
paths, tmux prefix, git settings, stage list, and per-agent context all come from
the config. See DESIGN.md.

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
        # Long-lived branches. Features are cut from base_branch and merged back
        # into it; release_branch is the human-gated promotion target. A
        # single-branch repo can set base_branch == release_branch.
        self.base_branch    = self.cfg.get("base_branch", "staging")
        self.release_branch = self.cfg.get("release_branch", "main")
        self.feature_prefix = self.cfg.get("feature_branch_prefix", "feature/")
        self.autonomy = int(self.cfg.get("autonomy_level", 3))
        self.poll     = int(self.cfg.get("poll_interval", 30))
        self.stages   = self.cfg["stages"]
        self.kickback = self.cfg.get("kickback_target", {})
        # Safeguard 6: per-feature kickback budget. Once a feature is kicked back this
        # many times it is quarantined and escalated, so a custom graph cannot loop
        # (e.g. dev <-> reviewer) forever.
        self.max_kickbacks = max(1, int(self.cfg.get("max_kickbacks", 2)))
        self.context  = self.cfg.get("context", {})
        # Front feeder: rough ideas land here; the product agent turns QUEUED rows
        # into build-queue features. Human-initiated (or auto only at top autonomy).
        self.backlog_file = self.cfg.get("backlog_file", "product-backlog.md")
        # Back gate: the project-provided deploy procedure (relative to docs) the
        # deploy agent follows, and an optional production label for verification.
        self.deployment_note = self.cfg.get("deployment_note", "dev-inbox/deployment-note.md")
        self.production_ref  = self.cfg.get("production_ref", "")
        # Per-stage I/O wiring (the compiled pipeline connections): a stage id maps
        # to {"reads": [...], "writes": [...]}, paths relative to the docs dir. The
        # daemon injects these to each agent via a read-only pipeline-instructions.md
        # overlay; the reusable agent CLAUDE.md is never edited.
        self.io = self.cfg.get("io", {})
        self.pipeline_version = str(self.cfg.get("pipeline_version", "1"))

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


def materialize_agent(p: "Project", name: str):
    """Materialize one agent workspace (CLAUDE.md role + PROJECT.md context +
    wrap-up skill + settings) from the generic template for `name`."""
    adir = p.agent_dir(name)
    adir.mkdir(parents=True, exist_ok=True)
    tmpl = AGENT_TEMPLATES / name / "CLAUDE.md"
    if tmpl.exists():
        shutil.copyfile(tmpl, adir / "CLAUDE.md")
    else:
        (adir / "CLAUDE.md").write_text(f"# {name.title()} agent\n\n(Generic template missing.)\n")
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
        ], "deny": [
            # The pipeline overlay is authoritative and underseer-owned: the agent
            # reads it but never rewrites its own instructions.
            f"Write({adir}/pipeline-instructions.md)",
        ]}
    }, indent=2))


def instantiate(p: "Project"):
    """Materialize the per-project pipeline workspace from the generic templates."""
    p.docs.mkdir(parents=True, exist_ok=True)
    (p.docs / "dev-inbox").mkdir(exist_ok=True)
    for stage in p.stages:
        materialize_agent(p, stage)
    # Helpers that are not pipeline stages: the product feeder and the back-end
    # deploy/verify agents. Materialize them if a template exists. (Brainstorming is
    # a Conductor agent, not a DPA one, so ideas is not materialized here.)
    for aux in ("product", "deploy", "testing-live"):
        if aux not in p.stages and (AGENT_TEMPLATES / aux / "CLAUDE.md").exists():
            materialize_agent(p, aux)
    if not (p.docs / p.backlog_file).exists():
        (p.docs / p.backlog_file).write_text(
            "# Product backlog\n\n"
            "Rough ideas land here (the ideas agent, or you). The product feeder turns\n"
            "QUEUED rows into build-queue features and marks them PROCESSED.\n\n"
            "| ID | Idea | Status | Source | Date |\n"
            "| -- | ---- | ------ | ------ | ---- |\n"
        )
    if not p.state_file.exists():
        p.state_file.write_text(
            "# Pipeline state\n\n"
            "**Current stage:** none\n"
            "**Stage status:** IDLE\n"
            "**Current feature:** none\n"
            "**Current feature id:** \n"
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


def read_backlog(p: "Project") -> list:
    """Parse the product backlog table (| ID | Idea | Status | Source | Date |)."""
    items = []
    f = p.docs / p.backlog_file
    if not f.exists():
        return items
    for line in f.read_text().splitlines():
        if not line.strip().startswith("|"):
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) < 3 or cells[0].lower() in ("id", "--", "---"):
            continue
        if set(cells[0]) <= set("- "):
            continue
        items.append({"id": cells[0], "idea": cells[1], "status": cells[2]})
    return items


def count_backlog_queued(p: "Project") -> int:
    return sum(1 for i in read_backlog(p) if i["status"].upper() == "QUEUED")


def signal(p: "Project", msg: str):
    """Write a non-blocking readiness notice to docs/signals.md. Unlike escalation,
    a signal never halts anything; it just surfaces "you could do X now" for the
    dashboard/operator. Deduped: the same message is written once until the
    operator clears the file."""
    sig = p.docs / "signals.md"
    existing = sig.read_text() if sig.exists() else ""
    if msg in existing:
        return
    stamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M")
    header = "" if existing else "# Signals (readiness prompts, non-blocking)\n\n"
    sig.write_text((existing.rstrip() + "\n" if existing else header) + f"- [{stamp}] {msg}\n")
    log(p, f"SIGNAL: {msg}")


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


def checkout_branch(p: "Project", branch: str, base: str | None = None) -> tuple[bool, str]:
    """Deterministically put the repo working tree on `branch`. If it does not
    exist, create it: from `base` when given (feature branches are cut from the
    base branch), otherwise from the current HEAD. The daemon owns branch isolation
    rather than trusting each agent to create the branch. Returns (ok, message)."""
    if _git(p, "rev-parse", "--verify", branch).returncode == 0:
        r = _git(p, "checkout", branch)
    elif base is not None:
        r = _git(p, "checkout", "-b", branch, base)
    else:
        r = _git(p, "checkout", "-b", branch)
    return (r.returncode == 0, (r.stderr or r.stdout).strip())


def _default_branch(p: "Project") -> str:
    """Best guess at the repo's default branch, used to seed base_branch."""
    r = _git(p, "symbolic-ref", "--quiet", "refs/remotes/origin/HEAD")
    if r.returncode == 0 and r.stdout.strip():
        return r.stdout.strip().rsplit("/", 1)[-1]
    for cand in ("main", "master"):
        if _git(p, "rev-parse", "--verify", cand).returncode == 0:
            return cand
    r = _git(p, "rev-parse", "--abbrev-ref", "HEAD")
    return r.stdout.strip() or "main"


def ensure_base_branch(p: "Project") -> tuple[bool, str]:
    """Make sure base_branch exists, creating it from the repo's default branch if
    missing (a ref only, no working-tree change). Features are always cut from a
    known base. Returns (ok, message)."""
    if _git(p, "rev-parse", "--verify", p.base_branch).returncode == 0:
        return (True, "exists")
    src = _default_branch(p)
    r = _git(p, "branch", p.base_branch, src)
    if r.returncode != 0:
        return (False, (r.stderr or r.stdout).strip())
    log(p, f"Created base branch '{p.base_branch}' from '{src}'")
    return (True, f"created from {src}")


def merge_feature_to_base(p: "Project", feature_branch: str) -> tuple[str, str]:
    """Merge a finished feature branch back into base_branch (no-ff), then delete
    it. On conflict, abort cleanly so base stays intact, and report so the caller
    can escalate; never force, never -X ours/theirs. Returns (status, message)
    where status is one of 'merged', 'conflict', 'error'. All local (no push)."""
    co = _git(p, "checkout", p.base_branch)
    if co.returncode != 0:
        return ("error", f"could not checkout {p.base_branch}: {(co.stderr or co.stdout).strip()}")
    m = _git(p, "merge", "--no-ff", "--no-edit", feature_branch)
    if m.returncode == 0:
        d = _git(p, "branch", "-d", feature_branch)
        note = "" if d.returncode == 0 else f" (branch not deleted: {(d.stderr or d.stdout).strip()})"
        return ("merged", f"merged {feature_branch} into {p.base_branch}{note}")
    # Merge failed: capture the conflicting files, then abort to keep base clean.
    files = _git(p, "diff", "--name-only", "--diff-filter=U").stdout.strip()
    _git(p, "merge", "--abort")
    if files:
        return ("conflict", f"merge conflict in: {files.replace(chr(10), ', ')}")
    return ("error", f"merge failed: {(m.stderr or m.stdout).strip()}")


def base_ahead_of_release(p: "Project") -> int:
    """How many commits base_branch is ahead of release_branch (0 if equal, either
    branch is missing, or nothing to promote). Read-only; local."""
    if p.base_branch == p.release_branch:
        return 0
    for b in (p.base_branch, p.release_branch):
        if _git(p, "rev-parse", "--verify", b).returncode != 0:
            return 0
    r = _git(p, "rev-list", "--count", f"{p.release_branch}..{p.base_branch}")
    try:
        return int(r.stdout.strip())
    except ValueError:
        return 0


# --------------------------------------------------------------------------- #
# Human-gated back-end operations (promote / deploy / verify)
#
# These are the deployment boundary. They are NEVER called from handle() at any
# autonomy level; the daemon only signals readiness. They run only when the
# operator invokes them (the dashboard's Gates buttons, or the CLI flags in
# main()). Each is one deliberate, human-triggered action.
# --------------------------------------------------------------------------- #

def promote(p: "Project") -> tuple[str, str]:
    """Human gate 1: merge base_branch into release_branch (no-ff). Conflict aborts
    cleanly and escalates; never force. Single-branch repo -> logged no-op."""
    if p.base_branch == p.release_branch:
        log(p, "Promote is a no-op (base_branch == release_branch)")
        return ("noop", "single-branch repo: base and release are the same")
    if _git(p, "rev-parse", "--verify", p.release_branch).returncode != 0:
        r = _git(p, "branch", p.release_branch, p.base_branch)
        if r.returncode != 0:
            return ("error", f"could not create {p.release_branch}: {(r.stderr or r.stdout).strip()}")
        log(p, f"Created release branch '{p.release_branch}' from '{p.base_branch}'")
        return ("promoted", f"created {p.release_branch} from {p.base_branch}")
    co = _git(p, "checkout", p.release_branch)
    if co.returncode != 0:
        return ("error", f"could not checkout {p.release_branch}: {(co.stderr or co.stdout).strip()}")
    m = _git(p, "merge", "--no-ff", "--no-edit", p.base_branch)
    if m.returncode == 0:
        log(p, f"Promoted {p.base_branch} into {p.release_branch}")
        return ("promoted", f"merged {p.base_branch} into {p.release_branch}")
    files = _git(p, "diff", "--name-only", "--diff-filter=U").stdout.strip()
    _git(p, "merge", "--abort")
    detail = f"merge conflict in: {files.replace(chr(10), ', ')}" if files else (m.stderr or m.stdout).strip()
    escalate(p, f"Could not promote {p.base_branch} into {p.release_branch}", detail)
    return ("conflict", detail)


def run_deploy_agent(p: "Project") -> tuple[str, str]:
    """Human gate 2: launch the deploy agent to ship release_branch to production,
    following the project's deployment note. Refuses (signals) if no note exists;
    the procedure is never guessed."""
    materialize_agent(p, "deploy")
    note = p.docs / p.deployment_note
    if not note.exists():
        signal(p, f"Deploy requested but no deployment procedure at {note}; add one first.")
        log(p, "Deploy refused: no deployment note")
        return ("refused", f"no deployment note at {note}")
    startnote = (
        f"You are the deploy agent, invoked by the operator. Read the deployment "
        f"procedure at {note} and follow it EXACTLY to deploy the {p.release_branch} "
        f"branch to production. Do not improvise. Snapshot before touching production, "
        f"write a deploy report, and report PASS or FAIL."
    )
    start_agent(p, "deploy", "(deploy to production)", p.release_branch, startnote)
    log(p, "Started deploy agent")
    return ("started", "deploy agent launched")


def run_testing_live_agent(p: "Project") -> tuple[str, str]:
    """Post-deploy verification: launch the live-testing agent against production.
    Reports PASS/FAIL, fixes nothing."""
    materialize_agent(p, "testing-live")
    target = p.production_ref or "the live environment"
    startnote = (
        f"You are the live-testing agent, invoked by the operator. Run live-safe "
        f"verification against production ({target}): acceptance smoke checks plus any "
        f"checkpoints from the deployment note at {p.docs / p.deployment_note}. Report "
        f"PASS or FAIL and classify any failure as a deployment problem or a code "
        f"problem. Fix nothing."
    )
    start_agent(p, "testing-live", "(verify production)", p.release_branch, startnote)
    log(p, "Started live-verification agent")
    return ("started", "live-testing agent launched")


def session_alive(p: "Project", stage: str) -> bool:
    return _tmux("has-session", "-t", p.tmux(stage)).returncode == 0


def _feature_slug(feature: str) -> str:
    return re.sub(r'[^a-z0-9]+', '-', feature.lower()).strip('-')[:40].strip('-')


def stage_io(p: "Project", stage: str, feature: str) -> tuple[list, list]:
    """Resolve a stage's declared file reads/writes to absolute paths under the docs
    dir, substituting the feature slug for `<slug>`. Non-file inputs (the branch,
    the queue item) are simply not declared, so they never appear here. Returns
    (reads, writes) as lists of Path."""
    slug = _feature_slug(feature)
    io = p.io.get(stage, {})
    resolve = lambda paths: [p.docs / str(x).replace("<slug>", slug) for x in paths]
    return resolve(io.get("reads", [])), resolve(io.get("writes", []))


def validate_wiring(p: "Project") -> list:
    """Safeguard 2: every declared stage input must be produced by an earlier stage
    (or be a pre-existing pipeline input). Returns a list of problems; empty means
    well-wired. Compares the raw declared paths (with the `<slug>` token intact), so
    a consumer's read must exactly match some earlier producer's write."""
    problems = []
    produced = set()
    prewired = {"product-backlog.md", "build-queue.md", p.backlog_file}
    for stage in p.stages:
        io = p.io.get(stage, {})
        for r in io.get("reads", []):
            if r in prewired or r in produced:
                continue
            problems.append(f"'{stage}' reads '{r}' which no earlier stage writes")
        for w in io.get("writes", []):
            produced.add(w)
    # Safeguard 7: every wiring path must stay inside the project's docs dir. A
    # declared read/write that escapes (via .. or an absolute path) is rejected, so
    # one project can never be wired to read or write another's files.
    docs_real = p.docs.resolve()
    for stage in p.stages:
        io = p.io.get(stage, {})
        for kind in ("reads", "writes"):
            for pth in io.get(kind, []):
                full = (p.docs / str(pth).replace("<slug>", "x")).resolve()
                if full != docs_real and docs_real not in full.parents:
                    problems.append(f"'{stage}' {kind[:-1]} path '{pth}' escapes the project directory")
    return problems


def missing_outputs(p: "Project", stage: str, feature: str) -> list:
    """Safeguard 4: declared output files this stage should have written but did not."""
    _, writes = stage_io(p, stage, feature)
    return [w for w in writes if not w.exists()]


def missing_inputs(p: "Project", stage: str, feature: str) -> list:
    """Safeguard 3: declared input files this stage needs that do not exist yet."""
    reads, _ = stage_io(p, stage, feature)
    return [r for r in reads if not r.exists()]


def pipeline_busy(state: dict) -> bool:
    """Safeguard 5: True if a feature is mid-flight, so a re-bake (config save) must
    wait. The save flow calls this and refuses to reconfigure a busy pipeline; the
    running feature also carries the pipeline version it started under."""
    return state.get("stage_status", "IDLE").upper() not in ("IDLE", "")


def write_pipeline_instructions(p: "Project", stage: str, feature: str):
    """Write the authoritative, read-only pipeline overlay into the agent's dir. It
    binds this stage's inputs/outputs/done-signal for this run and OVERRIDES any
    default file names in the reusable CLAUDE.md (which is never edited). Removes a
    stale overlay if the stage declares no wiring."""
    overlay = p.agent_dir(stage) / "pipeline-instructions.md"
    if stage not in p.io:
        if overlay.exists():
            overlay.unlink()
        return
    reads, writes = stage_io(p, stage, feature)
    kb = p.kickback.get(stage)
    lines = [
        "# Pipeline instructions (authoritative)", "",
        "While you run in this pipeline, this file OVERRIDES any default file names in",
        "your CLAUDE.md. Follow it for your inputs, your output(s), and how you signal done.",
        "", f"Feature: {feature}", f"Pipeline version: {p.pipeline_version}", "",
        "## Read (your inputs)",
    ]
    lines += [f"- {r}" for r in reads] or ["- (none as a file; your input is named in startup-context.md)"]
    lines += ["", "## Write (your output, exactly these paths)"]
    lines += [f"- {w}" for w in writes] or ["- (your work is code on the branch, not a doc file)"]
    lines += ["", "## Signal done", f"- update {p.state_file}: **Stage status:** COMPLETE"]
    if kb:
        lines.append(f"- if you cannot proceed: **Stage status:** KICKED BACK (routes to '{kb}')")
    lines += ["", f"Stay inside this project: only read or write under {p.repo} and {p.root}."]
    overlay.write_text("\n".join(lines) + "\n")


def write_startup_context(p: "Project", stage: str, feature: str, branch: str, note: str = ""):
    adir = p.agent_dir(stage)
    ctx = (
        f"# Startup Context\n\n"
        f"Agent stage: {stage}\n"
        f"Project: {p.label}\n"
        f"Feature: {feature}\n"
        f"Branch: {branch}\n"
        f"Repo: {p.repo}\n"
        f"Pipeline docs: {p.docs}\n\n"
        f"Read your CLAUDE.md (your role) and PROJECT.md (this project's context) first.\n"
        f"If `pipeline-instructions.md` exists in your directory, read it: it is\n"
        f"authoritative for your inputs, your output file(s), and how you signal done,\n"
        f"and it overrides any default file names in your CLAUDE.md.\n"
        f"Work on the repo at {p.repo} on branch {branch}.\n"
        f"When your stage is done, write your outputs and set the pipeline state:\n"
        f"  - update {p.state_file}: **Stage status:** COMPLETE (or KICKED BACK with a reason)\n"
        f"Then stop.\n"
    )
    if note:
        ctx += f"\n## Note\n\n{note}\n"
    (adir / "startup-context.md").write_text(ctx)


def start_agent(p: "Project", stage: str, feature: str, branch: str, note: str = ""):
    session = p.tmux(stage)
    adir = str(p.agent_dir(stage))
    write_startup_context(p, stage, feature, branch, note)
    write_pipeline_instructions(p, stage, feature)
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
    """Write a triaged escalation for the operator and block. The daemon runs a
    one-shot reasoning pass to summarize what happened and propose options, so what
    reaches the operator is already triaged (not a raw dump)."""
    inbox = p.docs / "dev-inbox"
    ctx = (f"Reason: {reason_str}\nDetail: {detail}\n\n"
           f"Pipeline state:\n{p.state_file.read_text() if p.state_file.exists() else ''}\n")
    if inbox.exists():
        for f in sorted(inbox.glob("*.md")):
            ctx += f"\n===== {f.name} =====\n{f.read_text()[:4000]}\n"
    triage = reason(
        "You are triaging a stuck automated build pipeline for a human operator. "
        "From the situation below, write a short escalation: (1) what happened, in "
        "one or two sentences; (2) the most likely cause; (3) two or three concrete "
        "options with a recommendation. Concise and practical. Markdown, no preamble.",
        ctx)
    body = triage or f"(Triage unavailable.)\n\nReason: {reason_str}\nDetail: {detail}"
    stamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M")
    esc = p.docs / "escalation.md"
    entry = f"## Escalation - {stamp}\n\n{body}\n\n**Status:** AWAITING OPERATOR\n\n---\n\n"
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
    branch = f"{p.feature_prefix}{item['id']}-{_feature_slug(feature)}"
    # Ensure the base branch exists, then cut the feature branch FROM it (not from
    # whatever happens to be checked out), so every feature starts from a known base.
    ok, msg = ensure_base_branch(p)
    if not ok:
        log(p, f"ERROR: could not ensure base branch {p.base_branch}: {msg}")
        escalate(p, f"Could not create base branch '{p.base_branch}'", msg)
        return
    ok, msg = checkout_branch(p, branch, base=p.base_branch)
    if not ok:
        log(p, f"ERROR: could not checkout branch {branch}: {msg}")
        escalate(p, f"Could not create feature branch for '{feature}'",
                 f"git checkout of {branch} from {p.base_branch} failed: {msg}")
        return
    update_queue_status(p, item["id"], "ACTIVE")
    first = p.stages[0]
    write_state(p, {
        "current_stage": first, "stage_status": "IN PROGRESS",
        "current_feature": feature, "current_feature_id": item["id"],
        "current_branch": branch,
        "current_pipeline_version": p.pipeline_version,
        "kick_back_count": "0", "waiting_for": "none",
    })
    log(p, f"Starting feature '{feature}' (id {item['id']}) at stage {first} on branch {branch} (from {p.base_branch})")
    start_agent(p, first, feature, branch)


def start_product_feeder(p: "Project"):
    """Run the product agent as a feeder: convert QUEUED backlog rows into
    build-queue features. It works on the pipeline docs, not a feature branch, so
    no checkout happens. It is tracked as current_stage 'product'; handle() treats
    its COMPLETE specially (feeder-done), not as end-of-pipeline."""
    n = count_backlog_queued(p)
    write_state(p, {
        "current_stage": "product", "stage_status": "IN PROGRESS",
        "current_feature": "(populate queue from backlog)", "current_feature_id": "",
        "current_branch": "none", "waiting_for": "none",
    })
    note = (
        f"You are running as the product feeder. Read {p.docs}/{p.backlog_file}, and for "
        f"each row with Status QUEUED, append a build-queue row to {p.queue_file} in the "
        f"format `| ID | Feature | QUEUED | none |` (increment the ID from the last queue "
        f"row), then set that backlog row's Status to PROCESSED. When done, set "
        f"**Stage status:** COMPLETE and stop."
    )
    start_agent(p, "product", "(populate queue from backlog)", "none", note)
    log(p, f"Started product feeder ({n} backlog item(s) queued)")


def handle(p: "Project", state: dict, items: list):
    stage  = state.get("current_stage", "none")
    status = state.get("stage_status", "IDLE").upper()
    feature = state.get("current_feature", "none")
    feature_id = state.get("current_feature_id", "")
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

    # Safeguard 5: if the config was re-baked to a new pipeline_version while this
    # feature is mid-flight, warn. The feature keeps its own wiring; do not re-bake
    # a busy pipeline (the save flow enforces this via pipeline_busy()).
    ver = state.get("current_pipeline_version", "")
    if ver and ver != p.pipeline_version and stage not in ("none", "product"):
        signal(p, f"pipeline_version is now {p.pipeline_version} but feature '{feature}' "
                  f"started on version {ver}; avoid re-baking while work is in flight.")

    if status in ("IDLE", "") or stage == "none":
        # Safeguard 2: never start work on a mis-wired pipeline. Signal and hold.
        problems = validate_wiring(p)
        if problems:
            signal(p, "Pipeline wiring is invalid: " + "; ".join(problems[:3])
                      + ". Fix the config and restart; no features will start.")
            return
        nxt = next_queued(items)
        if nxt:
            start_feature(p, nxt)
            return
        # Nothing runnable in the queue. Consider the front feeder: if the backlog
        # has QUEUED items, run product at the top autonomy level, otherwise just
        # signal that it could be run. Idea-gen itself is never auto-started.
        if count_backlog_queued(p) > 0:
            if p.autonomy >= 4:
                start_product_feeder(p)
                return
            signal(p, f"Backlog has {count_backlog_queued(p)} queued item(s); "
                      f"run the product feeder to turn them into build-queue features.")
        # Back gate readiness: if base is ahead of release, the operator can promote
        # and deploy. Signal only; the daemon NEVER promotes or deploys itself, at
        # any autonomy level.
        ahead = base_ahead_of_release(p)
        if ahead > 0:
            signal(p, f"{p.base_branch} is ahead of {p.release_branch} by {ahead} "
                      f"commit(s); ready to promote and deploy (human-gated).")
        return

    if status == "IN PROGRESS":
        return  # agent is working; nothing to do

    # The product feeder is tracked as a stage but is not part of the pipeline;
    # its COMPLETE means "queue refreshed", not "feature finished".
    if status == "COMPLETE" and stage == "product":
        kill_agent(p, "product")
        write_state(p, {"current_stage": "none", "stage_status": "IDLE",
                        "current_feature": "none", "current_feature_id": "",
                        "current_branch": "none", "waiting_for": "none"})
        log(p, "Product feeder finished; queue refreshed")
        return

    if status == "COMPLETE":
        # Safeguard 4: a stage cannot "complete" without producing its declared
        # output. Catch a broken hand-off here rather than passing a missing file on.
        miss_out = missing_outputs(p, stage, feature)
        if miss_out:
            mark_active_feature("BLOCKED")
            escalate(p, f"Stage '{stage}' reported COMPLETE but did not write its output",
                     ", ".join(str(m) for m in miss_out))
            write_state(p, {"stage_status": "BLOCKED", "waiting_for": "OPERATOR"})
            log(p, f"Post-condition failed for {stage}: missing {[str(m) for m in miss_out]}")
            return
        nxt = next_stage(p, stage)
        if nxt is None:
            # End of pipeline: merge the feature back into the base branch, then
            # the queue continues on a fresh branch cut from the updated base.
            kill_agent(p, stage)
            status_m, msg = merge_feature_to_base(p, branch)
            if status_m == "merged":
                mark_active_feature("COMPLETE")
                log(p, f"Feature '{feature}' complete; {msg}.")
                write_state(p, {"current_stage": "none", "stage_status": "IDLE",
                                "current_feature": "none", "current_feature_id": "",
                                "current_branch": "none", "waiting_for": "none"})
            else:
                # Conflict or error: do not advance. Leave the branch for the human,
                # mark the row BLOCKED, halt on OPERATOR, and escalate.
                mark_active_feature("BLOCKED")
                escalate(p, f"Could not merge '{feature}' into {p.base_branch}", msg)
                write_state(p, {"stage_status": "BLOCKED", "waiting_for": "OPERATOR"})
                log(p, f"Feature '{feature}' merge {status_m}: {msg}")
            return
        if p.autonomy >= 3:
            # Safeguard 3: do not advance into a stage whose declared inputs are
            # missing (e.g. the producer's file was deleted or never landed).
            miss_in = missing_inputs(p, nxt, feature)
            if miss_in:
                mark_active_feature("BLOCKED")
                escalate(p, f"Cannot start '{nxt}': a required input is missing",
                         ", ".join(str(m) for m in miss_in))
                write_state(p, {"stage_status": "BLOCKED", "waiting_for": "OPERATOR"})
                log(p, f"Pre-condition failed for {nxt}: missing {[str(m) for m in miss_in]}")
                return
            kill_agent(p, stage)
            write_state(p, {"current_stage": nxt, "stage_status": "IN PROGRESS"})
            start_agent(p, nxt, feature, branch)
            log(p, f"Advanced {stage} -> {nxt} for '{feature}'")
        else:
            write_state(p, {"stage_status": "BLOCKED", "waiting_for": "OPERATOR"})
            log(p, f"Stage {stage} complete; autonomy {p.autonomy} < 3, waiting for the operator")
        return

    if status in ("KICKED BACK", "KICKBACK"):
        count = int(state.get("kick_back_count", "0") or "0") + 1
        kill_agent(p, stage)
        if count >= p.max_kickbacks:
            # Kickback budget spent: stop looping. Quarantine the feature and escalate
            # to the operator with a triaged summary (the one-shot judgment step).
            mark_active_feature("QUARANTINED")
            escalate(p, f"Feature '{feature}' hit the kickback budget at {stage}",
                     f"Kicked back {count} times (budget {p.max_kickbacks}); quarantined pending your call.")
            write_state(p, {"current_stage": "none", "stage_status": "BLOCKED",
                            "current_feature": "none", "current_feature_id": "",
                            "kick_back_count": str(count), "waiting_for": "OPERATOR"})
            return
        target = p.kickback.get(stage, p.stages[0])
        note = f"Kicked back from {stage}. See the feedback in {p.docs}/dev-inbox/."
        write_state(p, {"current_stage": target, "stage_status": "IN PROGRESS",
                        "kick_back_count": str(count)})
        start_agent(p, target, feature, branch, note)
        log(p, f"Kicked back {stage} -> {target} (count {count}) for '{feature}'")
        return


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #

OPERATOR_ACTIONS = {
    "--promote":     lambda p: promote(p),
    "--deploy":      lambda p: run_deploy_agent(p),
    "--verify":      lambda p: run_testing_live_agent(p),
    "--run-product": lambda p: start_product_feeder(p),
    "--instantiate": lambda p: ("ok", "instantiated"),
}


def main():
    if len(sys.argv) < 2:
        print("usage: underseer.py /path/to/project.json "
              "[--promote|--deploy|--verify|--run-product|--instantiate]", file=sys.stderr)
        sys.exit(1)
    try:
        p = Project(sys.argv[1])
    except (ValueError, json.JSONDecodeError, FileNotFoundError) as e:
        # A clear one-line diagnostic in the journal beats a bare traceback in a
        # systemd restart loop. Exit non-zero; the config must be fixed first.
        print(f"FATAL: cannot load project config: {e}", file=sys.stderr)
        sys.exit(2)
    instantiate(p)

    # One-shot, human-triggered operator actions (the back-end gates and the feeder).
    # These run once and exit; they are never part of the autonomous loop.
    action = sys.argv[2] if len(sys.argv) > 2 else None
    if action is not None:
        if action not in OPERATOR_ACTIONS:
            print(f"unknown action: {action}", file=sys.stderr)
            sys.exit(1)
        result = OPERATOR_ACTIONS[action](p)
        print(json.dumps(result) if result is not None else "ok")
        return

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
