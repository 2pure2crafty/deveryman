<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../canvas.php';   // pulls in registry.php
fw_require_auth();
header('Content-Type: application/json');

function fail(array $errors): void { echo json_encode(['ok' => false, 'errors' => $errors]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(['POST only.']);
if (!fw_csrf_ok()) fail(['Bad or missing form token; reload the builder.']);

$payload = json_decode($_POST['payload'] ?? '', true);
if (!is_array($payload)) fail(['Malformed payload.']);

$label = trim((string) ($payload['label'] ?? ''));
$id = deveryman_slug_id($label);
if ($id === null) fail(['Name did not produce a valid id.']);
$existing = deveryman_pipeline_template($id);
if ($existing !== null && ($existing['source'] ?? '') === 'builtin') {
    fail(["'{$id}' is a builtin template; give it a new name to save your own."]);
}

$types = deveryman_agent_types();
$nodes = []; $branchTag = []; $seen = [];
foreach ($payload['nodes'] ?? [] as $pn) {
    if (!empty($pn['synthetic'])) continue;   // the view-only product feeder
    $nid = deveryman_slug_id((string) ($pn['id'] ?? ''));
    $at = (string) ($pn['agent_type'] ?? '');
    if ($nid === null || !isset($types[$at])) continue;
    if (isset($seen[$nid])) fail(["Duplicate node id '{$nid}'."]);
    $seen[$nid] = true;

    $node = ['id' => $nid, 'agent_type' => $at];
    if (($pn['chain'] ?? 'main') === 'escalation') $node['chain'] = 'escalation';
    $ei = trim((string) ($pn['extra_instructions'] ?? ''));
    if ($ei !== '') $node['extra_instructions'] = $ei;
    $kb = $pn['kickback'] ?? null;
    if (is_array($kb) && !empty($kb['target'])) {
        $t = deveryman_slug_id((string) $kb['target']);
        if ($t !== null) {
            $node['kickback'] = ['target' => $t, 'doc' => trim((string) ($kb['doc'] ?? ''))];
            $et = !empty($kb['escalation_target']) ? deveryman_slug_id((string) $kb['escalation_target']) : null;
            if ($et !== null) {
                $node['kickback']['escalation_target'] = $et;
                $node['kickback']['fail_threshold'] = max(1, (int) ($kb['fail_threshold'] ?? 2));
            }
        }
    }
    if (isset($pn['pos']) && is_array($pn['pos']) && count($pn['pos']) === 2) {
        $node['pos'] = [(float) $pn['pos'][0], (float) $pn['pos'][1]];
    }
    $nodes[] = $node;
    $bt = deveryman_slug_id((string) ($pn['branch_tag'] ?? ''));
    if ($bt !== null) $branchTag[$nid] = $bt;
}
if (!$nodes) fail(['Add at least one node.']);

$flow = [];
foreach ($payload['flow'] ?? [] as $e) {
    $from = deveryman_slug_id((string) ($e['from'] ?? ''));
    $to = deveryman_slug_id((string) ($e['to'] ?? ''));
    if ($from === null || $to === null || !isset($seen[$from], $seen[$to])) continue;
    $edge = ['from' => $from, 'to' => $to];
    if (!empty($branchTag[$to])) $edge['when'] = ['tag' => $branchTag[$to]];
    $flow[] = $edge;
}

$gates = [];
foreach ($nodes as $n) if (($types[$n['agent_type']]['kind'] ?? '') === 'gate') $gates[] = $n['id'];

$m = $payload['meta'] ?? [];
$tpl = [
    'label' => $label,
    'description' => trim((string) ($payload['description'] ?? '')) ?: $label,
    'conductor' => (bool) ($m['conductor'] ?? false),
    'conductor_agents' => is_array($m['conductor_agents'] ?? null) ? $m['conductor_agents'] : [],
    'branching' => [
        'base_branch' => trim((string) ($m['base_branch'] ?? '')) ?: 'staging',
        'release_branch' => trim((string) ($m['release_branch'] ?? '')) ?: 'main',
        'feature_branch_prefix' => trim((string) ($m['feature_branch_prefix'] ?? '')) ?: 'feature/',
    ],
    'backlog_file' => trim((string) ($payload['backlog_file'] ?? '')) ?: 'product-backlog.md',
    'deployment_note' => trim((string) ($m['deployment_note'] ?? '')) ?: 'dev-inbox/deployment-note.md',
    'autonomy_level' => max(1, min(5, (int) ($m['autonomy_level'] ?? 3))),
    'poll_interval' => max(5, (int) ($m['poll_interval'] ?? 30)),
    'pipeline_version' => '1',
    'nodes' => $nodes, 'flow' => $flow, 'gates' => $gates,
    'source' => 'user',
];
$ts = deveryman_slug_id((string) ($payload['tag_stage'] ?? ''));
if ($ts !== null && isset($seen[$ts])) $tpl['tag_stage'] = $ts;

$errors = deveryman_validate_template($tpl);
if ($errors) fail($errors);

if (!deveryman_save_pipeline_template($id, $tpl)) {
    fail(['Could not write the template registry (check permissions on launcher/registries/).']);
}
echo json_encode(['ok' => true, 'id' => $id,
    'redirect' => 'templates.php?msg=' . rawurlencode('Saved template: ' . $label)]);
