<?php
declare(strict_types=1);
/*
 * Project templates: named bundles that stand a whole project up at once (which
 * capabilities it gets, the DPA config shape, and the Conductor agents to seed).
 * The DPA is only the automated middle; a template is the scaffold around it.
 *
 * This file is now a thin ADAPTER. The templates themselves live in the
 * user-editable pipeline-template registry (launcher/registry.php); here we project
 * each one into the shape the applier (deveryman_apply_template) consumes:
 * { label, description, conductor, conductor_agents, dpa }. The `dpa` block is the
 * compiled underseer config (deveryman_compile_template), or null for a bare project.
 */

require_once __DIR__ . '/registry.php';

/**
 * All project templates, keyed by id, in the applier-facing shape. Derived from the
 * pipeline-template registry: `dpa` is the compiled config (null = bare project).
 */
function deveryman_templates(): array {
    $out = [];
    foreach (deveryman_pipeline_templates() as $id => $tpl) {
        $out[$id] = [
            'label' => $tpl['label'] ?? $id,
            'description' => $tpl['description'] ?? '',
            'conductor' => (bool) ($tpl['conductor'] ?? false),
            'conductor_agents' => $tpl['conductor_agents'] ?? [],
            'dpa' => deveryman_compile_template($tpl),
        ];
    }
    return $out;
}

/** One template by id, or null. */
function deveryman_template(string $id): ?array {
    return deveryman_templates()[$id] ?? null;
}
