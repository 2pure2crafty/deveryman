<?php
declare(strict_types=1);
/*
 * DPA dashboard lib: per-project, read-only view over each project's pipeline
 * state, on the D'everyman shared framework. Projects come from the shared
 * projects.json; each DPA project points at its own project.json (paths,
 * pipeline_root), so the dashboard reads that project's own .pipeline/docs.
 */

// Guard both so this lib can be required alongside conductor/lib.php from the
// launcher (project-first hub) without a fatal re-declare or an app-name clash.
if (!defined('DEVERYMAN_APP'))    define('DEVERYMAN_APP', 'DPA');
if (!defined('DEVERYMAN_CONFIG')) define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require_once __DIR__ . '/../shared/framework/framework.php';

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

/** Count build-queue rows by status (QUEUED / ACTIVE / COMPLETE / BLOCKED / ...). */
function dpa_queue_counts(array $cfg): array {
    $f = dpa_docs_dir($cfg) . '/build-queue.md';
    $counts = [];
    if (!is_file($f)) return $counts;
    foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '|') continue;
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (count($cells) < 4) continue;
        if (strtolower($cells[0]) === 'id' || preg_match('/^-+$/', $cells[0])) continue;
        $status = strtoupper($cells[2]);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return $counts;
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

/* --- human-gate controls (promote / deploy / verify / feeder) --------------- */

/** Absolute path to the daemon script, invoked for the operator gate actions. */
function dpa_underseer_path(): string {
    return __DIR__ . '/underseer.py';
}

/** The gate actions the dashboard may trigger, mapped to the daemon CLI flag. */
function dpa_gate_actions(): array {
    return [
        'promote'     => '--promote',
        'deploy'      => '--deploy',
        'verify'      => '--verify',
        'run-product' => '--run-product',
    ];
}

/** CSRF token for this session (Basic-auth friendly: uses a PHP session cookie). */
function dpa_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

/** Validate the CSRF token on a state-changing POST. */
function dpa_csrf_ok(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/** Hidden CSRF input for a form. */
function dpa_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . fw_h(dpa_csrf_token()) . '">';
}

/** How many commits base_branch is ahead of release_branch (readiness for promote). */
function dpa_base_ahead(array $cfg): int {
    $repo = $cfg['repo_root'] ?? '';
    if ($repo === '') return 0;
    $base = $cfg['base_branch'] ?? 'staging';
    $rel  = $cfg['release_branch'] ?? 'main';
    if ($base === $rel) return 0;
    [$e, $out] = fw_run_cmd(['git', '-C', $repo, 'rev-list', '--count', "{$rel}..{$base}"]);
    return $e === 0 ? (int) trim($out) : 0;
}

/** How many backlog rows are QUEUED (readiness for the product feeder). */
function dpa_backlog_queued(array $cfg): int {
    $docs = dpa_docs_dir($cfg);
    $bf = $cfg['backlog_file'] ?? 'product-backlog.md';
    $f = $docs . '/' . $bf;
    if (!is_file($f)) return 0;
    $n = 0;
    foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '|') continue;
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if (count($cells) < 3) continue;
        if (strtolower($cells[0]) === 'id' || preg_match('/^-+$/', $cells[0])) continue;
        if (strtoupper($cells[2]) === 'QUEUED') $n++;
    }
    return $n;
}

/** Append a gate action to the per-project audit trail. Best-effort. */
function dpa_gate_audit(array $cfg, string $user, string $action, string $result): void {
    $docs = dpa_docs_dir($cfg);
    if (!is_dir($docs)) @mkdir($docs, 0775, true);
    $line = date('Y-m-d H:i:s') . "\t" . $user . "\t" . $action . "\t"
          . str_replace(["\t", "\n"], ' ', $result) . "\n";
    @file_put_contents($docs . '/gate-audit.md', $line, FILE_APPEND);
}
