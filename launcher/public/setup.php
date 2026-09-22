<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
fw_require_auth();
fw_demo_block('AI credentials');

// Claude Code status: is the CLI present, and has it been used (auth/config
// present)? We don't spend a token to verify; we report what's on disk.
[$exit, $which] = fw_run_cmd(['bash', '-lc', 'command -v claude']);
$claudeCli = $exit === 0 && trim($which) !== '';
$home = getenv('HOME') ?: '/home/' . (getenv('USER') ?: 'you');
$claudeDir = is_dir($home . '/.claude');
$claudeCreds = is_file($home . '/.claude/.credentials.json') || is_file($home . '/.claude.json');
$claudeReady = $claudeCli && ($claudeCreds || $claudeDir);

fw_header('Setup', '/');
echo '<h1>AI credentials</h1>';
echo '<p class="desc">D\'everyman drives AI agents. Connect the AI services it may '
    . 'use here. Claude (Anthropic) is supported today; more are planned.</p>';

// Claude (first / only provider today)
echo '<div class="card"><strong>Claude (Anthropic)</strong>'
    . '<span class="pill ' . ($claudeReady ? 'on' : 'off') . '">'
    . ($claudeReady ? 'connected' : ($claudeCli ? 'not logged in' : 'CLI not found')) . '</span>';
if (!$claudeCli) {
    echo '<div class="meta">The <code>claude</code> CLI is not on PATH. Install Claude Code, '
        . 'then log in.</div>';
} elseif (!$claudeReady) {
    echo '<div class="meta">Run <code>claude</code> once as this service user and log in. '
        . 'That login is the credential D\'everyman uses; nothing is stored here.</div>';
} else {
    echo '<div class="meta">Claude Code is installed and authenticated for this user. '
        . 'Agents will use it.</div>';
}
echo '</div>';

// Future providers (framing, not yet wired)
echo '<div class="card" style="opacity:0.6"><strong>More AI models and services</strong>'
    . '<span class="pill off">planned</span>'
    . '<div class="meta">Additional providers (other models, hosted services) will slot in '
    . 'here. Claude is the first.</div></div>';

echo '<a class="back" href="/">&larr; ' . fw_h(DEVERYMAN_APP) . '</a>';
fw_footer();
