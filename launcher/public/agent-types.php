<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../registry.php';
fw_require_auth();

fw_header('Agent types', '/');
echo '<h1>Agent types</h1>';
echo '<p class="desc">The library of reusable agents a pipeline template can pick from. '
    . 'Builtins are the DPA-standard roles; the ones you save are yours to reuse and edit.</p>';

$msg = $_GET['msg'] ?? '';
if ($msg !== '') echo '<p style="color:#4ade80">' . fw_h($msg) . '</p>';

echo '<p><a class="btn" href="new-agent-type.php">New agent type</a></p>';

$kindPill = ['stage' => 'on', 'gate' => 'off', 'feeder' => 'off', 'conductor' => 'off'];
foreach (deveryman_agent_types() as $id => $t) {
    $isUser = ($t['source'] ?? '') === 'user';
    echo '<div class="card">';
    echo '<strong>' . fw_h($t['label'] ?? $id) . '</strong> <code>' . fw_h($id) . '</code>';
    $kind = $t['kind'] ?? 'stage';
    echo ' <span class="pill ' . ($kindPill[$kind] ?? 'off') . '">' . fw_h($kind) . '</span>';
    echo ' <span class="pill ' . ($isUser ? 'on' : 'off') . '">' . ($isUser ? 'yours' : 'builtin') . '</span>';
    $summary = $t['role']['summary'] ?? '';
    if ($summary === '' && !empty($t['role']['ref'])) $summary = 'Builtin role: ' . $t['role']['ref'];
    if ($summary !== '') echo '<div class="desc">' . fw_h($summary) . '</div>';
    $reads  = array_filter($t['reads'] ?? []);
    $writes = array_filter($t['writes'] ?? []);
    echo '<div class="meta">reads: ' . fw_h($reads ? implode(', ', $reads) : 'none')
        . ' &nbsp;|&nbsp; writes: ' . fw_h($writes ? implode(', ', $writes) : 'none') . '</div>';
    if (!empty($t['kickback']['target'])) {
        echo '<div class="meta">kickback to <code>' . fw_h($t['kickback']['target']) . '</code>'
            . (!empty($t['kickback']['doc']) ? ' via ' . fw_h($t['kickback']['doc']) : '') . '</div>';
    }
    echo '<div style="margin-top:8px">';
    if ($isUser) echo '<a href="new-agent-type.php?id=' . fw_h(rawurlencode($id)) . '">Edit</a> &nbsp;|&nbsp; ';
    echo '<a href="new-agent-type.php?from=' . fw_h(rawurlencode($id)) . '">Clone into a new type</a>';
    echo '</div>';
    echo '</div>';
}

fw_footer();
