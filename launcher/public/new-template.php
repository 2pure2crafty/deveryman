<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../registry.php';
fw_require_auth();

const TEMPLATE_ROWS = 12;   // ordered stage/gate slots in the form-based editor

/** Agent types eligible to be a pipeline node (stages and gates), keyed by id. */
function template_node_types(): array {
    $out = [];
    foreach (deveryman_agent_types() as $id => $t) {
        if (in_array($t['kind'] ?? 'stage', ['stage', 'gate'], true)) $out[$id] = $t;
    }
    return $out;
}

/* ---------- POST: build + validate + save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!fw_csrf_ok()) $errors[] = 'Bad or missing form token; reload and try again.';

    $label = trim($_POST['label'] ?? '');
    $id    = deveryman_slug_id($label);
    if ($id === null) $errors[] = 'Name did not produce a valid id.';
    $existing = deveryman_pipeline_template($id ?? '');
    if ($id !== null && $existing !== null && ($existing['source'] ?? '') === 'builtin') {
        $errors[] = "'{$id}' is a builtin template; pick another name.";
    }

    $types = template_node_types();
    $rowTypes = $_POST['node_type'] ?? [];
    $rowKick  = $_POST['kickback'] ?? [];
    $nodes = []; $flow = []; $gates = []; $prev = null;
    foreach ($rowTypes as $i => $typeId) {
        $typeId = trim((string) $typeId);
        if ($typeId === '' || !isset($types[$typeId])) continue;   // skip empty/invalid rows
        $node = ['id' => $typeId, 'agent_type' => $typeId];
        $kt = trim((string) ($rowKick[$i] ?? ''));
        if ($kt !== '') $node['kickback'] = ['target' => $kt, 'doc' => $types[$typeId]['kickback']['doc'] ?? ''];
        $nodes[] = $node;
        if (($types[$typeId]['kind'] ?? 'stage') === 'gate') $gates[] = $typeId;
        if ($prev !== null) $flow[] = ['from' => $prev, 'to' => $typeId];
        $prev = $typeId;
    }
    if (!$nodes) $errors[] = 'Add at least one stage.';

    $conductor = ($_POST['conductor'] ?? '') === '1';
    $tpl = [
        'label' => $label,
        'description' => trim($_POST['description'] ?? '') ?: $label,
        'conductor' => $conductor,
        'conductor_agents' => $conductor ? ['ideas'] : [],
        'branching' => [
            'base_branch' => trim($_POST['base_branch'] ?? '') ?: 'staging',
            'release_branch' => trim($_POST['release_branch'] ?? '') ?: 'main',
            'feature_branch_prefix' => trim($_POST['feature_branch_prefix'] ?? '') ?: 'feature/',
        ],
        'backlog_file' => trim($_POST['backlog_file'] ?? '') ?: 'product-backlog.md',
        'deployment_note' => trim($_POST['deployment_note'] ?? '') ?: 'dev-inbox/deployment-note.md',
        'autonomy_level' => max(1, min(5, (int) ($_POST['autonomy_level'] ?? 3))),
        'poll_interval' => max(5, (int) ($_POST['poll_interval'] ?? 30)),
        'pipeline_version' => '1',
        'nodes' => $nodes, 'flow' => $flow, 'gates' => $gates,
        'source' => 'user',
    ];

    // Validate the composed graph the same way the daemon will at load time.
    if (!$errors) $errors = array_merge($errors, deveryman_validate_template($tpl));

    if (!$errors) {
        if (deveryman_save_pipeline_template($id, $tpl)) {
            header('Location: templates.php?msg=' . rawurlencode('Saved template: ' . $label));
            exit;
        }
        $errors[] = 'Could not write the template registry (check permissions on launcher/registries/).';
    }

    fw_header('New template', 'templates.php');
    echo '<h1>New pipeline template</h1>';
    foreach ($errors as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
    echo '<a class="back" href="new-template.php">&larr; Back to the editor</a>';
    fw_footer();
    exit;
}

/* ---------- GET: the editor form ---------- */
$editId = $_GET['id'] ?? '';
$pre = ($editId !== '') ? deveryman_pipeline_template($editId) : null;
if ($pre !== null && ($pre['source'] ?? '') === 'builtin') {
    // Builtins are read-only, but they make a fine starting point: prefill, save as new.
    $preIsBuiltin = true;
} else {
    $preIsBuiltin = false;
}

