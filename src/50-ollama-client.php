class OllamaClient {
    private string $host;
    private int $timeout = 120;

    public function __construct(?string $host = null) {
        $this->host = $host ?? Config::get('ollama.host', 'http://localhost:11434');
    }

    public function host(): string { return $this->host; }

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
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
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
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
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
    public static function chatOptions(string $model = '', string $host = ''): array {
        $base = (int)Config::get('ollama.contextWindow', 16384);
        $cap  = (int)Config::get('ollama.maxContextWindow', 32768);
        // autoContext on: grow toward the model's max, clamped to the cap — and the
        // cap can pull it BELOW the baseline, so lowering maxContextWindow is how you
        // fit a smaller window on limited hardware.
        // autoContext off: use exactly contextWindow (full manual control).
        $ctx = $base;
        if (Config::get('ollama.autoContext', true)) {
            $max = $model !== '' ? self::modelContextLength($model, $host) : 0;
            $want = $max > 0 ? max($base, $max) : $base;
            $ctx = min($want, max(512, $cap));
        }
        self::$lastCtx = $ctx;
        $opts = ['num_ctx' => $ctx, 'temperature' => (float)Config::get('ollama.temperature', 0.3)];
        // GPU/RAM split: num_gpu = how many model layers stay on the GPU; the rest
        // spill to system RAM (CPU). Unset = let Ollama decide (best when the model
        // fits in VRAM). 0 = all CPU/RAM. A lower number frees VRAM but runs SLOWER.
        $gl = Config::get('ollama.gpuLayers', null);
        if ($gl !== null && $gl !== '') $opts['num_gpu'] = max(0, (int)$gl);
        // Optionally cap CPU threads used for the RAM-offloaded layers.
        $nt = Config::get('ollama.numThreads', null);
        if ($nt !== null && $nt !== '') $opts['num_thread'] = max(1, (int)$nt);
        return $opts;
    }

    // The num_ctx most recently sent (so the /status meter shows the real window).
    public static function effectiveContext(): int { return self::$lastCtx; }

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

    // $includeThinking=false drops a reasoning model's chain-of-thought (the `thinking`
    // field) and returns only the final answer (`content`) — used by `ollamadev chat`
    // so a plain chat shows the reply, not the model's internal reasoning.
    public function chatWithModel(string $model, array $messages, ?callable $handler = null, bool $includeThinking = true): string {
        // Stream when a handler is present so tokens appear as they're produced;
        // fall back to a single blocking response when no handler wants chunks.
        $stream = $handler !== null && (bool)Config::get('ollama.stream', true);
        $params = ['model' => $model, 'messages' => $messages, 'stream' => $stream, 'options' => self::chatOptions($model, $this->host)];
        $ch = curl_init($this->host . '/api/chat');
        $opts = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ];

        if ($stream) {
            // Parse Ollama's NDJSON stream line-by-line, emitting content deltas.
            $content = ''; $buf = '';
            $opts[CURLOPT_WRITEFUNCTION] = function($ch, $data) use (&$content, &$buf, $handler, $includeThinking) {
                // Ctrl-C during streaming: return 0 to make curl abort the transfer.
                if (class_exists('Interrupt') && Interrupt::aborted()) return 0;
                $buf .= $data;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                    if ($line === '') continue;
                    $j = json_decode($line, true);
                    if (!is_array($j)) continue;
                    $delta = $j['message']['content'] ?? '';
                    if ($delta === '' && $includeThinking && !empty($j['message']['thinking'])) $delta = $j['message']['thinking'];
                    if ($delta !== '') { $content .= $delta; if ($handler) $handler($delta); }
                    if (!empty($j['done'])) Usage::record($j);
                }
                return strlen($data);
            };
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            curl_close($ch);
            return $content;
        }

        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
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
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => 'json', 'options' => self::chatOptions($model, $this->host)];
        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
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
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => $schema, 'options' => self::chatOptions($model, $this->host)];
        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
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
        $params = ['model' => $model, 'messages' => $messages, 'tools' => $tools, 'stream' => $stream, 'options' => self::chatOptions($model, $this->host)];
        $ch = curl_init($this->host . '/api/chat');
        $base = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
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

