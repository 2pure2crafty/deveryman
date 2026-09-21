<?php
declare(strict_types=1);
/*
 * Project templates: named bundles that stand a whole project up at once (which
 * capabilities it gets, the DPA config shape, and the Conductor agents to seed).
 * The DPA is only the automated middle; a template is the scaffold around it.
 *
 * "DPA standard" is the full pipeline shape. "None" is a bare project. This is a
 * data definition on purpose: the customization branch adds more templates without
 * touching the applier.
 */

/**
 * All project templates, keyed by id. Each entry:
 *   label            human-readable name (shown in the dropdown)
 *   description      one line shown under the option
 *   conductor        bool: enable the Conductor lens
 *   conductor_agents string[]: agent role templates to seed on the Conductor side
 *   dpa              null, or the DPA project.json shape (the applier fills in the
 *                    per-project keys: name, label, repo_root, pipeline_root,
 *                    tmux_prefix, git_user, context)
 */
function deveryman_templates(): array {
    return [
        'none' => [
            'label' => 'None (bare project)',
            'description' => 'Just register the project and its new repo. Add capabilities later.',
            'conductor' => false,
            'conductor_agents' => [],
            'dpa' => null,
        ],
        'dpa-standard' => [
            'label' => 'DPA standard',
            'description' => 'The full shape: Conductor plus the automated DPA pipeline, branching, '
                . 'backlog, and human-gated deploy. All agents created, none spun up.',
            'conductor' => true,
            'conductor_agents' => ['ideas'],
            'dpa' => [
                'stages' => ['features', 'acceptance', 'dev', 'testing-staging',
                             'integration-testing', 'reviewer', 'ux-ui'],
                'kickback_target' => [
                    'testing-staging' => 'dev',
                    'integration-testing' => 'dev',
                    'reviewer' => 'dev',
                    'ux-ui' => 'dev',
                ],
                'autonomy_level' => 3,
                'poll_interval' => 30,
                'base_branch' => 'staging',
                'release_branch' => 'main',
                'feature_branch_prefix' => 'feature/',
                'backlog_file' => 'product-backlog.md',
                'deployment_note' => 'dev-inbox/deployment-note.md',
            ],
        ],
    ];
}

/** One template by id, or null. */
function deveryman_template(string $id): ?array {
    return deveryman_templates()[$id] ?? null;
}
