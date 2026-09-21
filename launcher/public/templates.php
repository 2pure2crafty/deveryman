<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../registry.php';
fw_require_auth();

fw_header('Pipeline templates', '/');
echo '<h1>Pipeline templates</h1>';
echo '<p class="desc">The library of pipeline shapes a new project or import can be stood up from. '
    . 'Builtins are DPA standard and the bare project; the ones you compose are yours to reuse and edit.</p>';

$msg = $_GET['msg'] ?? '';
if ($msg !== '') echo '<p style="color:#4ade80">' . fw_h($msg) . '</p>';

echo '<p><a class="btn" href="new-template.php">New template</a>'
    . ' <a class="btn" style="background:#444" href="new-template.php?id=dpa-standard">Start from DPA standard</a></p>';

foreach (deveryman_pipeline_templates() as $id => $tpl) {
    $isUser = ($tpl['source'] ?? '') === 'user';
    $compiled = deveryman_compile_template($tpl);
    echo '<div class="card">';
    echo '<strong>' . fw_h($tpl['label'] ?? $id) . '</strong> <code>' . fw_h($id) . '</code>';
    echo ' <span class="pill ' . ($isUser ? 'on' : 'off') . '">' . ($isUser ? 'yours' : 'builtin') . '</span>';
    if (!empty($tpl['description'])) echo '<div class="desc">' . fw_h($tpl['description']) . '</div>';
    if ($compiled === null) {
        echo '<div class="meta">No pipeline (bare / Conductor-only project).</div>';
    } else {
        echo '<div class="meta">stages: ' . implode(' &rarr; ', array_map('fw_h', $compiled['stages'])) . '</div>';
        if (!empty($compiled['kickback_target'])) {
            $ks = [];
            foreach ($compiled['kickback_target'] as $from => $to) $ks[] = fw_h((string) $from) . ' &rarr; ' . fw_h((string) $to);
            echo '<div class="meta">kickbacks: ' . implode(', ', $ks) . '</div>';
        }
        echo '<div class="meta">branches: ' . fw_h($compiled['base_branch']) . ' / ' . fw_h($compiled['release_branch'])
            . ' &nbsp;|&nbsp; autonomy ' . (int) $compiled['autonomy_level'] . '</div>';
    }
    $gates = $tpl['gates'] ?? [];
    if ($gates) echo '<div class="meta">gates: ' . fw_h(implode(', ', $gates)) . '</div>';
    echo '<div style="margin-top:8px">';
    echo '<a href="pipeline-builder.php?template=' . fw_h(rawurlencode($id)) . '">Open in builder</a>';
    echo ' &nbsp;|&nbsp; <a href="new-template.php?id=' . fw_h(rawurlencode($id)) . '">'
        . ($isUser ? 'Edit' : 'Clone into a new template') . '</a>';
    echo '</div>';
    echo '</div>';
}

fw_footer();
