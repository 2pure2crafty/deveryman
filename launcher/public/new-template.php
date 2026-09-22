<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../registry.php';
fw_require_auth();
if ($_SERVER['REQUEST_METHOD'] === 'POST') fw_demo_block('Saving');

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
    $rowIds   = $_POST['node_id'] ?? [];
    $rowKick  = $_POST['kickback'] ?? [];
    $rowExtra = $_POST['node_extra'] ?? [];
    $nodes = []; $flow = []; $gates = []; $prev = null;
    foreach ($rowTypes as $i => $typeId) {
        $typeId = trim((string) $typeId);
        if ($typeId === '' || !isset($types[$typeId])) continue;   // skip empty/invalid rows
        // Node id defaults to the type id; give repeats a distinct id (e.g. a second
        // reviewer as `reviewer-final`) so the same type can sit in more than one spot.
        $nid = deveryman_slug_id(trim((string) ($rowIds[$i] ?? ''))) ?? $typeId;
        $node = ['id' => $nid, 'agent_type' => $typeId];
        $extra = trim((string) ($rowExtra[$i] ?? ''));
        if ($extra !== '') $node['extra_instructions'] = $extra;   // per-pipeline tweak
        $kt = deveryman_slug_id(trim((string) ($rowKick[$i] ?? '')));
        if ($kt !== null) {
            $node['kickback'] = ['target' => $kt,
                'doc' => $types[$typeId]['kickback_doc'] ?? ('dev-inbox/' . $nid . '-feedback.md')];
        }
        $nodes[] = $node;
        if (($types[$typeId]['kind'] ?? 'stage') === 'gate') $gates[] = $nid;
        if ($prev !== null) $flow[] = ['from' => $prev, 'to' => $nid];
        $prev = $nid;
    }
    if (!$nodes) $errors[] = 'Add at least one stage.';
    $lastMain = $prev;   // the end of the shared front; branches fork from here

    // Optional escalation chain: a second run of agents a feature is routed onto after
    // repeated same-spot failure on the main chain. Built from its own rows; sequential.
    $escTypes = $_POST['esc_node_type'] ?? [];
    $escIds   = $_POST['esc_node_id'] ?? [];
    $escKick  = $_POST['esc_kickback'] ?? [];
    $escHead = null; $escPrev = null;
    foreach ($escTypes as $i => $typeId) {
        $typeId = trim((string) $typeId);
        if ($typeId === '' || !isset($types[$typeId])) continue;
        $nid = deveryman_slug_id(trim((string) ($escIds[$i] ?? ''))) ?? ('esc-' . $typeId);
        $node = ['id' => $nid, 'agent_type' => $typeId, 'chain' => 'escalation'];
        $kt = deveryman_slug_id(trim((string) ($escKick[$i] ?? '')));
        if ($kt !== null) {
            $node['kickback'] = ['target' => $kt,
                'doc' => $types[$typeId]['kickback_doc'] ?? ('dev-inbox/' . $nid . '-feedback.md')];
        }
        $nodes[] = $node;
        if ($escHead === null) $escHead = $nid;
        if ($escPrev !== null) $flow[] = ['from' => $escPrev, 'to' => $nid];
        $escPrev = $nid;
    }
    // Wire the entry: every main stage that can kick back escalates to the chain head
    // after `threshold` same-spot failures. (The form wires one shared chain; the
    // template JSON / visual builder can target different chains per stage.)
    $threshold = max(0, (int) ($_POST['esc_threshold'] ?? 0));
    if ($escHead !== null && $threshold > 0) {
        foreach ($nodes as &$n) {
            if (($n['chain'] ?? 'main') === 'main' && !empty($n['kickback']['target'])) {
                $n['kickback']['escalation_target'] = $escHead;
                $n['kickback']['fail_threshold'] = $threshold;
            }
        }
        unset($n);
    }

    // Tag branching is OFF unless explicitly enabled; enabling it requires a tagger
    // stage (the node that classifies each work order and assigns its tag). Branch
    // tags are chosen from the vocabulary, or added inline (which registers them).
    $branchingOn = ($_POST['branching_on'] ?? '') === '1';
    $tagStage = '';
    $branches = [];   // each: ['tag'=>, 'head'=>, 'tail'=>]
    if ($branchingOn) {
        $tagStage = deveryman_slug_id(trim((string) ($_POST['tag_stage'] ?? ''))) ?? '';
        $brSelTag = $_POST['branch_tag'] ?? [];      // chosen from the vocabulary
        $brNewTag = $_POST['branch_newtag'] ?? [];   // or a new tag typed inline
        $brTypes  = $_POST['branch_type'] ?? [];
        $brIds    = $_POST['branch_id'] ?? [];
        $brKick   = $_POST['branch_kick'] ?? [];
        foreach ($brTypes as $b => $rows) {
            // Resolve the block's tag: an inline new tag wins (and is registered),
            // otherwise the dropdown choice.
            $newLabel = trim((string) ($brNewTag[$b] ?? ''));
            $newId = deveryman_slug_id($newLabel);
            if ($newId !== null) { deveryman_save_tag($newId, ['label' => $newLabel, 'description' => '']); $tag = $newId; }
            else $tag = trim((string) ($brSelTag[$b] ?? ''));
            $head = null; $bprev = null;
            foreach ((array) $rows as $r => $typeId) {
                $typeId = trim((string) $typeId);
                if ($typeId === '' || !isset($types[$typeId])) continue;
                $nid = deveryman_slug_id(trim((string) ($brIds[$b][$r] ?? ''))) ?? (($tag !== '' ? $tag : 'br') . '-' . $typeId);
                $node = ['id' => $nid, 'agent_type' => $typeId];
                $kt = deveryman_slug_id(trim((string) ($brKick[$b][$r] ?? '')));
                if ($kt !== null) {
                    $node['kickback'] = ['target' => $kt,
                        'doc' => $types[$typeId]['kickback_doc'] ?? ('dev-inbox/' . $nid . '-feedback.md')];
                }
                $nodes[] = $node;
                if ($head === null) $head = $nid;
                if ($bprev !== null) $flow[] = ['from' => $bprev, 'to' => $nid];
                $bprev = $nid;
            }
            if ($head !== null) {
                if ($tag === '') $errors[] = 'A branch has stages but no tag; pick or add one, or clear the branch.';
                $branches[] = ['tag' => $tag, 'head' => $head, 'tail' => $bprev];
            }
        }
        if ($branches && $tagStage === '') {
            $errors[] = 'Tag branching is on but no tagger stage is set. Name the stage (e.g. the first '
                . 'one) that classifies each work order and writes its tag; without it, branches are dead.';
        }
    }

    // Merge node: the rejoin/end. Wanted if any branch exists (they must rejoin) or
    // the box is ticked. Fork edges leave the front: one guarded edge per branch, plus
    // a guardless default straight to merge for untagged/unmatched features.
    $mergeWanted = $branches || (($_POST['end_merge'] ?? '') === '1');
    if ($mergeWanted && $lastMain !== null) {
        $nodes[] = ['id' => 'merge', 'agent_type' => 'merge'];
        foreach ($branches as $br) {
            $edge = ['from' => $lastMain, 'to' => $br['head']];
            if ($br['tag'] !== '') $edge['when'] = ['tag' => $br['tag']];
            $flow[] = $edge;
            $flow[] = ['from' => $br['tail'], 'to' => 'merge'];
        }
        $flow[] = ['from' => $lastMain, 'to' => 'merge'];
    }

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
    if ($branchingOn && $tagStage !== '') $tpl['tag_stage'] = $tagStage;

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

