<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../../dpa/lib.php';   // dpa_projects, dpa_config, dpa_gate_actions, dpa_underseer_path, dpa_gate_audit, dpa_daemon_active
fw_require_auth();
fw_demo_block('Pipeline controls');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$slug   = $_POST['project'] ?? '';
$action = $_POST['action'] ?? '';
$back   = 'project.php?slug=' . rawurlencode($slug);

$projects = dpa_projects();                 // DPA-capable projects only
if (!isset($projects[$slug])) {
    header('Location: index.php');
    exit;
}
if (!fw_csrf_ok()) {
    header('Location: ' . $back . '&msg=' . rawurlencode('Request expired, please try again.'));
    exit;
}

$proj       = $projects[$slug];
$cfg        = dpa_config($proj);
$configPath = $proj['capabilities']['dpa']['config'] ?? '';
$user       = (string) ($_SERVER['PHP_AUTH_USER'] ?? 'operator');
$unit       = "dpa-underseer@{$slug}.service";

/** Redirect back to the project page with a flash message. */
function done(string $back, string $msg): void {
    header('Location: ' . $back . '&msg=' . rawurlencode($msg));
    exit;
}

// Daemon lifecycle: scoped sudo systemctl (start/stop also enable/disable for reboot survival).
if (in_array($action, ['start', 'stop', 'restart'], true)) {
    if ($action === 'start') {
        fw_run_cmd(['sudo', 'systemctl', 'enable', $unit]);
        fw_run_cmd(['sudo', 'systemctl', 'start', $unit]);
    } elseif ($action === 'stop') {
        fw_run_cmd(['sudo', 'systemctl', 'stop', $unit]);
        fw_run_cmd(['sudo', 'systemctl', 'disable', $unit]);
    } else {
        fw_run_cmd(['sudo', 'systemctl', 'restart', $unit]);
    }
    done($back, 'Development pipeline daemon: ' . $action);
}

// Human gates: run the daemon operator CLI as the service user (no sudo).
$gates = dpa_gate_actions();
if (isset($gates[$action])) {
    if ($cfg === null || $configPath === '') done($back, 'No project.json for this project.');
    [$e, $out, $err] = fw_run_cmd(['python3', dpa_underseer_path(), $configPath, $gates[$action]], null, '', 120);
    $result = trim($out) !== '' ? trim($out) : ($e === 0 ? 'ok' : trim($err));
    dpa_gate_audit($cfg, $user, $action, $result);
    done($back, $action . ': ' . $result);
}

// Set autonomy: write the project.json, then restart the daemon (it reads config only at start).
if ($action === 'set-autonomy') {
    $level = (int) ($_POST['autonomy'] ?? 0);
    if ($level < 1 || $level > 5 || $configPath === '') done($back, 'Invalid autonomy level.');
    $ok = fw_update_json($configPath, function (array $c) use ($level): array {
        $c['autonomy_level'] = $level;
        return $c;
    });
    if (!$ok) done($back, 'Could not write autonomy level.');
    $note = '';
    if (dpa_daemon_active($slug)) {
        fw_run_cmd(['sudo', 'systemctl', 'restart', $unit]);
        $note = ' (daemon restarted)';
    }
    done($back, 'autonomy set to ' . $level . $note);
}

header('Location: index.php');
exit;
