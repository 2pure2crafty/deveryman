<?php
declare(strict_types=1);
/*
 * Launcher lib: shared helpers for the D'everyman front door, including the
 * server-control (System) page. The launcher doubles as a thin GUI over the
 * host: it can restart D'everyman's own services and reboot the box, all through
 * a tightly scoped sudoers allowlist (see dpa/deveryman-dpa.sudoers.example).
 *
 * Assumes the shared framework is already required by the including page.
 */

/**
 * The D'everyman core services this GUI may control. The keys are the systemd
 * unit base names; ONLY these may be restarted, and each is matched literally
 * against the sudoers allowlist, so a forged service name goes nowhere.
 */
function deveryman_core_services(): array {
    return [
        'deveryman-launcher' => 'Launcher (this app)',
        'conductor'          => 'Conductor dashboard',
        'conductor-daemon'   => 'Conductor daemon (auto-wrap-down)',
        'dpa-dashboard'      => 'DPA dashboard',
    ];
}

/** ['active'=>bool, 'enabled'=>bool, 'active_raw'=>string, 'enabled_raw'=>string] for a unit. */
function service_state(string $unit): array {
    [, $a] = fw_run_cmd(['systemctl', 'is-active', $unit]);
    [, $e] = fw_run_cmd(['systemctl', 'is-enabled', $unit]);
    $a = trim($a); $e = trim($e);
    return ['active' => $a === 'active', 'enabled' => $e === 'enabled', 'active_raw' => $a, 'enabled_raw' => $e];
}

/** Registered DPA projects (slug => label) from the shared registry. */
function deveryman_dpa_instances(): array {
    $path = __DIR__ . '/../projects.json';
    if (!is_file($path)) return [];
    $reg = json_decode((string) file_get_contents($path), true) ?: [];
    $out = [];
    foreach (($reg['projects'] ?? []) as $slug => $p) {
        if (!empty($p['capabilities']['dpa'])) $out[$slug] = $p['label'] ?? $slug;
    }
    return $out;
}

/**
 * Cheap host vitals read straight from procfs / PHP builtins (no shell, no sudo):
 * uptime, load average, memory, and disk for the D'everyman root.
 */
function host_vitals(): array {
    $v = ['uptime' => '', 'load' => '', 'mem' => '', 'disk' => ''];

    $up = @file_get_contents('/proc/uptime');
    if ($up !== false) {
        $secs = (int) (float) explode(' ', trim($up))[0];
        $d = intdiv($secs, 86400); $h = intdiv($secs % 86400, 3600); $m = intdiv($secs % 3600, 60);
        $v['uptime'] = ($d ? "{$d}d " : '') . "{$h}h {$m}m";
    }

    $la = @sys_getloadavg();
    if (is_array($la)) $v['load'] = implode('  ', array_map(fn($x) => number_format($x, 2), $la));

    $mem = @file_get_contents('/proc/meminfo');
    if ($mem !== false && preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemAvailable:\s+(\d+)/', $mem, $a)) {
        $totalKb = (int) $t[1]; $availKb = (int) $a[1]; $usedKb = $totalKb - $availKb;
        $v['mem'] = fw_gb($usedKb * 1024) . ' / ' . fw_gb($totalKb * 1024) . ' used';
    }

    $root = '/';
    $free = @disk_free_space($root); $total = @disk_total_space($root);
    if ($free !== false && $total !== false && $total > 0) {
        $v['disk'] = fw_gb($total - $free) . ' / ' . fw_gb($total) . ' used ('
            . round(($total - $free) / $total * 100) . '%)';
    }
    return $v;
}

/** Bytes -> compact GiB/MiB string. */
function fw_gb(float $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
    return round($bytes / 1048576) . 'M';
}

/* --- new project + template applier ---------------------------------------- */

require_once __DIR__ . '/templates.php';

/** Lowercase slug, [a-z0-9-] only, non-empty; null if it cannot produce a safe slug. */
function deveryman_slugify(string $s): ?string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return ($s !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $s)) ? $s : null;
}

/** Is a slug already registered in a projects.json-shaped registry file? */
function deveryman_slug_registered(string $path, string $slug): bool {
    if (!is_file($path)) return false;
    $d = json_decode((string) @file_get_contents($path), true) ?: [];
    return isset($d['projects'][$slug]);
}

