// LM STUDIO CLIENT — talks to LM Studio's local OpenAI-compatible server
// (default http://localhost:1234/v1). Still 100% local; no cloud. Implements the
// same public surface the Agent/Crew use, so it's a drop-in for OllamaClient.
//
// Note on tool messages: the OpenAI protocol requires tool results to carry a
// tool_call_id matching a prior assistant tool_call. OllamaDev's loop uses the
// looser Ollama shape, so we flatten 'tool' messages into plain user text
// ("[tool: name] result") — the model still sees the results, without the strict
// id bookkeeping.
class LMStudioClient {
    private string $host;

    public function __construct(?string $host = null) {
        $h = $host ?: Config::get('lmstudio.host', 'http://localhost:1234/v1');
        $this->host = rtrim($h, '/');
    }

    public function host(): string { return $this->host; }

    private function get(string $path): ?array {
        $ch = curl_init($this->host . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer lm-studio']]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return null;
        $j = json_decode((string)$resp, true);
        return is_array($j) ? $j : null;
    }

    public function checkConnection(): bool {
        $j = $this->get('/models');
        return is_array($j) && isset($j['data']);
    }

    public function listModels(): array {
        $j = $this->get('/models');
        if (!is_array($j) || !isset($j['data'])) return [];
        return array_values(array_filter(array_map(fn($m) => $m['id'] ?? '', $j['data'])));
    }

    public function listModelsDetailed(): array {
        $out = [];
        foreach ($this->listModels() as $id) $out[] = ['name' => $id, 'size' => 0, 'modified_at' => '', 'details' => []];
        return $out;
    }

    // Flatten our message history into clean OpenAI {role, content} messages.
    private function normMessages(array $messages): array {
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = (string)($m['content'] ?? '');
            if ($role === 'tool') {
                $name = $m['tool_name'] ?? 'tool';
                $out[] = ['role' => 'user', 'content' => "[tool: $name] " . $content];
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }
        return $out;
    }

    private function body(string $model, array $messages, array $extra = []): string {
        return json_encode(array_merge([
            'model' => $model,
            'messages' => $this->normMessages($messages),
            'temperature' => (float)Config::get('ollama.temperature', 0.6),
        ], $extra));
    }

    private function recordUsage(array $j): void {
        if (!isset($j['usage'])) return;
        Usage::record([
            'prompt_eval_count' => (int)($j['usage']['prompt_tokens'] ?? 0),
            'eval_count' => (int)($j['usage']['completion_tokens'] ?? 0),
            'done' => true,
        ]);
    }

    public function chatWithModel(string $model, array $messages, callable $handler = null): string {
        $stream = $handler !== null && (bool)Config::get('ollama.stream', true);
        $ch = curl_init($this->host . '/chat/completions');
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->body($model, $messages, ['stream' => $stream]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer lm-studio'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ];
        if ($stream) {
            $content = ''; $buf = '';
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$content, &$buf, $handler) {
                if (class_exists('Interrupt') && Interrupt::aborted()) return 0;
                $buf .= $data;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                    if ($line === '' || strpos($line, 'data:') !== 0) continue;
                    $payload = trim(substr($line, 5));
                    if ($payload === '[DONE]') continue;
                    $j = json_decode($payload, true);
                    if (!is_array($j)) continue;
                    $delta = $j['choices'][0]['delta']['content'] ?? '';
                    if ($delta !== '') { $content .= $delta; if ($handler) $handler($delta); }
                    if (isset($j['usage'])) $this->recordUsage($j);
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
        $j = json_decode((string)$resp, true);
        if (is_array($j)) {
            $this->recordUsage($j);
            $c = $j['choices'][0]['message']['content'] ?? '';
            if ($c !== '' && $handler) $handler($c);
            return (string)$c;
        }
        return '';
    }

    public function chatJson(string $model, array $messages): ?array {
        $ch = curl_init($this->host . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->body($model, $messages, ['stream' => false, 'response_format' => ['type' => 'json_object']]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer lm-studio'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        $content = is_array($j) ? ($j['choices'][0]['message']['content'] ?? '') : '';
        if ($content === '') return null;
        $d = json_decode($content, true);
        return is_array($d) ? $d : null;
    }

    public function chatWithTools(string $model, array $messages, array $tools): array {
        $ch = curl_init($this->host . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->body($model, $messages, ['stream' => false, 'tools' => $tools]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer lm-studio'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        if ($code !== 200) {
            $err = is_array($j) ? ($j['error']['message'] ?? ($j['error'] ?? '')) : (string)$resp;
            if (is_array($err)) $err = json_encode($err);
            return ['ok' => false, 'unsupported' => stripos((string)$err, 'tool') !== false, 'error' => (string)$err];
        }
        if (is_array($j)) $this->recordUsage($j);
        $msg = is_array($j) ? ($j['choices'][0]['message'] ?? []) : [];
        $content = $msg['content'] ?? '';
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

// Provider factory: pick the right local backend for a host. OpenAI-style hosts
// (LM Studio etc., ending in /v1 or on :1234) use LMStudioClient; otherwise Ollama.
class ModelClient {
    public static string $override = ''; // set by --lmstudio / --host for the session

    public static function isOpenAiStyle(string $host): bool {
        return $host !== '' && (str_contains($host, '/v1') || str_contains($host, ':1234'));
    }

    public static function for(string $host): object {
        if ($host === '') return self::default();
        return self::isOpenAiStyle($host) ? new LMStudioClient($host) : new OllamaClient($host);
    }

    public static function default(): object {
        if (self::$override !== '') return self::for(self::$override);
        if (Config::get('provider', 'ollama') === 'lmstudio') return new LMStudioClient(Config::get('lmstudio.host', 'http://localhost:1234/v1'));
        return new OllamaClient(Config::get('ollama.host', 'http://localhost:11434'));
    }

    public static function activeLabel(): string {
        if (self::$override !== '') return self::isOpenAiStyle(self::$override) ? ('LM Studio (' . self::$override . ')') : self::$override;
        return Config::get('provider', 'ollama') === 'lmstudio' ? 'LM Studio' : 'Ollama';
    }
}
