// Real token / context usage meter. Ollama's /api/chat responses carry
// prompt_eval_count (tokens in the prompt the model just processed) and
// eval_count (tokens it generated). We capture the latest values at the
// client layer and expose them here, plus cumulative per-session totals that
// are persisted under Config::costsDir() so they survive turns and resumes.
class Usage {
    // Latest single-turn counts (from the most recent /api/chat response).
    private static int $lastPrompt = 0;
    private static int $lastEval = 0;
    private static bool $haveReal = false;

    // Cumulative totals for the active session.
    private static int $totalPrompt = 0;
    private static int $totalEval = 0;
    private static ?string $sessionId = null;

    // Called by OllamaClient after every chat response. $j is the decoded
    // final JSON object (the 'done' line for streams, or the whole body for
    // non-streaming). No-ops gracefully when the counts are absent.
    public static function record($j): void {
        if (!is_array($j)) return;
        $p = isset($j['prompt_eval_count']) ? (int)$j['prompt_eval_count'] : 0;
        $e = isset($j['eval_count']) ? (int)$j['eval_count'] : 0;
        if ($p <= 0 && $e <= 0) return;
        self::$lastPrompt = $p;
        self::$lastEval = $e;
        self::$haveReal = true;
        self::$totalPrompt += $p;
        self::$totalEval += $e;
        self::persist();
    }

    public static function haveReal(): bool { return self::$haveReal; }
    public static function lastPrompt(): int { return self::$lastPrompt; }
    public static function lastEval(): int { return self::$lastEval; }
    public static function totalPrompt(): int { return self::$totalPrompt; }
    public static function totalEval(): int { return self::$totalEval; }

    public static function contextWindow(): int {
        // Prefer the actual num_ctx last sent (auto-grown to the model's max), so
        // the meter reflects the real window, not just the configured baseline.
        $eff = class_exists('OllamaClient') ? OllamaClient::effectiveContext() : 0;
        return $eff > 0 ? $eff : (int)Config::get('ollama.contextWindow', 16384);
    }

    // A 10-cell ASCII fill bar for the given fraction (0..1).
    public static function bar(float $frac, int $cells = 10): string {
        if ($frac < 0) $frac = 0; if ($frac > 1) $frac = 1;
        $filled = (int)round($frac * $cells);
        return '[' . str_repeat('#', $filled) . str_repeat('-', $cells - $filled) . ']';
    }

    // "4210/16384 tokens (26%) [###-------]" using real prompt tokens when
    // available; otherwise the caller's estimate, flagged with a leading ~.
    public static function contextMeter(int $estimate): string {
        $ctx = self::contextWindow();
        $used = self::$haveReal ? self::$lastPrompt : $estimate;
        $approx = self::$haveReal ? '' : '~';
        $frac = $ctx > 0 ? $used / $ctx : 0.0;
        $pct = (int)round($frac * 100);
        return sprintf('%s%d/%d tokens (%d%%) %s', $approx, $used, $ctx, $pct, self::bar($frac));
    }

    // Bind the active session so persisted totals load and subsequent records
    // accumulate against the right file.
    public static function bindSession(string $id): void {
        if (self::$sessionId === $id) return;
        self::$sessionId = $id;
        self::$totalPrompt = 0; self::$totalEval = 0;
        $path = self::path($id);
        if ($path !== null && is_file($path)) {
            $d = json_decode((string)file_get_contents($path), true);
            if (is_array($d)) {
                self::$totalPrompt = (int)($d['total_prompt'] ?? 0);
                self::$totalEval = (int)($d['total_eval'] ?? 0);
            }
        }
    }

    private static function path(?string $id): ?string {
        if ($id === null || $id === '') return null;
        return Config::costsDir() . '/' . $id . '.json';
    }

    private static function persist(): void {
        $path = self::path(self::$sessionId);
        if ($path === null) return;
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($path, json_encode([
            'id' => self::$sessionId,
            'total_prompt' => self::$totalPrompt,
            'total_eval' => self::$totalEval,
            'updated_at' => date('c'),
        ], JSON_PRETTY_PRINT));
    }
}
