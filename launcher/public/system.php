<?php
declare(strict_types=1);
define('DEVERYMAN_APP', "D'everyman");
define('DEVERYMAN_CONFIG', getenv('DEVERYMAN_CONFIG') ?: '/etc/default/conductor');
require __DIR__ . '/../../shared/framework/framework.php';
require __DIR__ . '/../lib.php';
fw_require_auth();

$flash = $_GET['msg'] ?? '';

fw_header('System', '/');
echo '<h1>System</h1>';
echo '<p class="desc">D\'everyman as a GUI over its own host: service health, restarts, '
    . 'and host reboot. All actions run through a tightly scoped sudo allowlist.</p>';

if ($flash !== '') {
    echo '<div class="card"><strong>' . fw_h($flash) . '</strong></div>';
}

// --- host vitals ----------------------------------------------------------
$v = host_vitals();
echo '<div class="card"><strong>Host</strong>'
    . '<div class="meta">' . fw_h(gethostname() ?: '') . '</div>'
    . '<div class="meta">uptime: ' . fw_h($v['uptime']) . ' &nbsp; load: ' . fw_h($v['load']) . '</div>'
    . '<div class="meta">memory: ' . fw_h($v['mem']) . '</div>'
    . '<div class="meta">disk (/): ' . fw_h($v['disk']) . '</div>'
    . '</div>';

// --- core services --------------------------------------------------------
echo '<h2>Core services</h2>';
echo '<p class="meta">"Enabled" means it starts automatically on boot (survives a reboot).</p>';
foreach (deveryman_core_services() as $unit => $label) {
    $st = service_state($unit . '.service');
    echo '<div class="card"><strong>' . fw_h($label) . '</strong>';
    echo '<span class="pill ' . ($st['active'] ? 'on' : 'off') . '">' . fw_h($st['active_raw']) . '</span>';
    echo '<span class="pill ' . ($st['enabled'] ? 'on' : 'off') . '">'
        . ($st['enabled'] ? 'boot: on' : 'boot: ' . fw_h($st['enabled_raw'])) . '</span>';
    echo '<div class="meta">' . fw_h($unit) . '.service</div>';
    echo '<form method="post" action="system-action.php" style="margin-top:8px">'
        . '<input type="hidden" name="action" value="restart-service">'
        . '<input type="hidden" name="service" value="' . fw_h($unit) . '">'
        . '<button class="btn" type="submit">Restart</button></form>';
    echo '</div>';
}

// --- DPA pipeline daemons -------------------------------------------------
$dpa = deveryman_dpa_instances();
if ($dpa) {
    echo '<h2>Pipeline daemons</h2>';
    foreach ($dpa as $slug => $label) {
        $st = service_state("dpa-underseer@{$slug}.service");
        echo '<div class="card"><strong>' . fw_h($label) . '</strong>';
        echo '<span class="pill ' . ($st['active'] ? 'on' : 'off') . '">' . fw_h($st['active_raw']) . '</span>';
        echo '<span class="pill ' . ($st['enabled'] ? 'on' : 'off') . '">'
            . ($st['enabled'] ? 'boot: on' : 'boot: ' . fw_h($st['enabled_raw'])) . '</span>';
        echo '<div class="meta">start/stop these from each project\'s DPA page</div>';
        echo '</div>';
    }
}

// --- danger zone ----------------------------------------------------------
echo '<h2>Reboot host</h2>';
echo '<div class="card">';
echo '<p class="desc">Reboots the whole server. Enabled services (above) come back automatically; '
    . 'this app will be unreachable for a minute or two. Type <code>REBOOT</code> to confirm.</p>';
echo '<form method="post" action="system-action.php" onsubmit="return confirm(\'Reboot the server now?\')">'
    . '<input type="hidden" name="action" value="reboot">'
    . '<label>Confirmation<input type="text" name="confirm" placeholder="type REBOOT" autocomplete="off"></label>'
    . '<button class="btn stop" type="submit" style="margin-top:10px;background:#b91c1c">Reboot server</button>'
    . '</form>';
echo '</div>';

fw_footer();