// Reconstruct the editor from a saved template: the shared front (guardless spine),
// the escalation chain, the tag branches (guarded edges), and whether a merge exists.
$rows = []; $escRows = []; $escThreshold = 0; $branchBlocks = []; $mergeChecked = ($pre === null);
$tagStageVal = $pre['tag_stage'] ?? '';
if ($pre !== null) {
    $flowAll = $pre['flow'] ?? [];
    $byId = [];
    foreach ($pre['nodes'] ?? [] as $n) $byId[$n['id'] ?? ''] = $n;
    // The merge node + escalation nodes are handled separately from the front rows.
    $mergeId = null;
    foreach ($pre['nodes'] ?? [] as $n) if (($n['agent_type'] ?? '') === 'merge') { $mergeId = $n['id'] ?? 'merge'; break; }
    $mergeChecked = $mergeId !== null;
    $firstOut = function (string $from, bool $guarded) use ($flowAll) {
        foreach ($flowAll as $e) {
            if (($e['from'] ?? '') !== $from) continue;
            if ($guarded === !empty($e['when'])) return $e;
        }
        return null;
    };
    // Front spine: from the first node, follow guardless edges (stopping at merge).
    $mainNodes = [];
    foreach ($pre['nodes'] ?? [] as $n) {
        if (($n['chain'] ?? 'main') === 'escalation') continue;
        if (($n['agent_type'] ?? '') === 'merge') continue;
        $mainNodes[$n['id'] ?? ''] = true;
    }
    $targets = [];
    foreach ($flowAll as $e) if (empty($e['when'])) $targets[$e['to'] ?? ''] = true;
    $start = null;
    foreach ($mainNodes as $mid => $_) if (!isset($targets[$mid])) { $start = $mid; break; }
    $seen = []; $cur = $start;
    while ($cur !== null && isset($mainNodes[$cur]) && !isset($seen[$cur])) {
        $seen[$cur] = true;
        $n = $byId[$cur];
        $rows[] = ['type' => $n['agent_type'] ?? '', 'id' => $n['id'] ?? '',
                   'kick' => $n['kickback']['target'] ?? '', 'extra' => $n['extra_instructions'] ?? ''];
        if (!empty($n['kickback']['fail_threshold'])) $escThreshold = (int) $n['kickback']['fail_threshold'];
        $ne = $firstOut($cur, false);
        $cur = $ne['to'] ?? null;
    }
    // Branches: each guarded edge out of the front starts a branch chain; walk it to
    // the merge (or its end), collecting its rows and its tag.
    foreach ($flowAll as $e) {
        if (empty($e['when']['tag'])) continue;
        $tag = $e['when']['tag']; $brows = []; $bcur = $e['to'] ?? null; $guard = 0;
        while ($bcur !== null && $bcur !== $mergeId && isset($byId[$bcur]) && $guard++ < 30) {
            $bn = $byId[$bcur];
            $brows[] = ['type' => $bn['agent_type'] ?? '', 'id' => $bn['id'] ?? '', 'kick' => $bn['kickback']['target'] ?? ''];
            $ne = $firstOut($bcur, false);
            $bcur = $ne['to'] ?? null;
        }
        $branchBlocks[] = ['tag' => $tag, 'rows' => $brows];
    }
    // Escalation rows (chain = escalation), ordered by their sub-flow.
    $escIds = [];
    foreach ($pre['nodes'] ?? [] as $n) if (($n['chain'] ?? 'main') === 'escalation') $escIds[$n['id'] ?? ''] = true;
    $subEsc = array_values(array_filter($flowAll, fn($e) => isset($escIds[$e['from'] ?? ''], $escIds[$e['to'] ?? ''])));
    foreach (deveryman_flow_order(array_values(array_intersect_key($byId, $escIds)), $subEsc) as $nid) {
        $n = $byId[$nid] ?? null; if ($n === null) continue;
        $escRows[] = ['type' => $n['agent_type'] ?? '', 'id' => $n['id'] ?? '', 'kick' => $n['kickback']['target'] ?? ''];
    }
}
$branchingOn = $tagStageVal !== '' || !empty($branchBlocks);
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
echo '<p class="meta">Pick an agent type per slot; empty slots are skipped. Node id defaults to the '
    . 'type id; to place the same type more than once, give the repeats a distinct id (e.g. a second '
    . 'reviewer as <code>reviewer-final</code>). Kickback target is the id of an earlier node this one '
    . 'can route back to (e.g. <code>dev</code>); every review/test stage should wire one.</p>';
