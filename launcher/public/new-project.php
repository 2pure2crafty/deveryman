<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../lib.php';
fw_require_auth();

/* ---------- GET: the form ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fw_header('New project', '/');
    echo '<h1>New project</h1>';
    echo '<p class="desc">Stand up a brand-new project from a template, with a fresh repo. '
        . 'To bring in an existing repo instead, use <a href="import.php">Import a repo</a>.</p>';
    echo '<form method="post" action="new-project.php">'
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
    echo '<label><input type="checkbox" name="create_repo" value="1" style="width:auto"> '
        . 'Create a private GitHub repo (needs <code>gh</code> auth; otherwise the repo stays local)</label>';
    echo '<button class="btn" type="submit" style="margin-top:10px">Create project</button></form>';
    fw_footer();
    exit;
}

/* ---------- POST: create it ---------- */
$label      = trim($_POST['label'] ?? '');
$templateId = $_POST['template'] ?? 'none';
$createRepo = ($_POST['create_repo'] ?? '') === '1';
$slug       = deveryman_slugify($label);

$errors = [];
if ($slug === null) $errors[] = 'Project name did not produce a valid slug.';
if (deveryman_template($templateId) === null) $errors[] = 'Unknown template.';

if (!$errors) {
    $res = deveryman_apply_template($slug, $label, $templateId, $createRepo);
    if ($res['ok']) {
        $msg = 'Created ' . $label;
        if (!empty($res['warnings'])) $msg .= ' (' . implode('; ', $res['warnings']) . ')';
        header('Location: /?msg=' . rawurlencode($msg));
        exit;
    }
    $errors = array_merge($errors, $res['errors']);
}

fw_header('New project', '/');
echo '<h1>New project</h1>';
foreach ($errors as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
echo '<a class="back" href="new-project.php">&larr; Try again</a>';
fw_footer();
