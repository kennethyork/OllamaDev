class OllamaClient {
    private string $host;
    private int $timeout = 120;

    public function __construct(?string $host = null) {
        $this->host = $host ?? Config::get('ollama.host', 'http://localhost:11434');
    }

    public function host(): string { return $this->host; }

    // Encode a request body so a stray invalid-UTF-8 byte in file content (common
    // once a read/grep pulls in a binary blob or an oddly-encoded file) substitutes
    // to U+FFFD instead of making json_encode() return false — which would set
    // CURLOPT_POSTFIELDS to an empty body and silently break the whole turn.
    public static function jenc($data): string {
        $s = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        if ($s === false) $s = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $s === false ? '{}' : $s;
    }

    // Is a curl errno / HTTP status a TRANSIENT network failure worth retrying?
    // Covers dropped connections, a server that's briefly down, and 5xx/429 — NOT
    // a clean 4xx (a bad request won't fix itself by retrying).
    public static function isTransient(int $errno, int $code): bool {
        // 6 resolve · 7 connect · 18 partial · 52 got-nothing · 55 send · 56 recv (dropped)
        if ($errno !== 0 && in_array($errno, [6, 7, 18, 52, 55, 56], true)) return true;
        return in_array($code, [500, 502, 503, 504, 429], true);
    }
    // Exponential backoff between retries (250ms · 500ms · 1s, capped 2s), unless
    // the user hit Ctrl-C.
    private static function retryWait(int $attempt): void {
        if (class_exists('Interrupt') && Interrupt::aborted()) return;
        usleep(min(2000, 250 * (1 << max(0, $attempt - 1))) * 1000);
    }
    private static int $maxAttempts = 3;

    // Non-streaming JSON POST with transient-failure retries. Returns the response
    // body, or '' after the final attempt fails. Centralises the retry loop for the
    // crew's plan/verdict calls (chatJson / chatStructured).
    private function postJsonRetry(string $path, string $payload, int $timeout = 300): string {
        for ($attempt = 1; ; $attempt++) {
            $ch = curl_init($this->host . $path);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            ]);
            $resp = (string)curl_exec($ch);
            $errno = curl_errno($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === '' && $attempt < self::$maxAttempts && self::isTransient($errno, $code)
                && !(class_exists('Interrupt') && Interrupt::aborted())) { self::retryWait($attempt); continue; }
            return $resp;
        }
    }

    public function checkConnection(): bool {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response) { $data = json_decode($response, true); return $code === 200 && isset($data['models']); }
        return false;
    }

    public function listModelsDetailed(): array {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) { $data = json_decode($response, true); if (isset($data['models'])) return $data['models']; }
        return [];
    }

    public function listModels(): array {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) { $data = json_decode($response, true); if (isset($data['models'])) return array_map(fn($m) => $m['name'], $data['models']); }
        return [];
    }

    public function completion(string $prompt, ?string $model = null, int $maxTokens = 200): string {
        $model = $model ?: Config::get('ollama.defaultModel', 'llama3.2:latest');
        $params = ['model' => $model, 'prompt' => $prompt, 'stream' => false, 'options' => ['num_predict' => $maxTokens]];
        $ch = curl_init($this->host . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => self::jenc($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $j = json_decode($resp, true);
            $content = $j['response'] ?? '';
            $thinking = $j['thinking'] ?? '';
            if (!empty($thinking) && empty($content)) $content = $thinking;
            return $content;
        }
        return '';
    }

    public function codeComplete(string $code, string $cursor, ?string $model = null): string {
        $prompt = "Complete the following code. Only return the completion, no explanation. No markdown, just code:\n\n" . $code . "\n\nCursor position: " . strlen($code) . "\n=> ";
        $params = ['model' => $model ?: Config::get('ollama.defaultModel', 'llama3.2:latest'), 'prompt' => $prompt, 'stream' => true, 'options' => ['num_predict' => 150]];
        $ch = curl_init($this->host . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => self::jenc($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $j = json_decode($resp, true);
            return trim($j['response'] ?? '');
        }
        return '';
    }

    public function codeReview(string $code): string {
        $prompt = "Review this code and provide suggestions for improvements, bugs, and best practices. Be concise:\n\n" . substr($code, 0, 4000) . "\n\nReview:";
        $messages = [['role' => 'user', 'content' => $prompt]];
        return $this->chatWithModel($model ?? Config::get('ollama.defaultModel', 'llama3.2:latest'), $messages) ?: $this->completion($prompt, $model);
    }

    public function chat(array $messages, ?callable $handler = null): string {
        $model = Config::get('ollama.defaultModel', 'llama3.2:latest');
        return $this->chatWithModel($model, $messages, $handler);
    }

    private static array $ctxCache = []; // model => detected max context length
    private static int $lastCtx = 0;     // last effective num_ctx we sent
    private static array $capCache = []; // model => capability tags from /api/show

    // Generation options applied to every chat request. Crucially this sets
    // num_ctx: without it Ollama silently caps context at 2048 tokens, which
    // truncates the system prompt and tool history out from under the agent.
    // When autoContext is on, we grow num_ctx to the MODEL's own max context
    // length (from /api/show), capped by ollama.maxContextWindow to stay safe
    // on RAM/VRAM — i.e. "as big as the model allows, within reason".
    //
    // CLOUD models are the exception: they run on Ollama's servers, so the local
    // VRAM cap doesn't apply — they always get their FULL trained context window
    // (the maxContextWindow clamp is bypassed). Falls back to ollama.cloudContextWindow
    // only if /api/show can't report the model's length.
    public static function chatOptions(string $model = '', string $host = ''): array {
        $base = (int)Config::get('ollama.contextWindow', 16384);
        $cap  = (int)Config::get('ollama.maxContextWindow', 32768);
        $isCloud = $model !== '' && class_exists('Models') && Models::isCloud($model);
        // autoContext on: grow toward the model's max, clamped to the cap — and the
        // cap can pull it BELOW the baseline, so lowering maxContextWindow is how you
        // fit a smaller window on limited hardware.
        // autoContext off: use exactly contextWindow (full manual control) — but a
        // cloud model still gets its full window, since the cap is a local-VRAM concern.
        $ctx = $base;
        if ($isCloud) {
            $max = $model !== '' ? self::modelContextLength($model, $host) : 0;
            $ctx = $max > 0 ? $max : (int)Config::get('ollama.cloudContextWindow', 131072);
        } elseif (Config::get('ollama.autoContext', true)) {
            $max = $model !== '' ? self::modelContextLength($model, $host) : 0;
            $want = $max > 0 ? max($base, $max) : $base;
            $ctx = min($want, max(512, $cap));
        }
        // Low-resource mode (--light / ollama.lowResource): keep the KV cache small
        // so a long session doesn't creep up on VRAM and freeze the box. Local only —
        // a cloud model has no local footprint.
        $lowRes = (bool)Config::get('ollama.lowResource', false);
        if (!$isCloud && $lowRes) $ctx = min($ctx, max(2048, (int)Config::get('ollama.lowResourceCtx', 8192)));
        self::$lastCtx = $ctx;
        $opts = ['num_ctx' => $ctx, 'temperature' => (float)Config::get('ollama.temperature', 0.3)];
        // GPU/RAM split: num_gpu = how many model layers stay on the GPU; the rest
        // spill to system RAM (CPU). Unset = let Ollama decide (best when the model
        // fits in VRAM). 0 = all CPU/RAM. A lower number frees VRAM but runs SLOWER.
        $gl = Config::get('ollama.gpuLayers', null);
        if ($gl !== null && $gl !== '') $opts['num_gpu'] = max(0, (int)$gl);
        // CPU threads. Explicit numThreads wins; otherwise low-resource mode leaves
        // half the cores for the OS so the machine stays responsive while it thinks.
        $nt = Config::get('ollama.numThreads', null);
        if ($nt !== null && $nt !== '') $opts['num_thread'] = max(1, (int)$nt);
        elseif ($lowRes && !$isCloud) { $cores = (int)@shell_exec('nproc 2>/dev/null'); if ($cores > 2) $opts['num_thread'] = max(2, intdiv($cores, 2)); }
        return $opts;
    }

    // keep_alive controls how long Ollama keeps the model resident in VRAM/RAM after
    // a request. Default (unset) = Ollama's 5 minutes. Set ollama.keepAlive to "0"
    // (unload immediately — frees your VRAM the moment you stop), "30s"/"10m", or
    // "-1" (keep loaded). Sent as a top-level field. LOCAL models only — a cloud
    // model runs on Ollama's servers, so there's no local footprint to manage.
    private static function keepAlive(string $model = ''): array {
        if ($model !== '' && class_exists('Models') && Models::isCloud($model)) return [];
        $ka = Config::get('ollama.keepAlive', null);
        return ($ka !== null && $ka !== '') ? ['keep_alive' => $ka] : [];
    }

    // The num_ctx most recently sent (so the /status meter shows the real window).
    public static function effectiveContext(): int { return self::$lastCtx; }

    // For a cloud model, check whether the local Ollama can actually reach it —
    // i.e. you've run `ollama signin`. Returns a short reason string when the
    // failure is an AUTH problem (so the caller can point at `ollama signin`), or
    // null when it's fine or the failure is something else (model still loading,
    // network). Cheap: a 1-token /api/chat probe.
    public static function cloudAuthError(string $model, string $host = ''): ?string {
        $host = rtrim($host ?: Config::get('ollama.host', 'http://localhost:11434'), '/');
        $ch = curl_init($host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => false, 'options' => ['num_predict' => 1]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        ]);
        $resp = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) return null;
        $j = json_decode($resp, true);
        $err = is_array($j) ? (string)($j['error'] ?? '') : $resp;
        if ($code === 401 || $code === 403 || preg_match('/unauthor|sign ?in|not.*log|authenticat|api key|forbidden|requires/i', $err)) {
            return $err !== '' ? $err : "HTTP $code";
        }
        return null;   // not an auth failure (e.g. the model is still loading)
    }

    // A model's trained context length via /api/show (model_info "*.context_length").
    // Cached per model; returns 0 if unknown / Ollama unreachable.
    public static function modelContextLength(string $model, string $host = ''): int {
        if (isset(self::$ctxCache[$model])) return self::$ctxCache[$model];
        $host = $host ?: Config::get('ollama.host', 'http://localhost:11434');
        $ch = curl_init($host . '/api/show');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['name' => $model]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $max = 0;
        $j = json_decode((string)$resp, true);
        if (is_array($j) && !empty($j['model_info']) && is_array($j['model_info'])) {
            foreach ($j['model_info'] as $k => $v) {
                if (is_string($k) && str_ends_with($k, '.context_length') && is_numeric($v)) { $max = (int)$v; break; }
            }
        }
        return self::$ctxCache[$model] = $max;
    }

    // A model's capability tags (e.g. ["completion","tools","vision"]) from
    // /api/show. Cached per model; [] if unknown / Ollama unreachable.
    public static function modelCapabilities(string $model, string $host = ''): array {
        if ($model === '') return [];
        if (isset(self::$capCache[$model])) return self::$capCache[$model];
        $host = $host ?: Config::get('ollama.host', 'http://localhost:11434');
        $ch = curl_init($host . '/api/show');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['name' => $model]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $caps = [];
        $j = json_decode((string)$resp, true);
        if (is_array($j) && !empty($j['capabilities']) && is_array($j['capabilities'])) {
            $caps = array_values(array_filter($j['capabilities'], 'is_string'));
        }
        return self::$capCache[$model] = $caps;
    }

    // Does this model report multimodal (image) input support?
    public static function modelSupportsVision(string $model, string $host = ''): bool {
        return in_array('vision', self::modelCapabilities($model, $host), true);
    }

    // Does this model report tool / function-calling support?
    public static function modelSupportsTools(string $model, string $host = ''): bool {
        return in_array('tools', self::modelCapabilities($model, $host), true);
    }

    // How the currently-loaded model is split across GPU/CPU, from Ollama's /api/ps.
    // Returns [] if the model isn't loaded; otherwise size (bytes), vram (bytes in
    // VRAM), gpuPct (0-100, how much is on the GPU vs spilled to CPU/RAM), and the
    // loaded context length. Lets `/context` show whether you're 100% GPU or paying
    // a CPU-offload penalty.
    public static function psInfo(string $model = '', string $host = ''): array {
        $host = $host ?: Config::get('ollama.host', 'http://localhost:11434');
        $ch = curl_init($host . '/api/ps');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        if (!is_array($j) || empty($j['models'])) return [];
        $pick = null;
        foreach ($j['models'] as $m) {
            if ($model === '' || ($m['name'] ?? '') === $model || ($m['model'] ?? '') === $model) { $pick = $m; break; }
        }
        if ($pick === null) return [];
        $size = (int)($pick['size'] ?? 0);
        $vram = (int)($pick['size_vram'] ?? 0);
        return [
            'name'    => (string)($pick['name'] ?? $model),
            'size'    => $size,
            'vram'    => $vram,
            'gpuPct'  => $size > 0 ? (int)round($vram / $size * 100) : 0,
            'context' => (int)($pick['context_length'] ?? 0),
        ];
    }

    // Request params that turn ON Ollama's dedicated reasoning channel, so a thinking
    // model routes its chain-of-thought to the `thinking` field (shown dimmed) and
    // keeps `content` as the clean final answer — instead of narrating reasoning
    // INTO content, where it reads as part of the reply. Returns ['think'=>true]
    // ONLY when the model reports a `thinking` capability (sending it to a model that
    // doesn't is a hard HTTP 400 "does not support thinking") and config allows it.
    // Disable with: config set ollama.think false.
    public static function thinkParams(string $model, string $host = ''): array {
        if (!Config::get('ollama.think', true)) return [];
        return in_array('thinking', self::modelCapabilities($model, $host), true) ? ['think' => true] : [];
    }

    // $includeThinking=false drops a reasoning model's chain-of-thought (the `thinking`
    // field) and returns only the final answer (`content`) — used by `ollamadev chat`
    // so a plain chat shows the reply, not the model's internal reasoning.
    // $onThinking, when given, receives the model's chain-of-thought deltas as they
    // stream — so the caller can SHOW the reasoning (e.g. dimmed) live, letting the
    // user watch where it's heading and Ctrl-C if it's wrong — WITHOUT folding that
    // reasoning into the returned answer (the saved reply stays answer-only). When
    // it's null the legacy behavior holds: with $includeThinking, thinking is used
    // as the visible content only when there's no content delta.
    public function chatWithModel(string $model, array $messages, ?callable $handler = null, bool $includeThinking = true, ?callable $onThinking = null): string {
        // Stream when a handler is present so tokens appear as they're produced;
        // fall back to a single blocking response when no handler wants chunks.
        $stream = ($handler !== null || $onThinking !== null) && (bool)Config::get('ollama.stream', true);
        $params = ['model' => $model, 'messages' => $messages, 'stream' => $stream, 'options' => self::chatOptions($model, $this->host)]
            + self::thinkParams($model, $this->host)   // thinking models: emit reasoning in the `thinking` field, content stays the clean answer
            + self::keepAlive($model);                        // free VRAM when idle if ollama.keepAlive is set
        $opts = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => self::jenc($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ];

        if ($stream) {
            // Parse Ollama's NDJSON stream line-by-line, emitting content deltas.
            $content = ''; $buf = '';
            $opts[CURLOPT_WRITEFUNCTION] = function($ch, $data) use (&$content, &$buf, $handler, $includeThinking, $onThinking) {
                // Ctrl-C during streaming: return 0 to make curl abort the transfer.
                if (class_exists('Interrupt') && Interrupt::aborted()) return 0;
                $buf .= $data;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                    if ($line === '') continue;
                    $j = json_decode($line, true);
                    if (!is_array($j)) continue;
                    $cd = $j['message']['content'] ?? '';
                    if ($cd !== '') { $content .= $cd; if ($handler) $handler($cd); }
                    $th = $j['message']['thinking'] ?? '';
                    if ($th !== '') {
                        if ($onThinking) $onThinking($th);                                 // show reasoning live, keep it out of the answer
                        elseif ($includeThinking && $cd === '') { $content .= $th; if ($handler) $handler($th); }  // legacy: thinking stands in for content
                    }
                    if (!empty($j['done'])) Usage::record($j);
                }
                return strlen($data);
            };
            // Retry a transient drop ONLY while nothing has streamed yet — re-running
            // after partial output would duplicate tokens on screen.
            for ($attempt = 1; ; $attempt++) {
                $ch = curl_init($this->host . '/api/chat');
                curl_setopt_array($ch, $opts);
                curl_exec($ch);
                $errno = curl_errno($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $aborted = class_exists('Interrupt') && Interrupt::aborted();
                if ($content === '' && !$aborted && $attempt < self::$maxAttempts && self::isTransient($errno, $code)) {
                    $buf = ''; self::retryWait($attempt); continue;
                }
                break;
            }
            return $content;
        }

        // Non-streaming: a dropped connection or 5xx is fully retryable (no partial output).
        $resp = '';
        for ($attempt = 1; ; $attempt++) {
            $ch = curl_init($this->host . '/api/chat');
            curl_setopt_array($ch, $opts);
            $resp = (string)curl_exec($ch);
            $errno = curl_errno($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === '' && $attempt < self::$maxAttempts && self::isTransient($errno, $code)
                && !(class_exists('Interrupt') && Interrupt::aborted())) { self::retryWait($attempt); continue; }
            break;
        }
        if ($resp !== '') {
            $j = json_decode($resp, true);
            Usage::record($j);
            if ($j && isset($j['message'])) {
                $content = $j['message']['content'] ?? '';
                $thinking = $j['message']['thinking'] ?? '';
                if (!empty($thinking) && empty($content) && $includeThinking) $content = $thinking;
                if (!empty($content)) {
                    if ($handler) $handler($content);
                    return $content;
                }
            }
        }
        return '';
    }

    // One-shot chat constrained to valid JSON (Ollama's format=json). Returns the
    // decoded object, or null on failure. Used by Crew's Director/Auditor so
    // local models reliably emit parseable plans/verdicts.
    public function chatJson(string $model, array $messages): ?array {
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => 'json', 'options' => self::chatOptions($model, $this->host)] + self::keepAlive($model);
        $resp = $this->postJsonRetry('/api/chat', self::jenc($params));
        $j = json_decode((string)$resp, true);
        $content = is_array($j) ? ($j['message']['content'] ?? '') : '';
        if ($content === '') return null;
        $d = json_decode($content, true);
        return is_array($d) ? $d : null;
    }

    // Schema-constrained decoding (Ollama structured outputs): the model's reply
    // is forced to match $schema, so it CANNOT emit malformed JSON. Used by
    // tools.mode=structured to make tool calls reliable on local models. Returns
    // the decoded object, or null on failure.
    public function chatStructured(string $model, array $messages, array $schema): ?array {
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => $schema, 'options' => self::chatOptions($model, $this->host)] + self::keepAlive($model);
        $resp = $this->postJsonRetry('/api/chat', self::jenc($params));
        $j = json_decode((string)$resp, true);
        Usage::record($j);
        $content = is_array($j) ? ($j['message']['content'] ?? '') : '';
        if ($content === '') return null;
        $d = json_decode($content, true);
        return is_array($d) ? $d : null;
    }

    // Native Ollama function-calling. Returns:
    //   ['ok'=>true, 'content'=>string, 'calls'=>[['name'=>,'params'=>], ...]]
    //   ['ok'=>false, 'unsupported'=>bool, 'error'=>string]
    // $onDelta, when given, streams the reply so the user sees tokens appear live
    // instead of a blank screen while a slow model generates. It's called as
    // $onDelta(string $text, bool $isThinking) for each content/thinking delta;
    // tool_calls still arrive structured and are accumulated across the stream.
    public function chatWithTools(string $model, array $messages, array $tools, ?callable $onDelta = null): array {
        $stream = $onDelta !== null && (bool)Config::get('ollama.stream', true);
        $params = ['model' => $model, 'messages' => $messages, 'tools' => $tools, 'stream' => $stream, 'options' => self::chatOptions($model, $this->host)]
            + self::thinkParams($model, $this->host)   // route reasoning to the dimmed `thinking` channel (thinking models only)
            + self::keepAlive($model);                       // free VRAM when idle if ollama.keepAlive is set
        $ch = curl_init($this->host . '/api/chat');
        $base = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => self::jenc($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ];

        if ($stream) {
            // Parse Ollama's NDJSON stream: emit content/thinking deltas live and
            // accumulate tool_calls (which Ollama delivers structured, usually in
            // the final message). A non-200 can't be read from headers mid-stream,
            // so we sniff the first line for an {"error":…} object.
            $content = ''; $buf = ''; $calls = []; $err = ''; $sawAny = false;
            $base[CURLOPT_WRITEFUNCTION] = function($ch, $data) use (&$content, &$buf, &$calls, &$err, &$sawAny, $onDelta) {
                if (class_exists('Interrupt') && Interrupt::aborted()) return 0;
                $buf .= $data;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                    if ($line === '') continue;
                    $j = json_decode($line, true);
                    if (!is_array($j)) continue;
                    if (isset($j['error'])) { $err = (string)$j['error']; continue; }
                    $sawAny = true;
                    $msg = $j['message'] ?? [];
                    $cd = $msg['content'] ?? '';
                    if ($cd !== '') { $content .= $cd; $onDelta($cd, false); }
                    $td = $msg['thinking'] ?? '';
                    if ($td !== '') $onDelta($td, true);
                    foreach (($msg['tool_calls'] ?? []) as $tc) {
                        $f = $tc['function'] ?? [];
                        $name = $f['name'] ?? '';
                        $args = $f['arguments'] ?? [];
                        if (is_string($args)) $args = json_decode($args, true) ?: [];
                        if ($name !== '') $calls[] = ['name' => $name, 'params' => is_array($args) ? $args : []];
                    }
                    if (!empty($j['done'])) Usage::record($j);
                }
                return strlen($data);
            };
            curl_setopt_array($ch, $base);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err !== '' || ($code !== 200 && !$sawAny)) {
                $err = $err ?: "HTTP $code";
                return ['ok' => false, 'unsupported' => stripos($err, 'tool') !== false || stripos($err, 'does not support') !== false, 'error' => $err];
            }
            return ['ok' => true, 'content' => $content, 'calls' => $calls];
        }

        curl_setopt_array($ch, $base);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        Usage::record($j);
        if ($code !== 200) {
            $err = is_array($j) ? ($j['error'] ?? '') : (string)$resp;
            return ['ok' => false, 'unsupported' => stripos($err, 'tool') !== false || stripos($err, 'does not support') !== false, 'error' => $err];
        }
        $msg = is_array($j) ? ($j['message'] ?? []) : [];
        $content = $msg['content'] ?? '';
        if (empty($content) && !empty($msg['thinking'])) $content = $msg['thinking'];
        $calls = [];
        foreach (($msg['tool_calls'] ?? []) as $tc) {
            $f = $tc['function'] ?? [];
            $name = $f['name'] ?? '';
            $args = $f['arguments'] ?? [];
            if (is_string($args)) $args = json_decode($args, true) ?: [];
            if ($name !== '') $calls[] = ['name' => $name, 'params' => is_array($args) ? $args : []];
        }
        return ['ok' => true, 'content' => (string)$content, 'calls' => $calls];
    }
}

