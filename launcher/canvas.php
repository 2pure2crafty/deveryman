<?php
declare(strict_types=1);
/*
 * Canvas model: resolve a pipeline template into the flat shape the builder's
 * JavaScript adapter draws. All the model knowledge (agent types, kinds, I/O,
 * kickback/escalation, tag routing) is resolved here, server-side, reusing
 * registry.php; the adapter stays a dumb renderer. Read-only.
 */

require_once __DIR__ . '/registry.php';

/**
 * Build the canvas model for a template id. Returns null if unknown. Shape:
 *   { id, label, tag_stage, backlog_file,
 *     nodes: [ {id,label,agent_type,kind,chain,reads[],writes[],
 *               kickback:{target,doc,escalation_target,fail_threshold}|null,
 *               is_tagger:bool} ],
 *     flow:  [ {from,to,when:{tag}|null} ],   # forward edges
 *     gates: [id], merge_stages: [id] }
 * A synthetic `product` feeder is prepended when the template has a backlog file but
 * no explicit feeder node, so the canvas shows the full front of the pipeline.
 */
function deveryman_canvas_model(string $tplId): ?array {
    $tpl = deveryman_pipeline_template($tplId);
    if ($tpl === null) return null;
    $types = deveryman_agent_types();

    $nodes = []; $haveFeeder = false; $stageIds = [];
    foreach ($tpl['nodes'] ?? [] as $n) {
        $r = deveryman_resolve_node($n, $types);
        if ($r === null) continue;
        if ($r['kind'] === 'feeder') $haveFeeder = true;
        if ($r['kind'] === 'stage') $stageIds[] = $r['id'];
        $nodes[] = [
            'id' => $r['id'],
            'label' => $types[$r['agent_type']]['label'] ?? $r['id'],
            'agent_type' => $r['agent_type'],
            'kind' => $r['kind'],
            'chain' => $r['chain'],
            'reads' => array_values($r['reads']),
            'writes' => array_values($r['writes']),
            'kickback' => $r['kickback'],
            'extra_instructions' => $n['extra_instructions'] ?? '',
            'branch_tag' => '',   // filled from the incoming guarded edge below
            'pos' => (isset($n['pos']) && is_array($n['pos'])) ? $n['pos'] : null,
            'is_tagger' => ($tpl['tag_stage'] ?? '') === $r['id'],
        ];
    }

    $flow = [];
    foreach ($tpl['flow'] ?? [] as $e) {
        if (empty($e['from']) || empty($e['to'])) continue;
        $flow[] = ['from' => $e['from'], 'to' => $e['to'], 'when' => $e['when'] ?? null];
    }
    // A node's branch tag is the guard on its incoming flow edge (edited per node in
    // the builder; on save it becomes the edge guard again).
    $byNodeId = [];
    foreach ($nodes as $k => $nn) $byNodeId[$nn['id']] = $k;
    foreach ($flow as $e) {
        if (!empty($e['when']['tag']) && isset($byNodeId[$e['to']])) {
            $nodes[$byNodeId[$e['to']]]['branch_tag'] = $e['when']['tag'];
        }
    }

    // Synthesize the product feeder at the front if the template feeds from a backlog
    // but has no explicit feeder node (the daemon runs it as an aux agent).
    $backlog = $tpl['backlog_file'] ?? 'product-backlog.md';
    if (!$haveFeeder && $stageIds) {
        $incoming = [];
        foreach ($flow as $e) $incoming[$e['to']] = true;
        $head = null;
        foreach ($stageIds as $sid) if (!isset($incoming[$sid])) { $head = $sid; break; }
        $head = $head ?? $stageIds[0];
        array_unshift($nodes, [
            'id' => 'product', 'label' => 'Product', 'agent_type' => 'product',
            'kind' => 'feeder', 'chain' => 'main',
            'reads' => [$backlog], 'writes' => ['build-queue.md'],
            'kickback' => null, 'extra_instructions' => '', 'branch_tag' => '', 'pos' => null,
            'is_tagger' => ($tpl['tag_stage'] ?? '') === 'product',
            'synthetic' => true,   // a view-only feeder; not persisted on save
        ]);
        $flow[] = ['from' => 'product', 'to' => $head, 'when' => null];
    }

    $merge = [];
    foreach ($nodes as $n) if ($n['kind'] === 'merge') $merge[] = $n['id'];

    $b = $tpl['branching'] ?? [];
    return [
        'id' => $tplId,
        'label' => $tpl['label'] ?? $tplId,
        'source' => $tpl['source'] ?? 'user',
        'tag_stage' => $tpl['tag_stage'] ?? '',
        'backlog_file' => $backlog,
        'nodes' => $nodes,
        'flow' => $flow,
        'gates' => array_values($tpl['gates'] ?? []),
        'merge_stages' => $merge,
        'meta' => [
            'base_branch' => $b['base_branch'] ?? 'staging',
            'release_branch' => $b['release_branch'] ?? 'main',
            'feature_branch_prefix' => $b['feature_branch_prefix'] ?? 'feature/',
            'deployment_note' => $tpl['deployment_note'] ?? 'dev-inbox/deployment-note.md',
            'autonomy_level' => (int) ($tpl['autonomy_level'] ?? 3),
            'poll_interval' => (int) ($tpl['poll_interval'] ?? 30),
            'conductor' => (bool) ($tpl['conductor'] ?? false),
            'conductor_agents' => $tpl['conductor_agents'] ?? [],
        ],
    ];
}

/** Agent types for the builder palette (add-node menu): id, label, kind, I/O. */
function deveryman_canvas_palette(): array {
    $out = [];
    foreach (deveryman_agent_types() as $id => $t) {
        $out[] = [
            'id' => $id,
            'label' => $t['label'] ?? $id,
            'kind' => $t['kind'] ?? 'stage',
            'reads' => array_values($t['reads'] ?? []),
            'writes' => array_values($t['writes'] ?? []),
            'kickback_doc' => $t['kickback_doc'] ?? null,
        ];
    }
    return $out;
}