for ($i = 0; $i < TEMPLATE_ROWS; $i++) {
    $curType = $rows[$i]['type'] ?? '';
    $curId   = $rows[$i]['id'] ?? '';
    $curKick = $rows[$i]['kick'] ?? '';
    echo '<div class="card" style="padding:8px">';
    echo '<select name="node_type[' . $i . ']" style="width:40%"><option value="">(empty)</option>';
    foreach ($types as $tid => $t) {
        $sel = ($tid === $curType) ? ' selected' : '';
        echo '<option value="' . fw_h($tid) . '"' . $sel . '>' . fw_h(($t['label'] ?? $tid) . ' [' . ($t['kind'] ?? 'stage') . ']') . '</option>';
    }
    echo '</select> ';
    echo '<input type="text" name="node_id[' . $i . ']" value="' . fw_h((string) $curId) . '" placeholder="node id (optional)" style="width:27%"> ';
    echo '<input type="text" name="kickback[' . $i . ']" value="' . fw_h((string) $curKick) . '" placeholder="kickback to" style="width:27%">';
    $curExtra = $rows[$i]['extra'] ?? '';
    echo '<input type="text" name="node_extra[' . $i . ']" value="' . fw_h((string) $curExtra) . '" placeholder="extra instructions for this pipeline (optional)" style="width:100%;margin-top:6px">';
    echo '</div>';
}

