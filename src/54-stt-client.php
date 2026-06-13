// SPEECH-TO-TEXT — engine-agnostic, 100% local. PHP can't run an STT model, but
// it can drive a local one, the same way it drives Ollama: either curl a local
// HTTP server (OpenAI-compatible /v1/audio/transcriptions — whisper.cpp server,
// faster-whisper, vosk-server, …) or exec a local CLI. You bring the engine;
// this just orchestrates it. Nothing leaves the machine. Configure one of:
//   "stt": { "host": "http://localhost:8081" }            // local HTTP server
//   "stt": { "command": "whisper-cli -f {file} -otxt" }    // local CLI ({file} = audio path)
class SttClient {
    // Explicitly configured (a local HTTP server or a custom CLI).
    public static function enabled(): bool {
        return trim((string) Config::get('stt.host', '')) !== '' || trim((string) Config::get('stt.command', '')) !== '';
    }

    // Usable at all: configured OR a known open-source engine is on PATH, so
    // /voice and the desktop mic "just work" with zero config once whisper et al.
    // are installed. Still 100% local either way.
    public static function available(): bool {
        return self::enabled() || self::detectedEngine() !== '';
    }

    private static function bin(string $name): bool {
        return trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null')) !== '';
    }

    // --- Bundled / auto-provisioned whisper.cpp engine ------------------------
    // The "bake-in": /voice works with no manual install because OllamaDev ships
    // (desktop builds) or fetches (CLI, first use) a tiny self-contained
    // whisper.cpp binary + a ggml model. Source stays pure PHP — the binary is a
    // CI-built release asset, fetched at runtime like an Ollama model pull.

