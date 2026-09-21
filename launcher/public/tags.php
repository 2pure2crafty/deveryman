<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../registry.php';
fw_require_auth();

/* ---------- POST: add a tag ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!fw_csrf_ok()) $errors[] = 'Bad or missing form token; reload and try again.';
    $label = trim($_POST['label'] ?? '');
    $id    = deveryman_slug_id($label);
    if ($id === null) $errors[] = 'Name did not produce a valid tag id.';
    if (!$errors && deveryman_save_tag($id, ['label' => $label, 'description' => trim($_POST['description'] ?? '')])) {
        header('Location: tags.php?msg=' . rawurlencode('Added tag: ' . $label));
        exit;
    }
    if (!$errors) $errors[] = 'Could not write the tag registry (check permissions on launcher/registries/).';
    fw_header('Tags', '/');
    echo '<h1>Tags</h1>';
    foreach ($errors as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
    echo '<a class="back" href="tags.php">&larr; Back</a>';
    fw_footer();
    exit;
}

/* ---------- GET: the vocabulary + add form ---------- */
fw_header('Tags', '/');
echo '<h1>Tags</h1>';
echo '<p class="desc">The controlled vocabulary tag-based routing draws from. A pipeline that forks '
    . 'routes on these tags, and the tagger sets a feature\'s tag from this set. Add tags here or inline '
    . 'when you wire a route.</p>';
$msg = $_GET['msg'] ?? '';
if ($msg !== '') echo '<p style="color:#4ade80">' . fw_h($msg) . '</p>';

foreach (deveryman_tags() as $id => $t) {
    echo '<div class="card"><strong>' . fw_h($t['label'] ?? $id) . '</strong> <code>' . fw_h($id) . '</code>';
    if (!empty($t['description'])) echo '<div class="desc">' . fw_h($t['description']) . '</div>';
    echo '</div>';
}

echo '<h2>Add a tag</h2>';
echo '<form method="post" action="tags.php">' . fw_csrf_field()
    . '<label>Name <input type="text" name="label" placeholder="e.g. Chore" required></label>'
    . '<label>Description <input type="text" name="description" placeholder="One line"></label>'
    . '<button class="btn" type="submit" style="margin-top:10px">Add tag</button></form>';
fw_footer();
