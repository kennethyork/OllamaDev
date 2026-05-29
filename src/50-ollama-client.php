class OllamaClient {
    private string $host;
    private int $timeout = 120;

    public function __construct(?string $host = null) {
        $this->host = $host ?? Config::get('ollama.host', 'http://localhost:11434');
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

    public function completion(string $prompt, string $model = null, int $maxTokens = 200): string {
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

    public function codeComplete(string $code, string $cursor, string $model = null): string {
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

    public function chat(array $messages, callable $handler = null): string {
        $model = Config::get('ollama.defaultModel', 'llama3.2:latest');
        return $this->chatWithModel($model, $messages, $handler);
    }

    // Generation options applied to every chat request. Crucially this sets
    // num_ctx: without it Ollama silently caps context at 2048 tokens, which
    // truncates the system prompt and tool history out from under the agent.
    public static function chatOptions(): array {
        return [
            'num_ctx' => (int)Config::get('ollama.contextWindow', 16384),
            'temperature' => (float)Config::get('ollama.temperature', 0.6),
        ];
    }

    public function chatWithModel(string $model, array $messages, callable $handler = null): string {
        // Stream when a handler is present so tokens appear as they're produced;
        // fall back to a single blocking response when no handler wants chunks.
        $stream = $handler !== null && (bool)Config::get('ollama.stream', true);
        $params = ['model' => $model, 'messages' => $messages, 'stream' => $stream, 'options' => self::chatOptions()];
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
            $opts[CURLOPT_WRITEFUNCTION] = function($ch, $data) use (&$content, &$buf, $handler) {
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
                    if ($delta === '' && !empty($j['message']['thinking'])) $delta = $j['message']['thinking'];
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
                if (!empty($thinking) && empty($content)) $content = $thinking;
                if (!empty($content)) {
                    if ($handler) $handler($content);
                    return $content;
                }
            }
        }
        return '';
    }

    // One-shot chat constrained to valid JSON (Ollama's format=json). Returns the
    // decoded object, or null on failure. Used by Forge's Director/Auditor so
    // local models reliably emit parseable plans/verdicts.
    public function chatJson(string $model, array $messages): ?array {
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false, 'format' => 'json', 'options' => self::chatOptions()];
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

    // Native Ollama function-calling. Returns:
    //   ['ok'=>true, 'content'=>string, 'calls'=>[['name'=>,'params'=>], ...]]
    //   ['ok'=>false, 'unsupported'=>bool, 'error'=>string]
    public function chatWithTools(string $model, array $messages, array $tools): array {
        $params = ['model' => $model, 'messages' => $messages, 'tools' => $tools, 'stream' => false, 'options' => self::chatOptions()];
        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
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

