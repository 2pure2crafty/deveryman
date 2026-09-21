<?php
declare(strict_types=1);
/*
 * The customization registries: agent types and pipeline templates, as
 * user-editable JSON instead of hardcoded PHP.
 *
 * Two data files live under launcher/registries/ (created on first save):
 *   agent-types.json       a library of reusable agent types (a role + its I/O)
 *   pipeline-templates.json a library of pipeline templates (nodes + flow)
 *
 * A pipeline template is the model from docs/PIPELINE-MODEL.md: nodes (agents),
 * flow edges (sequence), and kickback edges. It COMPILES down (deveryman_compile_
 * template) to the lossy project.json shape the underseer already runs: an ordered
 * `stages` list, a `kickback_target` map, and the per-stage `io` wiring. Nobody
 * hand-edits the underseer config; the template is the source of truth.
 *
 * Builtins (DPA standard, and the "none" bare project) are defined here as seeds.
 * The JSON files start absent; the loaders return the seed until the first user
 * save, which materializes the seed to disk and adds the user's entry alongside it.
 *
 * Assumes the shared framework is already required (fw_write_json_atomic,
 * fw_update_json).
 */

/** Directory holding the registry JSON files (created lazily on first save). */
function deveryman_registry_dir(): string {
    return __DIR__ . '/registries';
}

function deveryman_agent_types_path(): string {
    return deveryman_registry_dir() . '/agent-types.json';
}

function deveryman_pipeline_templates_path(): string {
    return deveryman_registry_dir() . '/pipeline-templates.json';
}

/**
 * Lowercase [a-z0-9-] id for a registry entry, or null if none is derivable.
 * Named distinctly from lib.php's deveryman_slugify so registry.php can be required
 * on its own (the two share logic but must not redeclare).
 */
function deveryman_slug_id(string $s): ?string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return ($s !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $s)) ? $s : null;
}

