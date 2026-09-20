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

fw_run_cmd(['sudo', 'systemctl', $action, "dpa-underseer@{$slug}.service"]);
header('Location: ' . $back);
exit;
