<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../../shared/framework/tokens.php';
fw_require_auth();

$conductorUrl = fw_config_get('DEVERYMAN_CONDUCTOR_URL');
$dpaUrl       = fw_config_get('DEVERYMAN_DPA_URL');

// Project list comes from Conductor's registry for now (the shared projects.json
// unification is a later step). Each project offers Conductor + DPA lenses.
$registryPath = '/var/www/deveryman/conductor/registry.json';
$projects = [];
if (is_file($registryPath)) {
    $reg = json_decode((string) file_get_contents($registryPath), true);
    $projects = $reg['projects'] ?? [];
}

fw_header('Home');
echo "<h1>D'everyman</h1>";
echo '<p class="desc">Your projects, through either lens: Conductor (manual agents) or DPA (the pipeline).</p>';

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
        echo '<div class="card"><strong>' . fw_h($p['label'] ?? $slug) . '</strong>';
        if (!empty($p['description'])) {
            echo '<div class="desc">' . fw_h(mb_strimwidth($p['description'], 0, 120, '...')) . '</div>';
        }
        // Conductor lens: deep-link to the project page if we have a base URL.
        $cHref = $conductorUrl !== '' ? rtrim($conductorUrl, '/') . '/project.php?slug=' . rawurlencode($slug) : '';
        echo $cHref !== ''
            ? '<a class="btn" href="' . fw_h($cHref) . '">Conductor</a>'
            : '<span class="pill off">Conductor URL not set</span>';
        // DPA lens: single pipeline for now (multi-project later).
        echo $dpaUrl !== ''
            ? '<a class="btn" style="background:#444" href="' . fw_h($dpaUrl) . '">DPA</a>'
            : '<span class="pill off">DPA URL not set</span>';
        echo '</div>';
    }
}

echo '<h2>Direct</h2>';
if ($conductorUrl !== '') echo '<a class="btn" href="' . fw_h($conductorUrl) . '">Conductor dashboard</a>';
if ($dpaUrl !== '')       echo '<a class="btn" style="background:#444" href="' . fw_h($dpaUrl) . '">DPA dashboard</a>';
fw_footer();
