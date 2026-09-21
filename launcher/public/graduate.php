<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../conductor/lib.php';   // load_registry, agent_status, perform_wrapdown
fw_require_auth();
fw_csrf_token();

$agentSlug = $_REQUEST['agent'] ?? '';
$os        = deveryman_oneshot_slug();
$reg       = load_registry();
$osProject = $reg['projects'][$os] ?? null;
$osAgent   = $osProject['agents'][$agentSlug] ?? null;
if ($osAgent === null) {
    fw_header('Graduate', '/');
    echo '<h1>Unknown one-shot agent</h1><p class="meta">No one-shot agent "' . fw_h($agentSlug) . '".</p>';
    fw_footer();
    exit;
}

/* ---------- GET: the form ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fw_header('Graduate agent', '/');
    echo '<a class="back" href="agent.php?slug=oneshot&agent=' . rawurlencode($agentSlug) . '">&larr; '
        . fw_h($osAgent['label'] ?? $agentSlug) . '</a>';
    echo '<h1>Graduate to a project</h1>';
    echo '<p class="desc">Stand up a new project and pull this agent in as its <strong>'
        . fw_h($osAgent['template'] ?? 'agent') . '</strong> agent. Its memory (SESSION.md, history) comes along.</p>';
    echo '<form method="post" action="graduate.php">'
        . '<input type="hidden" name="agent" value="' . fw_h($agentSlug) . '">' . fw_csrf_field()
        . '<label>Project name <input type="text" name="label" placeholder="e.g. My App" required></label>'
        . '<label>Template <select name="template">';
    foreach (deveryman_templates() as $id => $t) echo '<option value="' . fw_h($id) . '">' . fw_h($t['label']) . '</option>';
    echo '</select></label>';
    echo '<button class="btn" type="submit" style="margin-top:10px">Graduate</button></form>';
    fw_footer();
    exit;
}

/* ---------- POST: graduate ---------- */
if (!fw_csrf_ok()) {
    header('Location: graduate.php?agent=' . rawurlencode($agentSlug));
    exit;
}
$projLabel  = trim($_POST['label'] ?? '');
$templateId = $_POST['template'] ?? 'none';

// Wrap the agent down first so nothing is mid-flight and its SESSION.md is current.
if (agent_status($osAgent['tmux'] ?? '') !== 'stopped') {
    perform_wrapdown($osProject, $osAgent, 90);
}

$res = deveryman_graduate($agentSlug, $projLabel, $templateId);
if ($res['ok']) {
    header('Location: project.php?slug=' . rawurlencode($res['project'])
        . '&msg=' . rawurlencode('Graduated ' . ($osAgent['label'] ?? $agentSlug) . ' into this project.'));
    exit;
}

fw_header('Graduate agent', '/');
echo '<h1>Graduate to a project</h1>';
foreach ($res['errors'] as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
echo '<a class="back" href="graduate.php?agent=' . rawurlencode($agentSlug) . '">&larr; Try again</a>';
fw_footer();
