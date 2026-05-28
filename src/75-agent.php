class Agent {
    private OllamaClient $client;
    private string $model;
    private array $systemPrompt;

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

$tools = 'TOOLS AVAILABLE:

FILE OPERATIONS:
1. view <file_path> [offset=0] [limit=100] - Read file with line numbers
2. cat <file_path> [limit=50] - Read file, alias for view
3. head <file_path> [n=10] - Show first n lines
4. tail <file_path> [n=10] - Show last n lines
5. read <file_path> [limit=50] - Read file, alias for view

FILE MANIPULATION:
6. write <file_path> <content> - Create or overwrite file
7. edit <file_path> <old_string> <new_string> - Replace first occurrence
8. patch <file_path> <diff> - Apply unified diff patch
9. touch <file_path> - Create empty file
10. mkdir <path> - Create directory
11. rm <path> [recursive=false] - Delete file/directory
12. cp <src> <dst> - Copy file/directory
13. mv <src> <dst> - Move/rename file/directory

DIRECTORY OPERATIONS:
14. ls [path="."] [limit=0] - List directory contents
15. list_directory <path> - Alias for ls
16. list_files <path> - Alias for ls
17. cd <path> - Change directory (output shows new path)
18. pwd - Show current directory
19. find [path="."] name=<pattern> - Find files by name
20. tree [path="."] [depth=2] - Show directory tree

FILE ANALYSIS:
21. grep <pattern> [path="."] [include="*"] - Search using regex
22. wc <file_path> - Count lines, words, characters
23. stat <file_path> - Show file stats
24. diff <file1> <file2> - Compare two files
25. sort <file_path> - Sort lines in file
26. uniq <file_path> - Remove duplicate lines

GIT OPERATIONS:
27. git_status - Show working tree status
28. git_diff [file] - Show changes
29. git_log [limit=10] - Show commit history
30. git_branch [-a] - List branches
31. git_checkout <branch> - Switch branches
32. git_commit <message> - Commit changes
33. git_add <path> - Stage changes
34. git_push - Push to remote
35. git_pull - Pull from remote
36. git_clone <url> [dir] - Clone repository
37. git_merge <branch> - Merge branch
38. git_rebase <branch> - Rebase onto branch
39. git_fetch - Fetch from remote
40. git_stash [push|pop|list] - Manage stashes
41. git_show <ref> - Show commit/file details
42. git_remote [-v] - Show remote URLs

CODE INTELLIGENCE:
43. goto <file_path> <symbol> - Go to symbol definition
44. goto_definition <file_path> <symbol> - Alias for goto
45. find_refs <file_path> <symbol> - Find symbol references
46. refs <file_path> <symbol> - Alias for find_refs
47. symbols <file_path> - List file symbols
48. hover <file_path> <line> - Show hover info
49. definition <file_path> <symbol> - Alias for goto
50. diagnostics <file_path> - Show syntax errors
51. format <file_path> - Format code file
52. lsp <file_path> <command> - Send LSP command

WEB:
53. fetch <url> - Download web page content

BACKGROUND:
54. bg command=<cmd> - Run command in background
55. wait_bg seconds=<n> - Wait for background jobs

SEARCH:
56. glob <pattern> - Find files matching glob pattern
57. changes [since="1 hour ago"] - Show recent file changes

AGENTS:
58. agent <task> - Run sub-agent task
59. summarize - Summarize conversation

MCP:
60. mcp_servers - List MCP servers and tools
61. mcp server=<name> tool=<toolname> [args] - Call MCP tool

PERMISSIONS:
62. permission <tool> [allow|deny] - Manage tool permissions

SYSTEM:
63. bash <command> - Execute shell command
64. execute_command <command> - Alias for bash
65. editor <path> - Open file in editor
66. watch [path="."] [timeout=30] - Poll for file changes

TOOL CALL FORMAT:
<tool_call>
name: ls
params: path=/tmp
</tool_call>

AVAILABLE TOOLS: ls, view, cat, head, tail, read, write, edit, patch, touch, mkdir, rm, cp, mv, pwd, cd, find, tree, grep, wc, stat, diff, sort, uniq, glob, changes, git_status, git_diff, git_log, git_branch, git_checkout, git_commit, git_add, git_push, git_pull, git_clone, git_merge, git_rebase, git_fetch, git_stash, git_show, git_remote, goto, goto_definition, find_refs, refs, symbols, hover, definition, diagnostics, format, lsp, fetch, bg, wait_bg, agent, summarize, mcp_servers, mcp, permission, bash, execute_command, editor, watch

TOOL PERMISSIONS:
- All operations execute directly - no permission prompts

COMPACT/SUMMARIZE:
- When conversation exceeds ~20 messages, call summarize tool';

        return ['role' => 'system', 'content' => $prompt . $projectMemory . "\n\n" . $tools];
    }

    public function setModel(string $model): void { $this->model = $model; }
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

