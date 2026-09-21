<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
fw_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$slug   = $_POST['project'] ?? '';
$action = $_POST['action'] ?? '';
$projects = dpa_projects();
$back = 'index.php?project=' . rawurlencode($slug);

// Known project only.
if (!isset($projects[$slug])) {
    header('Location: index.php');
    exit;
}
// CSRF: every state-changing action must carry the session token.
if (!dpa_csrf_ok()) {
    header('Location: ' . $back . '&msg=' . rawurlencode('Request expired, please try again.'));
    exit;
}

$proj = $projects[$slug];
$user = (string)($_SERVER['PHP_AUTH_USER'] ?? 'operator');

// Daemon lifecycle: scoped systemctl (needs sudo; matches the sudoers scope).
// start/stop also enable/disable so a running daemon survives a reboot.
if (in_array($action, ['start', 'stop', 'restart'], true)) {
    $unit = "dpa-underseer@{$slug}.service";
    if ($action === 'start') {
        fw_run_cmd(['sudo', 'systemctl', 'enable', $unit]);
        fw_run_cmd(['sudo', 'systemctl', 'start', $unit]);
    } elseif ($action === 'stop') {
        fw_run_cmd(['sudo', 'systemctl', 'stop', $unit]);
        fw_run_cmd(['sudo', 'systemctl', 'disable', $unit]);
    } else {
        fw_run_cmd(['sudo', 'systemctl', 'restart', $unit]);
    }
    header('Location: ' . $back);
    exit;
}

// Human gates (promote / deploy / verify / run-product / start-ideas): invoke the
// daemon's operator CLI as THIS service user, not root. These do git and agent
// spawns that must run as the pipeline user; no sudo is involved. argv array, no
// shell, the config path comes from the registry and the flag from an allowlist.
$gates = dpa_gate_actions();
if (isset($gates[$action])) {
    $cfg = dpa_config($proj);
    $configPath = $proj['capabilities']['dpa']['config'] ?? '';
    if ($cfg === null || $configPath === '') {
        header('Location: ' . $back . '&msg=' . rawurlencode('No project.json for this project.'));
        exit;
    }
    [$e, $out, $err] = fw_run_cmd(['python3', dpa_underseer_path(), $configPath, $gates[$action]], null, '', 120);
    $result = trim($out) !== '' ? trim($out) : ($e === 0 ? 'ok' : trim($err));
    dpa_gate_audit($cfg, $user, $action, $result);
    header('Location: ' . $back . '&msg=' . rawurlencode($action . ': ' . $result));
    exit;
}

header('Location: index.php');
exit;
