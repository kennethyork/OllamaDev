class Agent {
    private OllamaClient $client;
    private string $model;
    private array $systemPrompt;
    private bool $chatMode = false; // when true: pure conversation, no tools

    public function __construct() {
        $this->client = new OllamaClient();
        $models = $this->client->listModels();
        $default = Config::get('ollama.defaultModel', '');
        // Prefer the configured/-m model if it's actually installed; otherwise
        // fall back to the first installed model so we never target a missing one.
        if ($default && in_array($default, $models, true)) {
            $this->model = $default;
        } else {
            $this->model = !empty($models) ? $models[0] : ($default ?: 'llama3.2:latest');
        }
        $this->systemPrompt = $this->buildSystemPrompt();
    }

    public function buildSystemPrompt(): array {
        $manualPrompt = Config::get('agents.systemPrompt', '');
        $prompt = !empty($manualPrompt) ? $manualPrompt : SystemPrompts::detectForModel($this->model);
        
        $projectMemory = '';
        $memoryFiles = ['OLLAMADEV.md', '.ollamadev.md', '.ollamadev'];
        foreach ($memoryFiles as $mf) {
            if (is_file($mf)) {
                $projectMemory = "\n\nPROJECT CONTEXT (from $mf):\n" . file_get_contents($mf);
                break;
            }
        }

        // Chat mode: no tool instructions at all - pure conversation.
        if ($this->chatMode) {
            return ['role' => 'system', 'content' => $prompt . $projectMemory];
        }

$tools = "You have tools available for acting on the project: read/view files, write/edit files, list/search (ls, glob, grep, find), run shell commands (bash), git operations, and code navigation. When a capable model is used these are provided automatically; otherwise call one with:
<tool_code>{\"name\": \"TOOL\", \"arguments\": {\"PARAM\": \"VALUE\"}}</tool_code>

Use a tool ONLY when the request needs it. For greetings, questions, or explanations, just reply in plain text - do not call a tool.";

        // Small models can't use native tool-calling, so give them explicit
        // few-shot examples of the exact format - this makes agent mode work
        // reliably for them too.
        if ($this->isSmallModel()) {
            $tools .= "

When an action IS needed, output ONLY the tool call line in exactly this format - no other text:
User: list the files here
<tool_code>{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}</tool_code>
User: show me README.md
<tool_code>{\"name\": \"view\", \"arguments\": {\"file_path\": \"README.md\"}}</tool_code>
User: create notes.txt containing hello
<tool_code>{\"name\": \"write\", \"arguments\": {\"file_path\": \"notes.txt\", \"content\": \"hello\"}}</tool_code>
User: find php files
<tool_code>{\"name\": \"glob\", \"arguments\": {\"pattern\": \"*.php\"}}</tool_code>
User: run the tests
<tool_code>{\"name\": \"bash\", \"arguments\": {\"command\": \"ls -la\"}}</tool_code>";
        }

        return ['role' => 'system', 'content' => $prompt . $projectMemory . "\n\n" . $tools];
    }

    public function setModel(string $model): void { $this->model = $model; $this->systemPrompt = $this->buildSystemPrompt(); }

    // Resolve a user-typed model name to an actually-installed model. Matches
    // exactly, then "name:latest", then a unique prefix (so "/model qwen2.5-coder"
    // works without the tag). Returns null when nothing matches.
    public function resolveModel(string $name): ?string {
        $name = trim($name);
        if ($name === '') return null;
        $installed = $this->client->listModels();
        if (empty($installed)) return $name; // can't verify (Ollama down) - trust it
        if (in_array($name, $installed, true)) return $name;
        if (in_array("$name:latest", $installed, true)) return "$name:latest";
        $prefix = array_values(array_filter($installed, fn($m) => str_starts_with($m, $name)));
        return count($prefix) >= 1 ? $prefix[0] : null;
    }
    public function setChatMode(bool $on): void { $this->chatMode = $on; $this->systemPrompt = $this->buildSystemPrompt(); }
    public function isChatMode(): bool { return $this->chatMode; }

    // Heuristic: treat models <= ~3.5GB as "small" - their tool-calling is
    // unreliable, so chat mode is a better default for them.
    public function isSmallModel(): bool {
        foreach ($this->client->listModelsDetailed() as $m) {
            if (($m['name'] ?? '') === $this->model) {
                return (int)($m['size'] ?? 0) > 0 && (int)$m['size'] < 3_758_096_384;
            }
        }
        // Fall back to a name hint (e.g. 1b/2b/3b/mini/tiny/small).
        return (bool)preg_match('/(?:^|[:\-_ ])(0\.\d|1|1\.\d|2|2\.\d|3|3\.\d)b\b|mini|tiny|small|smollm/i', $this->model);
    }
    public function getModel(): string { return $this->model; }
    public function listModels(): array { return $this->client->listModels(); }
    public function listModelsDetailed(): array { return $this->client->listModelsDetailed(); }
    public function checkConnection(): bool { return $this->client->checkConnection(); }

    // Summarize a transcript with the model itself (one-shot, no tools). Used by
    // session compaction. Returns '' on any failure so the caller can fall back.
    public function summarize(string $transcript): string {
        $transcript = substr($transcript, 0, 24000); // bound the prompt size
        $sys = ['role' => 'system', 'content' =>
            "You compress conversations for an AI coding assistant's memory. Produce a " .
            "concise summary that preserves: decisions made, file paths touched, code/edits " .
            "applied, commands run and their outcomes, and any unfinished tasks. Use terse " .
            "bullet points. Do NOT call tools or ask questions - output only the summary."];
        $user = ['role' => 'user', 'content' => "Summarize this conversation so far:\n\n" . $transcript];
        try {
            return trim($this->client->chatWithModel($this->model, [$sys, $user]));
        } catch (\Throwable $e) {
            return '';
        }
    }

    // Convert stored session messages into the shape Ollama's chat API expects:
    // drop internal bookkeeping (id, created_at) and keep only role/content plus
    // the structured tool_calls / tool_name that make function-calling coherent.
    private function wire(array $messages): array {
        $out = [];
        foreach ($messages as $m) {
            $w = ['role' => $m['role'] ?? 'user', 'content' => (string)($m['content'] ?? '')];
            if (!empty($m['tool_calls'])) $w['tool_calls'] = $m['tool_calls'];
            if (!empty($m['tool_name'])) $w['tool_name'] = $m['tool_name'];
            if (!empty($m['images']) && is_array($m['images'])) $w['images'] = $m['images'];
            $out[] = $w;
        }
        return $out;
    }

    public function run(array $messages, callable $handler = null): string {
        $allMessages = array_merge([$this->systemPrompt], $this->wire($messages));
        $response = '';
        $this->client->chatWithModel($this->model, $allMessages, function($chunk) use (&$response, $handler) {
            $response .= $chunk;
            if ($handler) $handler($chunk);
        });
        return $response;
    }

    // One model turn. Uses Ollama's native function-calling when the model
    // supports it (structured tool_calls, no parsing); otherwise falls back to
    // text-format parsing. Returns ['content'=>string, 'calls'=>[...]].
    public function chatTurn(array $messages): array {
        $allMessages = array_merge([$this->systemPrompt], $this->wire($messages));

        // Chat mode: pure conversation, no tools offered or parsed.
        if ($this->chatMode) {
            $resp = '';
            $this->client->chatWithModel($this->model, $allMessages, function($c) use (&$resp) { $resp .= $c; });
            return ['content' => $resp, 'calls' => []];
        }

        // Bail out before issuing another request if the user pressed Ctrl-C.
        if (class_exists('Interrupt') && Interrupt::aborted()) return ['content' => '', 'calls' => []];

        // Try native tools unless we've already learned this model lacks support.
        if (($GLOBALS['nativeTools'][$this->model] ?? null) !== false) {
            $res = $this->client->chatWithTools($this->model, $allMessages, Tools::schemas());
            if (!empty($res['ok'])) {
                $GLOBALS['nativeTools'][$this->model] = true;
                $calls = $res['calls'];
                // Some models emit text-format calls even in native mode; catch them.
                if (empty($calls)) $calls = $this->parseToolCalls($res['content']);
                return ['content' => $res['content'], 'calls' => $calls];
            }
            if (!empty($res['unsupported'])) {
                $GLOBALS['nativeTools'][$this->model] = false; // remember and stop retrying
            } else {
                // Transient/other error: surface as empty turn rather than looping.
                return ['content' => $res['error'] ?? '', 'calls' => []];
            }
        }

        // Text-format fallback.
        $response = $this->run($messages);
        return ['content' => $response, 'calls' => $this->parseToolCalls($response)];
    }

    // Execute a list of ['name'=>, 'params'=>] calls, returning tool-role results.
    public function executeCalls(array $calls): array {
        $results = [];
        foreach ($calls as $call) {
            $tool = Tools::find($call['name']);
            $params = $call['params'] ?? [];
            $result = $tool ? Tools::run($call['name'], $params) : "Error: tool '{$call['name']}' not found";
            if (preg_match('/^FILE_WRITE:(.+)/', $result, $m)) { $GLOBALS['editedFiles'][] = $m[1]; }
            elseif (preg_match('/^FILE_EDIT:(.+)/', $result, $m)) { $GLOBALS['editedFiles'][] = $m[1]; }
            $results[] = ['role' => 'tool', 'name' => $call['name'], 'content' => $result];
        }
        return $results;
    }

    public function parseAndExecuteTools(string $content): array {
        return $this->executeCalls($this->parseToolCalls($content));
    }

    // Scan for balanced JSON objects that look like tool calls. Robust to
    // missing/garbled close tags, code fences, and nested argument objects -
    // the common failure modes for small local models. Returns name/params/raw.
    public static function extractJsonToolCalls(string $content): array {
        $calls = [];
        $len = strlen($content);
        for ($i = 0; $i < $len; $i++) {
            if ($content[$i] !== '{') continue;
            $depth = 0; $inStr = false; $esc = false; $end = -1;
            for ($j = $i; $j < $len; $j++) {
                $c = $content[$j];
                if ($inStr) {
                    if ($esc) $esc = false;
                    elseif ($c === '\\') $esc = true;
                    elseif ($c === '"') $inStr = false;
                    continue;
                }
                if ($c === '"') $inStr = true;
                elseif ($c === '{') $depth++;
                elseif ($c === '}') { $depth--; if ($depth === 0) { $end = $j; break; } }
            }
            if ($end < 0) break; // unbalanced from here on
            $raw = substr($content, $i, $end - $i + 1);
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json['name']) && is_string($json['name'])) {
                $args = $json['arguments'] ?? $json['params'] ?? $json['input'] ?? [];
                if (!is_array($args)) $args = [];
                $calls[] = ['name' => $json['name'], 'params' => $args, 'raw' => $raw];
                $i = $end; // skip consumed object
            }
        }
        return $calls;
    }

    // Remove tool-call markup (tags + JSON objects) from text meant for display.
    public function stripToolMarkup(string $content): string {
        foreach (self::extractJsonToolCalls($content) as $c) {
            $content = str_replace($c['raw'], '', $content);
        }
        $content = preg_replace('/<\/?tool_(code|call)>/', '', $content);
        return trim($content);
    }

public function parseToolCalls(string $content): array {
        // Primary: balanced-JSON extraction. Handles every JSON form models
        // emit - <tool_code>/<tool_call>-wrapped, bare, missing close tags,
        // nested arguments - covering the overwhelming majority of cases.
        $jsonCalls = self::extractJsonToolCalls($content);
        if (!empty($jsonCalls)) {
            return array_map(fn($c) => ['name' => $c['name'], 'params' => $c['params']], $jsonCalls);
        }

        // Fallback: the documented text format some models emit, e.g.
        //   name: ls   params: path=/tmp
        //   name: write arguments: file_path=a.txt content="hi"
        // (with or without surrounding <tool_call> tags). Best-effort key=value.
        $calls = [];
        if (preg_match_all('/name:\s*([a-zA-Z_]\w*)\s*(?:params|arguments):\s*([^\n<]+)/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $params = [];
                if (preg_match_all('/([a-zA-Z_]\w*)\s*=\s*("[^"]*"|\'[^\']*\'|[^,\s]+)/', $m[2], $kv, PREG_SET_ORDER)) {
                    foreach ($kv as $p) $params[$p[1]] = trim($p[2], "\"' ");
                }
                $calls[] = ['name' => trim($m[1]), 'params' => $params];
            }
        }
        return $calls;
    }

    public function parseGptOssToolCalls(string $content): array {
        $calls = [];

        preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*\n?\s*(\{[\s\S]*?\})\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $toolName = trim($m[1]);
            $rawJson = trim($m[2]);
            $rawJson = preg_replace('/\s+/', ' ', $rawJson);
            $jsonParams = json_decode($rawJson, true);
            if ($jsonParams && isset($jsonParams['task'])) {
                $calls[] = ['name' => $toolName, 'params' => $jsonParams];
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*\n\s*(\w+):\s*"([^"]*)"/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $calls[] = ['name' => $m[1], 'params' => [$m[2] => $m[3]]];
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*<name>(\w+)<\/name>\s*<params>\s*<(\w+)>([^<]*)<\/\2>\s*<\/params>\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $calls[] = ['name' => $m[1], 'params' => [$m[2] => $m[3]]];
            }
        }

if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*\{([^}]+)\}\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $toolName = trim($m[1]);
                $rawParams = trim($m[2]);
                if (preg_match_all('/(\w+)\s*:\s*"([^"]*)"/', $rawParams, $kvMatches, PREG_SET_ORDER)) {
                    $params = [];
                    foreach ($kvMatches[1] as $i => $key) {
                        $params[$key] = $kvMatches[2][$i];
                    }
                    if (!empty($params)) {
                        $calls[] = ['name' => $toolName, 'params' => $params];
                    }
                }
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*(\w+):\s*(\S+)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $calls[] = ['name' => $m[1], 'params' => [$m[2] => $m[3]]];
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*\{([^}]+)\}\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $toolName = trim($m[1]);
                $rawParams = trim($m[2]);
                if (preg_match_all('/(\w+)\s*:\s*([^\s,}]+)/', $rawParams, $kvMatches, PREG_SET_ORDER)) {
                    $params = [];
                    foreach ($kvMatches[1] as $i => $key) {
                        $params[$key] = trim($kvMatches[2][$i], "\"'\"");
                    }
                    if (!empty($params)) {
                        $calls[] = ['name' => $toolName, 'params' => $params];
                    }
                }
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*\{([^}]+)\}\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $toolName = trim($m[1]);
                $rawParams = trim($m[2]);
                if (preg_match('/(\w+)\s*:\s*"([^"]*)"/', $rawParams, $kvMatch)) {
                    $calls[] = ['name' => $toolName, 'params' => [$kvMatch[1] => $kvMatch[2]]];
                }
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*([^\n<]+)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $toolName = trim($m[1]);
                $rawParams = trim($m[2]);
                if (!empty($toolName) && !empty($rawParams)) {
                    $tool = Tools::find($toolName);
                    $paramName = "command";
                    $calls[] = ['name' => $toolName, 'params' => [$paramName => $rawParams]];
                }
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*(\w+)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $toolName = trim($m[1]);
                $tool = Tools::find($toolName);
                if ($tool) {
                    $paramName = "command";
                    $calls[] = ['name' => $toolName, 'params' => [$paramName => trim($m[2])]];
                }
            }
        }

        if (empty($calls)) {
            preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*(.+?)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $toolName = trim($m[1]);
                $rawParams = trim($m[2]);
                $tool = Tools::find($toolName);
                if ($tool && !empty($rawParams)) {
                    $paramName = "command";
                    $calls[] = ['name' => $toolName, 'params' => [$paramName => $rawParams]];
                }
            }
        }

        return $calls;
    }

    private function parseParams(string $argsStr): array {
        $argsStr = trim($argsStr);
        if (empty($argsStr)) return [];
        if (str_starts_with($argsStr, '{')) {
            $decoded = json_decode($argsStr, true);
            if ($decoded !== null) return $decoded;
        }
        $params = [];
        foreach (explode(',', $argsStr) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) $params[trim($kv[0])] = trim($kv[1], "\"' ");
        }
        return $params;
    }
}

