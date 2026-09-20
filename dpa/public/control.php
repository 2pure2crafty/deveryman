<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
fw_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$slug = $_POST['project'] ?? '';
$action = $_POST['action'] ?? '';

$projects = dpa_projects();
$back = 'index.php?project=' . rawurlencode($slug);

// Only known DPA projects, only start/stop/restart (matches the sudoers scope).
if (!isset($projects[$slug]) || !in_array($action, ['start', 'stop', 'restart'], true)) {
    header('Location: index.php');
    exit;
}

$unit = "dpa-underseer@{$slug}.service";
// start/stop also enable/disable so the daemon's running state survives a reboot:
// systemd brings an enabled instance back up on boot and leaves a disabled one
// down. restart leaves the enable state untouched.
if ($action === 'start') {
    fw_run_cmd(['sudo', 'systemctl', 'enable', $unit]);
    fw_run_cmd(['sudo', 'systemctl', 'start', $unit]);
} elseif ($action === 'stop') {
    fw_run_cmd(['sudo', 'systemctl', 'stop', $unit]);
    fw_run_cmd(['sudo', 'systemctl', 'disable', $unit]);
} else { // restart
    fw_run_cmd(['sudo', 'systemctl', 'restart', $unit]);
}
header('Location: ' . $back);
exit;
