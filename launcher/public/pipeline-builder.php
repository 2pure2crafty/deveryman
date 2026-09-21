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
$paletteJson = json_encode(deveryman_canvas_palette(), JSON_UNESCAPED_SLASHES);
$tagsJson = json_encode(array_keys(deveryman_tags()), JSON_UNESCAPED_SLASHES);
$csrf = fw_csrf_token();
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
.dv-chrome{position:fixed;top:52px;right:10px;width:250px;display:flex;flex-direction:column;gap:10px;z-index:20;max-height:calc(100vh - 64px);overflow:auto}
.dv-palette{position:fixed;top:52px;left:10px;width:220px}
.dv-panel{background:#1c1c1c;border:1px solid #333;border-radius:8px;padding:10px;font-size:.82rem}
.dv-h{color:#7ab8ff;font-weight:600;margin-bottom:6px}.dv-h2{color:#9ad0a0;font-weight:600;margin:8px 0 4px}
.dv-sub{color:#888;font-size:.76rem;margin-bottom:6px}
.dv-f{display:block;margin:5px 0}.dv-f span{display:block;color:#aaa;font-size:.74rem;margin-bottom:2px}
.dv-f input,.dv-f select,.dv-f textarea{width:100%;box-sizing:border-box;background:#141414;color:#eee;border:1px solid #333;border-radius:5px;padding:5px}
.dv-check{display:flex;align-items:center;gap:6px}.dv-check input{width:auto}.dv-check span{margin:0}
.dv-btn{background:#2563eb;color:#fff;border:none;border-radius:6px;padding:8px 12px;cursor:pointer;margin-top:8px;font-size:.82rem}
.dv-btn.dv-danger{background:#7f1d1d}.dv-save{width:100%}
.dv-msg{margin-top:8px;font-size:.78rem;color:#aaa}.dv-msg.ok{color:#4ade80}.dv-msg.err{color:#f87171}
.dv-palette select{width:100%;box-sizing:border-box;background:#141414;color:#eee;border:1px solid #333;border-radius:5px;padding:5px}
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
  DeverymanCanvas.render({
    model: <?= $modelJson ?>,
    palette: <?= $paletteJson ?>,
    tags: <?= $tagsJson ?>,
    saveUrl: "pipeline-save.php",
    csrf: <?= json_encode($csrf) ?>,
    canvas: "#c"
  });
</script>
</body></html>
