#!/bin/bash
# Build script for ollamadev single-file binary

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="$SCRIPT_DIR/dist/release"

mkdir -p "$BUILD_DIR"

echo "Building ollamadev..."

# Create single-file PHP binary by embedding all requires
cat > "$BUILD_DIR/ollamadev" << 'ENDOFFILE'
#!/usr/bin/env php
<?php
// OllamaDev - Single-file PHP binary
// Built from modular source

define('OLLAMADEV_VERSION', '0.1.0');

class Config {
    private static $config;

    public static function load(): array {
        if (self::$config) return self::$config;
        $defaults = [
            'ollama' => ['host' => 'http://localhost:11434', 'defaultModel' => 'llama3.2:latest'],
            'agents' => ['coder' => ['temperature' => 0.7, 'maxTokens' => 4096]],
            'data' => ['directory' => '.ollamadev']
        ];
        $home = getenv('HOME') ?: '/tmp';
        $paths = [$home.'/.ollamadev/config.json', $home.'/.config/ollamadev/config.json', '.ollamadev.json'];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $json = json_decode(file_get_contents($path), true);
                if ($json) { self::$config = array_replace_recursive($defaults, $json); return self::$config; }
            }
        }
        self::$config = $defaults;
        return self::$config;
    }

    public static function get(string $key, $default = null) {
        $config = self::load();
        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $k) { if (!isset($value[$k])) return $default; $value = $value[$k]; }
        return $value;
    }

    public static function dataDir(): string {
        $dir = self::get('data.directory', '.ollamadev');
        return str_starts_with($dir, '/') ? $dir : getcwd() . '/' . $dir;
    }

    public static function sessionsDir(): string { return self::dataDir() . '/sessions'; }
}

class MCPClient {
    private ?string $command;
    private string $type;
    private string $url;
    private array $headers;

    public function __construct(array $config) {
        $this->command = $config['command'] ?? null;
        $this->type = $config['type'] ?? 'stdio';
        $this->url = $config['url'] ?? '';
        $this->headers = $config['headers'] ?? [];
    }

    public function listTools(): array {
        if ($this->type === 'sse' && !empty($this->url)) {
            $ch = curl_init($this->url . '/tools');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers)]);
            $resp = curl_exec($ch);
            curl_close($ch);
            return json_decode($resp, true) ?? [];
        }
        return [];
    }

    public function callTool(string $name, array $args): string {
        if ($this->type === 'sse' && !empty($this->url)) {
            $ch = curl_init($this->url . '/rpc');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['method' => 'tools/call', 'params' => ['name' => $name, 'input' => $args]]),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers)),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true) ?? [];
            if (isset($data['content'][0]['text'])) return $data['content'][0]['text'];
            return $resp;
        }
        return "MCP tool call not supported for type: {$this->type}";
    }
}

class MCP {
    private static array $servers = [];
    private static array $tools = [];

    public static function load(array $config): void {
        $servers = $config['mcpServers'] ?? [];
        foreach ($servers as $name => $cfg) {
            if (($cfg['disabled'] ?? false)) continue;
            $client = new MCPClient($cfg);
            self::$servers[$name] = $client;
            $tools = $client->listTools();
            foreach ($tools as $tool) {
                self::$tools[$name . '/' . ($tool['name'] ?? '')] = ['name' => $name, 'tool' => $tool['name'] ?? ''];
            }
        }
    }

    public static function listTools(): array {
        $result = [];
        foreach (self::$tools as $key => $info) {
            $result[] = $key;
        }
        return $result;
    }

    public static function call(string $name, array $args): string {
        if (!isset(self::$tools[$name])) return "Tool not found: $name";
        $info = self::$tools[$name];
        $server = self::$servers[$info['name']] ?? null;
        if (!$server) return "Server not found: {$info['name']}";
        return $server->callTool($info['tool'], $args);
    }
}

class LSPClient {
    private string $command;
    private array $args;
    private array $caps;

    public function __construct(string $command, array $args = []) {
        $this->command = $command;
        $this->args = $args;
    }

    public function initialize(): void {
        $this->caps = ['textDocumentSync' => 1];
    }

