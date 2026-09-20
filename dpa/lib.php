<?php
declare(strict_types=1);
/*
 * DPA dashboard lib: per-project, read-only view over each project's pipeline
 * state, on the D'everyman shared framework. Projects come from the shared
 * projects.json; each DPA project points at its own project.json (paths,
 * pipeline_root), so the dashboard reads that project's own .pipeline/docs.
 */

define('DEVERYMAN_APP', 'DPA');
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../shared/framework/framework.php';

function dpa_projects_json(): string {
    return __DIR__ . '/../projects.json';
}

/** All projects from the shared registry. */
function dpa_all_projects(): array {
    $p = dpa_projects_json();
    if (!is_file($p)) return [];
    $d = json_decode((string) file_get_contents($p), true);
    return $d['projects'] ?? [];
}

/** Projects that have a DPA capability, keyed by slug. */
function dpa_projects(): array {
    $out = [];
    foreach (dpa_all_projects() as $slug => $proj) {
        if (!empty($proj['capabilities']['dpa'])) $out[$slug] = $proj;
    }
    return $out;
}

/** Load a DPA project's config (its project.json), or null. */
function dpa_config(array $proj): ?array {
    $path = $proj['capabilities']['dpa']['config'] ?? '';
    if ($path === '' || !is_file($path)) return null;
    return json_decode((string) file_get_contents($path), true) ?: null;
}

/** The docs dir for a DPA project (pipeline_root/docs). */
function dpa_docs_dir(array $cfg): string {
    $root = $cfg['pipeline_root'] ?? rtrim($cfg['repo_root'] ?? '', '/') . '/.pipeline';
    return rtrim($root, '/') . '/docs';
}

function dpa_read_doc(string $docs, string $name): string {
    $f = $docs . '/' . $name;
    return is_file($f) ? (string) file_get_contents($f) : '';
}

/** Recent lines of a project's underseer log. */
function dpa_log_tail(array $cfg, int $n = 30): string {
    $root = $cfg['pipeline_root'] ?? rtrim($cfg['repo_root'] ?? '', '/') . '/.pipeline';
    $f = rtrim($root, '/') . '/underseer.log';
    if (!is_file($f)) return '';
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return implode("\n", array_slice($lines, -$n));
}

/** Is the per-project pipeline daemon running (systemd instance)? */
function dpa_daemon_active(string $slug): bool {
    [, $out] = fw_run_cmd(['systemctl', 'is-active', "dpa-underseer@{$slug}.service"]);
    return trim($out) === 'active';
}
