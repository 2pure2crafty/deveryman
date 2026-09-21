<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../lib.php';                 // launcher helpers (git/resolution/peek)
require_once __DIR__ . '/../../conductor/lib.php';    // agent_status, agent_dir, agent_token_usage, fmt_tokens, status_badge, load_registry
require_once __DIR__ . '/../../dpa/lib.php';          // dpa_config, dpa_docs_dir, dpa_read_doc, dpa_daemon_active, dpa_base_ahead, dpa_queue_counts
fw_require_auth();
fw_csrf_token(); // start session before any output

$conductorUrl = fw_config_get('DEVERYMAN_CONDUCTOR_URL');
$dpaUrl       = fw_config_get('DEVERYMAN_DPA_URL');
$msg          = $_GET['msg'] ?? '';

/* --- resolve the project across both registries --- */
$slug = $_GET['slug'] ?? '';
$projectsPath = __DIR__ . '/../../projects.json';
$projects = [];
if (is_file($projectsPath)) {
    $reg = json_decode((string) file_get_contents($projectsPath), true);
    $projects = $reg['projects'] ?? [];
}
$sharedProj = $projects[$slug] ?? null;
if ($sharedProj === null) {
    fw_header('Project', '/');
    echo '<h1>Unknown project</h1><p class="meta">No project "' . fw_h($slug) . '" in the registry.</p>';
    fw_footer();
    exit;
}

$caps     = $sharedProj['capabilities'] ?? [];
$condProj = load_registry()['projects'][$slug] ?? null;         // conductor/registry.json (agent data)
$dpaCfg   = !empty($caps['dpa']) ? dpa_config($sharedProj) : null;
$repo     = deveryman_repo_root($slug, $sharedProj, $dpaCfg, $condProj);
$label    = $sharedProj['label'] ?? $slug;
$dpaRunning = !empty($caps['dpa']) ? dpa_daemon_active($slug) : false;

fw_header($label, '/');
echo '<h1>' . fw_h($label) . '</h1>';
if ($msg !== '') echo '<div class="card"><strong>' . fw_h($msg) . '</strong></div>';

// Inline DPA action form (CSRF), posting to project-action.php.
$pform = function (string $action, string $label, string $bg, array $extra = []) use ($slug): string {
    $h = '<form method="post" action="project-action.php" style="display:inline">'
        . '<input type="hidden" name="project" value="' . fw_h($slug) . '">'
        . '<input type="hidden" name="action" value="' . fw_h($action) . '">'
        . fw_csrf_field();
    foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . fw_h($k) . '" value="' . fw_h((string) $v) . '">';
    return $h . '<button class="btn" type="submit" style="background:' . $bg . '">' . fw_h($label) . '</button></form>';
};

$summary = $dpaCfg['context']['project_summary'] ?? '';
if ($summary === '' && $condProj) $summary = $condProj['description'] ?? '';
if ($summary !== '') echo '<p class="desc">' . fw_h($summary) . '</p>';

/* --- header card: chips + GitHub + git status --- */
echo '<div class="card">';
if (!empty($caps['conductor'])) echo '<span class="pill on">Conductor</span>';
if (!empty($caps['dpa'])) echo '<span class="pill ' . ($dpaRunning ? 'on' : 'off') . '">DPA ' . ($dpaRunning ? 'running' : 'idle') . '</span>';
$gh = deveryman_github_url($repo, $dpaCfg, $condProj);
if ($gh !== '') echo '<div class="meta">repo: <a href="' . fw_h($gh) . '">' . fw_h($gh) . '</a></div>';
if (deveryman_is_repo($repo)) {
    $ab = deveryman_git_ahead_behind($repo);
    $abTxt = $ab['upstream'] ? ($ab['ahead'] . ' ahead / ' . $ab['behind'] . ' behind origin') : 'no upstream (local only)';
    echo '<div class="meta">branch <strong>' . fw_h(deveryman_git_branch($repo)) . '</strong>, ' . fw_h($abTxt) . '</div>';
    $lc = deveryman_git_last_commit($repo);
    if ($lc) echo '<div class="meta">last commit: ' . fw_h($lc['hash']) . ' ' . fw_h($lc['subject'])
        . ' (' . fw_h($lc['when']) . ', ' . fw_h($lc['author']) . ')</div>';
} else {
    echo '<div class="meta">' . fw_h($repo !== '' ? $repo : '(no repo path)') . '</div>';
}
echo '</div>';

/* --- last session handoff peek (newest across all this project's agents) --- */
$sessionPaths = [];
if ($dpaCfg && !empty($dpaCfg['pipeline_root'])) {
    foreach (glob($dpaCfg['pipeline_root'] . '/agents/*/SESSION.md') ?: [] as $s) $sessionPaths[] = $s;
}
if ($condProj) {
    foreach (($condProj['agents'] ?? []) as $ag) $sessionPaths[] = agent_dir($condProj, $ag) . '/SESSION.md';
}
$handoff = deveryman_latest_session_handoff($sessionPaths);
if ($handoff) {
    echo '<details><summary>Last session handoff (' . fw_h(date('Y-m-d H:i', $handoff['mtime'])) . ')</summary>'
        . '<pre>' . fw_h($handoff['peek']) . '</pre></details>';
}