echo '<h2>Escalation chain (optional)</h2>';
echo '<p class="meta">A second run of agents a feature is routed onto when it keeps failing at the '
    . 'same spot, before it ever reaches you. Leave empty for none. Fill some slots and set the '
    . 'threshold: any main stage that can kick back will route here after that many same-spot failures, '
    . 'and only if the chain also fails does it escalate to you.</p>';
$escThresholdVal = $escThreshold ?: '';
echo '<label>Escalate to the chain after this many same-spot failures '
    . '<input type="number" name="esc_threshold" min="0" value="' . fw_h((string) $escThresholdVal) . '" placeholder="0 = off"></label>';
$escRowCount = 4;
for ($i = 0; $i < $escRowCount; $i++) {
    $curType = $escRows[$i]['type'] ?? '';
    $curId   = $escRows[$i]['id'] ?? '';
    $curKick = $escRows[$i]['kick'] ?? '';
    echo '<div class="card" style="padding:8px">';
    echo '<select name="esc_node_type[' . $i . ']" style="width:40%"><option value="">(empty)</option>';
    foreach ($types as $tid => $t) {
        $sel = ($tid === $curType) ? ' selected' : '';
        echo '<option value="' . fw_h($tid) . '"' . $sel . '>' . fw_h(($t['label'] ?? $tid) . ' [' . ($t['kind'] ?? 'stage') . ']') . '</option>';
    }
    echo '</select> ';
    echo '<input type="text" name="esc_node_id[' . $i . ']" value="' . fw_h((string) $curId) . '" placeholder="node id (optional)" style="width:27%"> ';
    echo '<input type="text" name="esc_kickback[' . $i . ']" value="' . fw_h((string) $curKick) . '" placeholder="kickback to" style="width:27%">';
    echo '</div>';
}

