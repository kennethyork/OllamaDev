class Agent {
    private $client; // OllamaClient or LMStudioClient (see ModelClient factory)
    private string $model;
    private array $systemPrompt;
    private bool $builtPlanMode = false;   // plan-mode state captured when the prompt was built
    private bool $chatMode = false; // when true: pure conversation, no tools
    private bool $triedFallback = false; // switched to a tool-capable model once?

    public function __construct() {
        $this->client = ModelClient::default();
        $models = $this->client->listModels();
        $default = Config::get('ollama.defaultModel', '');
        // Prefer the configured/-m model if it's actually installed; otherwise
        // fall back to the first installed model so we never target a missing one.
        if ($default && in_array($default, $models, true)) {
            $this->model = $default;
        } elseif (!empty($models) && ($best = Models::bestInstalled($models)) !== '') {
            // No usable configured default — prefer a known tool-capable model
            // from the fallback chain over whatever happens to be listed first.
            $this->model = $best;
        } else {
            // Nothing from the fallback chain is installed. Still prefer ANY
            // catalogued tool-capable model over an arbitrary first-listed one
            // (which may be chat-only) before giving up to $models[0].
            $tc = !empty($models) ? Models::anyToolCapable($models) : '';
            $this->model = $tc !== '' ? $tc
                : (!empty($models) ? $models[0] : ($default ?: 'llama3.2:latest'));
        }
        $this->systemPrompt = $this->buildSystemPrompt();
    }

    public function buildSystemPrompt(): array {
        $manualPrompt = Config::get('agents.systemPrompt', '');
        $prompt = !empty($manualPrompt) ? $manualPrompt : SystemPrompts::detectForModel($this->model);
        // Output style (tone/verbosity) shapes every reply; plan mode gates editing.
        if (class_exists('OutputStyles')) $prompt .= OutputStyles::promptSuffix();
        $this->builtPlanMode = class_exists('Permission') && Permission::inPlanMode();
        if ($this->builtPlanMode) {
            $prompt .= "\n\nPLAN MODE: Investigate with READ-ONLY tools only — do NOT create/edit files or run mutating commands yet. When you have a concrete plan, call exit_plan_mode(plan: \"…\") and wait for the user's approval before implementing.";
        }

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

        // Text-protocol tool-calling (tools.mode=text): we don't rely on Ollama's
        // native function-calling at all — the model emits tool calls as JSON and
        // our own parser extracts them. Give it the exact format + a tool catalog
        // so it knows what's callable without the native schema.
        $toolMode = $this->effectiveToolMode();
        if ($toolMode === 'text' || $toolMode === 'structured') {
            $tools .= "\n\nAVAILABLE TOOLS (required args bare, [optional] in brackets):\n" . Tools::textCatalog();
        }
        $actNudge = " To create or change a file you MUST call write/edit with the file's contents in the argument — do NOT paste code into your message, and do NOT wrap file contents in ``` markdown fences (write raw bytes; e.g. a PHP file starts with <?php).";
        if ($toolMode === 'text') {
            $tools .= "\n\nTOOL PROTOCOL — there is no automatic tool API. When an action is needed, output ONE line containing ONLY this JSON (no surrounding prose), then stop and wait for the result:\n"
                . "<tool_code>{\"name\": \"TOOL\", \"arguments\": {\"PARAM\": \"VALUE\"}}</tool_code>" . $actNudge;
        } elseif ($toolMode === 'structured') {
            $tools .= "\n\nRESPONSE FORMAT — every reply is JSON: {\"message\": \"<text for the user>\", \"tool_calls\": [{\"tool\": \"<name>\", \"arguments\": {<params>}}]}. "
                . "To act, add entries to tool_calls (using the exact tool names and params above); leave it [] when you're just replying. Put any prose in message. Work one step at a time and read tool results before continuing." . $actNudge;
        }

        // Small models can't use native tool-calling, so give them explicit
        // few-shot examples of the <tool_code> format. Never in structured mode
        // (which uses the {message,tool_calls} envelope, not <tool_code>).
        if ($toolMode !== 'structured' && ($this->isSmallModel() || $toolMode === 'text')) {
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

        // Progressive-disclosure skills: list only names+descriptions; the model
        // loads a skill's full instructions on demand via the `skill` tool.
        $skills = class_exists('Skills') ? Skills::catalog() : '';
        if ($skills !== '') {
            $tools .= "\n\nAVAILABLE SKILLS — when a request matches one, FIRST call the skill tool to load its instructions, then follow them:\n" . $skills;
        }

        // Graph memory: a short index of saved notes; pull full ones with recall,
        // save durable facts with remember (link notes via [[slug]]).
        $mem = class_exists('Memory') ? Memory::index() : '';
        if ($mem !== '') {
            $tools .= "\n\nPROJECT MEMORY — durable notes about this project. Use recall(slug) to read one, recall(query) to search, and remember(title, content) to save a new fact (link related notes with [[slug]]):\n" . $mem;
        }

        return ['role' => 'system', 'content' => $prompt . $projectMemory . "\n\n" . $tools];
    }

    public function setModel(string $model): void { $this->model = $model; $this->systemPrompt = $this->buildSystemPrompt(); }

    // Point this agent at a specific Ollama host (used by the Crew to spread coders
    // across multiple machines/GPUs for real parallel inference).
    public function setHost(string $host): void { if (trim($host) !== '') $this->client = ModelClient::for(trim($host)); }

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
    public function host(): string { return method_exists($this->client, 'host') ? $this->client->host() : ''; }

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

    public function run(array $messages, ?callable $handler = null): string {
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
        // Keep the prompt in sync with plan mode: when a tool (exit_plan_mode) or
        // command flips plan mode mid-session, rebuild so the "PLAN MODE: read-only"
        // directive is added/dropped on the very next turn — otherwise an approved
        // plan still reads as plan mode and the model keeps refusing to edit.
        if (class_exists('Permission') && Permission::inPlanMode() !== $this->builtPlanMode) $this->systemPrompt = $this->buildSystemPrompt();
        $allMessages = array_merge([$this->systemPrompt], $this->wire($messages));

        // Chat mode: pure conversation, no tools offered or parsed.
        if ($this->chatMode) {
            $resp = '';
            $this->client->chatWithModel($this->model, $allMessages, function($c) use (&$resp) { $resp .= $c; });
            return ['content' => $resp, 'calls' => []];
        }

        // Bail out before issuing another request if the user pressed Ctrl-C.
        if (class_exists('Interrupt') && Interrupt::aborted()) return ['content' => '', 'calls' => []];

        $toolMode = $this->effectiveToolMode();

        // Structured mode: schema-constrained decoding. The backend forces the
        // reply to match a {message, tool_calls[]} schema, so the model CANNOT
        // emit malformed tool JSON or hallucinate a tool. Most reliable local
        // tool-calling. If the backend can't constrain output, we still asked
        // for the envelope in the prompt, so we parse it from free text.
        if ($toolMode === 'structured') {
            $key = get_class($this->client);
            if (($GLOBALS['structuredCap'][$key] ?? null) !== false && method_exists($this->client, 'chatStructured')) {
                $res = $this->client->chatStructured($this->model, $allMessages, self::toolCallSchema());
                if (is_array($res)) { $GLOBALS['structuredCap'][$key] = true; return $this->mapStructured($res); }
                $GLOBALS['structuredCap'][$key] = false; // backend can't constrain — stop trying
            }
            return $this->interpretStructuredText($this->run($messages));
        }

        // Text-protocol mode: we own the protocol — prompt for JSON tool calls
        // and parse them ourselves. Portable across all models/backends.
        if ($toolMode === 'text') {
            $response = $this->run($messages);
            return ['content' => $response, 'calls' => $this->parseToolCalls($response)];
        }

        // Native (explicit tools.mode=native only). Try native tools unless we
        // already learned this model lacks support.
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
                // Graceful degradation: if a tool-capable model is installed, switch
                // to it for the rest of the session (once) rather than dropping to the
                // brittle text-format path. Honors the configured model.fallback chain.
                if (!$this->triedFallback && (bool)Config::get('model.autoFallback', true)) {
                    $this->triedFallback = true;
                    $alt = Models::toolFallback($this->client->listModels(), $this->model);
                    if ($alt !== '' && $alt !== $this->model) {
                        fwrite(STDERR, "\n\033[2m  ⓘ {$this->model} can't use native tools — falling back to $alt for tool calls.\033[0m\n");
                        $this->setModel($alt);
                        $retry = array_merge([$this->systemPrompt], $this->wire($messages));
                        $res2 = $this->client->chatWithTools($this->model, $retry, Tools::schemas());
                        if (!empty($res2['ok'])) {
                            $GLOBALS['nativeTools'][$this->model] = true;
                            $calls = $res2['calls'] ?: $this->parseToolCalls($res2['content']);
                            return ['content' => $res2['content'], 'calls' => $calls];
                        }
                    }
                }
            } else {
                // Transient/other error: surface as empty turn rather than looping.
                return ['content' => $res['error'] ?? '', 'calls' => []];
            }
        }

        // Text-format fallback.
        $response = $this->run($messages);
        return ['content' => $response, 'calls' => $this->parseToolCalls($response)];
    }

    // Resolve the configured tools.mode, turning 'auto' into the best available
    // mechanism: schema-constrained decoding when the backend supports it (most
    // reliable), else the text protocol. Native function-calling is opt-in only
    // (tools.mode=native) — it's the fragile, version-dependent one.
    private function effectiveToolMode(): string {
        $m = Config::get('tools.mode', 'auto');
        if ($m !== 'auto') return $m; // native | text | structured (explicit)
        $key = get_class($this->client);
        if (($GLOBALS['structuredCap'][$key] ?? null) === false) return 'text';
        return method_exists($this->client, 'chatStructured') ? 'structured' : 'text';
    }

    // Map a {message, tool_calls:[{tool,arguments}]} envelope to a turn result.
    private function mapStructured(array $res): array {
        $calls = [];
        foreach (($res['tool_calls'] ?? []) as $tc) {
            if (!is_array($tc)) continue;
            $name = (string)($tc['tool'] ?? $tc['name'] ?? '');
            $args = $tc['arguments'] ?? $tc['params'] ?? [];
            if ($name !== '' && Tools::find($name)) $calls[] = ['name' => $name, 'params' => is_array($args) ? $args : []];
        }
        return ['content' => (string)($res['message'] ?? ''), 'calls' => $calls];
    }

    // Interpret a free-text reply that was prompted for the structured envelope
    // (used when the backend couldn't truly constrain output). Falls back to the
    // generic JSON tool-call scanner if it isn't a clean envelope.
    private function interpretStructuredText(string $raw): array {
        $whole = json_decode(trim($raw), true);
        if (is_array($whole) && (isset($whole['tool_calls']) || isset($whole['message']))) return $this->mapStructured($whole);
        return ['content' => $raw, 'calls' => $this->parseToolCalls($raw)];
    }

    // JSON schema for structured (constrained) tool-calling. The reply must be
    // {message, tool_calls:[{tool, arguments}]} — tool names are an enum of the
    // real registered tools, so the model can neither malform the JSON nor
    // hallucinate a tool. Empty tool_calls = a plain reply in `message`.
    public static function toolCallSchema(): array {
        $names = class_exists('Tools') ? Tools::all() : [];
        sort($names);
        $toolProp = ['type' => 'string'];
        if ($names) $toolProp['enum'] = $names;
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Reply to the user; use when no tool is needed, or to explain before/after tool calls.'],
                'tool_calls' => [
                    'type' => 'array',
                    'description' => 'Tools to run this step. Empty if none.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tool' => $toolProp,
                            'arguments' => ['type' => 'object', 'description' => 'Arguments for the tool (object; may be empty).'],
                        ],
                        'required' => ['tool', 'arguments'],
                    ],
                ],
            ],
            'required' => ['message', 'tool_calls'],
        ];
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
            // Small models often emit ALMOST-valid JSON (trailing commas, single
            // quotes, Python True/None, unquoted keys). Rather than silently drop
            // the call — which reads to the user as "the model described it but did
            // nothing" — attempt a conservative repair before giving up.
            if (!is_array($json)) { $rep = self::repairJson($raw); if ($rep !== null) $json = json_decode($rep, true); }
            // Accept both {"name":..} (text protocol) and {"tool":..} (structured envelope shape).
            $tn = (isset($json['name']) && is_string($json['name'])) ? $json['name']
                : ((isset($json['tool']) && is_string($json['tool'])) ? $json['tool'] : null);
            if (is_array($json) && $tn !== null) {
                $args = $json['arguments'] ?? $json['params'] ?? $json['input'] ?? [];
                if (!is_array($args)) $args = [];
                $calls[] = ['name' => $tn, 'params' => $args, 'raw' => $raw];
                $i = $end; // skip consumed object
            }
        }
        return $calls;
    }

    // Conservatively repair the almost-valid JSON small models emit, so a tool call
    // isn't silently dropped. ONLY called after a strict parse already failed, and
    // returns null unless the repair actually parses — so it can only recover a
    // broken call, never corrupt a valid one (valid JSON never reaches here).
    public static function repairJson(string $s): ?string {
        $r = $s;
        // Python/JS literals in value position → JSON.
        $r = preg_replace('/:\s*True\b/', ': true', $r);
        $r = preg_replace('/:\s*False\b/', ': false', $r);
        $r = preg_replace('/:\s*(None|nil|undefined)\b/', ': null', $r);
        // Single-quoted strings (no embedded double quote) → double-quoted.
        $r = preg_replace("/'([^'\"]*)'/", '"$1"', $r);
        // Unquoted object keys: {key: ..} / , key: .. → "key":
        $r = preg_replace('/([{,]\s*)([A-Za-z_]\w*)(\s*):/', '$1"$2"$3:', $r);
        // Trailing commas before } or ].
        $r = preg_replace('/,(\s*[}\]])/', '$1', $r);
        // Smart/curly quotes a model may paste → straight quotes.
        $r = strtr($r, ['“' => '"', '”' => '"', '’' => "'", '‘' => "'"]);
        return is_array(json_decode($r, true)) ? $r : null;
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

