<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require_once __DIR__ . '/../registry.php';
fw_require_auth();
if ($_SERVER['REQUEST_METHOD'] === 'POST') fw_demo_block('Saving');

/* ---------- POST: save the type ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!fw_csrf_ok()) $errors[] = 'Bad or missing form token; reload and try again.';

    $label = trim($_POST['label'] ?? '');
    $id    = deveryman_slug_id($label);
    $kind  = $_POST['kind'] ?? 'stage';
    if (!in_array($kind, ['stage', 'gate'], true)) $kind = 'stage';

    if ($id === null) $errors[] = 'Name did not produce a valid id.';
    // Builtin ids are reserved so DPA standard stays stable; a user may overwrite
    // their own saved types by name.
    $existing = deveryman_agent_type($id ?? '');
    if ($id !== null && $existing !== null && ($existing['source'] ?? '') === 'builtin') {
        $errors[] = "'{$id}' is a builtin agent type; pick another name.";
    }

    $entry = [
        'label' => $label,
        'kind'  => $kind,
        'role'  => [
            'summary'  => trim($_POST['summary'] ?? ''),
            'dos'      => deveryman_lines($_POST['dos'] ?? ''),
            'donts'    => deveryman_lines($_POST['donts'] ?? ''),
            'freeform' => trim($_POST['freeform'] ?? ''),
        ],
        'reads'       => deveryman_lines($_POST['reads'] ?? ''),
        'writes'      => deveryman_lines($_POST['writes'] ?? ''),
        'done_signal' => trim($_POST['done_signal'] ?? '') ?: 'set the pipeline-state Stage status to COMPLETE',
        // A type advertises the doc it leaves when kicking back; WHERE it routes is
        // wired per pipeline on the template node, not here.
        'kickback_doc' => trim($_POST['kickback_doc'] ?? '') ?: null,
        'source'       => 'user',
    ];

    if (!$errors) {
        if (deveryman_save_agent_type($id, $entry)) {
            header('Location: agent-types.php?msg=' . rawurlencode('Saved agent type: ' . $label));
            exit;
        }
        $errors[] = 'Could not write the agent-type registry (check permissions on launcher/registries/).';
    }

    fw_header('New agent type', 'agent-types.php');
    echo '<h1>New agent type</h1>';
    foreach ($errors as $e) echo '<p style="color:#f87171">' . fw_h($e) . '</p>';
    echo '<a class="back" href="new-agent-type.php">&larr; Try again</a>';
    fw_footer();
    exit;
}

/* ---------- GET: the form ----------
 * ?id=<user type>   edit that user type in place (prefills the name too).
 * ?from=<any type>  clone into a NEW type: prefill everything except the name, so a
 *                   builtin can be a starting point (Feature 7: save as a new type).
 */
$editId = $_GET['id'] ?? '';
$fromId = $_GET['from'] ?? '';
$isClone = false;
$pre = ($editId !== '') ? deveryman_agent_type($editId) : null;
if ($pre !== null && ($pre['source'] ?? '') === 'builtin') $pre = null;   // builtins are not edited in place
if ($pre === null && $fromId !== '') {
    $src = deveryman_agent_type($fromId);
    if ($src !== null) {
        $isClone = true;
        // A builtin role is a CLAUDE.md file (no do/do-not split); drop its text into
        // free-form as an editable starting point.
        if (!empty($src['role']['ref'])) {
            $src['role'] = ['summary' => '', 'dos' => [], 'donts' => [], 'freeform' => rtrim(deveryman_render_role($src))];
        }
        $src['label'] = '';   // force a new name
        $pre = $src;
    }
}
$v = function (string $path, $default = '') use ($pre) {
    if ($pre === null) return $default;
    $cur = $pre;
    foreach (explode('.', $path) as $k) { $cur = $cur[$k] ?? null; if ($cur === null) return $default; }
    return $cur;
};
$lines = fn($arr) => is_array($arr) ? implode("\n", $arr) : '';
$editing = $pre !== null && !$isClone;

$title = $editing ? 'Edit agent type' : 'New agent type';
fw_header($title, 'agent-types.php');
echo '<h1>' . fw_h($title) . '</h1>';
if ($isClone) echo '<p class="meta">Cloning an existing type. Give it a new name to save your own copy.</p>';
echo '<p class="desc">Save a reusable agent: its role (what it does), the files it '
    . 'reads and writes, how it signals done, and the doc it leaves when it kicks work back. '
    . 'Pipeline templates then pick from these types and wire where a kickback routes. The role '
    . 'becomes the agent\'s CLAUDE.md; its inputs and outputs are enforced by the pipeline at run time.</p>';

echo '<form method="post" action="new-agent-type.php">' . fw_csrf_field();
echo '<label>Name <input type="text" name="label" value="' . fw_h((string) $v('label')) . '" placeholder="e.g. Accessibility" required></label>';
echo '<label>Kind <select name="kind">';
foreach (['stage' => 'Stage (runs in the pipeline)', 'gate' => 'Gate (human-triggered)'] as $k => $lbl) {
    $sel = ((string) $v('kind', 'stage') === $k) ? ' selected' : '';
    echo '<option value="' . $k . '"' . $sel . '>' . fw_h($lbl) . '</option>';
}
echo '</select></label>';

echo '<h2>Role</h2>';
echo '<label>Summary <input type="text" name="summary" value="' . fw_h((string) $v('role.summary')) . '" placeholder="One line: what this agent is for"></label>';
echo '<label>Do (one per line) <textarea name="dos" rows="4" placeholder="Check colour contrast&#10;Verify keyboard nav">' . fw_h($lines($v('role.dos'))) . '</textarea></label>';
echo '<label>Do not (one per line) <textarea name="donts" rows="3" placeholder="Refactor unrelated code">' . fw_h($lines($v('role.donts'))) . '</textarea></label>';
echo '<label>Free-form notes (markdown) <textarea name="freeform" rows="4" placeholder="Anything else the agent should know">' . fw_h((string) $v('role.freeform')) . '</textarea></label>';

echo '<h2>Inputs and outputs</h2>';
echo '<p class="meta">Paths are relative to the pipeline docs dir. Use <code>&lt;slug&gt;</code> for the feature slug (e.g. <code>acceptance/&lt;slug&gt;-criteria.md</code>).</p>';
echo '<label>Reads (one file per line) <textarea name="reads" rows="3" placeholder="dev-inbox/build-phase.md">' . fw_h($lines($v('reads'))) . '</textarea></label>';
echo '<label>Writes (one file per line) <textarea name="writes" rows="3" placeholder="dev-inbox/a11y-notes.md">' . fw_h($lines($v('writes'))) . '</textarea></label>';
echo '<label>Done signal <input type="text" name="done_signal" value="' . fw_h((string) $v('done_signal', 'set the pipeline-state Stage status to COMPLETE')) . '"></label>';

echo '<h2>Kickback (optional)</h2>';
echo '<p class="meta">If this agent can send work back (a reviewer or tester), name the feedback doc it '
    . 'writes. Where it routes is wired per pipeline on the template, not here.</p>';
echo '<label>Kickback doc <input type="text" name="kickback_doc" value="' . fw_h((string) $v('kickback_doc')) . '" placeholder="dev-inbox/a11y-fixes.md"></label>';

echo '<button class="btn" type="submit" style="margin-top:12px">Save agent type</button></form>';
fw_footer();
