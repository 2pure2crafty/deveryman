<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../conductor/lib.php';   // agent_* helpers, load_registry, status_badge
fw_require_auth();
fw_csrf_token(); // start session before any output

$slug      = $_GET['slug'] ?? '';
$agentSlug = $_GET['agent'] ?? '';
$msg       = $_GET['msg'] ?? '';

$reg     = load_registry();
$project = $reg['projects'][$slug] ?? null;
$agent   = $project['agents'][$agentSlug] ?? null;
if ($project === null || $agent === null) {
    fw_header('Agent', '/');
    echo '<h1>Unknown agent</h1><p class="meta">No agent "' . fw_h($agentSlug) . '" in project "' . fw_h($slug) . '".</p>';
    fw_footer();
    exit;
}

$tmux   = $agent['tmux'] ?? '';
$status = agent_status($tmux);
$dir    = agent_dir($project, $agent);

fw_header($agent['label'] ?? $agentSlug, '/');
echo '<a class="back" href="project.php?slug=' . rawurlencode($slug) . '">&larr; ' . fw_h($project['label'] ?? $slug) . '</a>';
echo '<h1>' . fw_h($agent['label'] ?? $agentSlug) . '</h1>';
if ($msg !== '') echo '<div class="card"><strong>' . fw_h($msg) . '</strong></div>';

// status + tokens + path
echo '<div class="card">' . status_badge($status);
$use  = agent_token_usage($project, $agent);
$life = $use['input'] + $use['output'] + $use['cache_read'] + $use['cache_write'];
echo '<div class="meta">model ' . fw_h($agent['model'] ?? 'auto')
    . ', context ' . fmt_tokens(agent_context_size($project, $agent))
    . ', lifetime ' . fmt_tokens($life) . '</div>';
echo '<div class="meta">' . fw_h($dir) . '</div>';
$url = agent_session_url($tmux);
if ($url) echo '<div class="meta"><a href="' . fw_h($url) . '">Open in the Claude app</a></div>';
echo '</div>';

// inline actions (CSRF)
$act = function (string $action, string $label, string $bg) use ($slug, $agentSlug): string {
    return '<form method="post" action="agent-action.php" style="display:inline">'
        . '<input type="hidden" name="project" value="' . fw_h($slug) . '">'
        . '<input type="hidden" name="agent" value="' . fw_h($agentSlug) . '">'
        . '<input type="hidden" name="action" value="' . fw_h($action) . '">'
        . fw_csrf_field()
        . '<button class="btn" type="submit" style="background:' . $bg . '">' . fw_h($label) . '</button></form>';
};
echo $status === 'stopped'
    ? $act('spinup', 'Spin up', '#2563eb')
    : $act('wrapdown', 'Wrap up & stop', '#b91c1c');
// One-shot agents can graduate into a project.
if ($slug === 'oneshot') {
    echo '<a class="btn" style="background:#7c3aed" href="graduate.php?agent=' . rawurlencode($agentSlug) . '">Graduate to project</a>';
}

// last session handoff (capped)
$sm = $dir . '/SESSION.md';
if (is_file($sm)) {
    $peek = implode("\n", array_slice(@file($sm, FILE_IGNORE_NEW_LINES) ?: [], 0, 100));
    echo '<h2>Last session handoff</h2><pre>' . fw_h($peek) . '</pre>';
}

// live output
if ($status !== 'stopped') {
    $tail = agent_pane_tail($tmux, 24);
    if ($tail !== '') echo '<h2>Live output</h2><pre>' . fw_h($tail) . '</pre>';
}

// directory listing (top level)
echo '<h2>Directory</h2><pre>';
foreach (@scandir($dir) ?: [] as $e) {
    if ($e === '.' || $e === '..') continue;
    echo fw_h($e) . (is_dir($dir . '/' . $e) ? '/' : '') . "\n";
}
echo '</pre>';

fw_footer();