    public function diagnostics(string $filePath): array {
        if (!file_exists($filePath)) return [];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $diags = [];

        if ($ext === 'php') {
            $output = shell_exec("php -l " . escapeshellarg($filePath) . " 2>&1");
            if (strpos($output, 'error') !== false || strpos($output, 'Parse error') !== false) {
                if (preg_match('/Parse error.*on line (\d+)/', $output, $m)) {
                    $diags[] = ['line' => (int)$m[1], 'col' => 1, 'severity' => 'error', 'message' => trim($output)];
                }
            }
        } elseif (in_array($ext, ['js', 'ts', 'py', 'rb', 'java', 'c', 'cpp', 'rs'])) {
            if ($ext === 'py') {
                $output = shell_exec("python -m py_compile " . escapeshellarg($filePath) . " 2>&1");
            } else {
                $output = shell_exec($ext . " -c " . escapeshellarg($filePath) . " 2>&1");
            }
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        }

        return $diags;
    }
}

class LSP {
    private static array $clients = [];

    public static function load(array $config): void {
        $servers = $config['lsp'] ?? [];
        foreach ($servers as $name => $cfg) {
            if (($cfg['disabled'] ?? false) || empty($cfg['command'])) continue;
            self::$clients[$name] = new LSPClient($cfg['command'], $cfg['args'] ?? []);
        }
    }

    public static function diagnostics(string $filePath): array {
        foreach (self::$clients as $client) {
            $result = $client->diagnostics($filePath);
            if (!empty($result)) return $result;
        }
        return [];
    }
}

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

    public function chat(array $messages, callable $handler = null): string {
        $model = Config::get('ollama.defaultModel', 'llama3.2:latest');
        return $this->chatWithModel($model, $messages, $handler);
    }

    public function chatWithModel(string $model, array $messages, callable $handler = null): string {
        $params = ['model' => $model, 'messages' => $messages, 'stream' => true];
        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => function($curl, $data) use ($handler) {
                foreach (explode("\n", trim($data)) as $line) {
                    if (empty($line)) continue;
                    $resp = json_decode($line, true);
                    if ($resp && isset($resp['message']['content']) && $handler) $handler($resp['message']['content']);
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout
        ]);
        curl_exec($ch);
        curl_close($ch);
        return '';
    }
}

class Tools {
    private static array $tools = [];

    public static function register(string $name, callable $fn): void { self::$tools[$name] = $fn; }
    public static function find(string $name): ?callable { return self::$tools[$name] ?? null; }
    public static function run(string $name, array $params): string {
        $fn = self::find($name);
        if (!$fn) return "Error: tool '$name' not found";
        try { return $fn($params); } catch (Exception $e) { return "Error: " . $e->getMessage(); }
    }
    public static function all(): array { return array_keys(self::$tools); }
}

Tools::register('view', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $offset = isset($p['offset']) ? (int)$p['offset'] : 0;
    $limit = isset($p['limit']) ? (int)$p['limit'] : count($lines);
    $out = '';
    for ($i = $offset; $i < min($offset + $limit, count($lines)); $i++) $out .= sprintf("%4d  %s", $i + 1, $lines[$i]);
    return $out;
});

Tools::register('write', function($p) {
    $path = $p['file_path'] ?? ''; $content = $p['content'] ?? '';
    if (empty($path)) return "missing file_path";
    if ($content === '') return "missing content";
    $dir = dirname($path);
    if (!empty($dir) && !is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, $content) !== false ? "Written to $path" : "Error writing file: $path";
});

Tools::register('edit', function($p) {
    $path = $p['file_path'] ?? ''; $oldStr = $p['old_string'] ?? ''; $newStr = $p['new_string'] ?? '';
    if (empty($path)) return "missing file_path";
    if (empty($oldStr)) return "missing old_string";
    $content = file_get_contents($path);
    if ($content === false) return "Error reading file: $path";
    $pos = strpos($content, $oldStr);
    if ($pos === false) return "old_string not found in file";
    return file_put_contents($path, substr_replace($content, $newStr, $pos, strlen($oldStr))) !== false ? "Edited $path" : "Error writing file: $path";
});

