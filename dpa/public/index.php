<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
fw_require_auth();

$running   = dpa_daemon_running();
$enabled   = dpa_daemon_enabled();
$config    = dpa_read('current-config.md');
$escalation = dpa_read('escalation.md');
$queue     = dpa_find('build-queue.md');
$state     = dpa_find('pipeline-state.md');
$logTail   = dpa_log_tail(30);

fw_header('Pipeline', '/');
echo '<h1>DPA pipeline</h1>';

echo '<div class="card"><strong>Daemon (underseer)</strong>'
    . '<span class="pill ' . ($running ? 'on' : 'off') . '">' . ($running ? 'running' : 'stopped') . '</span>'
    . '<span class="pill ' . ($enabled ? 'on' : 'off') . '">auto-revive ' . ($enabled ? 'on' : 'off') . '</span>'
    . '</div>';

if (trim($escalation) !== '') {
    echo '<div class="card" style="border-color:#b45309"><strong>Escalation, needs Patch</strong>'
        . '<pre>' . fw_h(trim($escalation)) . '</pre></div>';
}

echo '<h2>Active cycle</h2>';
echo trim($config) !== ''
    ? '<pre>' . fw_h(trim($config)) . '</pre>'
    : '<p class="meta">No active cycle config (current-config.md not found or empty).</p>';

echo '<h2>Build queue</h2>';
echo trim($queue) !== ''
    ? '<pre>' . fw_h(trim($queue)) . '</pre>'
    : '<p class="meta">No build queue found.</p>';

echo '<h2>Pipeline state</h2>';
echo trim($state) !== ''
    ? '<pre>' . fw_h(trim($state)) . '</pre>'
    : '<p class="meta">No canonical pipeline-state.md found (currently scattered per-stage; consolidated in the multi-project refactor).</p>';

echo '<h2>Daemon log (recent)</h2>';
echo trim($logTail) !== ''
    ? '<pre>' . fw_h($logTail) . '</pre>'
    : '<p class="meta">No underseer.log yet.</p>';

echo '<p class="meta">Read-only. Cycle controls (start/stop, approve escalations, daemon toggle) '
    . 'come with the multi-project refactor, when the pipeline is un-paused.</p>';
fw_footer();
