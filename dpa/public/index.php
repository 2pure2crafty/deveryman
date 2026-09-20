<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
fw_require_auth();

$projects = dpa_projects();
$slug = $_GET['project'] ?? '';

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
        . '<button class="btn" type="submit" style="background:' . $bg . '">' . fw_h($label) . '</button></form>';
};
if ($active) {
    echo $ctl('stop', 'Stop daemon', '#b91c1c') . $ctl('restart', 'Restart', '#444');
} else {
    echo $ctl('start', 'Start daemon', '#2563eb');
}
echo '</div>';

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

$logTail = dpa_log_tail($cfg, 30);
echo '<h2>Daemon log (recent)</h2>';
echo trim($logTail) !== '' ? '<pre>' . fw_h($logTail) . '</pre>' : '<p class="meta">No log yet.</p>';

echo '<p class="meta">Read-only. Cycle controls (start/stop the daemon, approve escalations) '
    . 'need a scoped sudo rule for systemctl; see docs/FUTURE.md.</p>';
fw_footer();
