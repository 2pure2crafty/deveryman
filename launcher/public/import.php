<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../lib.php';   // deveryman_* templates + finalize + slugify
fw_require_auth();

$importsRoot = rtrim(fw_config_get('DEVERYMAN_PROJECTS_DIR', '/var/www/dpa-projects'), '/');
$projectsPath = __DIR__ . '/../../projects.json';

/** owner/name or a git URL (guards a leading dash so a value cannot become a gh flag). */
function import_repo_valid(string $repo): bool {
    return $repo !== '' && (bool) preg_match('#^([\w][\w.-]*/[\w.-]+|https?://[\w./:@-]+|git@[\w.:/-]+)$#', $repo);
}

/** Best-effort remote branch list (no clone): gh api for owner/name, ls-remote for a URL. */
function import_remote_branches(string $repo): array {
    if (preg_match('#^[\w][\w.-]*/[\w.-]+$#', $repo)) {
        [$e, $out] = fw_run_cmd(['gh', 'api', 'repos/' . $repo . '/branches', '--paginate', '--jq', '.[].name'], null, '', 30);
        return $e === 0 ? array_values(array_filter(array_map('trim', explode("\n", $out)))) : [];
    }
    [$e, $out] = fw_run_cmd(['git', 'ls-remote', '--heads', '--', $repo], null, '', 30);
    if ($e !== 0) return [];
    $branches = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (preg_match('#refs/heads/(.+)$#', $line, $m)) $branches[] = trim($m[1]);
    }
    return $branches;
}

/** A browsable https URL for the Conductor 'repo' field + project.json repo_url. */
function import_repo_url(string $repo): string {
    if (preg_match('#^[\w][\w.-]*/[\w.-]+$#', $repo)) return 'https://github.com/' . preg_replace('#\.git$#', '', $repo);
    if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?$#', $repo, $m)) return 'https://' . $m[1] . '/' . $m[2];
    return preg_replace('#\.git$#', '', $repo);
}

/** Clone owner/name (gh, your auth) or a URL (git) into $repoDir. Returns [ok, err]. */
function import_clone(string $repo, string $repoDir): array {
    if (preg_match('#^[\w][\w.-]*/[\w.-]+$#', $repo)) {
        [$e, , $err] = fw_run_cmd(['gh', 'repo', 'clone', $repo, $repoDir], null, '', 180);
    } else {
        [$e, , $err] = fw_run_cmd(['git', 'clone', '--', $repo, $repoDir], null, '', 180);
    }
    return [$e === 0, trim($err)];
}

/** Read the clone's key files and let a one-shot Haiku describe the stack for the DPA context. */
function import_detect_context(string $repoDir, string $label): array {
    $listing = '';
    [, $ls] = fw_run_cmd(['ls', '-a1', $repoDir]);
    $listing .= "Files:\n" . implode("\n", array_slice(explode("\n", trim($ls)), 0, 40));
    foreach (['package.json', 'README.md', 'composer.json', 'go.mod', 'requirements.txt'] as $f) {
        if (is_file("$repoDir/$f")) $listing .= "\n\n=== $f ===\n" . substr((string) file_get_contents("$repoDir/$f"), 0, 1500);
    }
    $ctxJson = fw_reason(
        'Given this repo listing and key files, output ONLY a compact JSON object with keys '
        . 'project_summary, tech_stack, conventions (one short sentence each). No prose, no code fences.',
        $listing, 90) ?? '';
    if (preg_match('/\{.*\}/s', $ctxJson, $m)) $ctxJson = $m[0];
    $ctx = json_decode($ctxJson, true) ?: [];
    return [
        'project_summary' => $ctx['project_summary'] ?? ('Imported repo: ' . $label),
        'tech_stack' => $ctx['tech_stack'] ?? '',
        'conventions' => $ctx['conventions'] ?? 'Small, self-contained changes matching the existing code.',
        'user_types' => '', 'design_notes' => '',
    ];
}

/** Clone into the container and finalize it into both registries. Returns [ok, errors[], warnings[]]. */
function import_finalize(string $slug, string $label, string $repo, string $templateId, array $branchOverrides, string $importsRoot): array {
    $container = $importsRoot . '/' . $slug;
    $repoDir = $container . '/repo';
    if (is_dir($container)) return [false, ['A project already exists at ' . $container . '.'], []];
    if (!@mkdir($container, 0775, true)) return [false, ['Could not create project directory at ' . $container . '.'], []];
    [$ok, $err] = import_clone($repo, $repoDir);
    if (!$ok) { deveryman_rrmdir($container); return [false, ['Clone failed: ' . $err], []]; }

    $tpl = deveryman_template($templateId);
    $opts = ['branch_overrides' => $branchOverrides, 'repo_url' => import_repo_url($repo)];
    if (!empty($tpl['dpa'])) $opts['context'] = import_detect_context($repoDir, $label);
    $fin = deveryman_finalize_project($slug, $label, $container, $repoDir, $templateId, $opts);
    return [$fin['ok'], $fin['errors'], $fin['warnings']];
}