/** Recursive delete, fenced to inside $root (rollback safety). */
function deveryman_rrmdir(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        (is_dir($p) && !is_link($p)) ? deveryman_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** Roll back a half-created container, only if it really resolves inside $root. */
function deveryman_rollback_container(string $container, string $root): void {
    $rc = realpath($container); $rr = realpath($root);
    if ($rc === false || $rr === false) return;
    if ($rc !== $rr && str_starts_with($rc, $rr . DIRECTORY_SEPARATOR)) deveryman_rrmdir($rc);
}

/** Remove a conductor-registry entry (used when a later step fails). */
function deveryman_unregister(string $path, string $slug): void {
    if (!is_file($path)) return;
    fw_update_json($path, function (array $reg) use ($slug): array {
        unset($reg['projects'][$slug]);
        return $reg;
    });
}

/**
 * Scaffold one Conductor agent workspace under the project container:
 * <container>/conductor/<name>/ with CLAUDE.md (from conductor/agent-templates),
 * PROJECT.md, the wrap-up skill, and a settings.json. Mirrors conductor's own
 * scaffold_new_agent shape. Returns true on success.
 */
function deveryman_scaffold_conductor_agent(string $container, string $name, string $label, ?string $templateName = null, string $instructions = ''): bool {
    $adir = $container . '/conductor/' . $name;
    if (!@mkdir($adir . '/.claude/skills/wrap-up', 0775, true) && !is_dir($adir)) return false;
    $tmpl = __DIR__ . '/../conductor/agent-templates/' . ($templateName ?? $name) . '/CLAUDE.md';
    $claude = is_file($tmpl) ? (string) file_get_contents($tmpl) : ('# ' . ucfirst($name) . " agent\n");
    if (trim($instructions) !== '') $claude .= "\n## Initial task\n\n" . trim($instructions) . "\n";
    if (@file_put_contents($adir . '/CLAUDE.md', $claude) === false) return false;
    @file_put_contents($adir . '/PROJECT.md', "# Project: {$label}\n\nThis agent works on the {$label} project.\n");
    $skSrc = __DIR__ . '/../shared/memory-kit/skills/wrap-up/SKILL.md';
    if (is_file($skSrc)) @copy($skSrc, $adir . '/.claude/skills/wrap-up/SKILL.md');
    $settings = ['permissions' => ['allow' => [
        "Read({$container}/**)", "Write({$adir}/**)",
        'Bash(git *)', 'Bash(ls *)', 'Bash(cat *)', 'Bash(grep *)', 'Bash(find *)',
        'Bash(head *)', 'Bash(tail *)', 'Bash(mkdir *)',
    ], 'deny' => []]];
    return @file_put_contents($adir . '/.claude/settings.json',
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
}

/**
 * Stand up a brand-new project from a template: create the container + a new git
 * repo (git init, seed commit so branches exist, staging + main, optional GitHub
 * repo), apply the template (DPA project.json + materialized workspace, and/or
 * scaffolded Conductor agents), and register it in BOTH registries. NEW projects
 * only; importing an existing repo is a separate flow.
 *
 * Returns ['ok'=>bool, 'errors'=>string[], 'warnings'=>string[]]. On any failure
 * the half-created container and any partial registration are rolled back.
 */
function deveryman_apply_template(string $slug, string $label, string $templateId, bool $createRepo, bool $scaffoldConductor = true): array {
    $warnings = [];
    $tpl = deveryman_template($templateId);
    if ($tpl === null) return ['ok' => false, 'errors' => ["Unknown template: {$templateId}"], 'warnings' => []];

    $root = rtrim(fw_config_get('DEVERYMAN_PROJECTS_DIR', '/var/www/dpa-projects'), '/');
    $container = $root . '/' . $slug;
    $repoDir = $container . '/repo';
    $projectsPath = __DIR__ . '/../projects.json';
    $condReg = __DIR__ . '/../conductor/registry.json';
    $underseer = __DIR__ . '/../dpa/underseer.py';

    // Idempotency: reject if the container or either registry already has the slug.
    if (is_dir($container)) return ['ok' => false, 'errors' => ["A project already exists at {$container}."], 'warnings' => []];
    if (deveryman_slug_registered($projectsPath, $slug)) return ['ok' => false, 'errors' => ["Slug '{$slug}' is already in projects.json."], 'warnings' => []];
    if (deveryman_slug_registered($condReg, $slug)) return ['ok' => false, 'errors' => ["Slug '{$slug}' is already in the Conductor registry."], 'warnings' => []];

    // 1. Container + subdirs.
    if (!@mkdir($repoDir, 0775, true)) return ['ok' => false, 'errors' => ["Could not create {$repoDir}."], 'warnings' => []];
    @mkdir($container . '/pipeline', 0775, true);
    @mkdir($container . '/conductor', 0775, true);

    // 2. New repo: git init -b main, a seed commit (inline identity) so branches
    //    exist, then staging from main. Optional GitHub repo (soft-fail).
    [$e, , $er] = fw_run_cmd(['git', '-C', $repoDir, 'init', '-q', '-b', 'main']);
    if ($e !== 0) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['git init failed: ' . trim($er)], 'warnings' => []]; }
    @file_put_contents($repoDir . '/README.md', "# {$label}\n");
    fw_run_cmd(['git', '-C', $repoDir, 'add', '-A']);
    [$e, , $er] = fw_run_cmd(['git', '-C', $repoDir,
        '-c', 'user.email=deveryman@localhost', '-c', 'user.name=deveryman',
        'commit', '-q', '-m', 'Initial commit']);
    if ($e !== 0) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['initial commit failed: ' . trim($er)], 'warnings' => []]; }
    [$e, , $er] = fw_run_cmd(['git', '-C', $repoDir, 'branch', 'staging']);
    if ($e !== 0) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['could not create staging branch: ' . trim($er)], 'warnings' => []]; }
    if ($createRepo) {
        [$e, , $er] = fw_run_cmd(['gh', 'repo', 'create', $slug, '--private', '--source=.', '--remote=origin', '--push'], $repoDir, '', 120);
        if ($e !== 0) $warnings[] = 'GitHub repo not created (gh: ' . trim($er) . '). The local repo is fine; add a remote later.';
    }

    // 3. DPA capability: write project.json at the container root + materialize.
    $caps = [];
    if ($tpl['conductor']) $caps['conductor'] = true;
    if (!empty($tpl['dpa'])) {
        $projectJson = array_merge([
            'name' => $slug, 'label' => $label,
            'repo_root' => $repoDir, 'pipeline_root' => $container . '/pipeline',
            'tmux_prefix' => strtoupper(substr(preg_replace('/[^a-z0-9]/', '', $slug), 0, 6)) ?: 'DPA',
            'git_user' => null,
            'context' => [
                'project_summary' => $label, 'tech_stack' => '',
                'conventions' => 'Small, self-contained changes.',
                'user_types' => '', 'design_notes' => '',
            ],
        ], $tpl['dpa']);
        $configPath = $container . '/project.json';
        if (!fw_write_json_atomic($configPath, $projectJson)) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['could not write project.json'], 'warnings' => $warnings]; }
        // Materialize any user-authored roles this template uses into the per-project
        // roles/ override dir the daemon reads, BEFORE --instantiate copies them in.
        $rawTpl = deveryman_pipeline_template($templateId);
        if ($rawTpl !== null) deveryman_write_custom_roles($rawTpl, $container . '/pipeline');
        [$e, , $er] = fw_run_cmd(['python3', $underseer, $configPath, '--instantiate'], null, '', 120);
        if ($e !== 0) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['materialize (--instantiate) failed: ' . trim($er)], 'warnings' => $warnings]; }
        $caps['dpa'] = ['config' => $configPath];
    }

    // 4. Conductor capability: scaffold agents + write the Conductor registry.
    // Skipped when a graduating one-shot agent will be moved in as the agent.
    if ($scaffoldConductor && $tpl['conductor'] && !empty($tpl['conductor_agents'])) {
        $prefix = fw_config_get('CONDUCTOR_TMUX_PREFIX', 'DEV');
        $agents = [];
        foreach ($tpl['conductor_agents'] as $an) {
            if (!deveryman_scaffold_conductor_agent($container, $an, $label)) {
                deveryman_rollback_container($container, $root);
                return ['ok' => false, 'errors' => ["could not scaffold Conductor agent '{$an}'"], 'warnings' => $warnings];
            }
            $agents[$an] = [
                'label' => ucfirst($an), 'path' => 'conductor/' . $an,
                'tmux' => $prefix . '-' . $slug . '-' . $an,
                'model' => 'sonnet', 'permission_mode' => 'acceptEdits', 'auto_wrapdown' => false,
            ];
        }
        $ok = fw_update_json($condReg, function (array $reg) use ($slug, $label, $container, $agents): array {
            $reg['projects'] ??= [];
            $reg['projects'][$slug] = ['label' => $label, 'path' => $container, 'repo' => null,
                'description' => $label, 'agents' => $agents];
            return $reg;
        });
        if (!$ok) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['could not write the Conductor registry'], 'warnings' => $warnings]; }
    }

    // 5. Register in the shared projects.json.
    $ok = fw_update_json($projectsPath, function (array $reg) use ($slug, $label, $container, $caps): array {
        $reg['projects'] ??= [];
        $reg['projects'][$slug] = ['label' => $label, 'path' => $container, 'capabilities' => $caps];
        return $reg;
    });
    if (!$ok) {
        deveryman_unregister($condReg, $slug);
        deveryman_rollback_container($container, $root);
        return ['ok' => false, 'errors' => ['could not register in projects.json'], 'warnings' => $warnings];
    }

    return ['ok' => true, 'errors' => [], 'warnings' => $warnings];
}

