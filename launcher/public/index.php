<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../../shared/framework/tokens.php';
fw_require_auth();

$conductorUrl = fw_config_get('DEVERYMAN_CONDUCTOR_URL');
$dpaUrl       = fw_config_get('DEVERYMAN_DPA_URL');

// Project list comes from the shared projects.json (the single registry both
// capabilities read). Each project declares which lenses it offers.
$projectsPath = __DIR__ . '/../../projects.json';
$projects = [];
if (is_file($projectsPath)) {
    $reg = json_decode((string) file_get_contents($projectsPath), true);
    $projects = $reg['projects'] ?? [];
}

fw_header('Home');
echo "<h1>D'everyman</h1>";
echo '<p class="desc">Your projects. Open one to drive its agents (Conductor) or run its pipeline (DPA).</p>';
$msg = $_GET['msg'] ?? '';
if ($msg !== '') echo '<div class="card"><strong>' . fw_h($msg) . '</strong></div>';
echo '<a class="btn" href="new-project.php">New project</a>';
echo '<a class="btn" style="background:#444" href="import.php">Import a repo</a>';
echo '<a class="btn" style="background:#444" href="setup.php">AI credentials</a>';
echo '<a class="btn" style="background:#444" href="system.php">System</a>';

// Aggregate token view
$tot = fw_total_tokens();
$used = $tot['input'] + $tot['output'] + $tot['cache_read'] + $tot['cache_write'];
$saved = fw_estimated_saved();
echo '<div class="card"><strong>Tokens</strong>'
    . '<div class="meta">used across everything: ' . fw_fmt_tokens($used)
    . ' (' . fw_fmt_tokens($tot['input'] + $tot['cache_read'] + $tot['cache_write']) . ' in / '
    . fw_fmt_tokens($tot['output']) . ' out, ' . $tot['files'] . ' transcripts)</div>'
    . '<div class="meta">estimated saved by auto-wrap-downs: ' . fw_fmt_tokens($saved) . ' (guesstimate)</div>'
    . '</div>';

echo '<h2>Projects</h2>';
if (empty($projects)) {
    echo '<p class="meta">No projects registered yet.</p>';
} else {
    foreach ($projects as $slug => $p) {
        $caps = $p['capabilities'] ?? [];
        $href = 'project.php?slug=' . rawurlencode($slug);
        echo '<div class="card"><strong>' . fw_h($p['label'] ?? $slug) . '</strong>';
        // Capability chips + live DPA daemon status at a glance.
        if (!empty($caps['conductor'])) echo '<span class="pill on">Conductor</span>';
        if (!empty($caps['dpa'])) {
            [, $st] = fw_run_cmd(['systemctl', 'is-active', "dpa-underseer@{$slug}.service"]);
            $running = trim($st) === 'active';
            echo '<span class="pill ' . ($running ? 'on' : 'off') . '">DPA ' . ($running ? 'running' : 'idle') . '</span>';
        }
        if (!empty($p['path'])) echo '<div class="meta">' . fw_h($p['path']) . '</div>';
        // Project-first: one link into the project hub (it offers both lenses).
        echo '<a class="btn" href="' . fw_h($href) . '">Open</a>';
        echo '</div>';
    }
}

echo '<h2>Direct</h2>';
if ($conductorUrl !== '') echo '<a class="btn" href="' . fw_h($conductorUrl) . '">Conductor dashboard</a>';
if ($dpaUrl !== '')       echo '<a class="btn" style="background:#444" href="' . fw_h($dpaUrl) . '">DPA dashboard</a>';
fw_footer();
