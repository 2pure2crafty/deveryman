<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../../conductor/lib.php';   // spawn_tmux_agent, perform_wrapdown, load_registry, agent_dir
fw_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$slug      = $_POST['project'] ?? '';
$agentSlug = $_POST['agent'] ?? '';
$action    = $_POST['action'] ?? '';
$back = 'agent.php?slug=' . rawurlencode($slug) . '&agent=' . rawurlencode($agentSlug);

$reg     = load_registry();
$project = $reg['projects'][$slug] ?? null;
$agent   = $project['agents'][$agentSlug] ?? null;
if ($project === null || $agent === null) {
    header('Location: index.php');
    exit;
}
if (!fw_csrf_ok()) {
    header('Location: ' . $back . '&msg=' . rawurlencode('Request expired, please try again.'));
    exit;
}

// Both actions run as the service user (tmux + claude), no sudo.
if ($action === 'spinup') {
    spawn_tmux_agent($agent['tmux'] ?? '', agent_dir($project, $agent), $agent['model'] ?? '', $agent['permission_mode'] ?? '');
    header('Location: ' . $back . '&msg=' . rawurlencode('Spinning up; open the Claude app in about a minute.'));
    exit;
}
if ($action === 'wrapdown') {
    $res = perform_wrapdown($project, $agent, 90);
    header('Location: ' . $back . '&msg=' . rawurlencode('Wrap-down: ' . ($res['reason'] ?? 'done')));
    exit;
}
header('Location: ' . $back);
exit;
