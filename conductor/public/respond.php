<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$projectSlug = $_POST['project'] ?? '';
$agentSlug = $_POST['agent'] ?? '';
$action = $_POST['action'] ?? '';

$registry = load_registry();
$project = $registry['projects'][$projectSlug] ?? null;
if ($project === null) error_page('No such project.');
$agent = $project['agents'][$agentSlug] ?? null;
if ($agent === null) error_page('No such agent.', 'index.php');

if (!tmux_session_exists($agent['tmux'])) {
    error_page('Session ' . $agent['tmux'] . ' is not running.', 'index.php');
}

if ($action === 'approve') {
    // Default-highlighted option (usually "Yes") - plain Enter selects it.
    run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], 'Enter']);
} elseif ($action === 'deny') {
    // Deny = cancel the permission prompt. Claude Code treats Escape as "reject"
    // ("Esc to cancel"), so a single Escape declines the pending tool call. This
    // is fail-safe by construction: it can never land on the default (approve)
    // option, and it does nothing harmful if the prompt has already cleared.
    // We deliberately do NOT navigate by a client-supplied option count: a wrong,
    // stale, or forged count could send zero Down keys and let Enter APPROVE the
    // very prompt the user was trying to deny.
    run_cmd(['tmux', 'send-keys', '-t', $agent['tmux'], 'Escape']);
} else {
    error_page('Unknown action.', 'index.php');
}
audit_log('respond', $agent['tmux'] . ' ' . $action);

header('Location: index.php');
exit;
