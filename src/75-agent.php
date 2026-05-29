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

public function run(array $messages, callable $handler = null): string {
        $allMessages = array_merge([$this->systemPrompt], $messages);
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
        $allMessages = array_merge([$this->systemPrompt], $messages);

        // Chat mode: pure conversation, no tools offered or parsed.
        if ($this->chatMode) {
            $resp = '';
            $this->client->chatWithModel($this->model, $allMessages, function($c) use (&$resp) { $resp .= $c; });
            return ['content' => $resp, 'calls' => []];
        }

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
        $calls = [];

        // Primary: balanced-JSON extraction (most reliable for local models).
        $jsonCalls = self::extractJsonToolCalls($content);
        if (!empty($jsonCalls)) {
            return array_map(fn($c) => ['name' => $c['name'], 'params' => $c['params']], $jsonCalls);
        }

if (preg_match_all('/<tool_code>\s*(\{.*?\})\s*<\/tool_code>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $calls[] = ['name' => $json['name'], 'params' => $json['arguments'] ?? []];
                }
            }
            if (!empty($calls)) return $calls;
        }
        
if (preg_match_all('/<tool_call>\s*(\{.*?\})\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $args = $json['arguments'] ?? $json['params'] ?? [];
                    $calls[] = ['name' => $json['name'], 'params' => $args];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*(\{[\s\S]*?\})\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[2], true);
                if ($json) {
                    $calls[] = ['name' => $m[1], 'params' => $json];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*(\{.*?\})\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[2], true);
                if ($json) {
                    $calls[] = ['name' => $m[1], 'params' => $json];
                }
            }
            if (!empty($calls)) return $calls;
        }
        
        if (preg_match_all('/<tool_call>\s*(\{.*?\})\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $calls[] = ['name' => $json['name'], 'params' => $json['arguments'] ?? []];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/tool_call_code:\s*(\w+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches[1] as $name) { $calls[] = ['name' => $name, 'params' => []]; }
            if (!empty($calls)) return $calls;
        }

if (preg_match_all('/<tool_code>\s*(\{[\s\S]*?\})\s*<\/tool_code>/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $args = $json['arguments'] ?? $json['params'] ?? [];
                    $calls[] = ['name' => $json['name'], 'params' => is_array($args) ? $args : []];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_call>\s*"server":\s*"([^"]+)",\s*"tool":\s*"([^"]+)"[^}]*\}/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $server = $m[1];
                $tool = $m[2];
                $args = [];
                if (preg_match('/"args":\s*(\{[^}]+\}|"[^"]*")/', $m[0], $argMatch)) {
                    $args = json_decode($argMatch[1], true) ?? [];
                }
                $calls[] = ['name' => 'mcp', 'params' => ['server' => $server, 'tool' => $tool, 'args' => $args]];
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<\/tool_call>\s*(\{[\s\S]*?\})\s*$/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode(trim($m[1]), true);
                if ($json && isset($json['name'])) {
                    $args = $json['arguments'] ?? $json['params'] ?? [];
                    $calls[] = ['name' => $json['name'], 'params' => is_array($args) ? $args : []];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (empty($calls) && preg_match_all('/\{"name":\s*"(\w+)",\s*"arguments":\s*(\{[^}]+\})\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $args = json_decode($m[2], true) ?? [];
                $calls[] = ['name' => $m[1], 'params' => $args];
            }
            if (!empty($calls)) return $calls;
        }

        if (empty($calls)) {
            preg_match_all('/<tool_code>\s*(\{[^}]+\})\s*<\/tool_code>/s', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $args = $json['arguments'] ?? $json['params'] ?? [];
                    if (isset($args[0]) && is_string($args[0])) {
                        $args = ['path' => $args[0]];
                    }
                    $calls[] = ['name' => $json['name'], 'params' => $args];
                }
            }
            if (!empty($calls)) return $calls;
        }

if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*arguments:\s*\{[^}]*\}[\s\n]*/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (preg_match('/arguments:\s*(\{[^}]*\})/', $m[0], $j)) {
                    $json = json_decode($j[1], true);
                    if ($json) {
                        $calls[] = ['name' => trim($m[1]), 'params' => $json];
                    }
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*(?:params|arguments):\s*([^\n]+)\n\s*(.+?)\n\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $params = [];
                $paramStr = trim($m[3]);
                preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)=("[^"]*"|\'[^\']*\'|[^,\n]+)/', $paramStr, $kvMatches);
                foreach ($kvMatches[1] as $i => $key) {
                    $val = trim($kvMatches[2][$i], "\"' ");
                    $params[$key] = $val;
                }
                $toolName = trim($m[1]);
                if (!empty($params) || !empty($toolName)) {
                    $calls[] = ['name' => $toolName, 'params' => $params];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*(?:params|arguments):\s*(.+?)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $params = [];
                $paramStr = trim($m[2]);
                $paramStr = trim($paramStr, ',');
                preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)=("[^"]*"|\'[^\']*\'|[^,\s]+)/', $paramStr, $kvMatches);
                foreach ($kvMatches[1] as $i => $key) {
                    $val = trim($kvMatches[2][$i], "\"' ");
                    $params[$key] = $val;
                }
                $toolName = trim($m[1]);
                if (!empty($params) || !empty($toolName)) {
                    $calls[] = ['name' => $toolName, 'params' => $params];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_call>\s*(\w+)\s*\n\s*params:\s*(.+?)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $params = [];
                $paramStr = trim($m[2]);
                preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)=("[^"]*"|\'[^\']*\'|[^,\n]+)/', $paramStr, $kvMatches);
                foreach ($kvMatches[1] as $i => $key) {
                    $val = trim($kvMatches[2][$i], "\"' ");
                    $params[$key] = $val;
                }
                $calls[] = ['name' => trim($m[1]), 'params' => $params];
            }
            if (!empty($calls)) return $calls;
        }

        $toolNames = ['ls', 'view', 'read', 'write', 'edit', 'grep', 'glob', 'find', 'cat', 'execute_command', 'list_directory', 'list_files', 'bash', 'shell', 'mkdir', 'mv', 'cp', 'rm', 'touch', 'diff', 'wc', 'git_status', 'git_diff', 'git_log', 'git_commit', 'git_add', 'git_checkout', 'git_branch', 'git_merge', 'git_rebase', 'git_stash', 'git_push', 'git_pull', 'git_clone', 'patch', 'diagnostics', 'goto_definition', 'find_references', 'bg', 'wait_bg'];
        $pattern = '/\b(' . implode('|', $toolNames) . ')\b/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $tool = $m[1];
            if (!in_array($tool, array_column($calls, 'name'))) {
                $calls[] = ['name' => $tool, 'params' => []];
            }
        }

        if (preg_match('/`(\w+)`\s*(?:command|tool|function)/i', $content, $m) && !in_array($m[1], array_column($calls, 'name'))) {
            if (in_array($m[1], $toolNames)) { $calls[] = ['name' => $m[1], 'params' => []]; }
        }

        if (preg_match('/(?:run|execute|call|use)\s+(?:the\s+)?`?(\w+)`?(?:\s+command|\s+tool)?/i', $content, $m)) {
            if (in_array($m[1], $toolNames) && !in_array($m[1], array_column($calls, 'name'))) {
                $calls[] = ['name' => $m[1], 'params' => []];
            }
        }

        if (preg_match_all('/"name"\s*:\s*"(\w+)"/', $content, $m) && empty($calls)) {
            foreach ($m[1] as $name) {
                if (in_array($name, $toolNames) && !in_array($name, array_column($calls, 'name'))) {
                    $calls[] = ['name' => $name, 'params' => []];
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
                    $paramName = $tool ? ($tool->params[0] ?? 'command') : 'command';
                    $calls[] = ['name' => $toolName, 'params' => [$paramName => $rawParams]];
                }
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
                    $paramName = $tool ? ($tool->params[0] ?? 'command') : 'command';
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
                    $paramName = $tool->params[0] ?? 'command';
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
                    $paramName = $tool->params[0] ?? 'command';
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