// Reconstruct ordered rows (agent_type + kickback target) from the template.
$rows = [];
if ($pre !== null) {
    $order = deveryman_flow_order($pre['nodes'] ?? [], $pre['flow'] ?? []);
    $byId = [];
    foreach ($pre['nodes'] ?? [] as $n) $byId[$n['id'] ?? ''] = $n;
    foreach ($order as $nid) {
        $n = $byId[$nid] ?? null; if ($n === null) continue;
        $rows[] = ['type' => $n['agent_type'] ?? '', 'kick' => $n['kickback']['target'] ?? ''];
    }
}
$b = $pre['branching'] ?? [];
$g = function (string $k, $d = '') use ($pre) { return $pre[$k] ?? $d; };

fw_header($pre ? 'Edit template' : 'New template', 'templates.php');
echo '<h1>' . ($pre ? 'Pipeline template' : 'New pipeline template') . '</h1>';
echo '<p class="desc">Compose a pipeline by ordering agent types top to bottom; each hands off to '
    . 'the next. Give a stage a kickback target (an earlier node id) if it can send work back. '
    . 'Saving compiles the graph into the underseer config; the same wiring checks the daemon runs '
    . 'are enforced here. (The visual node builder comes later; this is the form version.)</p>';
if ($preIsBuiltin) echo '<p class="meta">Starting from the builtin <strong>' . fw_h((string) $g('label')) . '</strong>. Give it a new name to save your own.</p>';

$types = template_node_types();
echo '<form method="post" action="new-template.php">' . fw_csrf_field();
echo '<label>Name <input type="text" name="label" value="' . fw_h($preIsBuiltin ? '' : (string) $g('label')) . '" placeholder="e.g. Docs pipeline" required></label>';
echo '<label>Description <input type="text" name="description" value="' . fw_h((string) $g('description')) . '" placeholder="One line"></label>';

echo '<h2>Stages, in order</h2>';
echo '<p class="meta">Pick an agent type per slot; empty slots are skipped. A slot\'s kickback target '
    . 'is the id of an earlier node it can route back to (e.g. <code>dev</code>). Node id = agent-type id, '
    . 'so use each type once in this form editor.</p>';
for ($i = 0; $i < TEMPLATE_ROWS; $i++) {
    $curType = $rows[$i]['type'] ?? '';
    $curKick = $rows[$i]['kick'] ?? '';
    echo '<div class="card" style="padding:8px">';
    echo '<select name="node_type[' . $i . ']" style="width:60%"><option value="">(empty)</option>';
    foreach ($types as $tid => $t) {
        $sel = ($tid === $curType) ? ' selected' : '';
        echo '<option value="' . fw_h($tid) . '"' . $sel . '>' . fw_h(($t['label'] ?? $tid) . ' [' . ($t['kind'] ?? 'stage') . ']') . '</option>';
    }
    echo '</select> ';
    echo '<input type="text" name="kickback[' . $i . ']" value="' . fw_h((string) $curKick) . '" placeholder="kickback to (optional)" style="width:35%">';
    echo '</div>';
}

echo '<h2>Branching + gates</h2>';
echo '<label>Base branch <input type="text" name="base_branch" value="' . fw_h((string) ($b['base_branch'] ?? 'staging')) . '"></label>';
echo '<label>Release branch <input type="text" name="release_branch" value="' . fw_h((string) ($b['release_branch'] ?? 'main')) . '"></label>';
echo '<label>Feature branch prefix <input type="text" name="feature_branch_prefix" value="' . fw_h((string) ($b['feature_branch_prefix'] ?? 'feature/')) . '"></label>';
echo '<label>Backlog file <input type="text" name="backlog_file" value="' . fw_h((string) $g('backlog_file', 'product-backlog.md')) . '"></label>';
echo '<label>Deployment note <input type="text" name="deployment_note" value="' . fw_h((string) $g('deployment_note', 'dev-inbox/deployment-note.md')) . '"></label>';
echo '<label>Autonomy level (1-5) <input type="number" name="autonomy_level" min="1" max="5" value="' . (int) $g('autonomy_level', 3) . '"></label>';
echo '<label>Poll interval (s) <input type="number" name="poll_interval" min="5" value="' . (int) $g('poll_interval', 30) . '"></label>';
$condChecked = ($pre === null || !empty($g('conductor'))) ? ' checked' : '';
echo '<label><input type="checkbox" name="conductor" value="1" style="width:auto"' . $condChecked . '> Enable Conductor (seed an ideas agent)</label>';

echo '<button class="btn" type="submit" style="margin-top:12px">Save template</button></form>';
fw_footer();