Tools::register('glob', function($p) {
    $pattern = $p['pattern'] ?? '';
    if (empty($pattern)) return "missing pattern";
    $basePath = $p['path'] ?? '.';
    if (!str_contains($pattern, '*')) $pattern = '**/*' . $pattern;
    $files = glob(rtrim($basePath, '/') . '/' . $pattern, GLOB_BRACE);
    return empty($files) ? "No files found" : implode("\n", $files);
});

Tools::register('grep', function($p) {
    $pattern = $p['pattern'] ?? '';
    if (empty($pattern)) return "missing pattern";
    $path = $p['path'] ?? '.';
    $include = $p['include'] ?? '';
    $cmd = "grep -rn --color=never " . escapeshellarg($pattern) . " " . escapeshellarg($path);
    if (!empty($include)) $cmd .= " --include=" . escapeshellarg($include);
    return shell_exec($cmd . ' 2>&1') ?: "No matches found";
});

Tools::register('ls', function($p) {
    $path = $p['path'] ?? '.';
    if (!is_dir($path)) return "Not a directory: $path";
    return shell_exec("ls -la " . escapeshellarg($path) . " 2>&1") ?: "Empty directory";
});

Tools::register('bash', function($p) {
    $cmd = $p['command'] ?? '';
    if (empty($cmd)) return "missing command";
    $readonly = ['ls', 'pwd', 'cat', 'head', 'tail', 'grep', 'find', 'git', 'echo', 'wc', 'sort', 'uniq', 'awk', 'sed', 'cut', 'tr', 'file', 'stat', 'diff', 'tree'];
    $first = strtok($cmd, ' ');
    if (!in_array($first, $readonly)) return "Command not allowed (readonly only): $first";
    foreach (['curl', 'wget', 'chmod', 'sudo', 'rm -rf', 'mkfs'] as $b) { if (str_contains($cmd, $b)) return "Dangerous command blocked: $b"; }
    return shell_exec($cmd . ' 2>&1') ?: "(no output)";
});

Tools::register('fetch', function($p) {
    $url = $p['url'] ?? '';
    if (empty($url)) return "missing url";
    return shell_exec("curl -fsSL --max-time " . ($p['timeout'] ?? 30) . " " . escapeshellarg($url) . " 2>&1") ?: "Failed to fetch $url";
});

Tools::register('diagnostics', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $diags = LSP::diagnostics($path);
    if (empty($diags)) return "No diagnostics";
    $out = '';
    foreach ($diags as $d) {
        $out .= "Line {$d['line']}: [{$d['severity']}] {$d['message']}\n";
    }
    return $out;
});

Tools::register('mcp', function($p) {
    $server = $p['server'] ?? '';
    $tool = $p['tool'] ?? '';
    $name = $server . '/' . $tool;
    if (empty($server) || empty($tool)) return "missing server or tool";
    $args = array_diff_key($p, ['server' => '', 'tool' => '']);
    return MCP::call($name, $args);
});

Tools::register('mcp_servers', function($p) {
    $servers = MCP::listTools();
    if (empty($servers)) return "No MCP servers configured";
    return "Available MCP tools:\n" . implode("\n", array_map(fn($s) => "  - $s", $servers));
});

Tools::register('patch', function($p) {
    $path = $p['file_path'] ?? '';
    $diff = $p['diff'] ?? '';
    if (empty($path)) return "missing file_path";
    if (empty($diff)) return "missing diff";
    $tmpFile = tempnam(sys_get_temp_dir(), 'patch_');
    file_put_contents($tmpFile, $diff);
    $output = shell_exec("patch -p1 -i " . escapeshellarg($tmpFile) . " 2>&1");
    unlink($tmpFile);
    return $output ?: "Patched $path";
});

Tools::register('agent', function($p) {
    $prompt = $p['prompt'] ?? '';
    if (empty($prompt)) return "missing prompt";
    return "Sub-agent requested: $prompt\n(Sub-agents run a nested Ollama session with the same tools)";
});

class Agent {
    private OllamaClient $client;
    private string $model;
    private array $systemPrompt;

