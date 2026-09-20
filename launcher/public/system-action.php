<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../lib.php';
fw_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$action = $_POST['action'] ?? '';

/** Redirect back to the System page with a short status message. */
function back(string $msg): void {
    header('Location: system.php?msg=' . rawurlencode($msg));
    exit;
}

/**
 * Run a fixed sudo systemctl command detached and slightly delayed, so the HTTP
 * response can flush before the action lands (needed when restarting the launcher
 * itself, or rebooting, which would otherwise kill this request mid-flight). The
 * argv is built only from values already validated against an allowlist.
 */
function deferred_sudo_systemctl(array $verbAndUnit): void {
    $cmd = 'sudo /usr/bin/systemctl';
    foreach ($verbAndUnit as $part) $cmd .= ' ' . escapeshellarg($part);
    exec('nohup sh -c ' . escapeshellarg('sleep 1; ' . $cmd) . ' >/dev/null 2>&1 &');
}

if ($action === 'restart-service') {
    $service = $_POST['service'] ?? '';
    // Only the known core services may be restarted; the name is a literal key,
    // never interpolated from arbitrary input.
    if (!array_key_exists($service, deveryman_core_services())) {
        back('Unknown service.');
    }
    $unit = $service . '.service';
    if ($service === 'deveryman-launcher') {
        // Restarting ourselves would kill this request; defer so the redirect flushes.
        deferred_sudo_systemctl(['restart', $unit]);
        back('Restarting the launcher; this page will reload in a moment.');
    }
    [$e, , $err] = fw_run_cmd(['sudo', 'systemctl', 'restart', $unit], null, '', 30);
    back($e === 0 ? "Restarted {$service}." : "Restart failed: " . trim($err));
}

if ($action === 'reboot') {
    if (($_POST['confirm'] ?? '') !== 'REBOOT') {
        back('Reboot not confirmed (type REBOOT).');
    }
    deferred_sudo_systemctl(['reboot']);
    back('Rebooting the host. Enabled services will come back automatically.');
}

back('Unknown action.');
