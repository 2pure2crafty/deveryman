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