    public function __construct() {
        $this->client = new OllamaClient();
        $models = $this->client->listModels();
        $this->model = !empty($models) ? $models[0] : 'llama3.2:latest';
        $this->systemPrompt = ['role' => 'system', 'content' => 'You are OllamaDev, an expert AI coding assistant running locally via Ollama.

CRITICAL: You are running on a LOCAL model. Local models need EXPLICIT instructions. Do not assume anything. State everything clearly.

TOOLS AVAILABLE:
1. view <file_path> [offset=0] [limit=100]
   - Reads file with line numbers (line: content)
   - Always check file exists before writing

2. write <file_path> <content>
   - Creates new file OR overwrites existing file completely
   - Use this when file does not exist or you want to replace ALL content

3. edit <file_path> <old_string> <new_string>
   - Replaces FIRST occurrence of old_string with new_string in file
   - old_string MUST match exactly (including spaces, newlines)
   - For multiple replacements, call edit multiple times

4. glob <pattern>
   - Finds files matching glob pattern (e.g., "*.php", "src/**/*.js")
   - Returns full paths, one per line

5. grep <pattern> [path="."] [include="*.php"]
   - Searches using regex (BRE syntax, not full regex)
   - include filters by file extension
   - Returns lines with format: filename:line:matched_content

6. ls [path="."]
   - Lists directory contents with permissions, size, date
   - Default path is current directory

7. bash <command>
   - Executes read-only commands: ls, cat, grep, find, git, echo, head, tail, wc, sort
   - Do NOT use: curl, wget, chmod, sudo, rm -rf, pip, npm install, git push
   - Output appears directly in chat

8. fetch <url>
   - Downloads web page content via curl
   - Use for reading documentation

9. patch <file_path> <diff>
   - Applies unified diff patch to file

10. diagnostics <file_path>
    - Shows syntax/lint errors for: php, js, ts, py, rb, java, c, cpp, rs
    - Returns line numbers and error messages

11. mcp_servers
    - Lists all configured MCP servers and their tools

12. mcp server=<name> tool=<toolname> [args]
    - Calls an MCP server tool

TOOL CALL FORMAT - YOU MUST FOLLOW THIS EXACTLY:
```
<tool_call>
name: tool_name
params: param1=value, param2=value
</tool_call>
```

- name must be ONE of: view, write, edit, glob, grep, ls, bash, fetch, patch, diagnostics, mcp, mcp_servers
- params are key=value pairs separated by commas
- For write, use: file_path=/path/to/file, content=the entire file content
- For edit, use: file_path=/path, old_string=text to find, new_string=replacement text
- String values do not need quotes unless they contain commas

HOW TO USE TOOLS - IMPORTANT:

1. TASK COMPLETION: Call tools repeatedly until the task is DONE.
   - Do NOT stop after one tool call
   - Do NOT just describe what you would do
   - Actually call the tools until the task is complete
   - Example: To fix a bug, call view, then grep, then edit, then diagnostics - keep going

2. EXPLORE BEFORE WRITE:
   - Use view/grep/glob to understand the codebase FIRST
   - Do not write code blind - explore first

3. VERIFY AFTER CHANGE:
   - After write/edit, use diagnostics or run tests to verify

4. ERROR RECOVERY:
   - If a tool fails, read the error message
   - Adjust your approach and retry
   - Common errors: file not found, permission denied, syntax error

5. NOVEL TASK WORKFLOW:
   a) Explore: glob/grep to understand structure
   b) View key files to understand implementation
   c) Make changes: write/edit
   d) Verify: diagnostics or run tests
   e) Test: bash to run linter, tests, etc
   f) Repeat until done

FORBIDDEN:
- Do not say "I cannot" or "I would need" - just use the tools
- Do not stop mid-task - keep going until complete
- Do not ask for permission to use tools - just use them
- Do not output anything except tool calls when solving tasks

OUTPUT RULES:
- When working on a task: output tool calls, not long explanations
- When done: give brief summary of what was changed
- You can explain briefly between tool calls if helpful
- NEVER write code in markdown - just use the write tool

