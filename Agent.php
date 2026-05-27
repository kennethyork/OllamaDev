class SystemPrompts {
    private static array $prompts = [
        'llama' => 'You are a helpful AI assistant running locally via Ollama. Be precise and explicit in your responses.',

        'mistral' => 'You are Mistral, a helpful AI assistant running locally via Ollama. Be concise and accurate.',

        'codellama' => "You are a coding assistant. Call tools to complete tasks. Output ONLY the tool call, nothing else.

Tools: ls, view, write, edit, glob, grep, bash, diagnostics, goto, symbols, refs

Example: User: view build.sh → <tool_code>{\"name\": \"view\", \"arguments\": {\"file_path\": \"build.sh\"}}</tool_code>",

        'qwen' => "You are Qwen. You MUST call tools to perform actions. NEVER describe what you would do - actually call the tools.

Examples:
User: list files
Response: <tool_code>\n{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}\n</tool_code>

User: show build.sh
Response: <tool_code>\n{\"name\": \"view\", \"arguments\": {\"file_path\": \"build.sh\"}}\n</tool_code>

User: find php files
Response: <tool_code>\n{\"name\": \"glob\", \"arguments\": {\"pattern\": \"*.php\"}}\n</tool_code>

Available tools: ls, view, write, edit, glob, grep, bash, fetch, patch, diagnostics",

'phi' => "You are Phi, a CLI coding assistant. Extract parameters from user request and call tools. NEVER explain, NEVER ask permission.

Tool format: <tool_code>{\"name\": \"TOOL\", \"arguments\": {\"PARAM\": \"VALUE\"}}</tool_code>

Tools: ls, view, write, edit, glob, grep pattern=REGEX path=PATH, bash command=CMD, diagnostics path=PATH, goto path=PATH symbol=NAME, symbols path=PATH, refs path=PATH symbol=NAME",

        'wizard' => "You are WizardCoder. When user asks you to list files, read files, or run commands - you MUST actually call the tool now, not explain how to do it. Tools execute directly - do NOT ask for permission.",

        'starcoder' => "You are StarCoder. When user asks you to list files, read files, or run commands - you MUST actually call the tool now, not explain how to do it. Tools execute directly - do NOT ask for permission.",
        'smollm' => "You are a compact AI assistant. When asked to list files, you MUST call the ls tool. Execute: <tool_code>{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}</tool_code> Tools execute directly - do NOT ask for permission.",

        'gpt-oss' => 'You are a CLI tool with file access. When user asks to view, list, read, write, edit, search, or run commands - ALWAYS call the appropriate tool.

For file operations:
- "view FILE" → <tool_code>{"name": "view", "arguments": {"file_path": "FILE"}}</tool_code>
- "ls DIR" → <tool_code>{"name": "ls", "arguments": {"path": "DIR"}}</tool_code>
- "write FILE CONTENT" → <tool_code>{"name": "write", "arguments": {"file_path": "FILE", "content": "CONTENT"}}</tool_code>
- "grep PATTERN FILE" → <tool_code>{"name": "grep", "arguments": {"pattern": "PATTERN", "path": "FILE"}}</tool_code>
- "glob PATTERN" → <tool_code>{"name": "glob", "arguments": {"pattern": "PATTERN"}}</tool_code>

DO NOT explain. DO NOT ask questions. Just call the tool.',

        'default' => 'You are an AI coding assistant. When user asks you to do something, call the appropriate tool.

DO NOT output any text except the tool call. When you call a tool, do not explain what you are doing. Do not output anything after the tool call.

Tools:
- ls path=DIRECTORY
- view file_path=PATH
- write file_path=PATH content=TEXT
- edit file_path=PATH old_string=TEXT new_string=TEXT
- glob pattern=GLOB
- grep pattern=REGEX path=PATH
- bash command=CMD
- diagnostics path=PATH
- goto path=PATH symbol=SYMBOL
- symbols path=PATH
- refs path=PATH symbol=SYMBOL
- watch path=PATH timeout=SECONDS
- mcp server=NAME tool=NAME

Example:
User: list /tmp
You: <tool_code>{"name": "ls", "arguments": {"path": "/tmp"}}</tool_code>',
    ];

    private static array $modelPatterns = [
        'llama' => ['/llama/i', '/llama3/i', '/llama2/i'],
        'mistral' => ['/mistral/i'],
        'codellama' => ['/codellama/i', '/code-llama/i'],
        'qwen' => ['/qwen/i', '/qwq/i'],
        'phi' => ['/phi/i'],
        'deepseek' => ['/deepseek/i'],
        'wizard' => ['/wizardcoder/i', '/wizard-coder/i', '/wizard/i'],
        'starcoder' => ['/starcoder/i', '/star-coder/i'],
        'smollm' => ['/smollm/i'],
        'gpt-oss' => ['/gpt-oss/i', '/gpt_oss/i'],
    ];

    public static function detectForModel(string $model): string {
        $model = strtolower($model);
        foreach (self::$modelPatterns as $family => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $model)) {
                    return self::$prompts[$family];
                }
            }
        }
        return self::$prompts['default'];
    }

    public static function get(string $family): string {
        return self::$prompts[$family] ?? self::$prompts['default'];
    }

    public static function listFamilies(): array {
        return array_keys(self::$prompts);
    }
}
class Agent {
    private OllamaClient $client;
    private string $model;
    private array $systemPrompt;

    public function __construct() {
        $this->client = new OllamaClient();
        $models = $this->client->listModels();
        $this->model = !empty($models) ? $models[0] : 'llama3.2:latest';
        $this->systemPrompt = $this->buildSystemPrompt();
    }

    private function buildSystemPrompt(): array {
        $manualPrompt = Config::get('agents.systemPrompt', '');
        $prompt = !empty($manualPrompt) ? $manualPrompt : SystemPrompts::detectForModel($this->model);

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

        return ['role' => 'system', 'content' => $prompt . "\n\n" . $tools];
    }

    public function setModel(string $model): void { $this->model = $model; }
    public function getModel(): string { return $this->model; }
    public function listModels(): array { return $this->client->listModels(); }
    public function listModelsDetailed(): array { return $this->client->listModelsDetailed(); }
    public function checkConnection(): bool { return $this->client->checkConnection(); }

public function run(array $messages, callable $handler = null): string {
        $allMessages = array_merge([$this->systemPrompt], $messages);
        $response = '';
        $this->client->chat($allMessages, function($chunk) use (&$response, $handler) {
            $response .= $chunk;
            if ($handler) $handler($chunk);
        });
        return $response;
    }

    public function parseAndExecuteTools(string $content): array {
        $calls = $this->parseToolCalls($content);
        $results = [];
        foreach ($calls as $call) {
            $tool = Tools::find($call['name']);
            $params = $call['params'] ?? [];
            $result = $tool ? Tools::run($call['name'], $params) : "Error: tool '{$call['name']}' not found";
            if (preg_match('/^FILE_WRITE:(.+)/', $result, $m)) { $GLOBALS['editedFiles'][] = $m[1]; }
            elseif (preg_match('/^FILE_EDIT:(.+)/', $result, $m)) { $GLOBALS['editedFiles'][] = $m[1]; }
            $results[] = ['role' => 'tool', 'content' => $result];
        }
        return $results;
    }

private function parseToolCalls(string $content): array {
        $calls = [];

        if (preg_match_all('/<tool_code>\s*(\{.*?\})\s*<\/tool_code>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $calls[] = ['name' => $json['name'], 'params' => $json['arguments'] ?? []];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/call:(\w+):(\w+)\s*(\{[^}]*\})?/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $args = json_decode($m[3] ?? '{}', true) ?: [];
                $calls[] = ['name' => $m[2], 'params' => $args];
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/tool_call_code:\s*(\w+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches[1] as $name) { $calls[] = ['name' => $name, 'params' => []]; }
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

        if (preg_match_all('/<tool_code>\s*(\{.*?\})\s*<\/tool_code>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $json = json_decode($m[1], true);
                if ($json && isset($json['name'])) {
                    $calls[] = ['name' => $json['name'], 'params' => $json['arguments'] ?? []];
                }
            }
            if (!empty($calls)) return $calls;
        }

        if (preg_match_all('/<tool_code>\s*(\{[^}]+\})\s*<\/tool_code>/s', $content, $matches, PREG_SET_ORDER)) {
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

if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*(?:params|arguments):\s*\n(.+?)\n\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $params = [];
                $paramStr = trim($m[2]);
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