/* --- Conductor box: this project's agents --- */
echo '<h2>Conductor</h2>';
$agents = $condProj['agents'] ?? [];
$spawnUrl = $conductorUrl !== '' ? rtrim($conductorUrl, '/') . '/spawn-form.php?project=' . rawurlencode($slug) : '';
if (empty($agents)) {
    echo '<div class="card"><div class="meta">No agents yet.</div>';
    if ($spawnUrl !== '') echo '<a class="btn" href="' . fw_h($spawnUrl) . '">New agent</a>';
    echo '</div>';
} else {
    foreach ($agents as $an => $ag) {
        echo '<div class="card"><strong>' . fw_h($ag['label'] ?? $an) . '</strong> ' . status_badge(agent_status($ag['tmux'] ?? ''));
        $use = agent_token_usage($condProj, $ag);
        $life = $use['input'] + $use['output'] + $use['cache_read'] + $use['cache_write'];
        echo '<div class="meta">model ' . fw_h($ag['model'] ?? 'auto') . ', context '
            . fmt_tokens(agent_context_size($condProj, $ag)) . ', lifetime ' . fmt_tokens($life) . '</div>';
        $sm = agent_dir($condProj, $ag) . '/SESSION.md';
        if (is_file($sm)) {
            $peek = implode("\n", array_slice(@file($sm, FILE_IGNORE_NEW_LINES) ?: [], 0, 30));
            echo '<details><summary>Last handoff</summary><pre>' . fw_h($peek) . '</pre></details>';
        }
        echo '<a class="btn" style="background:#444" href="agent.php?slug=' . rawurlencode($slug) . '&agent=' . rawurlencode((string) $an) . '">Open</a>';
        echo '</div>';
    }
    if ($spawnUrl !== '') echo '<a class="btn" href="' . fw_h($spawnUrl) . '">New agent</a>';
}

/* --- the two DPA boxes (only for DPA-capable projects) --- */
if ($dpaCfg) {
    $docs = dpa_docs_dir($dpaCfg);
    $counts = dpa_queue_counts($dpaCfg);
    $c = fn(string $k): int => (int) ($counts[$k] ?? 0);

    echo '<h2>Development Pipeline Automation</h2>';
    echo '<div class="card">';
    echo '<span class="pill ' . ($dpaRunning ? 'on' : 'off') . '">daemon ' . ($dpaRunning ? 'running' : 'idle') . '</span>';
    $state = dpa_read_doc($docs, 'pipeline-state.md');
    $stage = preg_match('/\*\*Current stage:\*\*\s*(.*)/', $state, $m) ? trim($m[1]) : 'none';
    $sstatus = preg_match('/\*\*Stage status:\*\*\s*(.*)/', $state, $m) ? trim($m[1]) : 'IDLE';
    echo '<div class="meta">stage: ' . fw_h($stage) . ' (' . fw_h($sstatus) . '), autonomy '
        . fw_h((string) ($dpaCfg['autonomy_level'] ?? '')) . '</div>';
    $backlog = dpa_backlog_queued($dpaCfg);
    echo '<div class="meta">queue: ' . $backlog . ' backlog, ' . $c('QUEUED') . ' queued, '
        . $c('ACTIVE') . ' active, ' . $c('COMPLETE') . ' complete'
        . ($c('BLOCKED') ? ', ' . $c('BLOCKED') . ' blocked' : '') . '</div>';
    // Autonomy slider (writes the config + restarts the daemon so it takes effect).
    $auto = (int) ($dpaCfg['autonomy_level'] ?? 3);
    echo '<form method="post" action="project-action.php" style="margin-top:8px">'
        . '<input type="hidden" name="project" value="' . fw_h($slug) . '">'
        . '<input type="hidden" name="action" value="set-autonomy">' . fw_csrf_field()
        . '<label>Autonomy: <input type="range" name="autonomy" min="1" max="5" value="' . $auto
        . '" style="width:auto" oninput="this.nextElementSibling.value=this.value"> <output>' . $auto . '</output></label>'
        . '<button class="btn" type="submit" style="margin-top:6px;background:#444">Set autonomy</button></form>';
    echo '<div style="margin-top:8px">';
    if ($dpaRunning) echo $pform('stop', 'Stop', '#b91c1c') . $pform('restart', 'Restart', '#444');
    else echo $pform('start', 'Launch Development Pipeline Automation', '#2563eb');
    if ($backlog > 0) echo $pform('run-product', 'Run product feeder (' . $backlog . ')', '#2563eb');
    echo '</div></div>';

    $ahead = dpa_base_ahead($dpaCfg);
    $base = $dpaCfg['base_branch'] ?? 'staging';
    $rel  = $dpaCfg['release_branch'] ?? 'main';
    echo '<h2>Deployment Pipeline Automation</h2>';
    echo '<div class="card">';
    echo '<div class="meta">' . (int) $ahead . ' feature(s) on ' . fw_h($base) . ' ready to merge into '
        . fw_h($rel) . ' and ship.</div>';
    echo '<div style="margin-top:8px">';
    if ($ahead > 0) echo $pform('promote', 'Promote ' . $base . ' to ' . $rel, '#7c3aed');
    echo $pform('deploy', 'Deploy to production', '#b45309');
    echo $pform('verify', 'Verify production', '#444');
    echo '</div></div>';
}

fw_footer();
