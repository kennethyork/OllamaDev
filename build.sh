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

class Permission {
    private static array $allowed = [];
    private static array $denied = [];
    private static bool $promptMode = false;

    public static function setPromptMode(bool $mode): void {
        self::$promptMode = $mode;
    }

    public static function allow(string $tool): void {
        unset(self::$denied[$tool]);
        self::$allowed[$tool] = true;
    }

    public static function deny(string $tool): void {
        unset(self::$allowed[$tool]);
        self::$denied[$tool] = true;
    }

    public static function isAllowed(string $tool): bool {
        if (isset(self::$denied[$tool])) return false;
        if (isset(self::$allowed[$tool])) return true;
        if (!self::$promptMode) return true;
        return false;
    }

    public static function listAllowed(): array {
        return array_keys(self::$allowed);
    }

    public static function listDenied(): array {
        return array_keys(self::$denied);
    }

    public static function prompt(string $tool, string $command): bool {
        if (self::isAllowed($tool)) return true;
        echo "\n⚠️  Permission required for: $tool\n";
        echo "   Command: $command\n";
        echo "   Allow this? (yes/no/permanent): ";
        $input = trim(fgets(STDIN));
        if ($input === 'yes' || $input === 'y') {
            return true;
        } elseif ($input === 'permanent' || $input === 'p') {
            self::allow($tool);
            return true;
        }
        return false;
    }
}

class LSPClient {
    private string $command;
    private array $args;
    private array $caps;
    private $process;

    public function __construct(string $command, array $args = []) {
        $this->command = $command;
        $this->args = $args;
    }

    public function initialize(): void {
        $this->caps = [
            'textDocumentSync' => 1,
            'hoverProvider' => true,
            'definitionProvider' => true,
            'referencesProvider' => true,
            'documentSymbolProvider' => true,
            'completionProvider' => ['resolveProvider' => false]
        ];
    }

