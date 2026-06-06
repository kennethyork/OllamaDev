// CONTEXT TUNER — probe the machine + the active model and recommend a safe
// context window (num_ctx). Bigger num_ctx needs more RAM/VRAM for the KV cache,
// so the right ceiling is hardware-dependent. This gives an honest estimate and
// the exact command to set it. Drives `ollamadev context`.
class ContextTuner {
    // Total system RAM in bytes (Linux /proc/meminfo, macOS sysctl), or 0.
    public static function ramBytes(): int {
        $mi = @file_get_contents('/proc/meminfo');
        if ($mi && preg_match('/MemTotal:\s+(\d+)\s*kB/', $mi, $m)) return (int)$m[1] * 1024;
        $sysctl = trim((string)@shell_exec('command -v sysctl >/dev/null 2>&1 && sysctl -n hw.memsize 2>/dev/null'));
        if (ctype_digit($sysctl)) return (int)$sysctl;
        return 0;
    }

    // Largest GPU's VRAM in bytes via nvidia-smi, or 0 (CPU / non-NVIDIA / unknown).
    public static function vramBytes(): int {
        if (trim((string)@shell_exec('command -v nvidia-smi 2>/dev/null')) === '') return 0;
        $out = (string)@shell_exec('nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits 2>/dev/null');
        $max = 0;
        foreach (preg_split('/\r?\n/', trim($out)) as $line) { if (ctype_digit(trim($line))) $max = max($max, (int)trim($line)); }
        return $max * 1024 * 1024; // MiB → bytes
    }

    // Recommend a num_ctx that fits in $budget after the model's weights, clamped
    // to the model's native max. Conservative ~6k tokens of KV per free GB.
    private static function suggest(int $budget, int $modelBytes, int $modelMaxCtx): int {
        $overhead = 1_500_000_000; // runtime + activations headroom
        $free = $budget - $modelBytes - $overhead;
        if ($budget <= 0) return 16384; // unknown hardware → safe default
        if ($free <= 0) return 4096;
        $tokens = (int)(($free / 1_000_000_000) * 6000);
        $ceil = $modelMaxCtx > 0 ? $modelMaxCtx : 131072;
        $n = max(4096, min($tokens, $ceil, 131072));
        return (int)(floor($n / 4096) * 4096) ?: 4096; // round to a 4k multiple
    }

    public static function probe(): array {
        $ram = self::ramBytes(); $vram = self::vramBytes();
        $budget = $vram > 0 ? $vram : $ram;
        $model = Config::get('ollama.defaultModel', '');
        $modelBytes = 0;
        if (class_exists('OllamaClient')) {
            foreach ((new OllamaClient())->listModelsDetailed() as $m) {
                if (($m['name'] ?? '') === $model) { $modelBytes = (int)($m['size'] ?? 0); break; }
            }
        }
        $modelMax = (class_exists('OllamaClient') && $model !== '') ? OllamaClient::modelContextLength($model) : 0;
        return [
            'ram' => $ram, 'vram' => $vram, 'budget' => $budget,
            'model' => $model, 'modelBytes' => $modelBytes, 'modelMax' => $modelMax,
            'currentMax' => (int)Config::get('ollama.maxContextWindow', 32768),
            'currentBase' => (int)Config::get('ollama.contextWindow', 16384),
            'autoContext' => (bool)Config::get('ollama.autoContext', true),
            'suggested' => self::suggest($budget, $modelBytes, $modelMax),
        ];
    }

    private static function gb(int $b): string { return $b > 0 ? round($b / 1_000_000_000, 1) . ' GB' : 'unknown'; }

    public static function report(): string {
        $p = self::probe();
        $c = "\033[36m"; $d = "\033[2m"; $g = "\033[32m"; $y = "\033[33m"; $b = "\033[1m"; $r = "\033[0m";
        $where = $p['vram'] > 0 ? 'GPU VRAM' : 'system RAM';
        $out  = "\n{$b}OllamaDev — context tuner{$r}\n" . str_repeat('─', 46) . "\n";
        $out .= "  System RAM       {$c}" . self::gb($p['ram']) . "{$r}\n";
        $out .= "  GPU VRAM         {$c}" . ($p['vram'] > 0 ? self::gb($p['vram']) : 'none detected') . "{$r}\n";
        $out .= "  Budget ({$where}) {$c}" . self::gb($p['budget']) . "{$r}\n";
        $out .= "  Model            {$c}" . ($p['model'] ?: '(none)') . "{$r}{$d} · weights " . self::gb($p['modelBytes']) . ($p['modelMax'] > 0 ? " · native max " . number_format($p['modelMax']) . " tok" : '') . "{$r}\n";
        $cur = $p['autoContext'] ? ("auto → up to " . number_format($p['currentMax'])) : ("pinned " . number_format($p['currentBase']));
        $out .= "  Current num_ctx  {$c}$cur tok{$r}\n";
        $out .= "\n  {$g}{$b}Suggested: " . number_format($p['suggested']) . " tokens{$r} {$d}(estimate — KV cache scales with num_ctx){$r}\n";
        $out .= "  {$d}Set it for a session:{$r} {$c}ollamadev --num-ctx " . $p['suggested'] . "{$r}\n";
        $out .= "  {$d}Or persist in ~/.ollamadev/config.json:{$r} {$c}\"ollama\": { \"maxContextWindow\": " . $p['suggested'] . " }{$r}\n";
        if ($p['budget'] === 0) $out .= "  {$y}⚠ couldn't read memory — suggestion is a safe default; tune to taste.{$r}\n";
        $out .= "  {$d}Bigger = more room but more memory + slower. For tasks too big for any window, split with `crew`.{$r}\n";
        return $out . "\n";
    }
}
