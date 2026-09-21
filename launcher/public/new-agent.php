<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../conductor/lib.php';   // spawn_tmux_agent
fw_require_auth();

// Available agent role templates (conductor/agent-templates/<name>/CLAUDE.md).
$agentTemplates = [];
foreach (glob(__DIR__ . '/../../conductor/agent-templates/*', GLOB_ONLYDIR) ?: [] as $d) {
    if (is_file($d . '/CLAUDE.md')) $agentTemplates[] = basename($d);
}

/* ---------- GET: the form ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fw_header('New agent', '/');
    echo '<h1>New agent</h1>';
    echo '<p class="desc">Spin up a one-shot agent to think with, not tied to a project. Pick a role '
        . 'template, give it a starting prompt, and graduate it into a project later if it earns its keep.</p>';
    echo '<form method="post" action="new-agent.php">'
        . '<label>Name <input type="text" name="label" placeholder="e.g. Pricing ideas" required></label>'
        . '<label>Role template <select name="template">';
    foreach ($agentTemplates as $t) echo '<option value="' . fw_h($t) . '">' . fw_h($t) . '</option>';
    echo '</select></label>';
    echo '<label>Model <select name="model">'
        . '<option value="sonnet">sonnet</option><option value="opus">opus</option>'
        . '<option value="haiku">haiku</option><option value="">auto</option></select></label>';
    echo '<label>Initial prompt <textarea name="prompt" placeholder="What do you want to explore?"></textarea></label>';
    echo '<button class="btn" type="submit" style="margin-top:10px">Create and spin up</button></form>';
    fw_footer();
    exit;
}

/* ---------- POST: create + spin up ---------- */
$label    = trim($_POST['label'] ?? '');
$template = $_POST['template'] ?? '';
$model    = $_POST['model'] ?? 'sonnet';
$prompt   = trim($_POST['prompt'] ?? '');
if (!in_array($model, ['haiku', 'sonnet', 'opus', 'fable', ''], true)) $model = 'sonnet';

$errors = [];
if (!in_array($template, $agentTemplates, true)) $errors[] = 'Pick a valid role template.';

if (!$errors) {
    $res = deveryman_oneshot_create($label, $template, $model, $prompt);
    if ($res['ok']) {
        $slug = $res['slug'];
        $tmux = fw_config_get('CONDUCTOR_TMUX_PREFIX', 'DEV') . '-oneshot-' . $slug;
        spawn_tmux_agent($tmux, deveryman_oneshot_root() . '/conductor/' . $slug, $model, 'acceptEdits');
        header('Location: agent.php?slug=oneshot&agent=' . rawurlencode($slug)
            . '&msg=' . rawurlencode('Spinning up; open the Claude app in about a minute.'));
        exit;
    }
    $errors = array_merge($errors, $res['errors']);
}

fw_header('New agent', '/');
echo '<h1>New agent</h1>';
foreach ($errors as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
echo '<a class="back" href="new-agent.php">&larr; Try again</a>';
fw_footer();