echo '<h2>Tag branching (off by default)</h2>';
echo '<div class="card" style="border-color:#5a4b1f;background:#25200f">';
echo '<strong>Before you turn this on.</strong>';
echo '<p class="desc">A single linear pipeline needs none of this. Turn on branching only if different '
    . 'kinds of work order should take different paths (a UI change, a back-end change, a bug fix). If you do:</p>';
echo '<ul class="desc" style="margin:6px 0 0 18px">';
echo '<li>You must name a <strong>tagger stage</strong>: the stage (or the person filling the work order) '
    . 'that classifies each item and writes its tag. The daemon hands that stage the allowed tags and it '
    . 'sets one in the build-queue Tag column.</li>';
echo '<li>Branches route on tags from the shared <a href="tags.php">vocabulary</a> (pick from the list, or '
    . 'add one inline). No free-typed tags: a typo would be a dead branch.</li>';
echo '<li>If a work order reaches a fork with a tag that matches no branch (or none at all), it takes the '
    . 'default straight to merge if there is one, otherwise the daemon escalates it to you.</li>';
echo '</ul>';
$brOnAttr = $branchingOn ? ' checked' : '';
echo '<label style="margin-top:10px"><input type="checkbox" name="branching_on" value="1" style="width:auto"' . $brOnAttr . '> '
    . 'Enable tag branching (I understand the above)</label>';
echo '<label>Tagger stage (node id that sets each work order\'s tag) '
    . '<input type="text" name="tag_stage" value="' . fw_h((string) $tagStageVal) . '" placeholder="e.g. features"></label>';
echo '</div>';

$allTags = deveryman_tags();
$branchCount = 3; $branchRowCount = 3;
for ($bk = 0; $bk < $branchCount; $bk++) {
    $blk = $branchBlocks[$bk] ?? ['tag' => '', 'rows' => []];
    echo '<div class="card" style="padding:8px">';
    echo '<div>Branch tag: <select name="branch_tag[' . $bk . ']" style="width:45%"><option value="">(none)</option>';
    foreach ($allTags as $tid => $t) {
        $sel = ($tid === $blk['tag']) ? ' selected' : '';
        echo '<option value="' . fw_h($tid) . '"' . $sel . '>' . fw_h(($t['label'] ?? $tid) . ' (' . $tid . ')') . '</option>';
    }
    echo '</select> ';
    echo '<input type="text" name="branch_newtag[' . $bk . ']" value="" placeholder="or add a new tag" style="width:45%"></div>';
    for ($r = 0; $r < $branchRowCount; $r++) {
        $curType = $blk['rows'][$r]['type'] ?? '';
        $curId   = $blk['rows'][$r]['id'] ?? '';
        $curKick = $blk['rows'][$r]['kick'] ?? '';
        echo '<div style="margin-top:4px">';
        echo '<select name="branch_type[' . $bk . '][' . $r . ']" style="width:40%"><option value="">(empty)</option>';
        foreach ($types as $tid => $t) {
            $sel = ($tid === $curType) ? ' selected' : '';
            echo '<option value="' . fw_h($tid) . '"' . $sel . '>' . fw_h(($t['label'] ?? $tid) . ' [' . ($t['kind'] ?? 'stage') . ']') . '</option>';
        }
        echo '</select> ';
        echo '<input type="text" name="branch_id[' . $bk . '][' . $r . ']" value="' . fw_h((string) $curId) . '" placeholder="node id" style="width:27%"> ';
        echo '<input type="text" name="branch_kick[' . $bk . '][' . $r . ']" value="' . fw_h((string) $curKick) . '" placeholder="kickback to" style="width:27%">';
        echo '</div>';
    }
    echo '</div>';
}

echo '<h2>Branching + gates</h2>';
$mergeAttr = $mergeChecked ? ' checked' : '';
echo '<label><input type="checkbox" name="end_merge" value="1" style="width:auto"' . $mergeAttr . '> '
    . 'End with a merge stage (the daemon merges the feature into base there; required if you use branches)</label>';
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
