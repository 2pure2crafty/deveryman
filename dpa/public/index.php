<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
fw_require_auth();
dpa_csrf_token(); // start the session and ensure a CSRF token before any output

$projects = dpa_projects();
$slug = $_GET['project'] ?? '';
$msg = $_GET['msg'] ?? '';

/* ---- project list ---- */
if ($slug === '' || !isset($projects[$slug])) {
    fw_header('DPA', '/');
    echo '<h1>DPA pipelines</h1>';
    if (empty($projects)) {
        echo '<p class="meta">No DPA-capable projects in projects.json.</p>';
    } else {
        foreach ($projects as $s => $proj) {
            $active = dpa_daemon_active($s);
            echo '<div class="card"><strong>' . fw_h($proj['label'] ?? $s) . '</strong>'
                . '<span class="pill ' . ($active ? 'on' : 'off') . '">daemon ' . ($active ? 'running' : 'stopped') . '</span>'
                . '<div class="meta">' . fw_h($proj['path'] ?? '') . '</div>'
                . '<a class="btn" href="index.php?project=' . fw_h($s) . '">View pipeline</a></div>';
        }
    }
    fw_footer();
    exit;
}

/* ---- one project's pipeline ---- */
$proj = $projects[$slug];
$cfg  = dpa_config($proj);
fw_header('DPA: ' . ($proj['label'] ?? $slug), '/');
echo '<a class="back" href="index.php">&larr; DPA pipelines</a>';
echo '<h1>' . fw_h($proj['label'] ?? $slug) . ' pipeline</h1>';
if ($msg !== '') echo '<div class="card"><strong>' . fw_h($msg) . '</strong></div>';

if ($cfg === null) {
    echo '<p class="meta">No project.json found for this project ('
        . fw_h($proj['capabilities']['dpa']['config'] ?? '') . ').</p>';
    fw_footer();
    exit;
}

$docs = dpa_docs_dir($cfg);
$active = dpa_daemon_active($slug);
$stages = $cfg['stages'] ?? [];

echo '<div class="card"><strong>Daemon</strong>'
    . '<span class="pill ' . ($active ? 'on' : 'off') . '">' . ($active ? 'running' : 'stopped') . '</span>'
    . '<div class="meta">stages: ' . fw_h(implode(' -> ', $stages)) . ' &middot; autonomy ' . fw_h((string)($cfg['autonomy_level'] ?? '')) . '</div>';
$ctl = function (string $action, string $label, string $bg) use ($slug) {
    return '<form method="post" action="control.php" style="display:inline">'
        . '<input type="hidden" name="project" value="' . fw_h($slug) . '">'
        . '<input type="hidden" name="action" value="' . fw_h($action) . '">'
        . dpa_csrf_field()
        . '<button class="btn" type="submit" style="background:' . $bg . '">' . fw_h($label) . '</button></form>';
};
if ($active) {
    echo $ctl('stop', 'Stop daemon', '#b91c1c') . $ctl('restart', 'Restart', '#444');
} else {
    echo $ctl('start', 'Start daemon', '#2563eb');
}
echo '</div>';

/* ---- human gates (front feeder + back deploy). Never run by the daemon. ---- */
$ahead   = dpa_base_ahead($cfg);
$backlog = dpa_backlog_queued($cfg);
$base    = $cfg['base_branch'] ?? 'staging';
$rel     = $cfg['release_branch'] ?? 'main';
echo '<h2>Human gates</h2>';
echo '<div class="card"><div class="meta">These steps are always yours to trigger; the daemon '
    . 'never runs them itself, at any autonomy level. Readiness: ' . (int)$backlog . ' backlog item(s) '
    . 'queued; ' . fw_h($base) . ' is ahead of ' . fw_h($rel) . ' by ' . (int)$ahead . ' commit(s).</div>';
echo '<div style="margin-top:8px">';
if ($backlog > 0) echo $ctl('run-product', 'Run product feeder (' . (int)$backlog . ')', '#2563eb');
if ($ahead > 0)   echo $ctl('promote', 'Promote ' . fw_h($base) . ' to ' . fw_h($rel) . ' (' . (int)$ahead . ')', '#7c3aed');
echo $ctl('deploy', 'Deploy to production', '#b45309');
echo $ctl('verify', 'Verify production', '#444');
echo '</div></div>';

$state = dpa_read_doc($docs, 'pipeline-state.md');
echo '<h2>Pipeline state</h2>';
echo trim($state) !== '' ? '<pre>' . fw_h(trim($state)) . '</pre>' : '<p class="meta">No pipeline-state.md.</p>';

$queue = dpa_read_doc($docs, 'build-queue.md');
echo '<h2>Build queue</h2>';
echo trim($queue) !== '' ? '<pre>' . fw_h(trim($queue)) . '</pre>' : '<p class="meta">No build queue.</p>';

$esc = dpa_read_doc($docs, 'escalation.md');
if (trim($esc) !== '') {
    echo '<h2 style="color:#f59e0b">Escalation</h2><pre>' . fw_h(trim($esc)) . '</pre>';
}

$signals = dpa_read_doc($docs, 'signals.md');
if (trim($signals) !== '') {
    echo '<h2>Signals</h2><pre>' . fw_h(trim($signals)) . '</pre>';
}

$logTail = dpa_log_tail($cfg, 30);
echo '<h2>Daemon log (recent)</h2>';
echo trim($logTail) !== '' ? '<pre>' . fw_h($logTail) . '</pre>' : '<p class="meta">No log yet.</p>';

echo '<p class="meta">Daemon start/stop uses a scoped systemctl sudo rule. The human gates '
    . '(promote, deploy, verify) run the pipeline CLI as the service user and are never '
    . 'triggered by the daemon itself.</p>';
fw_footer();
