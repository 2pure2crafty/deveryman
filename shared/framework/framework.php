<?php
declare(strict_types=1);
/*
 * D'everyman shared framework: the generic plumbing every dashboard reuses
 * (auth, config, HTML shell, process runner). Prefixed fw_ to avoid collisions
 * while Conductor still carries its own copies; Conductor converges onto this
 * later (see docs/FUTURE.md). Apps set DEVERYMAN_APP and DEVERYMAN_CONFIG before
 * requiring this file.
 */

if (!defined('DEVERYMAN_APP'))    define('DEVERYMAN_APP', "D'everyman");
if (!defined('DEVERYMAN_CONFIG')) define('DEVERYMAN_CONFIG', '/etc/default/conductor');

/** Escape for HTML. */
function fw_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Parse KEY=value lines from the app's config file. Cached. */
function fw_config(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    if (is_readable(DEVERYMAN_CONFIG)) {
        foreach (file(DEVERYMAN_CONFIG, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) $c[$m[1]] = trim($m[2], "\"'");
        }
    }
    return $c;
}

function fw_config_get(string $key, string $default = ''): string {
    return fw_config()[$key] ?? $default;
}

/** HTTP basic auth against CONDUCTOR_USER/CONDUCTOR_PASS in the config file. */
function fw_require_auth(): void {
    $user = fw_config_get('CONDUCTOR_USER');
    $pass = fw_config_get('CONDUCTOR_PASS');
    $gu = $_SERVER['PHP_AUTH_USER'] ?? '';
    $gp = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user === '' || $pass === '' || !hash_equals($user, $gu) || !hash_equals($pass, $gp)) {
        header('WWW-Authenticate: Basic realm="' . DEVERYMAN_APP . '"');
        http_response_code(401);
        echo 'Auth required.';
        exit;
    }
}

/** Run an argv command (no shell), optionally with stdin and a timeout.
 *  Returns [exit, stdout, stderr]. On timeout: exit 124. */
function fw_run_cmd(array $argv, ?string $cwd = null, string $stdin = '', int $timeout = 0): array {
    $d = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($argv, $d, $pipes, $cwd);
    if (!is_resource($p)) return [1, '', 'failed to start'];
    if ($stdin !== '') fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    if ($timeout <= 0) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $deadline = microtime(true) + $timeout;
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if (microtime(true) > $deadline) {
            proc_terminate($p, 9);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            return [124, $out, $err . "\n[timeout]"];
        }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

/**
 * Write JSON atomically: encode, write to a temp file, then rename over the
 * target (rename is atomic on the same filesystem). A crash or full disk mid-write
 * leaves the original intact instead of a truncated, unparseable file. Returns
 * true on success. Callers MUST check the return value.
 */
function fw_write_json_atomic(string $path, $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if ($json === false) return false;
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Locked read-modify-write of a JSON file. Opens the file, takes an exclusive
 * lock, decodes, hands the array to $mutator, and writes the returned array back
 * under the same lock, so concurrent writers cannot clobber each other. Returns
 * true on success, false if the file could not be opened/locked/written.
 */
function fw_update_json(string $path, callable $mutator): bool {
    $fh = @fopen($path, 'c+');
    if ($fh === false) return false;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
    $raw = stream_get_contents($fh);
    $data = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?: []) : [];
    $updated = $mutator($data);
    rewind($fh);
    ftruncate($fh, 0);
    $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $ok = ($json !== false) && (fwrite($fh, $json) !== false);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/** One-shot headless reasoning via the claude CLI (Haiku). Returns text or null. */
function fw_reason(string $prompt, string $stdin = '', int $timeout = 120): ?string {
    [$exit, $out] = fw_run_cmd(['claude', '--model', 'haiku', '-p', $prompt], null, $stdin, $timeout);
    $out = trim($out);
    return ($exit === 0 && $out !== '') ? $out : null;
}

/** Shared dark mobile shell. $home is a link back to the launcher/dashboard. */
function fw_header(string $title, string $home = ''): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="theme-color" content="#111111">'
        . '<title>' . fw_h($title) . ' - ' . fw_h(DEVERYMAN_APP) . '</title><style>'
        . 'body{font-family:system-ui,sans-serif;max-width:680px;margin:0 auto;padding:16px;background:#111;color:#eee}'
        . 'a{color:#7ab8ff}h1{font-size:1.4rem}h2{font-size:1.1rem;margin-top:1.5em}'
        . '.card{background:#1c1c1c;border:1px solid #333;border-radius:8px;padding:14px;margin:10px 0}'
        . '.desc{color:#aaa;font-size:0.9rem;margin-top:4px}.meta{color:#888;font-size:0.8rem;margin-top:4px}'
        . '.btn{display:inline-block;background:#2563eb;color:#fff;padding:12px 18px;border-radius:6px;'
        . 'text-decoration:none;font-size:1rem;border:none;cursor:pointer;margin:4px 4px 4px 0}'
        . '.pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.8rem;margin-left:6px}'
        . '.pill.on{background:#14532d;color:#bbf7d0}.pill.off{background:#3f3f46;color:#d4d4d8}'
        . 'pre{white-space:pre-wrap;word-wrap:break-word;background:#141414;border:1px solid #333;'
        . 'border-radius:6px;padding:10px;font-size:0.82rem;color:#cfcfcf;max-height:360px;overflow:auto}'
        . '.back{display:inline-block;margin-bottom:10px;color:#888;text-decoration:none}'
        . '</style></head><body>';
    if ($home !== '') echo '<a class="back" href="' . fw_h($home) . '">&larr; ' . fw_h(DEVERYMAN_APP) . '</a>';
}

function fw_footer(): void {
    echo '</body></html>';
}