You have full access to this codebase. Act like a senior developer who gets things done efficiently.'];
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

    public function parseAndExecuteTools(string $content): array {
        $results = [];
        foreach ($this->parseToolCalls($content) as $call) {
            $tool = Tools::find($call['name']);
            $params = $call['params'] ?? [];
            $results[] = ['role' => 'tool', 'content' => $tool ? Tools::run($call['name'], $params) : "Error: tool '{$call['name']}' not found"];
        }
        return $results;
    }

    private function parseToolCalls(string $content): array {
        $calls = [];
        $inCall = false; $current = null; $args = '';
        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '<tool_call>') || str_starts_with($trimmed, '```tool_call')) { $inCall = true; $current = ['name' => '', 'params' => []]; continue; }
            if (str_ends_with($trimmed, '</tool_call>') || str_starts_with($trimmed, '```')) {
                if ($inCall && !empty($current['name'])) { $current['params'] = $this->parseParams($args); $calls[] = $current; }
                $inCall = false; $current = null; $args = ''; continue;
            }
            if ($inCall && $current !== null) {
                if (str_starts_with($trimmed, 'name:')) $current['name'] = trim(substr($trimmed, 5));
                elseif (str_starts_with($trimmed, 'params:') || str_starts_with($trimmed, 'args:')) $args = trim(substr($trimmed, strlen(str_starts_with($trimmed, 'params:') ? 'params:' : 'args:')));
                else $args .= ' ' . $trimmed;
            }
        }
        return $calls;
    }

    private function parseParams(string $argsStr): array {
        $argsStr = trim($argsStr);
        if (empty($argsStr)) return [];
        if (str_starts_with($argsStr, '{') && str_ends_with($argsStr, '}')) return json_decode($argsStr, true) ?? [];
        $params = [];
        foreach (explode(',', $argsStr) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) $params[trim($kv[0])] = trim($kv[1], "\"' ");
        }
        return $params;
    }
}

class TUI {
    const CLEAR = "\033[2J";
    const HOME = "\033[H";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";
    const DIM = "\033[2m";
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";

    private int $width = 80;
    private int $height = 24;

    public function __construct() {
        if (function_exists('exec')) {
            $size = [];
            exec('stty size 2>/dev/null', $size);
            if (count($size) === 2) {
                $this->height = (int)$size[0];
                $this->width = (int)$size[1];
            }
        }
    }

    public function clear(): void { echo self::CLEAR . self::HOME; }
    public function move(int $row, int $col): void { echo "\033[{$row};{$col}H"; }
    public function reset(): void { echo self::RESET; }

    public function write(string $text, ?string $color = null, bool $bold = false): void {
        if ($color) echo $color;
        if ($bold) echo self::BOLD;
        echo $text;
        if ($bold || $color) echo self::RESET;
    }

    public function writeAt(int $row, int $col, string $text, ?string $color = null): void {
        $this->move($row, $col);
        $this->write($text, $color);
    }

    public function clearLine(int $row): void {
        $this->move($row, 1);
        echo "\033[2K";
    }

    public function clearLines(int $start, int $end): void {
        for ($i = $start; $i <= $end; $i++) {
            $this->clearLine($i);
        }
    }

    public function box(int $top, int $left, int $height, int $width, ?string $title = null): void {
        $this->move($top, $left);
        echo '+' . str_repeat('─', $width - 2) . '+';
        for ($i = 1; $i < $height - 1; $i++) {
            $this->move($top + $i, $left);
            echo '│' . str_repeat(' ', $width - 2) . '│';
        }
        $this->move($top + $height - 1, $left);
        echo '+' . str_repeat('─', $width - 2) . '+';
        if ($title) {
            $this->move($top, $left + 2);
            echo " $title ";
        }
    }

    public function hline(int $row, int $left, int $width): void {
        $this->move($row, $left);
        echo str_repeat('─', $width);
    }

    public function statusBar(string $left, string $right, int $row = 0): void {
        $row = $row ?: $this->height;
        $this->move($row, 1);
        echo "\033[7m"; // reverse
        $padLen = $this->width - strlen($right);
        echo str_pad($left, $padLen);
        echo $right;
        echo self::RESET;
    }