    // Global engine home, shared across projects: ~/.ollamadev/stt.
    public static function sttDir(): string {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.ollamadev/stt';
    }
    // A bundled engine dir the desktop launcher points at (OLLAMADEV_STT_DIR), or ''.
    private static function bundledDir(): string {
        $d = trim((string) getenv('OLLAMADEV_STT_DIR'));
        return ($d !== '' && is_dir($d)) ? rtrim($d, '/\\') : '';
    }
    // The release-asset name for this platform: whisper-<os>-<arch>[.exe].
    public static function platformTarget(): string {
        $os = stripos(PHP_OS, 'WIN') === 0 ? 'windows' : (stripos(PHP_OS, 'DARWIN') !== false ? 'mac' : 'linux');
        $m  = strtolower((string) php_uname('m'));
        $arch = (strpos($m, 'arm') !== false || strpos($m, 'aarch64') !== false) ? 'arm64' : 'x64';
        return 'whisper-' . $os . '-' . $arch . ($os === 'windows' ? '.exe' : '');
    }
    // Path to a usable whisper.cpp binary: bundled → provisioned → PATH, or ''.
    public static function whisperCppBin(): string {
        $name = self::platformTarget();
        foreach ([self::bundledDir(), self::sttDir()] as $dir) {
            if ($dir === '') continue;
            $p = $dir . '/' . $name;
            if (is_file($p)) { @chmod($p, 0755); return $p; }
        }
        foreach (['whisper-cli', 'whisper-cpp', 'main'] as $b) if (self::bin($b)) return $b;
        return '';
    }
    // ggml model filename for a size (turbo → large-v3-turbo).
    public static function ggmlModelName(string $size = ''): string {
        $size = $size !== '' ? $size : self::model();
        $s = $size === 'turbo' ? 'large-v3-turbo' : $size;
        return 'ggml-' . $s . '.bin';
    }
    // Path to the ggml model for the current size in a bundled/provisioned dir.
    // Falls back to ANY ggml-*.bin present (so a bundled/provisioned model is used
    // even if stt.model points at a size that isn't installed). '' if none at all.
    public static function ggmlModelFile(string $size = ''): string {
        $name = self::ggmlModelName($size);
        $dirs = array_filter([self::bundledDir(), self::sttDir()]);
        foreach ($dirs as $dir) {                          // exact size first
            $p = $dir . '/' . $name;
            if (is_file($p)) return $p;
        }
        foreach ($dirs as $dir) {                          // else any model present
            $found = glob($dir . '/ggml-*.bin');
            if ($found) return $found[0];
        }
        return '';
    }
    // Is a self-contained whisper.cpp engine present (bundled or provisioned)?
    public static function hasBundledEngine(): bool {
        $name = self::platformTarget();
        foreach ([self::bundledDir(), self::sttDir()] as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $name)) return true;
        }
        return false;
    }

    // The active Whisper model size (what /voice and the auto path use). Defaults
    // to 'base'; the HTTP placeholder 'whisper-1' also maps to 'base' locally.
    public static function model(): string {
        $m = trim((string) Config::get('stt.model', ''));
        return ($m === '' || $m === 'whisper-1') ? 'base' : $m;
    }
    public static function setModel(string $size): void { Config::persist('stt.model', trim($size)); }

    // Recognised open-source Whisper sizes (CPU-friendly first). Used for the
    // /voice model picker and validation hints.
    public static function modelSizes(): array {
        return ['tiny', 'base', 'small', 'medium', 'large-v3', 'turbo'];
    }

    // --- Voice transcription history (append-only JSONL in the data dir) -------
    public static function historyFile(): string {
        return rtrim(Config::dataDir(), '/\\') . '/voice-history.jsonl';
    }

    // Record one transcription. $seconds is wall-clock capture length (0 if N/A).
    public static function logHistory(string $text, string $model, string $engine, int $when): void {
        $text = trim($text);
        if ($text === '') return;
        $entry = ['ts' => $when, 'model' => $model, 'engine' => $engine, 'text' => $text];
        @file_put_contents(self::historyFile(),
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    // The last $limit transcriptions, oldest→newest. [] if none.
    public static function history(int $limit = 10): array {
        $f = self::historyFile();
        if (!is_file($f)) return [];
        $lines = array_values(array_filter(array_map('trim', (array) @file($f))));
        $out = [];
        foreach (array_slice($lines, -$limit) as $l) {
            $j = json_decode($l, true);
            if (is_array($j) && isset($j['text'])) $out[] = $j;
        }
        return $out;
    }

    public static function clearHistory(): bool { return @unlink(self::historyFile()) || !is_file(self::historyFile()); }

    // Which open-source STT engine we can drive with no config, '' if none.
    // Prefers our bundled/provisioned whisper.cpp (fast, self-contained), then
    // any Whisper-family engine on PATH.
    public static function detectedEngine(): string {
        if (self::whisperCppBin() !== '') return 'whisper.cpp';   // bundled / provisioned / PATH
        if (self::bin('faster-whisper')) return 'faster-whisper';
        if (self::bin('whisper')) return 'openai-whisper';
        return '';
    }

    // Transcribe an audio file to text. Prefers explicit config (host/command),
    // then falls back to a detected local Whisper engine. '' on failure.
    public static function transcribe(string $audioPath): string {
        if (!is_file($audioPath)) return '';
        $host = trim((string) Config::get('stt.host', ''));
        $cmd  = trim((string) Config::get('stt.command', ''));
        if ($host !== '') return self::viaHttp($host, $audioPath);
        if ($cmd !== '')  return self::viaCommand($cmd, $audioPath);
        return self::viaAuto($audioPath);
    }

    // --- Mic recording (for /voice). Records 16 kHz mono WAV (ideal for Whisper)
    // via whichever open-source recorder is present. Returns the recorder name. -
    private static function recorder(): string {
        foreach (['arecord', 'ffmpeg', 'parecord'] as $r) if (self::bin($r)) return $r;
        return '';
    }
    public static function canRecord(): bool { return self::recorder() !== ''; }

    // Start recording in the background; returns the PID (0 on failure). Caller
    // stops it (Enter-to-stop UX) via stopRecording(). $maxSecs is a safety cap.
    public static function startRecording(string $out, int $maxSecs = 60): int {
        $r = self::recorder();
        $a = escapeshellarg($out);
        $d = (int) max(1, $maxSecs);
        $cmd = match ($r) {
            'arecord'  => "arecord -q -f S16_LE -r 16000 -c 1 -d $d $a",
            'ffmpeg'   => "ffmpeg -y -f alsa -i default -ar 16000 -ac 1 -t $d $a",
            'parecord' => "timeout $d parecord --rate=16000 --channels=1 --format=s16le --file-format=wav $a",
            default    => '',
        };
        if ($cmd === '') return 0;
        return (int) shell_exec($cmd . ' >/dev/null 2>&1 & echo $!');
    }

    // Stop a background recording cleanly (SIGINT lets the recorder finalize the
    // WAV header), then force-kill if it lingers.
    public static function stopRecording(int $pid): void {
        if ($pid <= 0) return;
        @shell_exec('kill -INT ' . (int) $pid . ' 2>/dev/null');
        usleep(250000);
        @shell_exec('kill ' . (int) $pid . ' 2>/dev/null');
    }

    // Zero-config transcription via a detected local Whisper engine.
    private static function viaAuto(string $file): string {
        $engine = self::detectedEngine();
        if ($engine === '') return '';
        // stt.model defaults to the HTTP name 'whisper-1'; for a local CLI map
        // that to a real Whisper size. Any explicit size (tiny|base|small|
        // medium|large-v3|distil-*) or a .bin/.onnx model path is honored.
        $model = trim((string) Config::get('stt.model', ''));
        $size  = self::model();
        $lang  = trim((string) Config::get('stt.language', '')); // '' = auto-detect
        $dir   = rtrim(sys_get_temp_dir(), '/\\');
        $base  = pathinfo($file, PATHINFO_FILENAME);

        if ($engine === 'openai-whisper') {
            $langArg = $lang !== '' ? ' --language ' . escapeshellarg($lang) : '';
            $cmd = 'whisper ' . escapeshellarg($file)
                 . ' --model ' . escapeshellarg($size) . $langArg
                 . ' --output_format txt --output_dir ' . escapeshellarg($dir)
                 . ' --device cpu --fp16 False 2>/dev/null';
            @shell_exec($cmd);
            $txt = $dir . '/' . $base . '.txt';
            $out = is_file($txt) ? trim((string) @file_get_contents($txt)) : '';
            @unlink($txt);
            return $out;
        }
        if ($engine === 'whisper.cpp') {
            $bin = self::whisperCppBin();
            if ($bin === '') return '';
            // Model: a bundled/provisioned ggml file for the size, else an explicit
            // stt.model path (advanced), else none → can't run.
            $mPath = self::ggmlModelFile();
            if ($mPath === '' && $model !== '' && is_file($model)) $mPath = $model;
            if ($mPath === '') return '';
            $langArg = $lang !== '' ? ' -l ' . escapeshellarg($lang) : '';
            $of = $dir . '/odv_wcpp_' . getmypid();
            @shell_exec(escapeshellarg($bin) . ' -m ' . escapeshellarg($mPath) . ' -f ' . escapeshellarg($file)
                . $langArg . ' -nt -otxt -of ' . escapeshellarg($of) . ' 2>/dev/null');
            $out = is_file($of . '.txt') ? trim((string) @file_get_contents($of . '.txt')) : '';
            @unlink($of . '.txt');
            return $out;
        }
        if ($engine === 'faster-whisper') {
            $langArg = $lang !== '' ? ' --language ' . escapeshellarg($lang) : '';
            $out = trim((string) @shell_exec('faster-whisper ' . escapeshellarg($file)
                 . ' --model ' . escapeshellarg($size) . $langArg
                 . ' --device cpu 2>/dev/null'));   // CPU-only by design
            return $out;
        }
        return '';
    }

    // POST the audio to a local OpenAI-compatible transcription endpoint.
    private static function viaHttp(string $host, string $file): string {
        $url = rtrim($host, '/');
        if (!preg_match('#/transcriptions$#', $url)) $url .= '/v1/audio/transcriptions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new CURLFile($file), 'model' => trim((string) Config::get('stt.model', 'whisper-1')), 'response_format' => 'json'],
            CURLOPT_HTTPHEADER => ['Authorization: Bearer local'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return '';
        $j = json_decode((string) $resp, true);
        if (is_array($j) && isset($j['text'])) return trim((string) $j['text']);   // {"text": "..."}
        return ($j === null && is_string($resp)) ? trim($resp) : '';               // some servers reply plain text
    }

    // Run a local CLI: "{file}" is replaced with the audio path; else it's appended.
    private static function viaCommand(string $tpl, string $file): string {
        $cmd = strpos($tpl, '{file}') !== false
            ? str_replace('{file}', escapeshellarg($file), $tpl)
            : $tpl . ' ' . escapeshellarg($file);
        return trim((string) @shell_exec($cmd . ' 2>/dev/null'));
    }

    // --- Auto-provision (the "bake-in") --------------------------------------
    // Download a self-contained whisper.cpp engine (this platform's release
    // asset) + the ggml model for $size into ~/.ollamadev/stt, so /voice works
    // with no manual install. One-time; needs network once, then fully local.
    // $onProgress($label, $done, $total) is optional.
    public static function provision(?callable $onProgress = null, string $size = ''): bool {
        $dir = self::sttDir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir)) return false;

        // 1. Engine binary (skip if we already have one bundled or on PATH).
        if (self::whisperCppBin() === '') {
            $target  = self::platformTarget();
            $binPath = $dir . '/' . $target;
            $url = 'https://github.com/kennethyork/OllamaDev/releases/latest/download/' . $target;
            if (!self::download($url, $binPath, $onProgress, 'engine')) { @unlink($binPath); return false; }
            @chmod($binPath, 0755);
        }
        // 2. Model from Hugging Face (ggerganov/whisper.cpp), if not already present.
        $size = $size !== '' ? $size : self::model();
        if (self::ggmlModelFile($size) === '') {
            $name = self::ggmlModelName($size);
            $dest = $dir . '/' . $name;
            $url  = 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/' . $name . '?download=true';
            if (!self::download($url, $dest, $onProgress, 'model ' . $name)) { @unlink($dest); return false; }
        }
        return self::whisperCppBin() !== '' && self::ggmlModelFile($size) !== '';
    }

    // Stream a URL to a file with optional progress. Follows redirects (GitHub /
    // Hugging Face both 302 to a CDN). Returns false on any HTTP/transport error.
    private static function download(string $url, string $dest, ?callable $onProgress, string $label): bool {
        $fh = @fopen($dest, 'wb');
        if ($fh === false) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'OllamaDev/' . (defined('OLLAMADEV_VERSION') ? OLLAMADEV_VERSION : 'dev'),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dlNow) use ($onProgress, $label) {
                if ($onProgress && $dlTotal > 0) $onProgress($label, (int) $dlNow, (int) $dlTotal);
                return 0;
            },
        ]);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $code >= 400) { @unlink($dest); return false; }
        return true;
    }
}
