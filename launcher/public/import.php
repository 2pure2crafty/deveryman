<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
fw_require_auth();

$projectsPath = __DIR__ . '/../../projects.json';
$importsRoot  = rtrim(fw_config_get('DEVERYMAN_PROJECTS_DIR', '/var/www/dpa-projects'), '/');

function slugify(string $s): ?string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return ($s !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $s)) ? $s : null;
}

/* ---------- GET: the form ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fw_header('Import a repo', '/');
    echo '<h1>Import a repo</h1>';
    echo '<p class="desc">Bring one of your repos into D\'everyman. It is cloned into your '
        . 'projects folder, registered, and you pick which lenses to enable. Uses <code>gh</code> '
        . 'for <code>owner/name</code>, or <code>git</code> for a full URL.</p>';
    echo '<form method="post" action="import.php">'
        . '<label>Repo <input type="text" name="repo" placeholder="owner/name or https://... .git" required></label>'
        . '<label>Project name <input type="text" name="label" placeholder="e.g. My App" required></label>'
        . '<label><input type="checkbox" name="cap_conductor" value="1" style="width:auto"> Enable Conductor (manual agents)</label>'
        . '<label><input type="checkbox" name="cap_dpa" value="1" style="width:auto"> Enable DPA (build pipeline)</label>'
        . '<button class="btn" type="submit" style="margin-top:10px">Import</button></form>';
    fw_footer();
    exit;
}

/* ---------- POST: the import bot ---------- */
$repo  = trim($_POST['repo'] ?? '');
$label = trim($_POST['label'] ?? '');
$capC  = ($_POST['cap_conductor'] ?? '') === '1';
$capD  = ($_POST['cap_dpa'] ?? '') === '1';
$slug  = slugify($label);

$errors = [];
if ($slug === null) $errors[] = 'Project name did not produce a valid slug.';
if ($repo === '' || !preg_match('#^([\w.-]+/[\w.-]+|https?://[\w./:@-]+|git@[\w.:/-]+)$#', $repo)) {
    $errors[] = 'Repo must be owner/name or a git URL.';
}
$dest = $importsRoot . '/' . ($slug ?? '');
if ($slug !== null && is_dir($dest)) $errors[] = 'A project already exists at ' . $dest . '.';

if (!$errors) {
    // Clone: gh for owner/name (private repos, your auth), git for a URL.
    if (preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        [$e, , $err] = fw_run_cmd(['gh', 'repo', 'clone', $repo, $dest], null, '', 180);
    } else {
        [$e, , $err] = fw_run_cmd(['git', 'clone', $repo, $dest], null, '', 180);
    }
    if ($e !== 0) $errors[] = 'Clone failed: ' . trim($err);
}

if (!$errors) {
    // Register in projects.json (the shared registry).
    $reg = is_file($projectsPath) ? (json_decode((string) file_get_contents($projectsPath), true) ?: []) : [];
    $reg['projects'] ??= [];
    $caps = [];
    if ($capC) $caps['conductor'] = true;
    if ($capD) {
        // Let a one-shot Haiku read the clone and describe the stack for the DPA context.
        $listing = '';
        [, $ls] = fw_run_cmd(['bash', '-lc', 'ls -a1 ' . escapeshellarg($dest) . ' | head -40']);
        $listing .= "Files:\n" . $ls;
        foreach (['package.json', 'README.md', 'composer.json', 'go.mod', 'requirements.txt'] as $f) {
            if (is_file("$dest/$f")) $listing .= "\n\n=== $f ===\n" . substr((string) file_get_contents("$dest/$f"), 0, 1500);
        }
        $ctxJson = fw_reason(
            'Given this repo listing and key files, output ONLY a compact JSON object with keys '
            . 'project_summary, tech_stack, conventions (one short sentence each). No prose, no code fences.',
            $listing, 90) ?? '';
        // Be tolerant of code fences / stray prose: pull the first {...} block.
        if (preg_match('/\{.*\}/s', $ctxJson, $m)) $ctxJson = $m[0];
        $ctx = json_decode($ctxJson, true) ?: [];
        $projectJson = [
            'name' => $slug, 'label' => $label,
            'repo_root' => $dest, 'pipeline_root' => "$dest/.pipeline",
            'tmux_prefix' => strtoupper(substr($slug, 0, 6)),
            'git_user' => null, 'autonomy_level' => 1, 'poll_interval' => 30,
            'stages' => ['features', 'dev', 'reviewer'],
            'kickback_target' => ['reviewer' => 'dev'],
            'context' => [
                'project_summary' => $ctx['project_summary'] ?? ('Imported repo: ' . $label),
                'tech_stack' => $ctx['tech_stack'] ?? '',
                'conventions' => $ctx['conventions'] ?? 'Small, self-contained changes matching the existing code.',
                'user_types' => '', 'design_notes' => '',
            ],
        ];
        file_put_contents("$dest/project.json", json_encode($projectJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $caps['dpa'] = ['config' => "$dest/project.json"];
    }
    $reg['projects'][$slug] = ['label' => $label, 'path' => $dest, 'capabilities' => $caps];
    file_put_contents($projectsPath, json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    header('Location: /');
    exit;
}

// errors -> show them
fw_header('Import failed', '/');
echo '<h1>Import a repo</h1>';
foreach ($errors as $err) echo '<p style="color:#f87171">' . fw_h($err) . '</p>';
echo '<a class="back" href="import.php">&larr; Try again</a>';
fw_footer();