/* ===================== POST ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phase = $_POST['phase'] ?? '';
    $repo  = trim($_POST['repo'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $templateId = $_POST['template'] ?? 'none';
    $slug  = deveryman_slugify($label);

    $errors = [];
    if (!fw_csrf_ok()) $errors[] = 'Bad or missing form token; reload and try again.';
    if ($slug === null) $errors[] = 'Project name did not produce a valid slug.';
    if (!import_repo_valid($repo)) $errors[] = 'Repo must be owner/name or a git URL.';
    $tpl = deveryman_template($templateId);
    if ($tpl === null) $errors[] = 'Unknown template.';
    if ($slug !== null && deveryman_slug_registered($projectsPath, $slug)) $errors[] = "A project '{$slug}' is already registered.";
    if ($slug !== null && is_dir($importsRoot . '/' . $slug)) $errors[] = 'A project already exists at ' . $importsRoot . '/' . $slug . '.';

    // Phase 1 (discover): if the template runs a pipeline, collect branch wiring first.
    if (!$errors && $phase === 'discover' && !empty($tpl['dpa'])) {
        $branches = import_remote_branches($repo);
        $dpa = $tpl['dpa'];
        render_wiring_form($repo, $label, $templateId, $branches, $dpa);
        exit;
    }

    // Otherwise finalize now: phase 'wire' (pipeline, branches chosen) or a
    // no-pipeline template (conductor-only / none) that needs no wiring.
    if (!$errors) {
        $overrides = [];
        if ($phase === 'wire') {
            foreach (['base_branch', 'release_branch', 'feature_branch_prefix', 'deployment_note'] as $k) {
                $val = trim($_POST[$k] ?? '');
                if ($val !== '') $overrides[$k] = $val;
            }
        }
        [$ok, $ferrors, $warnings] = import_finalize($slug, $label, $repo, $templateId, $overrides, $importsRoot);
        if ($ok) {
            $msg = 'Imported ' . $label;
            if ($warnings) $msg .= ' (' . implode('; ', $warnings) . ')';
            header('Location: /?msg=' . rawurlencode($msg));
            exit;
        }
        $errors = array_merge($errors, $ferrors);
    }

    fw_header('Import failed', 'import.php');
    echo '<h1>Import a repo</h1>';
    foreach ($errors as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
    echo '<a class="back" href="import.php">&larr; Try again</a>';
    fw_footer();
    exit;
}

/* ===================== GET: the first form ===================== */
fw_header('Import a repo', '/');
echo '<h1>Import a repo</h1>';
echo '<p class="desc">Bring one of your existing repos into D\'everyman. Pick a template; if it '
    . 'runs a pipeline you get a wiring step to map the pipeline onto your repo\'s real branches. '
    . 'Uses <code>gh</code> for <code>owner/name</code>, or <code>git</code> for a full URL.</p>';
echo '<form method="post" action="import.php">' . fw_csrf_field()
    . '<input type="hidden" name="phase" value="discover">'
    . '<label>Repo <input type="text" name="repo" placeholder="owner/name or https://... .git" required></label>'
    . '<label>Project name <input type="text" name="label" placeholder="e.g. My App" required></label>'
    . '<label>Template <select name="template">';
foreach (deveryman_templates() as $id => $t) {
    echo '<option value="' . fw_h($id) . '">' . fw_h($t['label']) . '</option>';
}
echo '</select></label>';
echo '<div class="meta" style="margin-top:6px">';
foreach (deveryman_templates() as $t) {
    echo '<strong>' . fw_h($t['label']) . ':</strong> ' . fw_h($t['description'] ?? '') . '<br>';
}
echo '</div>';
echo '<button class="btn" type="submit" style="margin-top:10px">Next</button></form>';
fw_footer();

/* ===================== the wiring step ===================== */
function render_wiring_form(string $repo, string $label, string $templateId, array $branches, array $dpa): void {
    fw_header('Wire the pipeline', 'import.php');
    echo '<h1>Wire the pipeline</h1>';
    echo '<p class="desc">Map <strong>' . fw_h($label) . '</strong>\'s pipeline onto your repo\'s branches. '
        . 'Features branch off the base branch and merge back into it (that is where the UX/UI stage lands); '
        . 'the deploy gate promotes the base branch onto the release branch. Nothing is cloned until you confirm.</p>';
    if ($branches) {
        echo '<p class="meta">Branches on the remote: ' . fw_h(implode(', ', $branches)) . '</p>';
    } else {
        echo '<p class="meta">Could not list the remote\'s branches; type them below (they must exist in the repo).</p>';
    }
    $list = '';
    foreach ($branches as $b) $list .= '<option value="' . fw_h($b) . '">';
    $base = fw_h($dpa['base_branch'] ?? 'staging');
    $rel  = fw_h($dpa['release_branch'] ?? 'main');
    $pref = fw_h($dpa['feature_branch_prefix'] ?? 'feature/');
    $note = fw_h($dpa['deployment_note'] ?? 'dev-inbox/deployment-note.md');
    echo '<form method="post" action="import.php">' . fw_csrf_field()
        . '<input type="hidden" name="phase" value="wire">'
        . '<input type="hidden" name="repo" value="' . fw_h($repo) . '">'
        . '<input type="hidden" name="label" value="' . fw_h($label) . '">'
        . '<input type="hidden" name="template" value="' . fw_h($templateId) . '">'
        . '<datalist id="branches">' . $list . '</datalist>'
        . '<label>Base branch (their staging: features branch off and merge back here) '
        . '<input type="text" name="base_branch" list="branches" value="' . $base . '" required></label>'
        . '<label>Release branch (their live: the deploy gate promotes onto this) '
        . '<input type="text" name="release_branch" list="branches" value="' . $rel . '" required></label>'
        . '<label>Feature branch prefix <input type="text" name="feature_branch_prefix" value="' . $pref . '"></label>'
        . '<label>Deployment note (docs-relative path the deploy agent follows) '
        . '<input type="text" name="deployment_note" value="' . $note . '"></label>'
        . '<button class="btn" type="submit" style="margin-top:10px">Import and wire</button></form>';
    fw_footer();
}