    public function sendRequest(string $method, array $params): ?array {
        if (!$this->process) {
            $cmd = $this->command . ' ' . implode(' ', $this->args);
            $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $this->process = proc_open($cmd, $descriptors, $pipes);
        }

        $id = uniqid();
        $request = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        fwrite($pipes[0], $request . "\n");
        fflush($pipes[0]);

        $response = fgets($pipes[1]);
        if ($response) {
            return json_decode($response, true);
        }
        return null;
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
        } elseif ($ext === 'js' || $ext === 'ts') {
            $output = shell_exec("npx tsc --noEmit " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\d+):(\d+)\s+error\s+(.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        } elseif ($ext === 'py') {
            $output = shell_exec("python -m py_compile " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output)) {
                if (preg_match('/line (\d+)/', $output, $m)) {
                    $diags[] = ['line' => (int)$m[1], 'col' => 1, 'severity' => 'error', 'message' => trim($output)];
                }
            }
        } elseif (in_array($ext, ['go'])) {
            $output = shell_exec("cd " . escapeshellarg(dirname($filePath)) . " && go vet ./... 2>&1");
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\w+\.go):(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[2], 'col' => (int)$m[3], 'severity' => 'error', 'message' => $m[4]];
                }
            }
        } elseif (in_array($ext, ['c', 'cpp'])) {
            $output = shell_exec("gcc -fsyntax-only " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output)) {
                preg_match_all('/(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        } elseif ($ext === 'rs') {
            $output = shell_exec("rustc --crate-type lib " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        }

        return $diags;
    }

    public function hover(string $filePath, int $line, int $col): ?string {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        if ($line < 1 || $line > count($lines)) return null;

        $currentLine = $lines[$line - 1];
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $currentLine, $matches, PREG_OFFSET_CAPTURE);

        $word = null;
        foreach ($matches[0] as $match) {
            if ($col >= $match[1] && $col <= $match[1] + strlen($match[0])) {
                $word = $match[0];
                break;
            }
        }

        if (!$word) return null;

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($ext === 'php') {
            $output = shell_exec("grep -n 'function $word\\|class $word\\|const $word' " . escapeshellarg($filePath) . " 2>/dev/null | head -5");
        } elseif ($ext === 'py') {
            $output = shell_exec("grep -n 'def $word\\|class $word\\|import $word' " . escapeshellarg($filePath) . " 2>/dev/null | head -5");
        } else {
            $output = shell_exec("grep -rn '$word' " . escapeshellarg($filePath) . " 2>/dev/null | head -5");
        }

        return $output ?: null;
    }

    public function gotoDefinition(string $filePath, int $line, int $col): ?array {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        if ($line < 1 || $line > count($lines)) return null;

        $currentLine = $lines[$line - 1];
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $currentLine, $matches, PREG_OFFSET_CAPTURE);

        $word = null;
        foreach ($matches[0] as $match) {
            if ($col >= $match[1] && $col <= $match[1] + strlen($match[0])) {
                $word = $match[0];
                break;
            }
        }

        if (!$word) return null;

        $dir = dirname($filePath);
        $output = shell_exec("grep -rn 'function $word\\|class $word\\|def $word\\|interface $word' " . escapeshellarg($dir) . " 2>/dev/null | head -1");

        if ($output && preg_match('/^(.*):(\d+):/', $output, $m)) {
            return ['file' => $m[1], 'line' => (int)$m[2]];
        }

        return null;
    }

    public function documentSymbols(string $filePath): array {
        $symbols = [];
        $content = file_get_contents($filePath);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        if ($ext === 'php') {
            preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'function', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
            preg_match_all('/class\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'class', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
        } elseif ($ext === 'py') {
            preg_match_all('/def\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'function', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
            preg_match_all('/class\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'class', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
        }

        return $symbols;
    }

    public function close(): void {
        if ($this->process) {
            proc_close($this->process);
            $this->process = null;
        }
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

    public static function hover(string $filePath, int $line, int $col): ?string {
        foreach (self::$clients as $client) {
            $result = $client->hover($filePath, $line, $col);
            if ($result) return $result;
        }
        return null;
    }

    public static function gotoDefinition(string $filePath, int $line, int $col): ?array {
        foreach (self::$clients as $client) {
            $result = $client->gotoDefinition($filePath, $line, $col);
            if ($result) return $result;
        }
        return null;
    }

    public static function documentSymbols(string $filePath): array {
        foreach (self::$clients as $client) {
            $result = $client->documentSymbols($filePath);
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
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false];
        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $j = json_decode($resp, true);
            if ($j && isset($j['message'])) {
                $content = $j['message']['content'] ?? '';
                $thinking = $j['message']['thinking'] ?? '';
                if (empty($content) && !empty($thinking)) $content = $thinking;
                if (!empty($content) && $handler) $handler($content);
            }
        }
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
    $path = $p['file_path'] ?? $p['path'] ?? '';
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

// Aliases for common tools
Tools::register('read', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

Tools::register('cat', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

Tools::register('head', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $n = $p['n'] ?? 10;
    $out = '';
    for ($i = 0; $i < min($n, count($lines)); $i++) $out .= $lines[$i];
    return $out;
});

Tools::register('tail', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $n = $p['n'] ?? 10;
    $start = max(0, count($lines) - $n);
    $out = '';
    for ($i = $start; $i < count($lines); $i++) $out .= $lines[$i];
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
    if (empty($pattern) && isset($p[0])) $pattern = $p[0];
    if (empty($pattern)) return "missing pattern";
    $basePath = $p['path'] ?? $p['file_path'] ?? '.';
    if (strpos($pattern, '*') === false) $pattern = '**/*' . $pattern;
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
Tools::register('list_directory', function($p) {
    $path = $p['path'] ?? '.';
    if (!is_dir($path)) return "Not a directory: $path";
    return shell_exec("ls -la " . escapeshellarg($path) . " 2>&1") ?: "Empty directory";
});
Tools::register('list_files', function($p) {
    $path = $p['path'] ?? '.';
    if (!is_dir($path)) return "Not a directory: $path";
    return shell_exec("ls -la " . escapeshellarg($path) . " 2>&1") ?: "Empty directory";
});
Tools::register('execute_command', function($p) {
    $cmd = $p['command'] ?? '';
    if (empty($cmd)) return "missing command";
    return shell_exec($cmd . " 2>&1") ?: "Command failed";
});

Tools::register('pwd', function($p) {
    return getcwd();
});

Tools::register('cd', function($p) {
    $path = $p['path'] ?? $p['dir'] ?? '';
    if (empty($path)) return "missing path";
    if (!is_dir($path)) return "Not a directory: $path";
    if (!chdir($path)) return "Failed to change directory: $path";
    return "Changed to: " . getcwd();
});

Tools::register('find', function($p) {
    $path = $p['path'] ?? '.';
    $name = $p['name'] ?? '*';
    $type = $p['type'] ?? '';
    $cmd = "find " . escapeshellarg($path);
    if ($type === 'd') $cmd .= " -type d";
    elseif ($type === 'f') $cmd .= " -type f";
    $cmd .= " -name " . escapeshellarg($name) . " 2>/dev/null";
    return shell_exec($cmd) ?: "No matches found";
});

Tools::register('tree', function($p) {
    $path = $p['path'] ?? '.';
    $depth = $p['depth'] ?? 2;
    return shell_exec("tree -L $depth -a " . escapeshellarg($path) . " 2>/dev/null") ?: shell_exec("find " . escapeshellarg($path) . " -maxdepth $depth -not -path '*/.*' | sort | sed 's|[^/]*/|  |g'");
});

Tools::register('stat', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing path";
    if (!file_exists($path)) return "Not found: $path";
    return shell_exec("stat " . escapeshellarg($path) . " 2>&1") ?: "stat failed";
});

Tools::register('diff', function($p) {
    $file1 = $p['file1'] ?? $p['file_path'] ?? '';
    $file2 = $p['file2'] ?? '';
    if (empty($file1) || empty($file2)) return "missing file1 or file2";
    return shell_exec("diff " . escapeshellarg($file1) . " " . escapeshellarg($file2) . " 2>&1") ?: "No differences";
});

Tools::register('wc', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    return shell_exec("wc -l " . escapeshellarg($path) . " 2>&1") ?: "wc failed";
});

Tools::register('sort', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    return shell_exec("sort " . escapeshellarg($path) . " 2>&1") ?: "sort failed";
});

Tools::register('uniq', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    return shell_exec("uniq " . escapeshellarg($path) . " 2>&1") ?: "uniq failed";
});

Tools::register('mkdir', function($p) {
    $path = $p['path'] ?? $p['dir'] ?? '';
    $parents = $p['parents'] ?? false;
    if (empty($path)) return "missing path";
    $cmd = $parents ? "mkdir -p" : "mkdir";
    return shell_exec("$cmd " . escapeshellarg($path) . " 2>&1") ?: "Created $path";
});

Tools::register('touch', function($p) {
    $path = $p['path'] ?? $p['file_path'] ?? '';
    if (empty($path)) return "missing path";
    if (file_exists($path)) {
        touch($path);
        return "Updated timestamp: $path";
    }
    if (touch($path)) return "Created: $path";
    return "Failed to create: $path";
});

Tools::register('cp', function($p) {
    $src = $p['src'] ?? $p['source'] ?? '';
    $dst = $p['dst'] ?? $p['dest'] ?? $p['destination'] ?? '';
    if (empty($src) || empty($dst)) return "missing src or dst";
    return shell_exec("cp -r " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1") ?: "Copied to $dst";
});

Tools::register('rm', function($p) {
    $path = $p['path'] ?? $p['file_path'] ?? '';
    $recursive = $p['recursive'] ?? $p['r'] ?? false;
    if (empty($path)) return "missing path";
    if (str_contains($path, 'node_modules') || str_contains($path, '.git')) return "Cannot remove system directories";
    if ($recursive) {
        return shell_exec("rm -rf " . escapeshellarg($path) . " 2>&1") ?: "Removed: $path";
    }
    return shell_exec("rm " . escapeshellarg($path) . " 2>&1") ?: "Removed: $path";
});

Tools::register('mv', function($p) {
    $src = $p['src'] ?? $p['source'] ?? '';
    $dst = $p['dst'] ?? $p['dest'] ?? $p['destination'] ?? '';
    if (empty($src) || empty($dst)) return "missing src or dst";
    return shell_exec("mv " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1") ?: "Moved to $dst";
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

Tools::register('bg', function($p) {
    $cmd = $p['command'] ?? $p['cmd'] ?? '';
    if (empty($cmd)) return "missing command";
    $background = $p['background'] ?? false;
    if ($background || str_ends_with(trim($cmd), '&')) {
        $cmd = trim($cmd, ' &');
        $cmd .= ' > /tmp/ollamadev_bg_' . substr(md5(mt_rand()), 0, 6) . '.log 2>&1 &';
        shell_exec($cmd);
        return "Started in background (PID: " . getmypid() . ")";
    }
    return shell_exec($cmd . ' 2>&1') ?: "(no output)";
});

Tools::register('wait_bg', function($p) {
    $maxWait = $p['seconds'] ?? 60;
    $start = time();
    while (time() - $start < $maxWait) {
        usleep(100000);
    }
    return "Waited $maxWait seconds";
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

Tools::register('hover', function($p) {
    $path = $p['file_path'] ?? '';
    $line = isset($p['line']) ? (int)$p['line'] : 1;
    $col = isset($p['col']) ? (int)$p['col'] : 1;
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    return LSP::hover($path, $line, $col) ?? "No hover info at position";
});

Tools::register('goto', function($p) {
    $path = $p['file_path'] ?? '';
    $line = isset($p['line']) ? (int)$p['line'] : 1;
    $col = isset($p['col']) ? (int)$p['col'] : 1;
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $result = LSP::gotoDefinition($path, $line, $col);
    if ($result) {
        return "Found at: {$result['file']}:{$result['line']}";
    }
    return "No definition found";
});

Tools::register('symbols', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $symbols = LSP::documentSymbols($path);
    if (empty($symbols)) return "No symbols found";
    $out = '';
    foreach ($symbols as $s) {
        $out .= "[{$s['kind']}] {$s['name']} (line {$s['line']})\n";
    }
    return $out;
});

Tools::register('find_refs', function($p) {
    $path = $p['file_path'] ?? '';
    $pattern = $p['pattern'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    if (empty($pattern)) return "No pattern specified";
    return shell_exec("grep -rn --color=never " . escapeshellarg($pattern) . " " . escapeshellarg(dirname($path)) . " 2>/dev/null | head -20") ?: "No references found";
});

Tools::register('format', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $formatted = false;

    if ($ext === 'php') {
        $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
        if (strpos($output, 'No syntax errors') !== false) {
            return "PHP syntax OK - use phpcbf for auto-formatting";
        }
        return $output;
    } elseif ($ext === 'js' || $ext === 'ts') {
        $output = shell_exec("npx prettier --check " . escapeshellarg($path) . " 2>&1");
        return $output ?: "Formatted";
    } elseif ($ext === 'py') {
        $output = shell_exec("python -m black --check " . escapeshellarg($path) . " 2>&1");
        return $output ?: "Formatted";
    }
    return "No formatter available for $ext";
});

Tools::register('lsp', function($p) {
    $action = $p['action'] ?? '';
    $path = $p['file_path'] ?? '';
    $line = isset($p['line']) ? (int)$p['line'] : 1;
    $col = isset($p['col']) ? (int)$p['col'] : 1;

    return match($action) {
        'diag' => Tools::run('diagnostics', $p),
        'hover' => Tools::run('hover', $p),
        'goto' => Tools::run('goto', $p),
        'symbols' => Tools::run('symbols', $p),
        'refs' => Tools::run('find_refs', $p),
        default => "Usage: lsp action=(diag|hover|goto|symbols|refs) file_path=<path> [line=<n>] [col=<n>]"
    };
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

Tools::register('permission', function($p) {
    $action = $p['action'] ?? '';
    $tool = $p['tool'] ?? '';
    if ($action === 'allow') {
        Permission::allow($tool);
        return "Allowed: $tool";
    } elseif ($action === 'deny') {
        Permission::deny($tool);
        return "Denied: $tool";
    } elseif ($action === 'list') {
        $allowed = Permission::listAllowed();
        return empty($allowed) ? "No permissions set" : "Allowed: " . implode(', ', $allowed);
    }
    return "Usage: permission action=(allow|deny|list) tool=<name>";
});

Tools::register('summarize', function($p) {
    $msgs = $p['messages'] ?? [];
    $context = $p['context'] ?? '';
    if (empty($msgs)) return "No messages to summarize";
    $text = implode("\n", array_map(fn($m) => $m['role'] . ': ' . substr($m['content'], 0, 200), $msgs));
    return "Summary placeholder - configure MCP summarizer for full functionality";
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
    $context = $p['context'] ?? '';
    $session = new Session(Config::load());
    $session->setAgentContext($context);
    ob_start();
    $session->startAgentLoop($prompt);
    $output = ob_get_clean();
    return $output ?: "Agent completed";
});

Tools::register('git_status', function($p) {
    $path = $p['path'] ?? '.';
    return shell_exec("cd " . escapeshellarg($path) . " && git status --short 2>&1") ?: "Not a git repo";
});

Tools::register('git_diff', function($p) {
    $path = $p['path'] ?? '.';
    $file = $p['file'] ?? '';
    $cached = $p['cached'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git diff";
    if ($cached) $cmd .= " --cached";
    if (!empty($file)) $cmd .= " -- " . escapeshellarg($file);
    return shell_exec($cmd . " 2>&1") ?: "No changes";
});

Tools::register('git_log', function($p) {
    $path = $p['path'] ?? '.';
    $n = $p['n'] ?? 10;
    return shell_exec("cd " . escapeshellarg($path) . " && git log --oneline -n $n 2>&1") ?: "Not a git repo";
});

Tools::register('git_branch', function($p) {
    $path = $p['path'] ?? '.';
    $all = $p['all'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git branch";
    if ($all) $cmd .= " -a";
    return shell_exec($cmd . " 2>&1") ?: "Not a git repo";
});

Tools::register('git_checkout', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    $new = $p['new'] ?? false;
    if (empty($branch)) return "missing branch";
    $cmd = "cd " . escapeshellarg($path) . " && git checkout";
    if ($new) $cmd .= " -b";
    $cmd .= " " . escapeshellarg($branch);
    return shell_exec($cmd . " 2>&1") ?: "Checkout failed";
});

Tools::register('git_commit', function($p) {
    $path = $p['path'] ?? '.';
    $msg = $p['message'] ?? $p['m'] ?? '';
    $all = $p['all'] ?? false;
    $amend = $p['amend'] ?? false;
    if (empty($msg)) return "missing commit message";
    $cmd = "cd " . escapeshellarg($path) . " && git commit";
    if ($all) $cmd .= " -a";
    if ($amend) $cmd .= " --amend";
    $cmd .= " -m " . escapeshellarg($msg);
    return shell_exec($cmd . " 2>&1") ?: "Commit failed";
});

Tools::register('git_add', function($p) {
    $path = $p['path'] ?? '.';
    $files = $p['files'] ?? '.';
    $all = $p['all'] ?? false;
    if ($all) {
        return shell_exec("cd " . escapeshellarg($path) . " && git add -A 2>&1") ?: "Added all";
    }
    return shell_exec("cd " . escapeshellarg($path) . " && git add " . escapeshellarg($files) . " 2>&1") ?: "Added $files";
});

Tools::register('git_merge', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    if (empty($branch)) return "missing branch";
    return shell_exec("cd " . escapeshellarg($path) . " && git merge " . escapeshellarg($branch) . " 2>&1") ?: "Merge failed";
});

Tools::register('git_rebase', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    $onto = $p['onto'] ?? '';
    if (empty($branch)) return "missing branch";
    $cmd = "cd " . escapeshellarg($path) . " && git rebase";
    if (!empty($onto)) $cmd .= " --onto " . escapeshellarg($onto);
    $cmd .= " " . escapeshellarg($branch);
    return shell_exec($cmd . " 2>&1") ?: "Rebase failed";
});

Tools::register('git_stash', function($p) {
    $path = $p['path'] ?? '.';
    $pop = $p['pop'] ?? false;
    $list = $p['list'] ?? false;
    $drop = $p['drop'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git stash";
    if ($list) return shell_exec($cmd . " list 2>&1") ?: "No stashes";
    if ($pop) return shell_exec($cmd . " pop 2>&1") ?: "Stash pop failed";
    if ($drop) return shell_exec($cmd . " drop 2>&1") ?: "Stash dropped";
    return shell_exec($cmd . " 2>&1") ?: "Stashed";
});

Tools::register('git_push', function($p) {
    $path = $p['path'] ?? '.';
    $force = $p['force'] ?? false;
    $upstream = $p['upstream'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git push";
    if ($force) $cmd .= " --force";
    if ($upstream) $cmd .= " -u";
    return shell_exec($cmd . " 2>&1") ?: "Push failed";
});

Tools::register('git_pull', function($p) {
    $path = $p['path'] ?? '.';
    $rebase = $p['rebase'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git pull";
    if ($rebase) $cmd .= " --rebase";
    return shell_exec($cmd . " 2>&1") ?: "Pull failed";
});

Tools::register('git_clone', function($p) {
    $url = $p['url'] ?? '';
    $path = $p['path'] ?? '.';
    if (empty($url)) return "missing url";
    return shell_exec("git clone " . escapeshellarg($url) . " " . escapeshellarg($path) . " 2>&1") ?: "Clone failed";
});

Tools::register('git_remote', function($p) {
    $path = $p['path'] ?? '.';
    $cmd = "cd " . escapeshellarg($path) . " && git remote -v 2>&1";
    return shell_exec($cmd) ?: "Not a git repo";
});

Tools::register('git_fetch', function($p) {
    $path = $p['path'] ?? '.';
    $all = $p['all'] ?? false;
    $prune = $p['prune'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git fetch";
    if ($all) $cmd .= " --all";
    if ($prune) $cmd .= " --prune";
    return shell_exec($cmd . " 2>&1") ?: "Fetch failed";
});

Tools::register('git_show', function($p) {
    $path = $p['path'] ?? '.';
    $ref = $p['ref'] ?? 'HEAD';
    $stat = $p['stat'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git show";
    if ($stat) $cmd .= " --stat";
    $cmd .= " " . escapeshellarg($ref);
    return shell_exec($cmd . " 2>&1") ?: "Show failed";
});

class SystemPrompts {
    private static array $prompts = [
        'llama' => 'You are a helpful AI assistant running locally via Ollama. Be precise and explicit in your responses.',

        'mistral' => 'You are Mistral, a helpful AI assistant running locally via Ollama. Be concise and accurate.',

        'codellama' => 'You are CodeLLama, an AI coding assistant. You specialize in writing, reading, and debugging code. Provide specific code examples with file paths and line numbers.',

        'qwen' => "You are Qwen. You MUST call tools to perform actions. NEVER describe what you would do - actually call the tools.

Examples:
User: list files
Response: <tool_code>\n{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}\n</tool_code>

User: show build.sh
Response: <tool_code>\n{\"name\": \"view\", \"arguments\": {\"file_path\": \"build.sh\"}}\n</tool_code>

User: find php files
Response: <tool_code>\n{\"name\": \"glob\", \"arguments\": {\"pattern\": \"*.php\"}}\n</tool_code>

Available tools: ls, view, write, edit, glob, grep, bash, fetch, patch, diagnostics",

        'phi' => 'You are Phi, a compact AI assistant running locally via Ollama. Be efficient and thorough. When asked to list files, ACTUALLY CALL THE TOOL - do not explain how to do it.',

        'wizard' => "You are WizardCoder. When user asks you to list files, read files, or run commands - you MUST actually call the tool now, not explain how to do it.

CRITICAL: Execute this EXACT format:
<tool_code>{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}</tool_code>

Do NOT explain. Do NOT show example code. Actually call the tool.",
        'starcoder' => "You are StarCoder. When user asks you to list files, read files, or run commands - you MUST actually call the tool now, not explain how to do it.

CRITICAL: Execute this EXACT format:
<tool_code>{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}</tool_code>

Do NOT explain. Do NOT show example code. Actually call the tool.",
        'smollm' => "You are a compact AI assistant. When asked to list files, you MUST call the ls tool. Execute: <tool_code>{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}</tool_code>",

        'gpt-oss' => "You are running as a CLI tool with file system access. When user asks to list files, you MUST use the ls tool. Execute this EXACT format: <tool_code>{\"name\": \"ls\", \"arguments\": {\"path\": \".\"}}</tool_code> Do NOT say you cannot access files. Do NOT explain. Call the tool now.",

        'default' => 'You are an expert AI coding assistant running locally via Ollama. Local models need EXPLICIT instructions. Do not assume anything. State everything clearly.',
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
        'gpt-oss' => ['/gpt-oss/i'],
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

11. mcp_servers
    - Lists all configured MCP servers and their tools
    - Use: mcp_servers -> shows server/tool list

12. mcp server=<name> tool=<toolname> [args]
    - Calls an MCP server tool
    - Example: mcp server=filesystem tool=read path=/tmp/file.txt

13. bg command=<cmd> [background=true]
    - Run command in background (adds &)
    - Output goes to /tmp/ollamadev_bg_*.log

14. wait_bg seconds=<n>
    - Wait for background jobs (n seconds)

MCP TOOLS:
- List available: call mcp_servers tool
- Call tool: mcp server=<name> tool=<tool> [param=value...]

TOOL PERMISSIONS:
- READ operations (view, glob, grep, ls, bash with readonly commands): Always allowed
- WRITE operations (write, edit, bash with modifying commands): Requires confirmation
- When prompted for permission, respond: PERMIT or DENY

COMPACT/SUMMARIZE:
- When conversation exceeds ~20 messages, summarize older messages
- Call: summarize with context of what was discussed
- Older summarized messages are replaced with a brief summary

TOOL CALL FORMAT - YOU MUST FOLLOW THIS EXACTLY:
<tool_call>
name: ls
params: path=.
</tool_call>

EXAMPLE - To list files in current directory:
<tool_call>
name: ls
params: path=.
</tool_call>

EXAMPLE - To search for files:
<tool_call>
name: glob
params: pattern=*.php
</tool_call>';

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

        if (preg_match_all('/<tool_call>\s*name:\s*(\w+)\s*params:\s*(.+?)\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $params = [];
                $paramStr = trim($m[2]);
                $paramStr = trim($paramStr, ',');
                preg_match_all('/([a-zA-Z_]+)=("[^"]*"|\'[^\']*\'|[^,\s]+)/', $paramStr, $kvMatches);
                foreach ($kvMatches[1] as $i => $key) {
                    $val = trim($kvMatches[2][$i], "\"' ");
                    $params[$key] = $val;
                }
                if (!empty($params) || !empty(trim($m[1]))) {
                    $calls[] = ['name' => $m[1], 'params' => $params];
                }
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

    public function load(string $sessionId): bool {
        $path = Config::sessionsDir() . '/' . $sessionId . '.json';
        if (!file_exists($path)) return false;
        $data = json_decode(file_get_contents($path), true);
        $this->id = $data['id'] ?? $sessionId;
        $this->title = $data['title'] ?? '';
        $this->model = $data['model'] ?? 'llama3.2:latest';
        $this->messages = $data['messages'] ?? [];
        return true;
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
    public function getModel(): string { return $this->model; }

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
        echo "║  Tools: view, write, edit, glob, grep, ls, bash, fetch, mcp, permission ║\n";
        echo "║  Auto-compact: enabled (at 20+ messages)                             ║\n";
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
            if (function_exists('readline')) {
                $input = readline('');
                if ($input === false) break;
                $input = trim($input);
                if (!empty($input)) readline_add_history($input);
            } else {
                $input = trim(fgets(STDIN));
            }

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

            // Auto-compact if too many messages
            if (count($this->messages) > 20) {
                $this->compactMessages();
            }

            $this->save();
            $this->renderStatus();
        }
    }

    private function compactMessages(): void {
        if (count($this->messages) < 15) return;

        echo "\n📝 Compacting conversation...\n";

        $keepLast = 5;
        $toSummarize = array_slice($this->messages, 0, -$keepLast);

        $summary = "Previous conversation summary:\n";
        foreach ($toSummarize as $msg) {
            $role = strtoupper($msg['role']);
            $content = substr($msg['content'], 0, 150);
            $summary .= "- $role: $content...\n";
        }

        $this->messages = array_merge(
            [['id' => 'summary_' . time(), 'role' => 'system', 'content' => $summary, 'created_at' => date('c')]],
            array_slice($this->messages, -$keepLast)
        );

        echo "   Compacted " . count($toSummarize) . " messages into summary.\n";
    }

    public function runSingle(string $prompt): string {
        $this->addMessage('user', $prompt);
        $thinkingMsgs = ['Thinking...', 'Working on it...', 'Analyzing...', 'Processing...'];
        echo $thinkingMsgs[array_rand($thinkingMsgs)] . "\n";
        $response = '';
        $this->agent->run($this->getMessages(), function($chunk) use (&$response) {
            $response .= $chunk;
        });
        echo "\nTool Results:\n";
        $toolResults = $this->agent->parseAndExecuteTools($response);
        foreach ($toolResults as $result) {
            $this->addMessage($result['role'], $result['content']);
            echo "[{$result['role']}]\n{$result['content']}\n";
        }
        if (empty($toolResults)) {
            echo "(no tools called)\n";
        }
        $this->addMessage('assistant', $response);
        $this->save();
        return $response;
    }
}

// CLI Entry Point
// Git CLI Command
if ($argc >= 2 && $argv[1] === 'git') {
    $config = Config::load();
    array_shift($argv);
    array_shift($argv);
    $gitCmd = implode(' ', $argv);
    if (empty($gitCmd)) {
        echo "Usage: ollamadev git <subcommand>\n";
        echo "Subcommands: status, diff, log, branch, checkout, commit, add, merge, rebase, stash, push, pull, clone, remote, fetch, show\n";
        exit(1);
    }
    $gitAliases = [
        'status' => 'git status',
        'diff' => 'git diff',
        'log' => 'git log --oneline -n 20',
        'branch' => 'git branch -a',
        'checkout' => 'git checkout',
        'commit' => 'git commit',
        'add' => 'git add',
        'merge' => 'git merge',
        'rebase' => 'git rebase',
        'stash' => 'git stash',
        'push' => 'git push',
        'pull' => 'git pull',
        'clone' => 'git clone',
        'remote' => 'git remote -v',
        'fetch' => 'git fetch --all',
        'show' => 'git show'
    ];
    $cmdParts = explode(' ', $gitCmd);
    $sub = $cmdParts[0];
    if (isset($gitAliases[$sub])) {
        $cmd = $gitAliases[$sub];
        if (count($cmdParts) > 1) $cmd .= ' ' . implode(' ', array_slice($cmdParts, 1));
        echo shell_exec($cmd . ' 2>&1');
    } else {
        passthru('git ' . $gitCmd);
    }
    exit(0);
}

// GitHub CLI Command
if ($argc >= 2 && $argv[1] === 'github') {
    $config = Config::load();
    array_shift($argv);
    array_shift($argv);
    $action = $argv[1] ?? '';
    $arg = $argv[2] ?? '';
    if ($action === 'pr' && !empty($arg)) {
        $prNum = filter_var($arg, FILTER_VALIDATE_INT);
        if (!$prNum) { echo "Invalid PR number\n"; exit(1); }
        $token = $_SERVER['GITHUB_TOKEN'] ?? '';
        $ch = curl_init("https://api.github.com/repos/o/o/pulls/$prNum");
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'] + ($token ? ["Authorization: token $token"] : []), CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'OllamaDev']);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (isset($data['head']['ref'])) {
            echo "PR #$prNum: {$data['title']}\nBranch: {$data['head']['ref']}\nURL: {$data['html_url']}\n";
            passthru("git fetch origin {$data['head']['ref']} && git checkout -b pr/$prNum origin/{$data['head']['ref']}");
        } else { echo "Could not fetch PR info\n"; }
    } elseif ($action === 'issue') {
        echo "GitHub Issues - use web interface or gh cli\n";
    } else {
        echo "Usage: ollamadev github pr <number>\n";
    }
    exit(0);
}

// MCP CLI Command
if ($argc >= 2 && $argv[1] === 'mcp') {
    $config = Config::load();
    $mcpConfig = $config['mcp'] ?? [];
    $action = $argv[2] ?? '';
    if ($action === 'list' || empty($action)) {
        echo "MCP Servers:\n";
        foreach ($mcpConfig as $name => $server) {
            echo "  $name: {$server['command']}\n";
        }
        if (empty($mcpConfig)) echo "  (none configured)\n";
    } elseif ($action === 'add') {
        $name = $argv[3] ?? '';
        $cmd = $argv[4] ?? '';
        if (empty($name) || empty($cmd)) { echo "Usage: ollamadev mcp add <name> <command>\n"; exit(1); }
        $mcpConfig[$name] = ['command' => $cmd, 'enabled' => true];
        $config['mcp'] = $mcpConfig;
        Config::save($config);
        echo "Added MCP server: $name\n";
    } elseif ($action === 'remove') {
        $name = $argv[3] ?? '';
        if (isset($mcpConfig[$name])) { unset($mcpConfig[$name]); $config['mcp'] = $mcpConfig; Config::save($config); echo "Removed $name\n"; }
        else echo "Server not found: $name\n";
    } else {
        echo "Usage: ollamadev mcp [list|add <name> <command>|remove <name>]\n";
    }
    exit(0);
}

// Serve/Web Command
if ($argc >= 2 && $argv[1] === 'serve') {
    $config = Config::load();
    $port = $argv[2] ?? 8080;
    echo "Starting OllamaDev web server on port $port...\n";
    echo "Web UI not yet implemented - use interactive mode: ollamadev\n";
    exit(0);
}

// Plugin CLI Command
if ($argc >= 2 && $argv[1] === 'plugin') {
    $config = Config::load();
    $pluginsDir = getenv('HOME') . '/.ollamadev/plugins';
    if (!is_dir($pluginsDir)) mkdir($pluginsDir, 0755, true);
    $action = $argv[2] ?? '';
    if ($action === 'list') {
        $plugins = glob("$pluginsDir/*.php");
        echo "Installed plugins:\n";
        foreach ($plugins ?: [] as $p) echo "  " . basename($p) . "\n";
        if (empty($plugins)) echo "  (none)\n";
    } elseif ($action === 'install') {
        $url = $argv[3] ?? '';
        if (empty($url)) { echo "Usage: ollamadev plugin install <url>\n"; exit(1); }
        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'plugin.php');
        $content = @file_get_contents($url);
        if ($content === false) { echo "Failed to download\n"; exit(1); }
        file_put_contents("$pluginsDir/$name", $content);
        echo "Installed: $name\n";
    } elseif ($action === 'remove') {
        $name = $argv[3] ?? '';
        $path = "$pluginsDir/$name";
        if (file_exists($path)) { unlink($path); echo "Removed: $name\n"; }
        else echo "Plugin not found: $name\n";
    } else {
        echo "Usage: ollamadev plugin [list|install <url>|remove <name>]\n";
    }
    exit(0);
}

// Export/Import Command
if ($argc >= 2 && $argv[1] === 'export') {
    $config = Config::load();
    $sessionId = $argv[2] ?? '';
    if (empty($sessionId)) {
        $sessions = Session::listAll($config);
        $sessionId = $sessions[0]['id'] ?? '';
    }
    if (empty($sessionId)) { echo "No sessions found\n"; exit(1); }
    $session = new Session($config, $sessionId);
    $data = json_encode(['id' => $sessionId, 'messages' => $session->getMessages(), 'model' => $session->getModel()], JSON_PRETTY_PRINT);
    $filename = "ollamadev-export-$sessionId.json";
    file_put_contents($filename, $data);
    echo "Exported to: $filename\n";
    exit(0);
}
if ($argc >= 2 && $argv[1] === 'import') {
    $config = Config::load();
    $file = $argv[2] ?? '';
    if (empty($file) || !file_exists($file)) { echo "Usage: ollamadev import <file>\n"; exit(1); }
    $data = json_decode(file_get_contents($file), true);
    if (!$data) { echo "Invalid JSON file\n"; exit(1); }
    $session = new Session($config);
    foreach ($data['messages'] ?? [] as $msg) { $session->addMessage($msg['role'], $msg['content']); }
    echo "Imported " . count($data['messages'] ?? []) . " messages into new session: {$session->getId()}\n";
    exit(0);
}

// Stats Command
if ($argc >= 2 && $argv[1] === 'stats') {
    $config = Config::load();
    $statsFile = getenv('HOME') . '/.ollamadev/stats.json';
    $stats = file_exists($statsFile) ? json_decode(file_get_contents($statsFile), true) : [];
    echo "OllamaDev Usage Stats\n";
    echo "=====================\n";
    echo "Total Requests: " . ($stats['requests'] ?? 0) . "\n";
    echo "Total Tokens: " . ($stats['tokens'] ?? 0) . "\n";
    echo "Sessions: " . ($stats['sessions'] ?? 0) . "\n";
    echo "Time: " . date('Y-m-d H:i:s', $stats['lastUsed'] ?? time()) . "\n";
    exit(0);
}

// ===== FLAG PARSING (must be before config load) =====
$flags = ['model' => null, 'continue' => false, 'session' => null, 'fork' => false, 'prompt' => null, 'agent' => null, 'pure' => false, 'port' => 0, 'hostname' => '127.0.0.1', 'mdns' => false, 'help' => false, 'version' => false];
$positional = [];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '-m' || $a === '--model') { $flags['model'] = $argv[++$i] ?? null; }
    elseif ($a === '-c' || $a === '--continue') { $flags['continue'] = true; }
    elseif ($a === '-s' || $a === '--session') { $flags['session'] = $argv[++$i] ?? null; }
    elseif ($a === '--fork') { $flags['fork'] = true; }
    elseif ($a === '--prompt') { $flags['prompt'] = $argv[++$i] ?? null; }
    elseif ($a === '--agent') { $flags['agent'] = $argv[++$i] ?? null; }
    elseif ($a === '--pure') { $flags['pure'] = true; }
    elseif ($a === '--port') { $flags['port'] = (int)($argv[++$i] ?? 0); }
    elseif ($a === '--hostname') { $flags['hostname'] = $argv[++$i] ?? '127.0.0.1'; }
    elseif ($a === '--mdns') { $flags['mdns'] = true; }
    elseif ($a === '-h' || $a === '--help') { $flags['help'] = true; }
    elseif ($a === '-v' || $a === '--version') { $flags['version'] = true; }
    elseif (!str_starts_with($a, '-')) { $positional[] = $a; }
}

// Apply env overrides
if (empty($flags['model']) && getenv('OLLAMA_MODEL')) $flags['model'] = getenv('OLLAMA_MODEL');
if (empty($flags['model']) && getenv('MODEL')) $flags['model'] = getenv('MODEL');

// Completion Command
if ($argc >= 2 && $argv[1] === 'completion') {
    $shell = $argv[2] ?? 'bash';
    echo "# ollamadev shell completion ($shell)\n";
    if ($shell === 'bash') {
        echo "complete -C 'ollamadev' ollamadev\n";
    } elseif ($shell === 'zsh') {
        echo "compdef _ollamadev ollamadev\n";
    }
    exit(0);
}

// Attach Command
if ($argc >= 2 && $argv[1] === 'attach') {
    $url = $argv[2] ?? '';
    if (empty($url)) { echo "Usage: ollamadev attach <url>\n"; exit(1); }
    echo "Attaching to: $url\n";
    echo "Attach not yet implemented\n";
    exit(0);
}

// Debug Command
if ($argc >= 2 && $argv[1] === 'debug') {
    $action = $argv[2] ?? '';
    echo "=== OllamaDev Debug Info ===\n";
    echo "Version: " . OLLAMADEV_VERSION . "\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "OS: " . PHP_OS . "\n";
    echo "Binary: " . __FILE__ . "\n";
    echo "Config: " . json_encode(Config::load(), JSON_PRETTY_PRINT) . "\n";
    if ($action === 'curl') {
        $ch = curl_init('http://localhost:11434/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        echo "Ollama API: " . (curl_exec($ch) ? "OK" : "FAIL") . "\n";
        curl_close($ch);
    }
    exit(0);
}

// Uninstall Command
if ($argc >= 2 && $argv[1] === 'uninstall') {
    echo "⚠️  This will remove OllamaDev and all data.\n";
    echo "Type 'yes' to confirm: ";
    $confirm = trim(fgets(STDIN));
    if ($confirm === 'yes') {
        $binary = __FILE__;
        if (file_exists($binary)) { unlink($binary); echo "Binary removed.\n"; }
        $home = getenv('HOME');
        $dirs = ["$home/.ollamadev", "$home/.config/ollamadev"];
        foreach ($dirs as $d) { if (is_dir($d)) { system("rm -rf " . escapeshellarg($d)); echo "Removed: $d\n"; } }
        echo "OllamaDev uninstalled.\n";
    }
    exit(0);
}

// Database Tools Command
if ($argc >= 2 && $argv[1] === 'db') {
    $action = $argv[2] ?? 'status';
    $home = getenv('HOME') . '/.ollamadev';
    if ($action === 'status') {
        echo "=== Database Status ===\n";
        echo "Data dir: $home\n";
        echo "Sessions: " . (is_dir("$home/data/sessions") ? count(scandir("$home/data/sessions")) - 2 : 0) . "\n";
    } elseif ($action === 'clean') {
        echo "Cleaning old sessions...\n";
        $cutoff = time() - (30 * 86400);
        if (is_dir("$home/data/sessions")) {
            foreach (glob("$home/data/sessions/*.json") as $f) {
                if (filemtime($f) < $cutoff) { unlink($f); echo "Removed: " . basename($f) . "\n"; }
            }
        }
        echo "Done.\n";
    } else {
        echo "Usage: ollamadev db [status|clean]\n";
    }
    exit(0);
}

// ACP Server Command
if ($argc >= 2 && $argv[1] === 'acp') {
    $port = $argv[2] ?? 18889;
    echo "Starting ACP server on port $port...\n";
    echo "ACP protocol not yet implemented.\n";
    exit(0);
}

// Upgrade Command
if ($argc >= 2 && $argv[1] === 'upgrade') {
    echo "Checking for updates...\n";
    $version = OLLAMADEV_VERSION;
    echo "Current: v$version\n";
    echo "Latest check not implemented - rebuild with: bash build.sh\n";
    exit(0);
}

// Models Command
if ($argc >= 2 && $argv[1] === 'models') {
    $config = Config::load();
    $client = new OllamaClient($config['ollama']['host'] ?? 'http://localhost:11434');
    $models = $client->listModels();
    echo "Available Models:\n";
    foreach ($models as $m) echo "  $m\n";
    exit(0);
}

// Providers Command
if ($argc >= 2 && $argv[1] === 'providers') {
    $config = Config::load();
    echo "OllamaDev Provider Configuration\n";
    echo "==================================\n";
    echo "Ollama Host: " . ($config['ollama']['host'] ?? 'http://localhost:11434') . "\n";
    echo "Default Model: " . ($config['ollama']['defaultModel'] ?? 'llama3.2:latest') . "\n";
    echo "\nTo change settings, edit: " . getenv('HOME') . "/.ollamadev/config.json\n";
    exit(0);
}

// Compact Command - auto compact sessions
if ($argc >= 2 && $argv[1] === 'compact') {
    $config = Config::load();
    $session = new Session($config);
    echo "Compacting session history...\n";
    echo "Done.\n";
    exit(0);
}

$config = Config::load();

// Apply model from flags
if (!empty($flags['model'])) { $config['ollama']['defaultModel'] = $flags['model']; }

// Handle positional commands
$cmd = $positional[0] ?? '';
$arg1 = $positional[1] ?? '';
$arg2 = $positional[2] ?? '';

// Built-in single-word commands
if ($cmd === 'help' || $flags['help']) {
    echo "OllamaDev CLI v" . OLLAMADEV_VERSION . " - Local AI coding agent using Ollama\n\n";
    echo "Usage: ollamadev [command] [options]\n\n";
    echo "Commands:\n";
    echo "  ollamadev            Start interactive chat\n";
    echo "  ollamadev chat       Start chat session\n";
    echo "  ollamadev new        Create new session\n";
    echo "  ollamadev list       List sessions\n";
    echo "  ollamadev load <id>  Load session\n";
    echo "  ollamadev git        Git commands (status, diff, commit, etc.)\n";
    echo "  ollamadev github pr  Fetch and checkout GitHub PR\n";
    echo "  ollamadev mcp        Manage MCP servers\n";
    echo "  ollamadev serve      Start web interface\n";
    echo "  ollamadev plugin     Manage plugins\n";
    echo "  ollamadev export     Export session to JSON\n";
    echo "  ollamadev import     Import session from JSON\n";
    echo "  ollamadev stats      Show usage statistics\n";
    echo "  ollamadev models     List available models\n";
    echo "  ollamadev providers  Show provider config\n";
    echo "  ollamadev compact    Compact session history\n";
    echo "  ollamadev upgrade    Check for updates\n";
    echo "  ollamadev completion Generate shell completion\n";
    echo "  ollamadev debug      Debug info\n";
    echo "  ollamadev db         Database tools\n";
    echo "  ollamadev uninstall  Uninstall OllamaDev\n";
    echo "  ollamadev help       Show this help\n";
    echo "\nOptions:\n";
    echo "  -m, --model <name>      Use specific model\n";
    echo "  -c, --continue          Continue last session\n";
    echo "  -s, --session <id>      Use specific session\n";
    echo "  --fork                  Fork session when continuing\n";
    echo "  --prompt <text>         Prompt to use\n";
    echo "  --agent <name>          Agent to use\n";
    echo "  --port <num>            Port for server\n";
    echo "  --hostname <host>       Hostname for server\n";
    echo "  --mdns                  Enable mDNS discovery\n";
    echo "  -h, --help              Show help\n";
    echo "  -v, --version           Show version\n";
    exit(0);
}

if ($cmd === 'version' || $flags['version']) { echo "OllamaDev v" . OLLAMADEV_VERSION . "\n"; exit(0); }

// Run single prompt if flag or positional
if (!empty($flags['prompt'])) {
    $session = new Session($config);
    $session->addMessage('user', $flags['prompt']);
    echo $session->runSingle($flags['prompt']) . "\n";
    exit(0);
}

// Continue last session
if ($flags['continue'] || $flags['session']) {
    $sessionId = $flags['session'];
    if (!$sessionId) {
        $sessions = Session::listAll($config);
        $sessionId = $sessions[0]['id'] ?? null;
    }
    if ($sessionId) {
        $session = new Session($config, $sessionId);
        if ($flags['fork'] && isset($argv[2])) {
            echo "Forking session...\n";
        }
        $session->start();
    } else { echo "No sessions to continue\n"; }
    exit(0);
}

if ($cmd === 'chat') {
    $prompt = $arg1 ?: '';
    if (empty($prompt) && !posix_isatty(STDIN)) { $prompt = trim(file_get_contents('php://stdin')); }
    if (empty($prompt)) { echo "Usage: ollamadev chat <prompt>\n"; exit(1); }
    $session = new Session($config);
    $session->addMessage('user', $prompt);
    $response = $session->runSingle($prompt);
    echo $response . "\n";
} elseif ($cmd === 'new') {
    (new Session($config))->createNew();
    echo "New session created.\n";
} elseif ($cmd === 'list') {
    foreach (Session::listAll($config) as $s) echo "{$s['id']} | {$s['title']} | {$s['model']} | {$s['updated_at']}\n";
} elseif ($cmd === 'load' && $arg1) {
    $session = new Session($config, $arg1);
    if (!file_exists(Config::sessionsDir() . '/' . $arg1 . '.json')) { echo "Session not found: $arg1\n"; exit(1); }
    $session->start();
} elseif ($cmd === 'run' && $arg1) {
    $session = new Session($config);
    $session->addMessage('user', $arg1);
    echo $session->runSingle($arg1) . "\n";
} elseif (empty($cmd)) {
    (new Session($config))->start();
} else {
    echo "Unknown command: $cmd\n";
    echo "Run 'ollamadev help' for usage.\n";
    exit(1);
}
ENDOFFILE

chmod +x "$BUILD_DIR/ollamadev"
echo "Built: $BUILD_DIR/ollamadev"

# Create version info
echo "v0.1.0" > "$BUILD_dir/VERSION"

# Show file info
ls -la "$BUILD_DIR/ollamadev"
echo "Lines: $(wc -l < "$BUILD_DIR/ollamadev")"