/** Split a textarea value into a trimmed, non-empty list (one item per line). */
function deveryman_lines(string $s): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $s) as $line) {
        $line = trim($line);
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

/* --- agent types ----------------------------------------------------------- */

/**
 * The builtin agent types, keyed by id. Each is:
 *   label        human name
 *   kind         feeder | stage | gate | conductor
 *   role         { ref: "<dir under dpa/agents or conductor/agent-templates>" }
 *                for builtins, or { summary, dos[], donts[], freeform } for a
 *                user-authored role (rendered to a CLAUDE.md by deveryman_render_role)
 *   reads        string[] docs-relative input files (<slug> = the feature slug)
 *   writes       string[] docs-relative output files (an agent may have several)
 *   done_signal  how the agent tells the underseer it finished
 *   kickback     { target, doc } | null  (a default; a template node may override)
 *   source       "builtin" | "user"
 *
 * These reproduce the DPA-standard I/O that launcher/templates.php used to hardcode.
 * Only the kickbacks that DPA standard actually wires are set (all to `dev`);
 * features/acceptance/dev carry no kickback, matching the live kickback_target.
 */
function deveryman_agent_type_seed(): array {
    $sig = 'set the pipeline-state Stage status to COMPLETE';
    return [
        'product' => [
            'label' => 'Product', 'kind' => 'feeder', 'role' => ['ref' => 'dpa/product'],
            'reads' => ['product-backlog.md'], 'writes' => ['build-queue.md'],
            'done_signal' => 'build-queue rows written; backlog rows marked PROCESSED',
            'kickback' => null, 'source' => 'builtin',
        ],
        'features' => [
            'label' => 'Features', 'kind' => 'stage', 'role' => ['ref' => 'dpa/features'],
            'reads' => [], 'writes' => ['dev-inbox/build-phase.md'],
            'done_signal' => $sig, 'kickback' => null, 'source' => 'builtin',
        ],
        'acceptance' => [
            'label' => 'Acceptance', 'kind' => 'stage', 'role' => ['ref' => 'dpa/acceptance'],
            'reads' => ['dev-inbox/build-phase.md'], 'writes' => ['acceptance/<slug>-criteria.md'],
            'done_signal' => $sig, 'kickback' => null, 'source' => 'builtin',
        ],
        'dev' => [
            'label' => 'Dev', 'kind' => 'stage', 'role' => ['ref' => 'dpa/dev'],
            'reads' => ['dev-inbox/build-phase.md'], 'writes' => [],
            'done_signal' => $sig, 'kickback' => null, 'source' => 'builtin',
        ],
        'testing-staging' => [
            'label' => 'Testing (staging)', 'kind' => 'stage', 'role' => ['ref' => 'dpa/testing-staging'],
            'reads' => ['acceptance/<slug>-criteria.md', 'dev-inbox/build-phase.md'], 'writes' => [],
            'done_signal' => $sig,
            'kickback' => ['target' => 'dev', 'doc' => 'dev-inbox/acceptance-fixes.md'], 'source' => 'builtin',
        ],
        'integration-testing' => [
            'label' => 'Integration testing', 'kind' => 'stage', 'role' => ['ref' => 'dpa/integration-testing'],
            'reads' => ['dev-inbox/build-phase.md'], 'writes' => [],
            'done_signal' => $sig,
            'kickback' => ['target' => 'dev', 'doc' => 'dev-inbox/integration-fixes.md'], 'source' => 'builtin',
        ],
        'reviewer' => [
            'label' => 'Reviewer', 'kind' => 'stage', 'role' => ['ref' => 'dpa/reviewer'],
            'reads' => ['dev-inbox/build-phase.md'], 'writes' => [],
            'done_signal' => $sig,
            'kickback' => ['target' => 'dev', 'doc' => 'dev-inbox/reviewer-feedback.md'], 'source' => 'builtin',
        ],
        'ux-ui' => [
            'label' => 'UX/UI', 'kind' => 'stage', 'role' => ['ref' => 'dpa/ux-ui'],
            'reads' => ['dev-inbox/build-phase.md'], 'writes' => [],
            'done_signal' => $sig,
            'kickback' => ['target' => 'dev', 'doc' => 'dev-inbox/ux-fixes.md'], 'source' => 'builtin',
        ],
        'deploy' => [
            'label' => 'Deploy', 'kind' => 'gate', 'role' => ['ref' => 'dpa/deploy'],
            'reads' => ['dev-inbox/deployment-note.md'], 'writes' => ['deploy-log/report.md'],
            'done_signal' => 'deploy report written', 'kickback' => null, 'source' => 'builtin',
        ],
        'testing-live' => [
            'label' => 'Testing (live)', 'kind' => 'gate', 'role' => ['ref' => 'dpa/testing-live'],
            'reads' => ['dev-inbox/deployment-note.md'], 'writes' => ['deploy-log/verify.md'],
            'done_signal' => 'verification report written', 'kickback' => null, 'source' => 'builtin',
        ],
        'ideas' => [
            'label' => 'Ideas (brainstorming)', 'kind' => 'conductor', 'role' => ['ref' => 'conductor/ideas'],
            'reads' => [], 'writes' => [],
            'done_signal' => 'human-run; no automated done-signal', 'kickback' => null, 'source' => 'builtin',
        ],
    ];
}

/** All agent types: the JSON registry if it exists, else the builtin seed. */
function deveryman_agent_types(): array {
    $p = deveryman_agent_types_path();
    if (is_file($p)) {
        $d = json_decode((string) @file_get_contents($p), true);
        if (is_array($d) && isset($d['agent_types']) && is_array($d['agent_types'])) return $d['agent_types'];
    }
    return deveryman_agent_type_seed();
}

/** One agent type by id, or null. */
function deveryman_agent_type(string $id): ?array {
    return deveryman_agent_types()[$id] ?? null;
}

/**
 * Save (create or overwrite) a user agent type. Materializes the seed to disk on
 * first write so the file ends up holding builtins + user types together. Returns
 * true on success.
 */
function deveryman_save_agent_type(string $id, array $entry): bool {
    $p = deveryman_agent_types_path();
    if (!is_dir(deveryman_registry_dir())) @mkdir(deveryman_registry_dir(), 0775, true);
    if (!is_file($p)) {
        if (!fw_write_json_atomic($p, ['agent_types' => deveryman_agent_type_seed()])) return false;
    }
    return fw_update_json($p, function (array $reg) use ($id, $entry): array {
        $reg['agent_types'] ??= [];
        $reg['agent_types'][$id] = $entry;
        return $reg;
    });
}

/**
 * Render an agent type's role to CLAUDE.md text. A builtin `role.ref` points at an
 * existing template file (dpa/agents/<x> or conductor/agent-templates/<x>); a user
 * role is composed from its summary / Do's / Don'ts / free-form sections.
 */
function deveryman_render_role(array $type): string {
    $role = $type['role'] ?? [];
    if (!empty($role['ref'])) {
        $ref = (string) $role['ref'];
        $base = str_starts_with($ref, 'conductor/')
            ? __DIR__ . '/../conductor/agent-templates/' . substr($ref, strlen('conductor/'))
            : __DIR__ . '/../dpa/agents/' . preg_replace('#^dpa/#', '', $ref);
        $f = $base . '/CLAUDE.md';
        if (is_file($f)) return (string) file_get_contents($f);
        return '# ' . ($type['label'] ?? $ref) . " agent\n\n(Role template missing.)\n";
    }
    $label = $type['label'] ?? 'Agent';
    $out = "# {$label} (pipeline role)\n\n";
    if (!empty($role['summary'])) $out .= trim((string) $role['summary']) . "\n\n";
    $dos = array_filter(array_map('trim', $role['dos'] ?? []));
    if ($dos) { $out .= "## Do\n\n"; foreach ($dos as $d) $out .= "- {$d}\n"; $out .= "\n"; }
    $donts = array_filter(array_map('trim', $role['donts'] ?? []));
    if ($donts) { $out .= "## Do not\n\n"; foreach ($donts as $d) $out .= "- {$d}\n"; $out .= "\n"; }
    if (!empty($role['freeform'])) $out .= trim((string) $role['freeform']) . "\n\n";
    // The I/O and done-signal are also delivered at run time via the read-only
    // pipeline-instructions.md overlay; restating them here keeps the CLAUDE.md
    // self-contained for a human reading it.
    $reads = array_filter($type['reads'] ?? []); $writes = array_filter($type['writes'] ?? []);
    $out .= "## Inputs and outputs\n\n";
    $out .= '- **Reads:** ' . ($reads ? implode(', ', $reads) : 'none (see pipeline-instructions.md)') . "\n";
    $out .= '- **Writes:** ' . ($writes ? implode(', ', $writes) : 'none (see pipeline-instructions.md)') . "\n";
    if (!empty($type['done_signal'])) $out .= '- **Done signal:** ' . $type['done_signal'] . "\n";
    if (!empty($type['kickback']['target'])) {
        $out .= '- **Kickback:** may route back to `' . $type['kickback']['target'] . '`';
        if (!empty($type['kickback']['doc'])) $out .= ' via `' . $type['kickback']['doc'] . '`';
        $out .= "\n";
    }
    return $out;
}

/* --- pipeline templates ---------------------------------------------------- */

/**
 * The builtin pipeline templates, keyed by id. A template is:
 *   label, description
 *   conductor         bool: enable the Conductor lens
 *   conductor_agents  string[]: agent types (kind=conductor) to seed
 *   branching         { base_branch, release_branch, feature_branch_prefix }
 *   backlog_file, deployment_note, autonomy_level, poll_interval, pipeline_version
 *   nodes             [ { id, agent_type, ...overrides } ]  (overrides reads/writes/
 *                     kickback/kind/done_signal from the agent type when present)
 *   flow              [ { from, to } ]  forward sequence
 *   gates             [ "<node id>" ]   human-only nodes
 *   source            "builtin" | "user"
 *
 * DPA standard here reproduces exactly what launcher/templates.php hard-coded once
 * compiled (same stages order, kickback_target, and io). See PIPELINE-MODEL.md.
 */
function deveryman_pipeline_template_seed(): array {
    return [
        'none' => [
            'label' => 'None (bare project)',
            'description' => 'Just register the project and its new repo. Add capabilities later.',
            'conductor' => false, 'conductor_agents' => [],
            'branching' => ['base_branch' => 'staging', 'release_branch' => 'main', 'feature_branch_prefix' => 'feature/'],
            'backlog_file' => 'product-backlog.md', 'deployment_note' => 'dev-inbox/deployment-note.md',
            'autonomy_level' => 3, 'poll_interval' => 30, 'pipeline_version' => '1',
            'nodes' => [], 'flow' => [], 'gates' => [], 'source' => 'builtin',
        ],
        'dpa-standard' => [
            'label' => 'DPA standard',
            'description' => 'The full shape: Conductor plus the automated DPA pipeline, branching, '
                . 'backlog, and human-gated deploy. All agents created, none spun up.',
            'conductor' => true, 'conductor_agents' => ['ideas'],
            'branching' => ['base_branch' => 'staging', 'release_branch' => 'main', 'feature_branch_prefix' => 'feature/'],
            'backlog_file' => 'product-backlog.md', 'deployment_note' => 'dev-inbox/deployment-note.md',
            'autonomy_level' => 3, 'poll_interval' => 30, 'pipeline_version' => '1',
            'nodes' => [
                ['id' => 'features', 'agent_type' => 'features'],
                ['id' => 'acceptance', 'agent_type' => 'acceptance'],
                ['id' => 'dev', 'agent_type' => 'dev'],
                ['id' => 'testing-staging', 'agent_type' => 'testing-staging'],
                ['id' => 'integration-testing', 'agent_type' => 'integration-testing'],
                ['id' => 'reviewer', 'agent_type' => 'reviewer'],
                ['id' => 'ux-ui', 'agent_type' => 'ux-ui'],
                ['id' => 'deploy', 'agent_type' => 'deploy'],
                ['id' => 'testing-live', 'agent_type' => 'testing-live'],
            ],
            'flow' => [
                ['from' => 'features', 'to' => 'acceptance'],
                ['from' => 'acceptance', 'to' => 'dev'],
                ['from' => 'dev', 'to' => 'testing-staging'],
                ['from' => 'testing-staging', 'to' => 'integration-testing'],
                ['from' => 'integration-testing', 'to' => 'reviewer'],
                ['from' => 'reviewer', 'to' => 'ux-ui'],
                ['from' => 'ux-ui', 'to' => 'deploy'],
                ['from' => 'deploy', 'to' => 'testing-live'],
            ],
            'gates' => ['deploy', 'testing-live'], 'source' => 'builtin',
        ],
    ];
}

/** All pipeline templates: the JSON registry if it exists, else the builtin seed. */
function deveryman_pipeline_templates(): array {
    $p = deveryman_pipeline_templates_path();
    if (is_file($p)) {
        $d = json_decode((string) @file_get_contents($p), true);
        if (is_array($d) && isset($d['templates']) && is_array($d['templates'])) return $d['templates'];
    }
    return deveryman_pipeline_template_seed();
}

/** One pipeline template by id, or null. */
function deveryman_pipeline_template(string $id): ?array {
    return deveryman_pipeline_templates()[$id] ?? null;
}

/** Save (create or overwrite) a user pipeline template. Seeds the file on first write. */
function deveryman_save_pipeline_template(string $id, array $entry): bool {
    $p = deveryman_pipeline_templates_path();
    if (!is_dir(deveryman_registry_dir())) @mkdir(deveryman_registry_dir(), 0775, true);
    if (!is_file($p)) {
        if (!fw_write_json_atomic($p, ['templates' => deveryman_pipeline_template_seed()])) return false;
    }
    return fw_update_json($p, function (array $reg) use ($id, $entry): array {
        $reg['templates'] ??= [];
        $reg['templates'][$id] = $entry;
        return $reg;
    });
}

/**
 * Materialize any user-authored roles a template uses into a per-project override
 * dir the daemon reads: <pipeline_root>/roles/<node-id>/CLAUDE.md. Builtin roles
 * (role.ref) are skipped, since the daemon already has them under dpa/agents. Called
 * by the applier before `--instantiate`. Returns the node ids that were written.
 */
function deveryman_write_custom_roles(array $pipelineTpl, string $pipelineRoot): array {
    $agentTypes = deveryman_agent_types();
    $written = [];
    foreach ($pipelineTpl['nodes'] ?? [] as $node) {
        $typeId = $node['agent_type'] ?? '';
        $type = $agentTypes[$typeId] ?? null;
        if ($type === null || !empty($type['role']['ref'])) continue;   // builtin ref: daemon has it
        $id = $node['id'] ?? $typeId;
        $dir = rtrim($pipelineRoot, '/') . '/roles/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) continue;
        if (@file_put_contents($dir . '/CLAUDE.md', deveryman_render_role($type)) !== false) $written[] = $id;
    }
    return $written;
}

/* --- resolving + compiling ------------------------------------------------- */

/**
 * Resolve one template node against its agent type: the node inherits the agent
 * type's kind / reads / writes / done_signal / kickback, and may override any of
 * them. Returns the fully-resolved node, or null if the agent type is unknown.
 */
function deveryman_resolve_node(array $node, array $agentTypes): ?array {
    $typeId = $node['agent_type'] ?? '';
    $type = $agentTypes[$typeId] ?? null;
    if ($type === null) return null;
    return [
        'id'          => $node['id'] ?? $typeId,
        'agent_type'  => $typeId,
        'kind'        => $node['kind']        ?? $type['kind']        ?? 'stage',
        'reads'       => $node['reads']       ?? $type['reads']       ?? [],
        'writes'      => $node['writes']      ?? $type['writes']      ?? [],
        'done_signal' => $node['done_signal'] ?? $type['done_signal'] ?? '',
        'kickback'    => array_key_exists('kickback', $node) ? $node['kickback'] : ($type['kickback'] ?? null),
    ];
}

/**
 * Order node ids along the forward flow: start from the node that is never a `to`
 * (the head), then follow `from -> to`. Falls back to declared node order if the
 * flow is empty or a cycle stalls the walk.
 */
function deveryman_flow_order(array $nodes, array $flow): array {
    $ids = array_map(fn($n) => $n['id'] ?? '', $nodes);
    $ids = array_values(array_filter($ids, fn($x) => $x !== ''));
    if (!$flow) return $ids;
    $next = []; $targets = [];
    foreach ($flow as $e) {
        if (isset($e['from'], $e['to'])) { $next[$e['from']] = $e['to']; $targets[$e['to']] = true; }
    }
    $head = null;
    foreach ($ids as $id) { if (!isset($targets[$id])) { $head = $id; break; } }
    if ($head === null) return $ids;   // every node is a target (cycle); bail to declared order
    $order = []; $seen = []; $cur = $head;
    while ($cur !== null && isset($cur) && !isset($seen[$cur])) {
        if (!in_array($cur, $ids, true)) break;
        $order[] = $cur; $seen[$cur] = true;
        $cur = $next[$cur] ?? null;
    }
    // Append any nodes the walk did not reach (defensive; keeps them in the config).
    foreach ($ids as $id) if (!isset($seen[$id])) $order[] = $id;
    return $order;
}

/**
 * Compile a pipeline template down to the project.json "dpa" shape the underseer
 * runs: ordered `stages`, `kickback_target`, per-stage `io`, plus branching and
 * feeder/gate file config. Returns null for a template with no stage nodes (a bare
 * project that gets no DPA capability).
 */
function deveryman_compile_template(array $tpl): ?array {
    $agentTypes = deveryman_agent_types();
    $byId = [];
    foreach ($tpl['nodes'] ?? [] as $n) {
        $r = deveryman_resolve_node($n, $agentTypes);
        if ($r !== null) $byId[$r['id']] = $r;
    }
    $order = deveryman_flow_order(array_values($byId), $tpl['flow'] ?? []);

    $stages = []; $io = []; $kickback = [];
    foreach ($order as $id) {
        $n = $byId[$id] ?? null;
        if ($n === null || ($n['kind'] ?? 'stage') !== 'stage') continue;
        $stages[] = $id;
        $io[$id] = ['reads' => array_values($n['reads']), 'writes' => array_values($n['writes'])];
        if (!empty($n['kickback']['target'])) $kickback[$id] = $n['kickback']['target'];
    }
    if (!$stages) return null;

    $b = $tpl['branching'] ?? [];
    return [
        'stages' => $stages,
        'kickback_target' => $kickback,
        'autonomy_level' => (int) ($tpl['autonomy_level'] ?? 3),
        'poll_interval' => (int) ($tpl['poll_interval'] ?? 30),
        'base_branch' => $b['base_branch'] ?? 'staging',
        'release_branch' => $b['release_branch'] ?? 'main',
        'feature_branch_prefix' => $b['feature_branch_prefix'] ?? 'feature/',
        'backlog_file' => $tpl['backlog_file'] ?? 'product-backlog.md',
        'deployment_note' => $tpl['deployment_note'] ?? 'dev-inbox/deployment-note.md',
        'pipeline_version' => (string) ($tpl['pipeline_version'] ?? '1'),
        'io' => $io,
    ];
}

/**
 * Validate a pipeline template before saving. Mirrors the daemon's own checks so a
 * template that saves here will also pass validate_wiring at load: every node
 * references a known agent type, node ids are unique, flow endpoints exist, kickback
 * targets exist, and (the connection contract) every declared input is produced by
 * an earlier stage in the flow or is a pre-existing pipeline input. Returns a list
 * of human-readable problems ([] = ok).
 */
function deveryman_validate_template(array $tpl): array {
    $problems = [];
    $agentTypes = deveryman_agent_types();
    $nodes = $tpl['nodes'] ?? [];
    $resolved = []; $ids = [];
    foreach ($nodes as $n) {
        $id = $n['id'] ?? '';
        if ($id === '') { $problems[] = 'A node is missing an id.'; continue; }
        if (isset($ids[$id])) { $problems[] = "Duplicate node id '{$id}'."; continue; }
        $ids[$id] = true;
        $r = deveryman_resolve_node($n, $agentTypes);
        if ($r === null) { $problems[] = "Node '{$id}' references unknown agent type '" . ($n['agent_type'] ?? '') . "'."; continue; }
        $resolved[$id] = $r;
    }
    foreach ($tpl['flow'] ?? [] as $e) {
        $from = $e['from'] ?? ''; $to = $e['to'] ?? '';
        if (!isset($ids[$from])) $problems[] = "Flow edge from unknown node '{$from}'.";
        if (!isset($ids[$to]))   $problems[] = "Flow edge to unknown node '{$to}'.";
    }
    foreach ($resolved as $id => $r) {
        $t = $r['kickback']['target'] ?? '';
        if ($t !== '' && !isset($ids[$t])) $problems[] = "Node '{$id}' kicks back to unknown node '{$t}'.";
    }

    // The connection contract, upstream-producer form (matches validate_wiring): walk
    // the stage nodes in flow order; each declared input must have been written by an
    // earlier stage, or be a prewired pipeline input. Gates read prewired files only.
    $order = deveryman_flow_order(array_values($resolved), $tpl['flow'] ?? []);
    $prewired = ['product-backlog.md', 'build-queue.md',
                 $tpl['backlog_file'] ?? 'product-backlog.md',
                 $tpl['deployment_note'] ?? 'dev-inbox/deployment-note.md'];
    $produced = [];
    foreach ($order as $id) {
        $n = $resolved[$id] ?? null;
        if ($n === null) continue;
        foreach ($n['reads'] ?? [] as $rd) {
            if (in_array($rd, $prewired, true) || in_array($rd, $produced, true)) continue;
            $problems[] = "Node '{$id}' reads '{$rd}' which no earlier stage writes.";
        }
        foreach ($n['writes'] ?? [] as $wr) $produced[] = $wr;
    }
    return $problems;
}
