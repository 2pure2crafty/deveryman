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
function deveryman_scaffold_conductor_agent(string $container, string $name, string $label): bool {
    $adir = $container . '/conductor/' . $name;
    if (!@mkdir($adir . '/.claude/skills/wrap-up', 0775, true) && !is_dir($adir)) return false;
    $tmpl = __DIR__ . '/../conductor/agent-templates/' . $name . '/CLAUDE.md';
    $claude = is_file($tmpl) ? (string) file_get_contents($tmpl) : ('# ' . ucfirst($name) . " agent\n");
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
function deveryman_apply_template(string $slug, string $label, string $templateId, bool $createRepo): array {
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
        [$e, , $er] = fw_run_cmd(['python3', $underseer, $configPath, '--instantiate'], null, '', 120);
        if ($e !== 0) { deveryman_rollback_container($container, $root); return ['ok' => false, 'errors' => ['materialize (--instantiate) failed: ' . trim($er)], 'warnings' => $warnings]; }
        $caps['dpa'] = ['config' => $configPath];
    }

    // 4. Conductor capability: scaffold agents + write the Conductor registry.
    if ($tpl['conductor'] && !empty($tpl['conductor_agents'])) {
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