    public function input(string $prompt, int $row = null): string {
        $row = $row ?: $this->height;
        $this->move($row, 1);
        echo "\033[7m$prompt\033[0m ";
        $input = '';
        while (true) {
            $c = $this->getChar();
            if ($c === "\n" || $c === "\r" || ord($c) === 13) {
                echo "\n";
                break;
            } elseif (ord($c) === 127 || ord($c) === 8) {
                if (strlen($input) > 0) {
                    $input = substr($input, 0, -1);
                    echo "\033[1D \033[1D";
                }
            } elseif (ord($c) >= 32) {
                $input .= $c;
                echo $c;
            }
        }
        return $input;
    }

    public function getChar(): string {
        $fp = fopen('/dev/tty', 'r');
        stream_set_blocking($fp, false);
        $c = fgetc($fp);
        fclose($fp);
        return $c ?? '';
    }

    public function keyPress(int $timeout = 0): ?string {
        if ($timeout > 0) {
            $fp = fopen('/dev/tty', 'r');
            stream_set_blocking($fp, false);
            usleep($timeout * 1000);
            $c = fgetc($fp);
            fclose($fp);
            return $c ?: null;
        }
        return $this->getChar();
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function renderMessages(array $messages, int $top = 2, int $bottom = 3): void {
        $maxLines = $this->height - $bottom - $top;
        $line = $top;

        foreach ($messages as $msg) {
            if ($line >= $this->height - $bottom) break;

            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            $icon = match($role) { 'user' => '👤', 'assistant' => '🤖', 'tool' => '🔧', default => '•' };
            $color = match($role) { 'user' => self::CYAN, 'assistant' => self::GREEN, 'tool' => self::YELLOW, default => self::DIM };

            $this->clearLine($line);
            $this->write(" $icon ", self::BOLD . $color);
            $this->write("[{$role}]", $color);
            $line++;

            foreach (explode("\n", $content) as $l) {
                if ($line >= $this->height - $bottom) break;
                $this->clearLine($line);
                $this->move($line++, 4);
                echo substr($l, 0, $this->width - 5);
            }
            if ($line < $this->height - $bottom) {
                $this->clearLine($line++);
            }
        }

        while ($line < $this->height - $bottom) {
            $this->clearLine($line++);
        }
    }

    public function renderModelList(array $models, string $current, int $top = 5, int $left = 10, int $height = 12, int $width = 50): void {
        $this->box($top, $left, $height, $width, "Models (Esc to close)");
        $row = $top + 2;
        foreach ($models as $i => $m) {
            $name = $m['name'] ?? 'unknown';
            $size = isset($m['size']) ? $this->formatBytes($m['size']) : '';
            $selected = $name === $current ? ' ◀' : '';
            $this->clearLine($row);
            $this->move($row++, $left + 3);
            $this->write(sprintf("%-25s %10s%s", $name, $size, $selected), $selected ? self::GREEN : self::DIM);
        }
    }

    public function renderSessionList(array $sessions, int $top = 5, int $left = 10, int $height = 12, int $width = 50): void {
        $this->box($top, $left, $height, $width, "Sessions (Enter to select, Esc close)");
        $row = $top + 2;
        foreach ($sessions as $s) {
            $title = substr($s['title'] ?? 'Untitled', 0, $width - 10);
            $this->clearLine($row);
            $this->move($row++, $left + 3);
            $this->write($title, self::DIM);
        }
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1024) . ' KB';
    }
}

class Session {
    private array $config;
    private string $id;
    private string $title;
    private string $model;
    private array $messages = [];
    private Agent $agent;

    public function __construct(array $config, ?string $sessionId = null) {
        $this->config = $config;
        $this->ensureDataDir();
        MCP::load($config);
        LSP::load($config);
        $this->agent = new Agent();
        if ($sessionId) { $this->load($sessionId); }
        else { $this->id = 'session_' . time() . '_' . substr(md5(mt_rand()), 0, 8); $this->title = "Session " . date('Y-m-d H:i'); $this->model = $this->getLatestModel(); }
    }

    private function ensureDataDir(): void { $dir = Config::sessionsDir(); if (!is_dir($dir)) mkdir($dir, 0755, true); }

    public function createNew(): void { $this->save(); }

