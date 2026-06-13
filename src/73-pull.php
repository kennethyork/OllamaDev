// Streaming model pull via Ollama POST /api/pull. Vanilla curl + NDJSON.
class Puller {
    public static function pull(string $model, ?string $host = null): bool {
        $model = trim($model);
        if ($model === '') { echo "\033[33m  pull: no model given\033[0m\n"; return false; }
        $host = $host ?: Config::get('ollama.host', 'http://localhost:11434');
        $isTty = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : false;
        $maxAttempts = 4;
        // A pull is a long download; a network blip shouldn't lose all progress. On a
        // TRANSIENT drop we re-invoke /api/pull — Ollama resumes from the layers it
        // already fetched (content-addressed), so it continues rather than restarts.
        // A server-side error (model not found / auth) is NOT retried.
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($host . '/api/pull');
            $buf = ''; $ok = false; $errMsg = ''; $lastPct = -1; $lastDrawn = '';
            $render = function(string $line) use ($isTty, &$lastDrawn) {
                if ($isTty) { echo "\r\033[K\033[2m  " . $line . "\033[0m"; }
                else { if ($line === $lastDrawn) return; echo "  " . $line . "\n"; }
                $lastDrawn = $line;
            };
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'stream' => true]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 0,
                CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buf, &$ok, &$errMsg, &$lastPct, $render, $model) {
                    if (class_exists('Interrupt') && Interrupt::aborted()) return 0;
                    $buf .= $data;
                    while (($nl = strpos($buf, "\n")) !== false) {
                        $line = trim(substr($buf, 0, $nl));
                        $buf = substr($buf, $nl + 1);
                        if ($line === '') continue;
                        $j = json_decode($line, true);
                        if (!is_array($j)) continue;
                        if (!empty($j['error'])) { $errMsg = (string)$j['error']; continue; }
                        $status = (string)($j['status'] ?? '');
                        if (stripos($status, 'success') !== false) { $ok = true; continue; }
                        $total = (int)($j['total'] ?? 0);
                        $completed = (int)($j['completed'] ?? 0);
                        if ($total > 0) {
                            $pct = (int)floor($completed * 100 / $total);
                            if ($pct !== $lastPct) { $lastPct = $pct; $render(sprintf('pulling %s  %3d%%  (%s / %s)', $model, $pct, Puller::bytes($completed), Puller::bytes($total))); }
                        } else {
                            $render('pulling ' . $model . '  ' . ($status !== '' ? $status : '...'));
                        }
                    }
                    return strlen($data);
                },
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch); $curlErr = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($isTty) echo "\r\033[K";
            if ($ok) { echo "\033[32m  + pulled " . $model . "\033[0m\n"; return true; }
            if ($errMsg !== '') { echo "\033[33m  pull failed: " . $errMsg . "\033[0m\n"; return false; }  // server said no — don't retry
            $aborted = class_exists('Interrupt') && Interrupt::aborted();
            if (!$aborted && $attempt < $maxAttempts && class_exists('OllamaClient') && OllamaClient::isTransient($errno, $code)) {
                echo "\033[2m  network blip — resuming pull (attempt " . ($attempt + 1) . "/$maxAttempts)…\033[0m\n";
                usleep(800000);
                continue;
            }
            $msg = $curlErr !== '' ? $curlErr : ($code !== 200 && $code !== 0 ? 'HTTP ' . $code : 'pull failed');
            echo "\033[33m  pull failed: " . $msg . "\033[0m\n";
            return false;
        }
        return false;
    }
    public static function bytes(int $b): string {
        if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
        if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
        if ($b >= 1024) return round($b / 1024, 1) . ' KB';
        return $b . ' B';
    }
}
