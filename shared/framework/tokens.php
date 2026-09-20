<?php
declare(strict_types=1);
/*
 * Shared token accounting: read Claude Code transcripts and total usage across
 * everything (Conductor agents, DPA agents, any). Used by the Everyman launcher
 * for the aggregate view. Conductor still has its own copy (converge later).
 */

function fw_transcripts_dir(): string {
    return rtrim(fw_config_get('CONDUCTOR_TRANSCRIPTS_DIR', '/home/patch/.claude/projects'), '/');
}

function fw_fmt_tokens(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return round($n / 1000, 1) . 'k';
    return (string) $n;
}

/** Sum input/output/cache tokens across every transcript on disk. */
function fw_total_tokens(): array {
    $t = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'files' => 0];
    foreach (glob(fw_transcripts_dir() . '/*/*.jsonl') as $f) {
        $t['files']++;
        foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rec = json_decode($line, true);
            if (!is_array($rec)) continue;
            $msg = $rec['message'] ?? [];
            $u = (is_array($msg) ? ($msg['usage'] ?? null) : null) ?? ($rec['usage'] ?? null);
            if (!is_array($u)) continue;
            $t['input']       += (int)($u['input_tokens'] ?? 0);
            $t['output']      += (int)($u['output_tokens'] ?? 0);
            $t['cache_read']  += (int)($u['cache_read_input_tokens'] ?? 0);
            $t['cache_write'] += (int)($u['cache_creation_input_tokens'] ?? 0);
        }
    }
    return $t;
}

/**
 * Estimated tokens SAVED by auto-wrap-downs: sum the context size at each
 * wrap-down (from the Conductor daemon log) times ~1.25 (the cold cache rebuild
 * avoided by resetting to a small SESSION.md). A guesstimate, not billing truth.
 */
function fw_estimated_saved(): int {
    $log = fw_config_get('CONDUCTOR_LOG_DIR', '/var/log/conductor') . '/daemon.log';
    if (!is_file($log)) return 0;
    $saved = 0;
    foreach (@file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_contains($line, 'WRAPDOWN') && preg_match('/ctx=(\d+)/', $line, $m)) {
            $saved += (int) round((int) $m[1] * 1.25);
        }
    }
    return $saved;
}
