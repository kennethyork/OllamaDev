class Agent {
    private $client; // OllamaClient (see ModelClient factory)
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

        // Thinking models: keep step-by-step reasoning in the dedicated reasoning
        // channel (the CLI shows it live, dimmed) so the visible reply is the answer
        // or the tool call — not reasoning narrated into the content. Without this,
        // the "reply in plain text for explanations" guidance below pulls reasoning
        // INTO content, where it reads as part of the answer.
        if (!$this->chatMode && $this->modelSupportsThinking()) {
            $prompt .= "\n\nREASONING: You are a thinking model. Do all your step-by-step reasoning in your private reasoning/thinking channel (it is streamed to the user separately). Your visible reply must contain ONLY the final answer or a tool call — never narrate your reasoning into the reply.";
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

        // Resolve the tool mechanism up front: the system prompt for NATIVE mode
        // must NOT show the text-format <tool_code> protocol. Doing so lures capable
        // models (mistral, qwen, …) into emitting a text pseudo-call like
        // `write{"file_path":…}` that bypasses Ollama's structured tool_calls and our
        // parser never sees — so the action silently no-ops. In native mode we just
        // tell the model the tools are wired in and to call them natively.
        $toolMode = $this->effectiveToolMode();
        $toolList = "read/view files, write/edit files, list/search (ls, glob, grep, find), run shell commands (bash), git operations, and code navigation";
        if ($toolMode === 'native') {
            $tools = "You have tools available for acting on the project: {$toolList}. They are wired directly into this conversation — invoke them with your native function/tool-calling. Do NOT write tool calls as text, JSON, or code blocks; just call the tool.\n\nUse a tool ONLY when the request needs it. For greetings, questions, or explanations, just reply in plain text - do not call a tool.";
        } else {
            $tools = "You have tools available for acting on the project: {$toolList}. When a capable model is used these are provided automatically; otherwise call one with:\n<tool_code>{\"name\": \"TOOL\", \"arguments\": {\"PARAM\": \"VALUE\"}}</tool_code>\n\nUse a tool ONLY when the request needs it. For greetings, questions, or explanations, just reply in plain text - do not call a tool.";
        }

        // Text-protocol tool-calling (tools.mode=text): we don't rely on Ollama's
        // native function-calling at all — the model emits tool calls as JSON and
        // our own parser extracts them. Give it the exact format + a tool catalog
        // so it knows what's callable without the native schema.
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

        // Few-shot examples of the <tool_code> text format — ONLY in text mode,
        // which is the only mode that actually parses that format. In native mode
        // these examples would teach the model to emit text-format calls that bypass
        // the structured tool_calls API (the mistral `write{…}` failure), and
        // structured mode uses the {message,tool_calls} envelope instead.
        if ($toolMode === 'text') {
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
    // Should this model DEFAULT to pure chat (tools off) when a session opens?
    // Only when it genuinely can't be trusted with tools: a small model that the
    // engine does NOT report as tool-capable. A small-but-tool-capable model
    // (llama3.2:3b, qwen2.5-coder:3b, …) keeps tools on — Ollama's native
    // function-calling is reliable for them. Big models always get tools (native
    // carries its own graceful fallback). The user can still flip with /chat /agent.
    public function shouldDefaultToChat(): bool {
        if ($this->modelSupportsTools() === true) return false; // engine: tool-capable → agent mode
        if (!$this->isSmallModel()) return false;               // big model → trust native + fallback
        return true;                                            // small AND not tool-capable → pure chat
    }
    public function getModel(): string { return $this->model; }
    public function listModels(): array { return $this->client->listModels(); }
    public function listModelsDetailed(): array { return $this->client->listModelsDetailed(); }
    public function checkConnection(): bool { return $this->client->checkConnection(); }
    public function host(): string { return method_exists($this->client, 'host') ? $this->client->host() : ''; }

    // Live tool-capability for the current model, straight from the engine
    // (Ollama's /api/show `capabilities`) — the model-specific truth, not a
    // hardcoded list. Returns true/false when the engine reports it, or null when
    // it can't say (Ollama unreachable, or a backend without capability tags).
    public function modelSupportsTools(): ?bool {
        if (!method_exists($this->client, 'modelCapabilities')) return null;
        $caps = $this->client::modelCapabilities($this->model, $this->host());
        if (empty($caps)) return null;                 // couldn't determine
        return in_array('tools', $caps, true);
    }

    // Does the engine report this model has a dedicated reasoning channel?
    public function modelSupportsThinking(): bool {
        if (!method_exists($this->client, 'modelCapabilities')) return false;
        return in_array('thinking', $this->client::modelCapabilities($this->model, $this->host()), true);
    }

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
    public function chatTurn(array $messages, ?callable $stream = null): array {
        // Keep the prompt in sync with plan mode: when a tool (exit_plan_mode) or
        // command flips plan mode mid-session, rebuild so the "PLAN MODE: read-only"
        // directive is added/dropped on the very next turn — otherwise an approved
        // plan still reads as plan mode and the model keeps refusing to edit.
        if (class_exists('Permission') && Permission::inPlanMode() !== $this->builtPlanMode) $this->systemPrompt = $this->buildSystemPrompt();
        $allMessages = array_merge([$this->systemPrompt], $this->wire($messages));

        // Chat mode: pure conversation, no tools offered or parsed. Stream tokens
        // live (content is clean prose here) so the screen isn't blank while a slow
        // model generates.
        if ($this->chatMode) {
            $resp = '';
            $this->client->chatWithModel($this->model, $allMessages, function($c) use (&$resp, $stream) {
                $resp .= $c;
                if ($stream) $stream($c, false);
            });
            return ['content' => $resp, 'calls' => [], 'streamed' => $stream !== null];
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

        // Native function-calling (the 'auto' default, and explicit tools.mode=native).
        // Try native tools unless we already learned this model lacks support.
        if (($GLOBALS['nativeTools'][$this->model] ?? null) !== false) {
            $res = $this->client->chatWithTools($this->model, $allMessages, Tools::schemas(), $stream);
            if (!empty($res['ok'])) {
                $GLOBALS['nativeTools'][$this->model] = true;
                $calls = $res['calls'];
                // Some models emit text-format calls even in native mode; catch them.
                if (empty($calls)) $calls = $this->parseToolCalls($res['content']);
                return ['content' => $res['content'], 'calls' => $calls, 'streamed' => $stream !== null];
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
                // Native call errored mid-flight — most commonly the model emitted
                // malformed tool-call JSON that Ollama's server-side template parser
                // rejects with a 500 ("Value looks like object, but can't find closing
                // '}' symbol"); gemma is prone to this on multi-line/nested arguments.
                // Do NOT surface Ollama's raw Go error as the answer, and do NOT
                // dead-end the turn. Retry native once (the bad JSON is usually
                // stochastic), then fall through to the text-format path below, whose
                // tolerant parser + repairJson can recover the call.
                $res2 = $this->client->chatWithTools($this->model, $allMessages, Tools::schemas());
                if (!empty($res2['ok'])) {
                    $calls = $res2['calls'] ?: $this->parseToolCalls($res2['content']);
                    return ['content' => $res2['content'], 'calls' => $calls];
                }
                // still failing → text-format fallback (do not return the raw error)
            }
        }

        // Text-format fallback.
        $response = $this->run($messages);
        return ['content' => $response, 'calls' => $this->parseToolCalls($response)];
    }

    // Resolve the configured tools.mode, turning 'auto' into the best available
    // mechanism. We PREFER Ollama's native function-calling: modern Ollama ships a
    // per-model tool template, so it reliably emits structured tool_calls for any
    // tool-capable model (gemma, llama, qwen, mistral, …). The old text / schema
    // parsing is brittle — many perfectly capable models describe the action in
    // prose and emit nothing parseable, so the agent "calls a tool" that never runs
    // and the turn dead-ends. Native carries its own graceful fallback (a model
    // Ollama reports as tool-unsupported drops to a fallback model, then to the text
    // protocol), so preferring it never dead-ends.
    private function effectiveToolMode(): string {
        $m = Config::get('tools.mode', 'auto');
        if ($m !== 'auto') return $m; // native | text | structured (explicit override)
        // Native first, until we've learned THIS model can't do it.
        if (($GLOBALS['nativeTools'][$this->model] ?? null) !== false && method_exists($this->client, 'chatWithTools')) {
            return 'native';
        }
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
        // Also strip toolname(args) function-call syntax we recognized as a call,
        // so a model that "narrated" the call in prose doesn't leak it into display.
        foreach (self::extractCallSyntax($content) as $c) {
            if (!empty($c['raw'])) $content = str_replace($c['raw'], '', $content);
        }
        // Strip the wrapper tags in every delimiter style models emit, including
        // gemma's pipe-delimited forms: <tool_call>, </tool_call>, <tool_call|>,
        // <|tool_call|>, <|tool_call>. The optional pipes/whitespace are what the
        // older pattern missed, so gemma4's "<tool_call|>" leaked into the reply.
        $content = preg_replace('/<\/?\|?\s*tool_(code|call)\s*\|?>/', '', $content);
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
        // Final fallback: Python/JS function-call syntax some models emit in prose
        // instead of structured tool_calls — e.g. view(file_path="build.txt") or
        // write(file_path="a.txt", content="hi"). Small tool-capable models
        // (mistral:7b, …) degrade to this when the native tool catalog is large.
        if (empty($calls)) $calls = self::extractCallSyntax($content);
        return $calls;
    }

    // Extract `toolname(arg="val", arg2='val2')` function-call syntax. Constrained
    // to KNOWN registered tool names so ordinary prose with parentheses never
    // false-fires. Handles keyword args (quoted or bare) and a single positional
    // string (mapped to the tool's first schema property). String-aware paren
    // balancing so a value containing ')' doesn't truncate the call.
    public static function extractCallSyntax(string $content): array {
        if (!class_exists('Tools')) return [];
        $names = Tools::all();
        if (empty($names)) return [];
        $calls = [];
        $len = strlen($content);
        foreach ($names as $name) {
            $off = 0;
            while (($p = strpos($content, $name, $off)) !== false) {
                $off = $p + 1;
                // require a word boundary before and `(` (optional spaces) after
                if ($p > 0 && (ctype_alnum($content[$p-1]) || $content[$p-1] === '_')) continue;
                $q = $p + strlen($name);
                while ($q < $len && ($content[$q] === ' ' || $content[$q] === "\t")) $q++;
                if ($q >= $len || $content[$q] !== '(') continue;
                // balanced ) scan, respecting quotes
                $depth = 0; $inStr = false; $quote = ''; $esc = false; $end = -1;
                for ($j = $q; $j < $len; $j++) {
                    $c = $content[$j];
                    if ($inStr) {
                        if ($esc) $esc = false;
                        elseif ($c === '\\') $esc = true;
                        elseif ($c === $quote) $inStr = false;
                        continue;
                    }
                    if ($c === '"' || $c === "'") { $inStr = true; $quote = $c; }
                    elseif ($c === '(') $depth++;
                    elseif ($c === ')') { $depth--; if ($depth === 0) { $end = $j; break; } }
                }
                if ($end < 0) continue;
                $inner = trim(substr($content, $q + 1, $end - $q - 1));
                $params = self::parseCallArgs($inner, $name);
                if ($params !== null) {
                    $calls[] = ['name' => $name, 'params' => $params, 'raw' => substr($content, $p, $end - $p + 1)];
                    $off = $end + 1;
                }
            }
        }
        return $calls;
    }

    // Parse the inside of toolname(...) into a params array. Accepts a JSON object,
    // keyword args (key="v", key='v', key=bare), or a single positional string that
    // maps to the tool's first schema property. Returns null if nothing parseable
    // (so a bare `foo()` with no args still yields []).
    private static function parseCallArgs(string $inner, string $tool): ?array {
        if ($inner === '') return [];
        if ($inner[0] === '{') { $j = json_decode($inner, true); if (is_array($j)) return $j; }
        $params = [];
        if (preg_match_all('/([A-Za-z_]\w*)\s*[=:]\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,]+)/', $inner, $kv, PREG_SET_ORDER)) {
            foreach ($kv as $p) {
                $v = trim($p[2]);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'")) $v = stripcslashes(substr($v, 1, -1));
                $params[$p[1]] = $v;
            }
            if (!empty($params)) return $params;
        }
        // single positional string → first schema property of the tool
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$|^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $inner, $m)) {
            $val = stripcslashes($m[1] ?? ($m[2] ?? ''));
            $first = self::firstToolParam($tool);
            if ($first !== '') return [$first => $val];
        }
        return null;
    }

    // The first declared parameter name for a tool (from its registered schema),
    // used to place a single positional call arg. '' if unknown.
    private static function firstToolParam(string $tool): string {
        foreach (Tools::schemas() as $s) {
            if (($s['function']['name'] ?? '') !== $tool) continue;
            $props = $s['function']['parameters']['properties'] ?? [];
            if (is_array($props) && $props) return (string)array_key_first($props);
        }
        return '';
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