    public function load(string $sessionId): void {
        $path = Config::sessionsDir() . '/' . $sessionId . '.json';
        $data = json_decode(file_get_contents($path), true);
        $this->id = $data['id'] ?? $sessionId;
        $this->title = $data['title'] ?? '';
        $this->model = $data['model'] ?? 'llama3.2:latest';
        $this->messages = $data['messages'] ?? [];
    }

    public function save(): void {
        $path = Config::sessionsDir() . '/' . $this->id . '.json';
        file_put_contents($path, json_encode(['id' => $this->id, 'title' => $this->title, 'model' => $this->model, 'messages' => $this->messages, 'created_at' => date('c'), 'updated_at' => date('c')], JSON_PRETTY_PRINT));
    }

    public function addMessage(string $role, string $content): void {
        $this->messages[] = ['id' => 'msg_' . time() . '_' . substr(md5(mt_rand()), 0, 6), 'role' => $role, 'content' => $content, 'created_at' => date('c')];
        $this->save();
    }

    public function getMessages(): array { return $this->messages; }
    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }

    public static function listAll(array $config): array {
        $dir = Config::sessionsDir();
        if (!is_dir($dir)) return [];
        $sessions = [];
        foreach (glob($dir . '/session_*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) $sessions[] = ['id' => basename($file, '.json'), 'title' => $data['title'] ?? 'Untitled', 'model' => $data['model'] ?? 'unknown', 'created_at' => $data['created_at'] ?? '', 'updated_at' => $data['updated_at'] ?? ''];
        }
        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        return $sessions;
    }

    private function getLatestModel(): string {
        $models = $this->agent->listModelsDetailed();
        if (empty($models)) return 'llama3.2:latest';
        usort($models, fn($a, $b) => strcmp($b['modified_at'] ?? '', $a['modified_at'] ?? ''));
        return $models[0]['name'] ?? 'llama3.2:latest';
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    private function renderBanner(): void {
        $models = $this->agent->listModelsDetailed();
        $modelCount = count($models);
        echo "\n╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                     OllamaDev                                ║\n";
        echo "║  Local AI coding agent powered by Ollama                     ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Current Model: {$this->model}                              ║\n";
        echo "║  {$modelCount} model(s) available                                ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Tools: view, write, edit, glob, grep, ls, bash, fetch       ║\n";
        echo "║  Commands: exit, new, mode, verbose, model, clear, help      ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    }

    private function renderPrompt(): void { echo "[{$this->model}] > "; }
    private function countTokens(): int { $total = 0; foreach ($this->messages as $msg) $total += strlen($msg['content'] ?? '') / 4; return (int)$total; }
    private function renderStatus(): void { echo "\n[Model: {$this->model} | Tokens: ~" . $this->countTokens() . " | Messages: " . count($this->messages) . "]\n"; }

    private function handleCommand(string $input): bool {
        $parts = preg_split('/\s+/', trim($input), 2);
        $cmd = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        switch ($cmd) {
            case 'exit': case 'quit': case 'q': echo "Goodbye!\n"; return true;
            case 'new': $this->save(); (new Session($this->config))->start(); return true;
            case 'mode': echo "Mode set to: " . ($args ?: 'auto') . "\n"; return false;
            case 'verbose': $GLOBALS['verbose'] = trim($args) === 'on'; echo "Verbose: " . ($GLOBALS['verbose'] ? 'on' : 'off') . "\n"; return false;
            case 'model':
                if (!empty($args)) { $this->agent->setModel($args); $this->model = $args; echo "Model: $args\n"; }
                else {
                    $models = $this->agent->listModelsDetailed();
                    echo "\nAvailable Models:\n";
                    echo str_repeat('-', 45) . "\n";
                    foreach ($models as $m) {
                        $name = $m['name'] ?? 'unknown';
                        $size = isset($m['size']) ? $this->formatBytes($m['size']) : 'unknown';
                        $marker = $name === $this->model ? ' *' : '';
                        echo sprintf("  %-20s %s%s\n", $name, $size, $marker);
                    }
                    echo str_repeat('-', 45) . "\n";
                    echo "Current: {$this->model}\n";
                }
                return false;
            case 'clear': echo "\033[2J\033[H"; return false;
            case 'help': $this->renderBanner(); return false;
            case '': return false;
            default:
                if (str_starts_with($cmd, '/')) { echo "Unknown command: $cmd\n"; return false; }
                return false;
        }
    }

    public function start(): void {
        $this->renderBanner();
        if (!$this->agent->checkConnection()) {
            echo "⚠️  Cannot connect to Ollama at " . Config::get('ollama.host') . "\n";
            echo "   Make sure Ollama is running: `ollama serve`\n\n";
        }

        if (!empty($this->messages)) {
            echo "📜 Loading previous messages...\n";
            foreach ($this->messages as $msg) {
                $icon = match($msg['role']) { 'user' => '👤', 'assistant' => '🤖', 'tool' => '🔧', default => '•' };
                echo "\n{$icon} [{$msg['role']}]\n{$msg['content']}\n";
            }
            echo "\n";
        }
        $this->renderStatus();

        while (true) {
            $this->renderPrompt();
            $input = trim(fgets(STDIN));

            if ($this->handleCommand($input)) break;
            if (empty($input)) continue;

            $this->addMessage('user', $input);
            $thinkingMsgs = [
                'Thinking...', 'Working on it...', 'Let me check that...', 'Analyzing...',
                'Processing...', 'figuring it out...', 'On it...', 'Checking...',
                'Searching...', 'reading...', 'writing...', 'coding...',
                'hm...', 'let me think...', 'give me a sec...', 'hold on...',
                'looking into it...', 'brb...', 'considering...', 'working...',
                'exploring...', 'examining...', 'investigating...', 'digesting...',
                'computing...', 'calculating...', 'reasoning...', 'thinking through...',
                'cooking up a response...', 'piecing it together...'
            ];
            $thinkMsg = $thinkingMsgs[array_rand($thinkingMsgs)];
            echo "\n🤖 $thinkMsg\n\n";

            $response = '';
            $this->agent->run($this->getMessages(), function($chunk) use (&$response) {
                echo $chunk;
                $response .= $chunk;
            });

            echo "\n";

            $toolResults = $this->agent->parseAndExecuteTools($response);
            foreach ($toolResults as $result) {
                $this->addMessage($result['role'], $result['content']);
                echo "\n🔧 [tool]\n{$result['content']}\n";
            }

            if (empty($toolResults) && !empty(trim($response))) {
                $this->addMessage('assistant', $response);
            } elseif (!empty($toolResults)) {
                $this->addMessage('assistant', $response);
            }

            $this->save();
            $this->renderStatus();
        }
    }
}

// CLI Entry Point
$config = Config::load();

if (isset($argv[1]) && $argv[1] === 'chat') {
    (new Session($config))->start();
} elseif (isset($argv[1]) && $argv[1] === 'new') {
    (new Session($config))->createNew();
    echo "New session created.\n";
} elseif (isset($argv[1]) && $argv[1] === 'list') {
    foreach (Session::listAll($config) as $s) echo "{$s['id']} | {$s['title']} | {$s['model']} | {$s['updated_at']}\n";
} elseif (isset($argv[1]) && $argv[1] === 'load' && isset($argv[2])) {
    (new Session($config, $argv[2]))->start();
} elseif (isset($argv[1]) && $argv[1] === 'help') {
    echo "OllamaDev CLI v" . OLLAMADEV_VERSION . " - Local AI coding agent using Ollama\n\n";
    echo "Usage: ollamadev [command]\n\n";
    echo "Commands:\n  ollamadev           Start interactive chat\n  ollamadev chat       Start chat session\n  ollamadev new        Create new session\n  ollamadev list       List sessions\n  ollamadev load <id>  Load session\n  ollamadev help       Show this help\n";
} elseif (isset($argv[1]) && $argv[1] === 'version') {
    echo "OllamaDev v" . OLLAMADEV_VERSION . "\n";
} else {
    (new Session($config))->start();
}
ENDOFFILE

chmod +x "$BUILD_DIR/ollamadev"
echo "Built: $BUILD_DIR/ollamadev"

# Create version info
echo "v0.1.0" > "$BUILD_dir/VERSION"

# Show file info
ls -la "$BUILD_DIR/ollamadev"
echo "Lines: $(wc -l < "$BUILD_DIR/ollamadev")"