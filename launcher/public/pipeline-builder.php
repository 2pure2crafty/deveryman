<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../canvas.php';
fw_require_auth();

// The pipeline builder is a full-width canvas surface, so it does NOT use the 680px
// mobile shell (fw_header). It loads one template into the model the adapter draws.
// Render-only for now; interactive editing + save is the next milestone.
$tplId = $_GET['template'] ?? 'dpa-standard';
$model = deveryman_canvas_model($tplId);
if ($model === null) {
    http_response_code(404);
    fw_header('Builder', 'templates.php');
    echo '<h1>Pipeline builder</h1><p style="color:#f87171">No such template: ' . fw_h($tplId) . '</p>';
    echo '<a class="back" href="templates.php">&larr; Templates</a>';
    fw_footer();
    exit;
}
$modelJson = json_encode($model, JSON_UNESCAPED_SLASHES);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#111111">
<title><?= fw_h($model['label']) ?> - pipeline builder</title>
<link rel="stylesheet" href="/vendor/litegraph/litegraph.css">
<style>
html,body{margin:0;background:#111;color:#eee;font-family:system-ui,-apple-system,sans-serif;overflow:hidden}
#bar{height:42px;display:flex;align-items:center;gap:14px;padding:0 12px;box-sizing:border-box;
     background:#161616;border-bottom:1px solid #333;font-size:.82rem}
#bar a{color:#888;text-decoration:none}#bar b{color:#7ab8ff}
.lg{margin-left:auto;display:flex;gap:12px}
.f{color:#7ab8ff}.k{color:#f87171}.e{color:#f0b429}.t{color:#2563eb}.muted{color:#888}
canvas{display:block}
</style></head><body>
<div id="bar">
  <a href="templates.php">&larr; Templates</a>
  <b><?= fw_h($model['label']) ?></b><span class="muted">pipeline builder</span>
  <span class="lg">
    <span class="f">&#9679; flow</span><span class="k">&#9679; kickback</span>
    <span class="e">&#9679; escalation</span><span class="t">&#9679; tag fork</span>
    <span class="muted">drag &middot; scroll-zoom &middot; pan</span>
  </span>
</div>
<canvas id="c"></canvas>
<script src="/vendor/litegraph/litegraph.js"></script>
<script src="/js/pipeline-canvas.js"></script>
<script>
  window.CANVAS_MODEL = <?= $modelJson ?>;
  DeverymanCanvas.render(window.CANVAS_MODEL, "#c");
</script>
</body></html>