/* --- project hub: git status, repo resolution, session-handoff peek --------- */
/* All read-only, built on fw_run_cmd (argv, no shell). Never throw; benign empty
 * values on a non-repo / missing dir / no upstream. */

/** True if $repo is a directory that looks like a git work tree. */
function deveryman_is_repo(string $repo): bool {
    if ($repo === '' || !is_dir($repo)) return false;
    [$e, $out] = fw_run_cmd(['git', '-C', $repo, 'rev-parse', '--is-inside-work-tree']);
    return $e === 0 && trim($out) === 'true';
}

/** Current branch name, or '' . */
function deveryman_git_branch(string $repo): string {
    if (!deveryman_is_repo($repo)) return '';
    [$e, $out] = fw_run_cmd(['git', '-C', $repo, 'rev-parse', '--abbrev-ref', 'HEAD']);
    return $e === 0 ? trim($out) : '';
}

/** ['upstream'=>bool,'ahead'=>int,'behind'=>int] vs the tracking branch. */
function deveryman_git_ahead_behind(string $repo): array {
    $out = ['upstream' => false, 'ahead' => 0, 'behind' => 0];
    if (!deveryman_is_repo($repo)) return $out;
    [$e] = fw_run_cmd(['git', '-C', $repo, 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
    if ($e !== 0) return $out;
    $out['upstream'] = true;
    [$ea, $a] = fw_run_cmd(['git', '-C', $repo, 'rev-list', '--count', '@{u}..HEAD']);
    [$eb, $b] = fw_run_cmd(['git', '-C', $repo, 'rev-list', '--count', 'HEAD..@{u}']);
    if ($ea === 0) $out['ahead'] = (int) trim($a);
    if ($eb === 0) $out['behind'] = (int) trim($b);
    return $out;
}

/** ['hash','subject','when','author'] for the last commit, or []. */
function deveryman_git_last_commit(string $repo): array {
    if (!deveryman_is_repo($repo)) return [];
    [$e, $out] = fw_run_cmd(['git', '-C', $repo, 'log', '-1', '--format=%h%x1f%s%x1f%cr%x1f%an']);
    if ($e !== 0 || trim($out) === '') return [];
    $p = explode("\x1f", trim($out));
    return ['hash' => $p[0] ?? '', 'subject' => $p[1] ?? '', 'when' => $p[2] ?? '', 'author' => $p[3] ?? ''];
}

/** origin remote URL, or '' . */
function deveryman_git_remote_url(string $repo): string {
    if (!deveryman_is_repo($repo)) return '';
    [$e, $out] = fw_run_cmd(['git', '-C', $repo, 'remote', 'get-url', 'origin']);
    return $e === 0 ? trim($out) : '';
}

/**
 * Resolve a project's repo working tree: DPA repo_root, else the Conductor
 * registry path, else the shared projects.json path, else ''.
 */
function deveryman_repo_root(string $slug, array $sharedProj, ?array $dpaCfg, ?array $condProj): string {
    if ($dpaCfg && !empty($dpaCfg['repo_root'])) return (string) $dpaCfg['repo_root'];
    if ($condProj && !empty($condProj['path'])) return (string) $condProj['path'];
    return (string) ($sharedProj['path'] ?? '');
}

/** A clickable https GitHub URL: explicit `repo` field first, else the git remote, normalized. */
function deveryman_github_url(string $repo, ?array $dpaCfg, ?array $condProj): string {
    $url = '';
    if ($condProj && !empty($condProj['repo'])) $url = (string) $condProj['repo'];
    elseif ($dpaCfg && !empty($dpaCfg['repo_url'])) $url = (string) $dpaCfg['repo_url'];
    else $url = deveryman_git_remote_url($repo);
    if ($url === '') return '';
    // Normalize git@github.com:owner/name(.git) -> https://github.com/owner/name
    if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?$#', $url, $m)) return 'https://' . $m[1] . '/' . $m[2];
    return preg_replace('#\.git$#', '', $url);
}

/**
 * Newest SESSION.md among the given candidate paths, with a peek of its first N
 * lines. Returns ['path','mtime','peek'] or [].
 */
function deveryman_latest_session_handoff(array $paths, int $lines = 40): array {
    $newest = null; $nmtime = -1;
    foreach ($paths as $p) {
        if (!is_file($p)) continue;
        $mt = filemtime($p);
        if ($mt !== false && $mt > $nmtime) { $nmtime = $mt; $newest = $p; }
    }
    if ($newest === null) return [];
    $head = array_slice(@file($newest, FILE_IGNORE_NEW_LINES) ?: [], 0, $lines);
    return ['path' => $newest, 'mtime' => $nmtime, 'peek' => implode("\n", $head)];
}

/* --- one-shot (project-less) agents + graduate ------------------------------ */

/** Root dir for one-shot agents (a deveryman-owned bucket, not CONDUCTOR_BASE_DIR). */
function deveryman_oneshot_root(): string {
    return rtrim(fw_config_get('DEVERYMAN_PROJECTS_DIR', '/var/www/dpa-projects'), '/') . '/oneshot';
}

/** The reserved Conductor-registry slug that holds project-less agents. */
function deveryman_oneshot_slug(): string {
    return 'oneshot';
}

/** Recursive copy of a directory tree (used to carry an agent's memory over). */
function deveryman_copy_dir(string $src, string $dst): void {
    @mkdir($dst, 0775, true);
    foreach (scandir($src) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $s = $src . '/' . $f; $d = $dst . '/' . $f;
        (is_dir($s) && !is_link($s)) ? deveryman_copy_dir($s, $d) : @copy($s, $d);
    }
}

/**
 * Create a one-shot (project-less) agent from an agent template: scaffold its
 * workspace under the one-shot bucket (with the initial prompt baked into its
 * CLAUDE.md as an initial task) and register it in conductor/registry.json under
 * the reserved `oneshot` project. Returns ['ok'=>bool,'slug'=>string,'errors'=>[]].
 * The caller spins it up.
 */
function deveryman_oneshot_create(string $label, string $template, string $model, string $instructions): array {
    $slug = deveryman_slugify($label);
    if ($slug === null) return ['ok' => false, 'slug' => '', 'errors' => ['Name did not produce a valid slug.']];
    if (!is_file(__DIR__ . '/../conductor/agent-templates/' . $template . '/CLAUDE.md')) {
        return ['ok' => false, 'slug' => '', 'errors' => ["Unknown agent template '{$template}'."]];
    }
    $root = deveryman_oneshot_root();
    @mkdir($root, 0775, true);
    $condReg = __DIR__ . '/../conductor/registry.json';
    $os = deveryman_oneshot_slug();

    $reg = is_file($condReg) ? (json_decode((string) @file_get_contents($condReg), true) ?: []) : [];
    if (isset($reg['projects'][$os]['agents'][$slug])) {
        return ['ok' => false, 'slug' => '', 'errors' => ["A one-shot agent '{$slug}' already exists."]];
    }
    if (!deveryman_scaffold_conductor_agent($root, $slug, $label, $template, $instructions)) {
        return ['ok' => false, 'slug' => '', 'errors' => ['Could not scaffold the agent workspace.']];
    }
    $prefix = fw_config_get('CONDUCTOR_TMUX_PREFIX', 'DEV');
    $ok = fw_update_json($condReg, function (array $r) use ($slug, $label, $template, $model, $prefix, $root, $os): array {
        $r['projects'] ??= [];
        $bucket = $r['projects'][$os] ?? ['label' => 'One-shot agents', 'repo' => null, 'description' => 'Project-less agents.', 'agents' => []];
        $bucket['path'] = $root;
        $bucket['agents'][$slug] = [
            'label' => $label, 'path' => 'conductor/' . $slug,
            'tmux' => $prefix . '-oneshot-' . $slug,
            'model' => $model, 'permission_mode' => 'acceptEdits',
            'auto_wrapdown' => false, 'template' => $template,
        ];
        $r['projects'][$os] = $bucket;
        return $r;
    });
    if (!$ok) {
        deveryman_rrmdir($root . '/conductor/' . $slug);
        return ['ok' => false, 'slug' => '', 'errors' => ['Could not write the Conductor registry.']];
    }
    return ['ok' => true, 'slug' => $slug, 'errors' => []];
}

/**
 * Graduate a one-shot agent into a new project: stand the project up from a
 * template (WITHOUT a fresh conductor agent), move the one-shot agent in as the
 * project's agent (carrying its SESSION.md + memory over), and remove it from the
 * one-shot bucket. The caller must have wrapped the agent down first. Returns
 * ['ok'=>bool,'errors'=>[],'warnings'=>[],'project'=>string].
 */
function deveryman_graduate(string $agentSlug, string $projLabel, string $templateId): array {
    $condReg = __DIR__ . '/../conductor/registry.json';
    $os = deveryman_oneshot_slug();
    $reg = is_file($condReg) ? (json_decode((string) @file_get_contents($condReg), true) ?: []) : [];
    $agent = $reg['projects'][$os]['agents'][$agentSlug] ?? null;
    if ($agent === null) return ['ok' => false, 'errors' => ['No such one-shot agent.'], 'warnings' => [], 'project' => ''];
    $projSlug = deveryman_slugify($projLabel);
    if ($projSlug === null) return ['ok' => false, 'errors' => ['Project name did not produce a valid slug.'], 'warnings' => [], 'project' => ''];

    // Stand up the project, skipping the template's own conductor-agent scaffold.
    $res = deveryman_apply_template($projSlug, $projLabel, $templateId, false, false);
    if (!$res['ok']) return ['ok' => false, 'errors' => $res['errors'], 'warnings' => $res['warnings'], 'project' => ''];

    $root = rtrim(fw_config_get('DEVERYMAN_PROJECTS_DIR', '/var/www/dpa-projects'), '/');
    $container = $root . '/' . $projSlug;
    $newAgent = $agent['template'] ?? $agentSlug;   // an ideas one-shot becomes the project's 'ideas' agent

    if (!deveryman_scaffold_conductor_agent($container, $newAgent, $projLabel, $newAgent)) {
        deveryman_rollback_container($container, $root);
        return ['ok' => false, 'errors' => ['Could not scaffold the graduated agent.'], 'warnings' => [], 'project' => ''];
    }
    $src = deveryman_oneshot_root() . '/conductor/' . $agentSlug;
    $dst = $container . '/conductor/' . $newAgent;
    if (is_file($src . '/SESSION.md')) @copy($src . '/SESSION.md', $dst . '/SESSION.md');
    if (is_dir($src . '/memory')) deveryman_copy_dir($src . '/memory', $dst . '/memory');

    $prefix = fw_config_get('CONDUCTOR_TMUX_PREFIX', 'DEV');
    fw_update_json($condReg, function (array $r) use ($projSlug, $projLabel, $container, $newAgent, $agent, $prefix, $os, $agentSlug): array {
        $r['projects'][$projSlug] = [
            'label' => $projLabel, 'path' => $container, 'repo' => null, 'description' => $projLabel,
            'agents' => [$newAgent => [
                'label' => ucfirst($newAgent), 'path' => 'conductor/' . $newAgent,
                'tmux' => $prefix . '-' . $projSlug . '-' . $newAgent,
                'model' => $agent['model'] ?? 'sonnet', 'permission_mode' => $agent['permission_mode'] ?? 'acceptEdits',
                'auto_wrapdown' => false,
            ]],
        ];
        unset($r['projects'][$os]['agents'][$agentSlug]);
        return $r;
    });
    deveryman_rrmdir($src);
    return ['ok' => true, 'errors' => [], 'warnings' => $res['warnings'], 'project' => $projSlug];
}
