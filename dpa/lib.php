<?php
declare(strict_types=1);
/*
 * DPA dashboard lib: read-only view over the pipeline's state surface, on the
 * Everyman shared framework. Paths are config-driven (defaults point at the
 * current single-project HDS layout); this is the seam the multi-project
 * refactor grows from. Read-only for now: no cycle controls yet.
 */

define('EVERYMAN_APP', 'DPA');
define('EVERYMAN_CONFIG', getenv('EVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../shared/framework/framework.php';

/** Directory holding the pipeline daemon's control/state files. */
function dpa_overseer_dir(): string {
    return rtrim(fw_config_get('DPA_OVERSEER_DIR', '/var/www/hdp/agents-archive/overseer'), '/');
}

/** Read a state file relative to the overseer dir, or '' if missing. */
function dpa_read(string $name): string {
    $p = dpa_overseer_dir() . '/' . $name;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

/** Is the underseer daemon process running? */
function dpa_daemon_running(): bool {
    [$exit, $out] = fw_run_cmd(['pgrep', '-f', 'underseer.py']);
    return $exit === 0 && trim($out) !== '';
}

/** Is the daemon-enabled flag present (auto-revive on)? */
function dpa_daemon_enabled(): bool {
    return is_file(dpa_overseer_dir() . '/daemon-enabled');
}

/** Locate the build queue (config override, else search common spots). */
function dpa_find(string $basename): string {
    $cfg = fw_config_get('DPA_' . strtoupper(str_replace(['-', '.'], '_', $basename)));
    if ($cfg !== '' && is_file($cfg)) return (string) file_get_contents($cfg);
    foreach ([
        '/var/www/hdp/staging/docs/' . $basename,
        dpa_overseer_dir() . '/' . $basename,
        '/var/www/hdp/agents-archive/' . $basename,
    ] as $p) {
        if (is_file($p)) return (string) file_get_contents($p);
    }
    return '';
}

/** Last N lines of the underseer log. */
function dpa_log_tail(int $n = 30): string {
    $p = dpa_overseer_dir() . '/underseer.log';
    if (!is_file($p)) return '';
    $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return implode("\n", array_slice($lines, -$n));
}
