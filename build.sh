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

define('OLLAMADEV_VERSION', '3.9.2');
$GLOBALS['editedFiles'] = [];

function isWindows(): bool { return stripos(PHP_OS, 'WIN') === 0; }

function crossPlatformLs(string $path): string {
    $path = rtrim($path, '/\\');
    if (!is_dir($path)) return "Not a directory: $path";
    $files = [];
    try {
        $iterator = new DirectoryIterator($path);
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if ($name === '.' || $name === '..') continue;
            $type = $file->isDir() ? 'd' : '-';
            $size = $file->getSize();
            $mtime = date('M d H:i', $file->getMTime());
            $perms = $file->isDir() ? 'drwxr-xr-x' : '-rw-r--r--';
            $files[] = sprintf("%s %s %5d %s %s", $perms, $type === 'd' ? '2' : '1', $size, $mtime, $name);
        }
        return empty($files) ? "Empty directory" : implode("\n", $files);
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

function crossPlatformFind(string $dir, string $pattern): string {
    $dir = rtrim($dir, '/\\');
    $results = [];
    $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            if (preg_match($regex, $file->getFilename())) {
                $results[] = $file->getPathname();
            }
        }
        return empty($results) ? "No matches found" : implode("\n", array_slice($results, 0, 100));
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

function crossPlatformTree(string $dir, int $depth = 2): string {
    $dir = rtrim($dir, '/\\');
    $output = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $maxDepth = $depth;
        foreach ($iterator as $file) {
            $depth = $iterator->getDepth();
            if ($depth > $maxDepth) continue;
            $prefix = str_repeat('  ', $depth);
            $name = $file->getFilename();
            if ($file->isDir()) {
                $output[] = $prefix . "📁 " . $name;
            } else {
                $output[] = $prefix . "📄 " . $name;
            }
        }
        return empty($output) ? "Empty" : implode("\n", $output);
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

class Config {
    private static $config;

    public static function load(): array {
        if (self::$config) return self::$config;
        $envOverrides = [];
        if (getenv('OLLAMA_HOST')) $envOverrides['ollama']['host'] = getenv('OLLAMA_HOST');
        if (getenv('OLLAMA_MODEL')) $envOverrides['ollama']['defaultModel'] = getenv('OLLAMA_MODEL');
        $defaults = [
            'ollama' => ['host' => getenv('OLLAMA_HOST') ?: 'http://localhost:11434', 'defaultModel' => getenv('OLLAMA_MODEL') ?: 'llama3.2:latest'],
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

    public static function binaryPath(): string {
        return $_SERVER['argv'][0] ?? 'ollamadev';
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

    public static function autoAllow(): void {
        self::$promptMode = false;
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

    public function completion(string $prompt, string $model = null, int $maxTokens = 200): string {
        $model = $model ?: Config::get('ollama.defaultModel', 'llama3.2:latest');
        $params = ['model' => $model, 'prompt' => $prompt, 'stream' => false, 'options' => ['num_predict' => $maxTokens]];
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
            return $j['response'] ?? '';
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
        if (!$fn) return CmdError::toolNotFound($name);
        if (!Permission::isAllowed($name)) return CmdError::permissionDenied($name);
        try { return $fn($params); } catch (Exception $e) { return CmdError::toolFailed($name, $e->getMessage()); }
    }
    public static function all(): array { return array_keys(self::$tools); }
}

class CmdError {
    public static function toolNotFound(string $tool): string {
        $suggestions = self::suggestSimilar($tool, Tools::all());
        $suggest = $suggestions ? " Did you mean: $suggestions?" : "";
        return "ollamadev: '$tool' is not a valid tool.$suggest\nRun 'ollamadev help tools' for available tools.";
    }

    public static function permissionDenied(string $tool): string {
        return "ollamadev: permission denied for tool '$tool'\nUse 'ollamadev permission allow $tool' to grant access.";
    }

    public static function toolFailed(string $tool, string $reason): string {
        return "ollamadev: tool '$tool' failed\n  Reason: $reason\nRun 'ollamadev help tools' for usage examples.";
    }

    public static function fileNotFound(string $path, string $tool = ''): string {
        $extra = $tool ? " for '$tool'" : '';
        return "ollamadev: file not found'$extra': $path\nHint: Use absolute paths or paths relative to current directory.";
    }

    public static function missingParam(string $param, string $tool = ''): string {
        $extra = $tool ? " in '$tool'" : '';
        return "ollamadev: missing required parameter '$param'$extra\nUsage: ollamadev help $tool";
    }

    public static function invalidArg(string $arg, string $reason, string $tool = ''): string {
        $extra = $tool ? " for '$tool'" : '';
        return "ollamadev: invalid argument '$arg'$extra\n  $reason";
    }

    private static function suggestSimilar(string $input, array $candidates): string {
        $closest = '';
        $minDist = PHP_INT_MAX;
        foreach ($candidates as $c) {
            $dist = levenshtein($input, $c);
            if ($dist < $minDist && $dist <= 3) {
                $minDist = $dist;
                $closest = $c;
            }
        }
        return $closest ? "'$closest'" : '';
    }
}

Tools::register('view', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return CmdError::missingParam('file_path', 'view');
    if (!file_exists($path)) return CmdError::fileNotFound($path, 'view');
    $lines = file($path);
    if ($lines === false) return CmdError::invalidArg($path, 'Unable to read file. Check permissions.', 'view');
    $offset = isset($p['offset']) ? (int)$p['offset'] : 0;
    $limit = isset($p['limit']) ? (int)$p['limit'] : count($lines);
    $out = '';
    for ($i = $offset; $i < min($offset + $limit, count($lines)); $i++) $out .= sprintf("%4d  %s", $i + 1, $lines[$i]);
    return $out;
});

Tools::register('read', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

// Aliases for common tools
Tools::register('read', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

Tools::register('cat', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

Tools::register('head', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $n = $p['n'] ?? 10;
    $out = '';
    for ($i = 0; $i < min($n, count($lines)); $i++) $out .= $lines[$i];
    return $out;
});

Tools::register('tail', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $n = $p['n'] ?? 10;
    $start = max(0, count($lines) - $n);
    $out = '';
    for ($i = $start; $i < count($lines); $i++) $out .= $lines[$i];
    return $out;
});

Tools::register('changes', function($p) {
    $path = $p['path'] ?? '.';
    $since = $p['since'] ?? '1 hour ago';
    $sinceTime = strtotime($since);
    $changes = [];
    exec("find " . escapeshellarg($path) . " -type f -newermt '" . date('Y-m-d H:i:s', $sinceTime) . "' 2>/dev/null", $files);
    foreach ($files as $f) {
        if (strpos($f, '.git') !== false || strpos($f, 'node_modules') !== false) continue;
        $status = trim(shell_exec("cd " . escapeshellarg(dirname($f)) . " && git status --porcelain " . escapeshellarg(basename($f)) . " 2>/dev/null") ?: '??');
        $changes[] = [$status, $f];
    }
    if (empty($changes)) return "No changes since $since";
    $out = "\033[1;34mChanges since $since:\033[0m\n";
    foreach ($changes as [$status, $f]) {
        $s1 = $status[0] ?? ' ';
        $s2 = $status[1] ?? ' ';
        $color = match(true) {
            $s1 === 'M' => "\033[31m",  // red - modified
            $s1 === 'A' => "\033[32m",  // green - added
            $s1 === 'D' => "\033[33m",  // yellow - deleted
            $s1 === 'R' => "\033[36m",  // cyan - renamed
            $s1 === '?' => "\033[90m",  // gray - untracked
            default => "\033[0m"
        };
        $typeIcon = is_dir($f) ? "\033[1;36m[dir]\033[0m" : "\033[1;37m[file]\033[0m";
        $out .= "$color$status\033[0m $typeIcon $f\n";
    }
    return $out;
});

Tools::register('watch', function($p) {
    $path = $p['path'] ?? '.';
    $exts = $p['extensions'] ?? 'php,js,ts,py,go,rs,html,css,json,yaml';
    $timeout = (int)($p['timeout'] ?? 30);
    $extensions = str_replace(',', '|', $exts);
    $extensions = str_replace('.', '\\.', $extensions);

    // Polling-based file watching
    $lastMtime = [];
    $files = [];
    exec("find " . escapeshellarg($path) . " -type f 2>/dev/null", $files);
    foreach ($files as $f) {
        if (preg_match('/\.(' . $extensions . ')$/i', $f)) {
            $lastMtime[$f] = filemtime($f);
        }
    }

    sleep(min($timeout, 60));
    clearstatcache();
    $changed = [];
    exec("find " . escapeshellarg($path) . " -type f 2>/dev/null", $files);
    foreach ($files as $f) {
        if (preg_match('/\.(' . $extensions . ')$/i', $f)) {
            $mtime = filemtime($f);
            if (!isset($lastMtime[$f]) || $lastMtime[$f] < $mtime) {
                $changed[] = $f;
            }
        }
    }

    if (empty($changed)) return "No file changes detected within ${timeout}s";
    return "Changed:\n" . implode("\n", array_slice($changed, 0, 50));
});

Tools::register('write', function($p) {
    $path = $p['file_path'] ?? ''; $content = $p['content'] ?? '';
    if (empty($path)) return "missing file_path";
    if ($content === '') return "missing content";
    $dir = dirname($path);
    if (!empty($dir) && !is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, $content) !== false ? "FILE_WRITE:$path" : "Error writing file: $path";
});

Tools::register('edit', function($p) {
    $path = $p['file_path'] ?? ''; $oldStr = $p['old_string'] ?? ''; $newStr = $p['new_string'] ?? '';
    if (empty($path)) return "missing file_path";
    if (empty($oldStr)) return "missing old_string";
    $content = file_get_contents($path);
    if ($content === false) return "Error reading file: $path";
    $pos = strpos($content, $oldStr);
    if ($pos === false) return "old_string not found in file";
    return file_put_contents($path, substr_replace($content, $newStr, $pos, strlen($oldStr))) !== false ? "FILE_EDIT:$path" : "Error writing file: $path";
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
    return crossPlatformLs($path);
});
Tools::register('list_directory', function($p) {
    $path = $p['path'] ?? '.';
    return crossPlatformLs($path);
});
Tools::register('list_files', function($p) {
    $path = $p['path'] ?? '.';
    return crossPlatformLs($path);
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
    return crossPlatformFind($path, $name);
});

Tools::register('tree', function($p) {
    $path = $p['path'] ?? '.';
    $depth = isset($p['depth']) ? (int)$p['depth'] : 2;
    return crossPlatformTree($path, $depth);
});

Tools::register('stat', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing path";
    if (!file_exists($path)) return "Not found: $path";
    $stat = stat($path);
    $type = is_dir($path) ? 'directory' : 'file';
    return sprintf("%s (%s)\nSize: %d bytes\nModified: %s\nPermissions: %o", $path, $type, $stat['size'], date('Y-m-d H:i:s', $stat['mtime']), $stat['mode'] & 0777);
});

Tools::register('diff', function($p) {
    $file1 = $p['file1'] ?? $p['file_path'] ?? $p['file'] ?? $p['source'] ?? '';
    $file2 = $p['file2'] ?? $p['dest'] ?? '';
    if (empty($file1) || empty($file2)) return "missing file1 or file2";
    if (!file_exists($file1) || !file_exists($file2)) return "File not found";
    $isGitDir = is_dir(dirname($file1) . '/.git') || is_dir($file1);
    if ($isGitDir || preg_match('/\.git[\/\\\]?$/', dirname($file1))) {
        $output = shell_exec("cd " . escapeshellarg(dirname($file1)) . " && git diff " . escapeshellarg(basename($file1)) . " 2>&1") ?: "No differences";
    } else {
        $output = shell_exec("diff -u " . escapeshellarg($file1) . " " . escapeshellarg($file2) . " 2>&1") ?: "No differences";
    }
    if (empty(trim($output))) return "No differences";
    $tmp = tempnam('/tmp', 'ollamadev_diff_') . '.txt';
    file_put_contents($tmp, $output);
    passthru("less -R " . escapeshellarg($tmp) . " 2>/dev/null");
    @unlink($tmp);
    return '';
});

Tools::register('wc', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = count(file($path));
    $chars = strlen(file_get_contents($path));
    $words = str_word_count(file_get_contents($path));
    return sprintf("%d lines, %d words, %d chars: %s", $lines, $words, $chars, $path);
});

Tools::register('sort', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Failed to read: $path";
    sort($lines);
    return implode('', $lines);
});

Tools::register('uniq', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Failed to read: $path";
    return implode('', array_unique($lines));
});

Tools::register('mkdir', function($p) {
    $path = $p['path'] ?? $p['dir'] ?? '';
    $parents = $p['parents'] ?? false;
    if (empty($path)) return "missing path";
    if (is_dir($path)) return "Already exists: $path";
    if ($parents) {
        if (mkdir($path, 0755, true)) return "Created: $path";
    } else {
        if (mkdir($path, 0755)) return "Created: $path";
    }
    return "Failed to create: $path";
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
    $src = $p['src'] ?? $p['source'] ?? $p['target'] ?? '';
    $dst = $p['dst'] ?? $p['dest'] ?? $p['destination'] ?? $p['target'] ?? '';
    if (empty($src) || empty($dst)) return "missing src or dst";
    $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
    $src = $src === '~' ? $home : (str_starts_with($src, '~/') ? $home . substr($src, 1) : (isWindows() && str_starts_with($src, '~\\') ? $home . substr($src, 1) : $src));
    $dst = $dst === '~' ? $home : (str_starts_with($dst, '~/') ? $home . substr($dst, 1) : (isWindows() && str_starts_with($dst, '~\\') ? $home . substr($dst, 1) : $dst));
    if (!file_exists($src)) return "Source not found: $src";
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iter as $file) {
            $dstPath = $dst . '/' . $iter->getSubPathName();
            if ($file->isDir()) mkdir($dstPath, 0755, true);
            else copy($file->getPathname(), $dstPath);
        }
        return "Copied directory to $dst";
    }
    $dstDir = dirname($dst);
    if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
    return copy($src, $dst) ? "Copied to $dst" : "Copy failed";
});

Tools::register('rm', function($p) {
    $path = $p['path'] ?? $p['file_path'] ?? '';
    $recursive = $p['recursive'] ?? $p['r'] ?? false;
    $dryRun = $p['dry_run'] ?? $p['dry-run'] ?? false;
    if (empty($path)) return CmdError::missingParam('path', 'rm');
    if (str_contains($path, 'node_modules') || str_contains($path, '.git')) return CmdError::invalidArg('path', 'Cannot remove system directories (node_modules, .git)', 'rm');
    $cmd = ($recursive ? 'rm -rf' : 'rm') . ' ' . escapeshellarg($path);
    if ($dryRun) {
        return "[DRY RUN] Would execute: $cmd\n[DRY RUN] Target: $path\n[DRY RUN] Recursive: " . ($recursive ? 'yes' : 'no');
    }
    $result = shell_exec("$cmd 2>&1") ?: "Removed: $path";
    return $result;
});

Tools::register('mv', function($p) {
    $src = $p['src'] ?? $p['source'] ?? '';
    $dst = $p['dst'] ?? $p['dest'] ?? $p['destination'] ?? '';
    if (empty($src) || empty($dst)) return "missing src or dst";
    return shell_exec("mv " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1") ?: "Moved to $dst";
});

Tools::register('editor', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "Usage: editor file_path=<path>";
    if (!file_exists($path)) return "File not found: $path";
    $editor = getenv('EDITOR') ?: (getenv('VISUAL') ?: 'nano');
    $cmd = "$editor " . escapeshellarg($path) . " 2>&1";
    exec($cmd, $out, $code);
    return $code === 0 ? "Edited $path" : "Editor error: " . implode("\n", $out);
});

Tools::register('bash', function($p) {
    $cmd = $p['command'] ?? $p['cmd'] ?? '';
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
    $timeout = (int)($p['timeout'] ?? 30);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return "Failed to fetch $url: $error";
    }
    curl_close($ch);
    return $result ?: "Empty response from $url";
});

Tools::register('diagnostics', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
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
    $path = $p['file_path'] ?? $p['path'] ?? '';
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
    $path = $p['file_path'] ?? $p['path'] ?? '';
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
    $path = $p['file_path'] ?? $p['path'] ?? '';
    $pattern = $p['pattern'] ?? $p['symbol'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    if (empty($pattern)) return "No pattern specified";
    return shell_exec("grep -rn --color=never " . escapeshellarg($pattern) . " " . escapeshellarg(dirname($path)) . " 2>/dev/null | head -20") ?: "No references found";
});

Tools::register('refs', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    $symbol = $p['symbol'] ?? $p['pattern'] ?? '';
    return Tools::run('find_refs', ['file_path' => $path, 'pattern' => $symbol]);
});

Tools::register('goto_definition', function($p) {
    return Tools::run('goto', $p);
});

Tools::register('definition', function($p) {
    return Tools::run('goto', $p);
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
    $prompt = $p['prompt'] ?? $p['task'] ?? '';
    $context = $p['context'] ?? '';
    $maxIterations = (int)($p['max_iterations'] ?? 5);
    $model = $GLOBALS['currentSessionModel'] ?? Config::get('ollama.defaultModel', 'llama3.2:latest');

    $blocked = ['mistral', 'smollm', 'starcoder', 'qwen', 'phi', 'firefunction', 'tinyllama', 'phi-2', 'llava', 'nanollm', 'mixtral', 'codellama', 'code-llama', 'nemotron', 'granite'];
    foreach ($blocked as $b) { if (stripos($model, $b) !== false) return "Model $model does not support tool calling. Try: gpt-oss, llama3.2, codestral, or deepseek-r1."; }

    if (empty($prompt)) return "missing prompt (need 'prompt' or 'task' parameter)";

    $subAgent = new Agent();
    $subAgent->setModel($model);
    $isGptOss = str_contains($model, 'gpt-oss');

    $systemPrompt = $subAgent->buildSystemPrompt();
    if (!empty($context)) {
        $systemPrompt['content'] .= "\n\nCONTEXT: $context";
    }
    
    $messages = [['role' => 'system', 'content' => $systemPrompt['content']], ['role' => 'user', 'content' => "Task: $prompt\n\nWork through this step by step. Use tools as needed and report results."]];
    
    $output = [];
    $iteration = 0;
    
    while ($iteration < $maxIterations) {
        $iteration++;
        $response = $subAgent->run($messages);
        $output[] = "=== Iteration $iteration ===\n$response\n";

        $toolCalls = $subAgent->parseToolCalls($response);
        if ($isGptOss && empty($toolCalls)) {
            $toolCalls = $subAgent->parseGptOssToolCalls($response);
        }
        if (empty($toolCalls) && (str_contains($model, 'command') || str_contains($model, 'gemma'))) {
            $toolCalls = $subAgent->parseGptOssToolCalls($response);
        }
        $messages[] = ['role' => 'assistant', 'content' => $response];

        if (empty($toolCalls)) {
            $lastMsg = end($messages);
            if (strpos(strtolower($lastMsg['content']), 'task complete') !== false || 
                strpos(strtolower($response), 'task complete') !== false ||
                strpos(strtolower($response), 'done') !== false) {
                break;
            }
            $messages[] = ['role' => 'user', 'content' => 'Continue or finish? If done, say "TASK_COMPLETE".'];
            continue;
        }
        
        foreach ($toolCalls as $call) {
            $toolName = $call['name'];
            $params = $call['params'] ?? [];
            $tool = Tools::find($toolName);
            
            if (!$tool) {
                $result = "Error: tool '$toolName' not found";
            } else {
                $result = $tool($params);
            }
            
            $messages[] = ['role' => 'tool', 'content' => "[$toolName] result: $result"];
            $output[] = "[$toolName] → " . substr($result, 0, 200) . (strlen($result) > 200 ? '...' : '') . "\n";
        }
    }
    
    return implode("\n", $output);
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

Tools::register('update', function($p) {
    $install = $p['install'] ?? false;
    $current = '3.9.5';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $json = @file_get_contents('https://api.github.com/repos/kennethyork/OllamaDev/releases/latest', false, $ctx);
    if (!$json) return "Error: Could not check for updates. Check your internet connection.";
    $data = json_decode($json, true);
    $tag = $data['tag_name'] ?? '';
    if (!$tag) return "Error: Could not parse release info.";
    if (version_compare($tag, $current, '<=')) {
        return "You're up to date (v$current)";
    }
    echo "Update available: $tag (current: $current)\n\n";
    $assets = $data['assets'] ?? [];
    $binary = null;
    foreach ($assets as $a) {
        if ($a['name'] === 'ollamadev') $binary = $a;
    }
    if (!$binary && count($assets) > 0) $binary = $assets[0];
    if ($binary) {
        echo "Download: {$binary['browser_download_url']}\n";
        echo "\nTo install:\n";
        if ($install) {
            $tmp = sys_get_temp_dir() . '/ollamadev_new';
            $url = $binary['browser_download_url'];
            echo "Downloading...\n";
            $downloaded = @file_put_contents($tmp, fopen($url, 'rb', false, $ctx));
            if ($downloaded) {
                chmod($tmp, 0755);
                $binPath = Config::binaryPath();
                rename($tmp, $binPath);
                echo "Updated to $tag. Restart to use new version.\n";
            } else {
                echo "Download failed. Try manually: curl -fsSL {$binary['browser_download_url']} -o /usr/local/bin/ollamadev\n";
            }
        } else {
            echo "  curl -fsSL {$binary['browser_download_url']} -o /usr/local/bin/ollamadev\n";
            echo "\nOr run: ollamadev update --install to auto-download\n";
        }
    }
    return "Run 'ollamadev update' to check again.";
});

Tools::register('git_cherry_pick', function($p) {
    $path = $p['path'] ?? '.';
    $ref = $p['ref'] ?? '';
    $no_commit = $p['no_commit'] ?? false;
    if (empty($ref)) return "missing ref (commit hash or branch)";
    $cmd = "cd " . escapeshellarg($path) . " && git cherry-pick";
    if ($no_commit) $cmd .= " --no-commit";
    $cmd .= " " . escapeshellarg($ref);
    return shell_exec($cmd . " 2>&1") ?: "Cherry-pick failed";
});

Tools::register('git_revert', function($p) {
    $path = $p['path'] ?? '.';
    $ref = $p['ref'] ?? '';
    $no_commit = $p['no_commit'] ?? false;
    if (empty($ref)) return "missing ref (commit hash or branch)";
    $cmd = "cd " . escapeshellarg($path) . " && git revert";
    if ($no_commit) $cmd .= " --no-commit";
    $cmd .= " " . escapeshellarg($ref);
    return shell_exec($cmd . " 2>&1") ?: "Revert failed";
});

Tools::register('git_merge', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    $no_ff = $p['no_ff'] ?? false;
    $squash = $p['squash'] ?? false;
    if (empty($branch)) return "missing branch";
    $cmd = "cd " . escapeshellarg($path) . " && git merge";
    if ($no_ff) $cmd .= " --no-ff";
    if ($squash) $cmd .= " --squash";
    $cmd .= " " . escapeshellarg($branch);
    return shell_exec($cmd . " 2>&1") ?: "Merge failed";
});

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

        'gpt-oss' => 'You are a CLI tool with file access. When user asks to run shell commands (echo, ls, cat, pwd, etc) - use bash tool. For file operations use view, write, edit, grep, glob.

TOOL CALL FORMAT (MUST use this exact XML format):
<tool_call>
name: bash
params: command="echo hello world"
</tool_call>

For bash commands:
- echo TEXT → bash with command="echo TEXT"
- ls → bash with command="ls"
- cat FILE → view with file_path=FILE

For file operations:
- view FILE → view with file_path=FILE
- write FILE CONTENT → write with file_path=FILE content=CONTENT
- grep PATTERN FILE → grep with pattern=PATTERN path=FILE

DO NOT use write tool for echo. DO NOT explain. Just call the tool.',

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

    public function buildSystemPrompt(): array {
        $manualPrompt = Config::get('agents.systemPrompt', '');
        $prompt = !empty($manualPrompt) ? $manualPrompt : SystemPrompts::detectForModel($this->model);
        
        $projectMemory = '';
        $memoryFiles = ['OLLAMADEV.md', '.ollamadev.md', '.ollamadev'];
        foreach ($memoryFiles as $mf) {
            if (file_exists($mf)) {
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

public function parseToolCalls(string $content): array {
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

class TerminalManager
{
    public string $baseDir;

    public function __construct()
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        $this->baseDir = $home . '/.ollamadev/terminals';
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    public function list(): array
    {
        $terminals = [];
        if (is_dir($this->baseDir)) {
            foreach (scandir($this->baseDir) as $name) {
                if ($name === '.' || $name === '..') continue;
                $dir = $this->baseDir . '/' . $name;
                if (is_dir($dir)) {
                    $terminals[] = $this->loadTerminal($name);
                }
            }
        }
        return $terminals;
    }

    public function create(string $name, string $model, ?string $cwd = null): array
    {
        if ($this->exists($name)) {
            return ['error' => "Terminal '$name' already exists"];
        }
        $dir = $this->baseDir . '/' . $name;
        mkdir($dir, 0755, true);
        mkdir($dir . '/history', 0755, true);
        $terminal = [
            'name' => $name,
            'model' => $model,
            'created' => date('Y-m-d H:i:s'),
            'last_used' => date('Y-m-d H:i:s'),
            'cwd' => $cwd ?? getcwd(),
            'pid' => null,
            'status' => 'stopped'
        ];
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function start(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        $terminal['status'] = 'running';
        $terminal['last_used'] = date('Y-m-d H:i:s');
        $this->saveTerminal($name, $terminal);
        return ['name' => $name, 'status' => 'running'];
    }

    public function stop(string $name): bool
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return false;
        if ($terminal['pid']) { shell_exec("kill " . (int)$terminal['pid'] . " 2>/dev/null"); }
        $terminal['status'] = 'stopped';
        $this->saveTerminal($name, $terminal);
        return true;
    }

    public function pause(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'running') return ['error' => "Terminal '$name' is not running"];
        $terminal['status'] = 'paused';
        $this->saveTerminal($name, $terminal);
        return ['success' => true, 'name' => $name, 'status' => 'paused'];
    }

    public function resume(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'paused') return ['error' => "Terminal '$name' is not paused"];
        $terminal['status'] = 'running';
        $this->saveTerminal($name, $terminal);
        return ['success' => true, 'name' => $name, 'status' => 'running'];
    }

    public function delete(string $name): bool
    {
        $dir = $this->baseDir . '/' . $name;
        if (!is_dir($dir)) return false;
        $this->stop($name);
        shell_exec("rm -rf " . escapeshellarg($dir));
        return true;
    }

    public function exists(string $name): bool { return is_dir($this->baseDir . '/' . $name); }

    public function loadTerminal(string $name): ?array
    {
        $file = $this->baseDir . '/' . $name . '/session.json';
        return file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    }

    public function saveTerminal(string $name, array $terminal): void
    {
        file_put_contents($this->baseDir . '/' . $name . '/session.json', json_encode($terminal, JSON_PRETTY_PRINT));
    }

    public function getLog(string $name, int $lines = 100): string
    {
        $logFile = $this->baseDir . '/' . $name . '/session.log';
        return file_exists($logFile) ? shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($logFile)) : '';
    }

    public function status(): array
    {
        $terminals = $this->list();
        $running = $stopped = 0;
        foreach ($terminals as $t) {
            if ($t['status'] === 'running') $running++; else $stopped++;
        }
        return ['total' => count($terminals), 'running' => $running, 'stopped' => $stopped, 'terminals' => $terminals];
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
        Permission::autoAllow();
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

    private function renderPrompt(): void { $cwd = basename(getcwd()); echo "[{$cwd}] [{$this->model}] > "; }
    private function countTokens(): int { $total = 0; foreach ($this->messages as $msg) $total += strlen($msg['content'] ?? '') / 4; return (int)$total; }
    private function renderStatus(): void { echo "\n[Model: {$this->model} | Tokens: ~" . $this->countTokens() . " | Messages: " . count($this->messages) . "]\n"; }
    private function showContext(): void {
        $pwd = getcwd();
        $edited = $GLOBALS['editedFiles'] ?? [];
        echo "\n📁 $pwd";
        if (!empty($edited)) {
            echo "\n✏️  Edited: " . implode(', ', $edited);
$GLOBALS['editedFiles'] = [];
$GLOBALS['currentSessionModel'] = null;
        }
    }

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
                if (!empty($args)) { $this->agent->setModel($args); $this->model = $args; $GLOBALS['currentSessionModel'] = $args; echo "Model: $args\n"; }
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
                readline_completion_function(function($string, $position) {
                    $baseCommands = ['help', 'exit', 'quit', 'clear', 'model', 'session', 'tools', 'git', 'status', 'compact', 'context', 'new', 'cd', 'ls'];
                    $line = readline_info()['line_buffer'] ?? '';
                    $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                    $matches = [];

                    if (count($parts) === 1) {
                        foreach ($baseCommands as $cmd) {
                            if (strpos($cmd, $string) === 0) $matches[] = $cmd;
                        }
                        return $matches;
                    }

                    $first = $parts[0];
                    if ($first === 'cd' && count($parts) === 2) {
                        $partial = $parts[1];
                        $parent = dirname($partial);
                        if (is_dir($parent)) {
                            $prefix = $parent === '/' ? '' : $parent . '/';
                            foreach (scandir($parent) as $f) {
                                if ($f !== '.' && strpos($f, basename($partial)) === 0) {
                                    $full = $prefix . $f;
                                    if (is_dir($full)) $matches[] = $full . '/';
                                    else $matches[] = $full;
                                }
                            }
                        }
                    } elseif ($first === 'git' && count($parts) === 2) {
                        $gitCmds = ['status', 'diff', 'log', 'branch', 'commit', 'push', 'pull', 'stash', 'checkout', 'add', 'fetch', 'merge', 'rebase'];
                        foreach ($gitCmds as $gc) { if (strpos($gc, $string) === 0) $matches[] = $gc; }
                    } elseif (in_array($first, ['view', 'cat', 'edit', 'write', 'grep', 'find', 'ls', 'diff', 'rm', 'cp', 'mv']) && count($parts) === 2) {
                        $partial = $parts[1];
                        if (strpos($partial, '/') !== false) {
                            $parent = dirname($partial);
                            if (is_dir($parent)) {
                                $prefix = $parent === '/' ? '' : $parent . '/';
                                foreach (scandir($parent) as $f) {
                                    if ($f !== '.' && strpos($f, basename($partial)) === 0) {
                                        $full = $prefix . $f;
                                        if (is_dir($full)) $matches[] = $full . '/';
                                        else $matches[] = $full;
                                    }
                                }
                            }
                        }
                    }
                    return array_slice($matches, 0, 50);
                });
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
                if (preg_match('/^(FILE_WRITE:|FILE_EDIT:)/', $result['content'])) continue;
                echo "\n🔧 [tool]\n{$result['content']}\n";
            }

            if (empty($toolResults) && !empty(trim($response))) {
                $this->addMessage('assistant', $response);
            } elseif (!empty($toolResults)) {
                $this->addMessage('assistant', $response);
            }

            $this->showContext();

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
        Permission::autoAllow();
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
        $this->showContext();
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
$flags = ['model' => null, 'continue' => false, 'session' => null, 'fork' => false, 'prompt' => null, 'agent' => null, 'pure' => false, 'port' => 0, 'hostname' => '127.0.0.1', 'mdns' => false, 'help' => false, 'version' => false, 'cwd' => null];
$positional = [];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '-m' || $a === '--model') { $flags['model'] = $argv[++$i] ?? null; }
    elseif ($a === '-c' || $a === '--continue') { $flags['continue'] = true; }
    elseif ($a === '-s' || $a === '--session') { $flags['session'] = $argv[++$i] ?? null; }
    elseif ($a === '--fork') { $flags['fork'] = true; }
    elseif ($a === '-p' || $a === '--prompt') { $flags['prompt'] = $argv[++$i] ?? null; }
    elseif ($a === '--agent') { $flags['agent'] = $argv[++$i] ?? null; }
    elseif ($a === '--pure') { $flags['pure'] = true; }
    elseif ($a === '--port') { $flags['port'] = (int)($argv[++$i] ?? 0); }
    elseif ($a === '--hostname') { $flags['hostname'] = $argv[++$i] ?? '127.0.0.1'; }
    elseif ($a === '--mdns') { $flags['mdns'] = true; }
    elseif ($a === '--cwd') { $flags['cwd'] = $argv[++$i] ?? null; }
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
    echo "# OllamaDev shell completion ($shell) - Generated by ollamadev\n";
    echo "# Install: ollamadev completion bash >> ~/.bashrc\n\n";
    if ($shell === 'bash') {
        echo <<<'BASH'
_ollamadev() {
    local cur prev cword
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"

    case "${prev}" in
        help)
            COMPREPLY=($(compgen -W 'topics usage options commands tools git terminal session examples tips' -- "${cur}"))
            return 0
            ;;
        terminal)
            COMPREPLY=($(compgen -W 'create spawn list attach start stop pause resume broadcast delete log help' -- "${cur}"))
            return 0
            ;;
        git)
            COMPREPLY=($(compgen -W 'status diff log branch commit push pull stash checkout add fetch merge rebase' -- "${cur}"))
            return 0
            ;;
        lsp)
            COMPREPLY=($(compgen -W '--port --hostname --help' -- "${cur}"))
            return 0
            ;;
        -m|--model)
            COMPREPLY=($(compgen -W 'llama3.2:latest deepseek-r1:32b gemma4:26b qwen3.6:27b' -- "${cur}"))
            return 0
            ;;
        *)
            COMPREPLY=($(compgen -W 'chat new list load terminal git lsp models help --help --version --model --prompt --continue --dry-run -h -v' -- "${cur}"))
            ;;
    esac
    return 0
}
complete -F _ollamadev ollamadev
BASH;
    } elseif ($shell === 'zsh') {
        echo <<<'ZSH'
#compdef ollamadev

_ollamadev() {
    local -a commands
    commands=(
        'chat:Start chat session'
        'new:Create new session'
        'list:List sessions'
        'load:Load session'
        'terminal:Terminal multiplexer'
        'git:Git commands'
        'lsp:LSP server for IDEs'
        'models:List available models'
        'help:Show help'
    )
    _describe 'command' commands
}

_ollamadev "$@"
ZSH;
    } elseif ($shell === 'fish') {
        echo <<<'FISH'
# OllamaDev Fish Shell Completion

complete -c ollamadev -n '__fish_use_subcommand' -a 'chat new list load terminal git lsp models help' -d 'Command'
complete -c ollamadev -n '__fish_seen_subcommand_from terminal' -a 'create spawn list attach start stop pause resume broadcast delete log' -d 'Terminal command'
complete -c ollamadev -n '__fish_seen_subcommand_from git' -a 'status diff log branch commit push pull stash checkout add' -d 'Git command'
complete -c ollamadev -s h -l help -d 'Show help'
complete -c ollamadev -s v -l version -d 'Show version'
complete -c ollamadev -s m -l model -d 'Use specific model' -r
FISH;
    } else {
        echo "Usage: ollamadev completion [bash|zsh|fish]\n";
        echo "Generate shell completion script\n";
        echo "\nExamples:\n";
        echo "  ollamadev completion bash >> ~/.bashrc\n";
        echo "  ollamadev completion zsh >> ~/.zshrc\n";
        echo "  ollamadev completion fish > ~/.config/fish/completions/ollamadev.fish\n";
        exit(1);
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
    $topic = $arg1 ?: 'topics';
    $topics = [
        'topics' => [
            'description' => 'Available help topics',
            'commands' => ['usage', 'options', 'commands', 'tools', 'git', 'terminal', 'session', 'examples', 'tips']
        ],
        'usage' => [
            'description' => 'Basic usage',
            'text' => <<<'USAGE'
Usage: ollamadev [command] [options]

Quick Start:
  ollamadev                    # Interactive chat
  ollamadev chat "your prompt" # Single prompt
  ollamadev terminal attach dev # Attach to terminal

Flags:
  --model <name>    Use specific model
  --prompt <text>   Run single prompt
  --continue        Continue last session
USAGE
        ],
        'options' => [
            'description' => 'Global options',
            'text' => <<<'OPTIONS'
Options:
  -m, --model <name>      Use specific model
  -c, --continue          Continue last session
  -s, --session <id>      Use specific session
  --fork                   Fork session when continuing
  --prompt <text>          Prompt to use
  --agent <name>           Agent to use
  --port <num>             Port for server
  --hostname <host>        Hostname for server
  --dry-run                Show what would be done
  -h, --help               Show help
  -v, --version           Show version
OPTIONS
        ],
        'commands' => [
            'description' => 'All commands',
            'text' => <<<'COMMANDS'
Commands:
  ollamadev            Start interactive chat
  ollamadev chat       Start chat session
  ollamadev new        Create new session
  ollamadev list       List sessions
  ollamadev load <id>  Load session
  ollamadev git        Git commands (status, diff, commit, etc.)
  ollamadev terminal   Terminal multiplexer
  ollamadev lsp        LSP server for IDEs
  ollamadev help [topic] Show help

See 'ollamadev help <topic>' for detailed help.
COMMANDS
        ],
        'tools' => [
            'description' => 'Available AI tools (66 total)',
            'text' => <<<'TOOLS'
Tools - Use in chat or directly:
  File: view, cat, head, tail, read, write, edit, patch, touch, mkdir, rm, cp, mv
  Search: grep, find, tree, glob, wc, stat, diff, sort, uniq
  Git: git_status, git_diff, git_log, git_branch, git_checkout, git_commit
  Code: goto, goto_definition, find_refs, symbols, hover, diagnostics, format, lsp
  System: bash, execute_command, editor, watch, fetch, bg, wait_bg, agent

Examples:
  view file_path=src/main.php
  write file_path=src/test.php content="<php code>"
  grep pattern="function foo" path=src/
  bash command="ls -la"

Use without parameters to see tool-specific help.
TOOLS
        ],
        'git' => [
            'description' => 'Git commands',
            'text' => <<<'GIT'
Git Commands:
  ollamadev git status      Show working tree status
  ollamadev git diff         Show changes
  ollamadev git log          Show commit history
  ollamadev git branch       List branches
  ollamadev git commit <msg> Commit changes
  ollamadev git push         Push to remote
  ollamadev git pull         Pull from remote
  ollamadev git stash        Stash changes

Examples:
  ollamadev git status
  ollamadev git commit "Fix bug"
GIT
        ],
        'terminal' => [
            'description' => 'Terminal multiplexer',
            'text' => <<<'TERM'
Terminal Commands:
  ollamadev terminal create <name> [--model <model>] [--cwd <path>]
  ollamadev terminal spawn <n> [--model <model>] [--prefix <name>]
  ollamadev terminal list
  ollamadev terminal attach <name>   (Ctrl+C to detach, stays running)
  ollamadev terminal start <name>
  ollamadev terminal stop <name>
  ollamadev terminal pause <name>
  ollamadev terminal resume <name>
  ollamadev terminal broadcast <msg>
  ollamadev terminal delete <name>
  ollamadev terminal log <name> [lines]

Examples:
  ollamadev terminal create dev --model llama3.2:latest
  ollamadev terminal spawn 4 --model gemma4:26b --prefix worker
  ollamadev terminal attach dev
TERM
        ],
        'session' => [
            'description' => 'Session management',
            'text' => <<<'SESSION'
Session Commands:
  ollamadev new            Create new session
  ollamadev list           List all sessions
  ollamadev load <id>       Load session by ID
  ollamadev compact         Compact session history

Sessions are stored in ~/.ollamadev/sessions/
SESSION
        ],
        'examples' => [
            'description' => 'Usage examples',
            'text' => <<<'EXAMPLES'
Examples:

  Interactive chat:
    ollamadev

  Single prompt:
    ollamadev "explain this function"
    echo "fix the bug" | ollamadev

  Use specific model:
    ollamadev --model deepseek-r1:32b "hello"

  Terminal multiplexer:
    ollamadev terminal create dev --model llama3.2:latest
    ollamadev terminal attach dev

  LSP for IDE:
    ollamadev lsp --port 4389

  Git operations:
    ollamadev git status
    ollamadev git commit "fix: resolve issue"
EXAMPLES
        ],
        'tips' => [
            'description' => 'Tips and tricks',
            'text' => <<<'TIPS'
Tips:

  Tab Completion - Press Tab for completions in interactive mode
  Ctrl+C - Detach from terminal (keeps it running)
  Ctrl+D - Exit chat (with confirmation)

  Model Switching:
    model                    # List models
    model llama3.2:latest   # Switch model

  Tools can be called directly:
    view file_path=README.md
    grep pattern="TODO" path=src/

  Config file: ~/.ollamadev/config.json

  Dry run for destructive commands:
    rm --dry-run file.txt   # Shows what would happen
TIPS
        ]
    ];
    if (isset($topics[$topic])) {
        $t = $topics[$topic];
        echo "OllamaDev Help: $topic\n";
        echo str_repeat('=', 50) . "\n\n";
        if (isset($t['text'])) {
            echo $t['text'] . "\n";
        } else {
            echo $t['description'] . "\n\n";
            if (isset($t['commands'])) {
                foreach ($t['commands'] as $c) {
                    if (isset($topics[$c])) {
                        echo "  $c - " . $topics[$c]['description'] . "\n";
                    }
                }
                echo "\nRun 'ollamadev help <topic>' for details.\n";
            }
        }
    } else {
        echo "OllamaDev Help: $topic\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Unknown help topic: $topic\n";
        echo "Available topics: " . implode(', ', array_keys($topics)) . "\n";
        echo "Run 'ollamadev help' for general help.\n";
        exit(1);
    }
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
} elseif ($cmd === 'update') {
    $install = isset($flags['install']);
    $current = '3.9.5';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: OllamaDev/3.9.5\r\n"]]);
    $json = @file_get_contents('https://api.github.com/repos/kennethyork/OllamaDev/releases/latest', false, $ctx);
    if (!$json || strpos($json, 'Request forbidden') !== false) { echo "Error: Could not check for updates (GitHub rate limit). Try again later.\n"; exit(1); }
    $data = json_decode($json, true);
    $tag = ltrim($data['tag_name'] ?? '', 'v');
    if (!$tag) { echo "Error: Could not parse release info.\n"; exit(1); }
    if (version_compare($tag, $current, '<=')) {
        echo "You're up to date (v$current)\n"; exit(0);
    }
    echo "Update available: v$tag (current: v$current)\n\n";
    $assets = $data['assets'] ?? [];
    $binary = null;
    foreach ($assets as $a) { if ($a['name'] === 'ollamadev') $binary = $a; }
    if (!$binary && count($assets) > 0) $binary = $assets[0];
    if ($binary) {
        echo "Download: {$binary['browser_download_url']}\n";
        if ($install) {
            $tmp = sys_get_temp_dir() . '/ollamadev_new';
            $url = $binary['browser_download_url'];
            echo "Downloading...\n";
            $downloaded = @file_put_contents($tmp, fopen($url, 'rb', false, $ctx));
            if ($downloaded) {
                chmod($tmp, 0755);
                $binPath = Config::binaryPath();
                rename($tmp, $binPath);
                echo "Updated to v$tag. Restart to use new version.\n";
            } else {
                echo "Download failed. Try manually:\n  curl -fsSL {$binary['browser_download_url']} -o /usr/local/bin/ollamadev\n";
            }
        } else {
            echo "\nTo install:\n  curl -fsSL {$binary['browser_download_url']} -o /usr/local/bin/ollamadev\n\nOr run: ollamadev update --install\n";
        }
    }
} elseif ($cmd === 'git') {
    $sub = $arg1 ?: 'status';
    $path = $flags['cwd'] ?? getcwd();
    if ($sub === 'status') { echo shell_exec("cd " . escapeshellarg($path) . " && git status 2>&1"); }
    elseif ($sub === 'diff') { echo shell_exec("cd " . escapeshellarg($path) . " && git diff 2>&1"); }
    elseif ($sub === 'log') { echo shell_exec("cd " . escapeshellarg($path) . " && git log --oneline -20 2>&1"); }
    elseif ($sub === 'branch') { echo shell_exec("cd " . escapeshellarg($path) . " && git branch -a 2>&1"); }
    elseif ($sub === 'commit' && $arg2) { echo shell_exec("cd " . escapeshellarg($path) . " && git add -A && git commit -m " . escapeshellarg($arg2) . " 2>&1"); }
    elseif ($sub === 'push') { echo shell_exec("cd " . escapeshellarg($path) . " && git push 2>&1"); }
    elseif ($sub === 'pull') { echo shell_exec("cd " . escapeshellarg($path) . " && git pull 2>&1"); }
    elseif ($sub === 'stash') { echo shell_exec("cd " . escapeshellarg($path) . " && git stash 2>&1"); }
    elseif ($sub === 'stash' && $arg2 === 'pop') { echo shell_exec("cd " . escapeshellarg($path) . " && git stash pop 2>&1"); }
    else { echo "Git commands: status, diff, log, branch, commit <msg>, push, pull, stash\n"; }
} elseif ($cmd === 'load' && $arg1) {
    $session = new Session($config, $arg1);
    if (!file_exists(Config::sessionsDir() . '/' . $arg1 . '.json')) { echo "Session not found: $arg1\n"; exit(1); }
    $session->start();
} elseif ($cmd === 'run' && $arg1) {
    $session = new Session($config);
    $session->addMessage('user', $arg1);
    echo $session->runSingle($arg1) . "\n";
} elseif ($cmd === 'terminal' || $cmd === 'term') {
    $sub = $arg1 ?: 'help';
    $tm = new TerminalManager();
    if ($sub === 'help' || $sub === '--help') {
        echo "OllamaDev Terminal Manager\n";
        echo "Usage: ollamadev terminal <command> [options]\n\n";
        echo "Commands:\n";
        echo "  terminal list              List all terminals\n";
        echo "  terminal create <name> [--model <model>] [--cwd <path>]\n";
        echo "  terminal start <name>     Mark terminal as running\n";
        echo "  terminal stop <name>      Mark terminal as stopped\n";
        echo "  terminal delete <name>    Delete a terminal\n";
        echo "  terminal attach <name>     Attach to terminal interactively\n";
        echo "  terminal detach           Detach from terminal (background)\n";
        echo "  terminal log <name> [n]    View last n lines of log\n";
        echo "  terminal broadcast <msg>  Send message to all terminals\n";
        echo "  terminal spawn <n> [--model <model>] [--cwd <path>] [--prefix <name>]  Spawn n terminals\n\n";
        echo "Examples:\n";
        echo "  ollamadev terminal create dev --model llama3.2:latest\n";
        echo "  ollamadev terminal spawn 4 --model deepseek-r1:32b\n";
        echo "  ollamadev terminal attach dev   (Ctrl+C = detach, stays running)\n";
        echo "  ollamadev terminal broadcast \"update available\"\n";
    } elseif ($sub === 'list' || $sub === 'ls') {
        $status = $tm->status();
        echo "Terminals: {$status['total']} | Running: {$status['running']} | Stopped: {$status['stopped']}\n\n";
        foreach ($status['terminals'] as $t) {
            $icon = $t['status'] === 'running' ? '🟢' : ($t['status'] === 'paused' ? '⏸️' : '⚫');
            echo "$icon {$t['name']} | {$t['model']} | {$t['status']} | cwd: {$t['cwd']}\n";
        }
    } elseif ($sub === 'create' || $sub === 'new') {
        $name = $arg2 ?: 'terminal-' . time();
        $model = $flags['model'] ?? 'llama3.2:latest';
        $cwd = $flags['cwd'] ?? getcwd();
        $result = $tm->create($name, $model, $cwd);
        if (isset($result['error'])) { echo "Error: {$result['error']}\n"; exit(1); }
        echo "Created terminal '$name' with model {$model}\n";
        echo "\nUse 'ollamadev terminal attach $name' to start chatting\n";
    } elseif ($sub === 'spawn') {
        $count = max(1, min(10, (int)($arg2 ?: 1)));
        $model = $flags['model'] ?? 'llama3.2:latest';
        $prefix = $flags['prefix'] ?? 'term';
        $cwd = $flags['cwd'] ?? getcwd();
        echo "Spawning $count terminals with model $model...\n";
        for ($i = 1; $i <= $count; $i++) {
            $name = $prefix . '-' . $i;
            $tm->create($name, $model, $cwd);
            echo "  Created $name\n";
        }
        echo "\nUse 'ollamadev terminal attach <name>' to interact\n";
    } elseif ($sub === 'start') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal start <name>\n"; exit(1); }
        $result = $tm->start($name);
        if (isset($result['error'])) { echo "Error: {$result['error']}\n"; exit(1); }
        echo "Started terminal '$name'\n";
    } elseif ($sub === 'stop') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal stop <name>\n"; exit(1); }
        $result = $tm->stop($name);
        if (isset($result['error'])) { echo "Error: {$result['error']}\n"; exit(1); }
        echo "Stopped terminal '$name' (state saved)\n";
    } elseif ($sub === 'pause') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal pause <name>\n"; exit(1); }
        $result = $tm->pause($name);
        if (isset($result['error'])) { echo "Error: {$result['error']}\n"; exit(1); }
        echo "Paused terminal '$name'\n";
    } elseif ($sub === 'resume') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal resume <name>\n"; exit(1); }
        $result = $tm->resume($name);
        if (isset($result['error'])) { echo "Error: {$result['error']}\n"; exit(1); }
        echo "Resumed terminal '$name'\n";
    } elseif ($sub === 'broadcast') {
        $msg = $arg2;
        if (!$msg) { echo "Usage: terminal broadcast <message>\n"; exit(1); }
        $status = $tm->status();
        $count = 0;
        foreach ($status['terminals'] as $t) {
            if ($t['status'] === 'running') {
                file_put_contents($tm->baseDir . "/{$t['name']}/broadcast.txt", $msg);
                $count++;
            }
        }
        echo "Broadcast to $count running terminals: $msg\n";
    } elseif ($sub === 'delete' || $sub === 'rm') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal delete <name>\n"; exit(1); }
        $tm->delete($name);
        echo "Deleted terminal '$name'\n";
    } elseif ($sub === 'attach') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal attach <name>\n"; exit(1); }
        if (!$tm->exists($name)) { echo "Terminal '$name' not found\n"; exit(1); }
        $terminal = $tm->loadTerminal($name);
        echo "Attaching to terminal '$name'...\n";
        echo "Model: {$terminal['model']}\n";
        echo "Working directory: {$terminal['cwd']}\n";
        echo "Log:\n" . str_repeat('-', 40) . "\n";
        echo $tm->getLog($name, 20);
        echo str_repeat('-', 40) . "\n";
        echo "\nType your message and press Enter.\n";
        echo "Press Ctrl+C to detach (terminal stays running in background).\n";
        echo "Type 'exit' or 'quit' to stop the terminal completely.\n\n";
        pcntl_signal(SIGINT, function() { echo "\nDetached from terminal (still running in background)\n"; exit(0); });
        while (true) {
            if (file_exists($tm->baseDir . "/$name/broadcast.txt")) {
                $bc = trim(file_get_contents($tm->baseDir . "/$name/broadcast.txt"));
                if ($bc) { echo "\n[BROADCAST]: $bc\n"; file_put_contents($tm->baseDir . "/$name/broadcast.txt", ''); }
            }
            echo "\n[{$name}]> ";
            $input = trim(fgets(STDIN));
            if ($input === 'exit' || $input === 'quit') {
                $tm->stop($name);
                echo "Stopped terminal '$name'\n";
                break;
            }
            if (empty($input)) continue;
            file_put_contents($tm->baseDir . "/$name/input.txt", $input);
            $responseFile = $tm->baseDir . "/$name/response.txt";
            $timeout = 60;
            $start = time();
            while (!file_exists($responseFile) && (time() - $start) < $timeout) { usleep(100000); }
            if (file_exists($responseFile)) {
                echo "\n" . file_get_contents($responseFile) . "\n";
                unlink($responseFile);
            } else {
                echo "\n[Timeout waiting for response - terminal may need restart]\n";
            }
        }
        echo "Detached from terminal '$name'\n";
    } elseif ($sub === 'detach') {
        echo "Detached (terminal continues running in background)\n";
    } elseif ($sub === 'log') {
        $name = $arg2;
        if (!$name) { echo "Usage: terminal log <name> [lines]\n"; exit(1); }
        $lines = $arg3 ?: 50;
        echo $tm->getLog($name, $lines);
    } else {
        echo "Unknown terminal command: $sub\n";
        echo "Run 'ollamadev terminal help' for usage\n";
        exit(1);
    }
} elseif ($cmd === 'lsp') {
    $port = $flags['port'] ?: 4389;
    $host = $flags['hostname'] ?: '127.0.0.1';
    echo "OllamaDev LSP server starting on $host:$port\n";
    echo "Connect your IDE to localhost:$port\n";
    echo "Press Ctrl+C to stop\n\n";

    $server = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
    if (!$server) { echo "Failed: $errstr\n"; exit(1); }
    echo "LSP server listening on $host:$port\n";

    $ollama = new OllamaClient();
    $watchedFiles = [];
    $watcherRunning = true;

    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use (&$watcherRunning) { $watcherRunning = false; });
        pcntl_signal(SIGINT, function() use (&$watcherRunning) { $watcherRunning = false; });
    }

    while ($conn = @stream_socket_accept($server, 60)) {
        $data = '';
        $len = 0;
        while (($line = fgets($conn)) !== false) {
            if (trim($line) === '') break;
            if (strpos($line, 'Content-Length:') === 0) {
                $len = (int)trim(substr($line, 15));
            }
            $data .= $line;
        }
        if ($len > 0) {
            $body = '';
            while (strlen($body) < $len && ($line = fgets($conn)) !== false) { $body .= $line; }
            $data .= $body;
        }
        $json = json_decode(trim($data), true);
        $id = $json['id'] ?? null;
        $method = $json['method'] ?? '';
        $params = $json['params'] ?? [];

        $response = ['jsonrpc' => '2.0', 'id' => $id];
        if ($method === 'initialize') {
            $response['result'] = [
                'capabilities' => [
                    'textDocumentSync' => 1,
                    'hoverProvider' => true,
                    'definitionProvider' => true,
                    'referencesProvider' => true,
                    'renameProvider' => ['prepareProvider' => true],
                    'documentSymbolProvider' => true,
                    'codeActionProvider' => ['codeActionKinds' => ['quickfix', 'refactor', 'source']],
                    'documentFormattingProvider' => true,
                    'documentRangeFormattingProvider' => true,
                    'completionProvider' => ['resolveProvider' => true, 'triggerCharacters' => ['.', '>', ':']]
                ],
                'serverInfo' => ['name' => 'ollamadev-lsp', 'version' => '1.0']
            ];
        } elseif ($method === 'textDocument/hover') {
            $response['result'] = ['contents' => 'OllamaDev LSP - Ask questions about code via ollamadev terminal'];
        } elseif ($method === 'textDocument/completion') {
            $text = $params['textDocument']['uri'] ?? '';
            $pos = $params['position'] ?? ['line' => 0, 'character' => 0];
            $context = $params['context'] ?? [];
            $trigger = $context['triggerCharacter'] ?? '';
            $model = Config::get('ollama.defaultModel', 'llama3.2:latest');
            $code = '// ' . $trigger . ' autocomplete';
            $completion = $ollama->codeComplete($code, $trigger, $model);
            $items = [];
            if (!empty(trim($completion))) {
                $items[] = ['label' => 'ollamadev: ' . substr(trim($completion), 0, 30), 'kind' => 1, 'detail' => 'AI completion', 'insertText' => trim($completion)];
            }
            $items[] = ['label' => '// TODO: ', 'kind' => 2, 'detail' => 'Add comment', 'insertText' => '// TODO: '];
            if ($trigger === '.') {
                $items[] = ['label' => 'ask AI for method', 'kind' => 1, 'detail' => 'Get AI completion', 'insertText' => ''];
            }
            $response['result'] = ['isIncomplete' => true, 'items' => $items];
        } elseif ($method === 'textDocument/didOpen' || $method === 'textDocument/didChange') {
            $uri = $params['textDocument']['uri'] ?? '';
            if ($uri) {
                $watchedFiles[$uri] = ['uri' => $uri, 'version' => time()];
            }
            $response['result'] = null;
        } elseif ($method === 'textDocument/didSave') {
            $uri = $params['textDocument']['uri'] ?? '';
            if ($uri && isset($watchedFiles[$uri])) {
                $watchedFiles[$uri]['saved'] = date('Y-m-d H:i:s');
            }
            $response['result'] = null;
        } elseif ($method === 'textDocument/documentSymbol') {
            $uri = $params['textDocument']['uri'] ?? '';
            $symbols = [];
            if ($uri && file_exists($uri)) {
                $content = file_get_contents($uri);
                $ext = pathinfo($uri, PATHINFO_EXTENSION);
                $lang = match($ext) { 'php' => 'PHP', 'js' => 'JavaScript', 'ts' => 'TypeScript', 'py' => 'Python', 'go' => 'Go', 'rs' => 'Rust', default => 'plain' };
                if (preg_match_all('/^(class|interface|trait|function|const|enum|struct)\s+(\w+)/m', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $kind = match($m[1]) { 'class' => 5, 'interface' => 11, 'trait' => 22, 'function' => 12, 'const' => 14, 'enum' => 24, 'struct' => 23 } ?: 12;
                        $symbols[] = ['name' => $m[2], 'kind' => $kind, 'location' => ['uri' => $uri, 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]]]];
                    }
                }
                if (preg_match_all('/^\s*(public|private|protected)?\s*(static)?\s*\$(\w+)/m', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $symbols[] = ['name' => '$' . $m[3], 'kind' => 7, 'containerName' => 'properties', 'location' => ['uri' => $uri, 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]]]];
                    }
                }
            }
            $response['result'] = $symbols;
        } elseif ($method === 'textDocument/references') {
            $uri = $params['textDocument']['uri'] ?? '';
            $pos = $params['position'] ?? ['line' => 0, 'character' => 0];
            $word = 'symbol';
            if ($uri && file_exists($uri)) {
                $content = file_get_contents($uri);
                if (preg_match('/\b(\w+)\b/', substr($content, 0, 500), $m)) $word = $m[1];
            }
            $response['result'] = [['uri' => $uri, 'range' => ['start' => ['line' => $pos['line'] ?? 0, 'character' => 0], 'end' => ['line' => $pos['line'] ?? 0, 'character' => strlen($word)]]]];
        } elseif ($method === 'textDocument/rename') {
            $uri = $params['textDocument']['uri'] ?? '';
            $newName = $params['newName'] ?? 'newName';
            $response['result'] = ['changes' => [$uri => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 100]], 'newText' => $newName]]]];
        } elseif ($method === 'textDocument/codeAction') {
            $uri = $params['textDocument']['uri'] ?? '';
            $range = $params['range'] ?? ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]];
            $context = $params['context'] ?? [];
            $code = $uri && file_exists($uri) ? file_get_contents($uri) : '';
            $actions = [];
            if (!empty($code)) {
                $prompt = "Analyze this code and suggest code actions (quick fixes, refactors):\n" . substr($code, 0, 2000) . "\n\nReturn a JSON array of {title, kind, command} where kinds are 'quickfix', 'refactor', or 'source'. Example: [{\"title\": \"Add null check\", \"kind\": \"quickfix\", \"command\": \"ollamadev.fix\"}]";
                $result = $ollama->chat([['role' => 'user', 'content' => $prompt]]);
                if ($result && preg_match_all('/\{[^}]+\}/', $result, $matches)) {
                    foreach ($matches[0] as $m) {
                        $action = json_decode($m, true);
                        if ($action && isset($action['title'])) {
                            $actions[] = [
                                'title' => $action['title'],
                                'kind' => $action['kind'] ?? 'quickfix',
                                'command' => ['title' => $action['title'], 'command' => 'ollamadev.action', 'arguments' => [$action]]
                            ];
                        }
                    }
                }
            }
            if (empty($actions)) {
                $actions[] = ['title' => 'Ask OllamaDev for suggestions', 'kind' => 'source', 'command' => ['title' => 'AI Assist', 'command' => 'ollamadev.ai', 'arguments' => []]];
            }
            $response['result'] = $actions;
        } elseif ($method === 'textDocument/formatting') {
            $uri = $params['textDocument']['uri'] ?? '';
            if ($uri && file_exists($uri)) {
                $content = file_get_contents($uri);
                $ext = pathinfo($uri, PATHINFO_EXTENSION);
                $cmd = match($ext) {
                    'php' => 'php -l -f',
                    'js' => 'npx prettier --stdin-filepath',
                    'ts' => 'npx prettier --stdin-filepath',
                    'py' => 'python3 -m black -',
                    'go' => 'gofmt',
                    'rs' => 'rustfmt',
                    'json' => 'jq .',
                    default => null
                };
                if ($cmd) {
                    $tmpIn = tempnam('/tmp', 'fmt_in_');
                    $tmpOut = tempnam('/tmp', 'fmt_out_');
                    file_put_contents($tmpIn, $content);
                    $formatted = shell_exec("$cmd < " . escapeshellarg($tmpIn) . " > " . escapeshellarg($tmpOut) . " 2>&1");
                    if (file_exists($tmpOut) && filesize($tmpOut) > 0) {
                        $formatted = file_get_contents($tmpOut);
                    }
                    unlink($tmpIn);
                    unlink($tmpOut);
                    if ($formatted && $formatted !== $content) {
                        $lines = explode("\n", $content);
                        $fmtLines = explode("\n", $formatted);
                        $edits = [];
                        $startLine = 0;
                        $endLine = count($lines) - 1;
                        $response['result'] = [['range' => ['start' => ['line' => $startLine, 'character' => 0], 'end' => ['line' => $endLine, 'character' => strlen($lines[$endLine] ?? '')]], 'newText' => $formatted]];
                    } else {
                        $response['result'] = [];
                    }
                } else {
                    $response['result'] = [];
                }
            } else {
                $response['result'] = [];
            }
        } elseif ($method === 'textDocument/publishDiagnostics') {
            $response['result'] = null;
        } elseif ($method === 'completionItem/resolve') {
            $item = $params;
            $response['result'] = $item;
        } elseif (strpos($method, 'ollamadev/') === 0) {
            $action = substr($method, 10);
            if ($action === 'chat') {
                $msg = $params['message'] ?? '';
                $result = $ollama->chat([['role' => 'user', 'content' => $msg]]);
                $response['result'] = ['reply' => $result ?: 'Use ollamadev terminal for full chat'];
            } elseif ($action === 'review') {
                $code = $params['code'] ?? '';
                $result = $ollama->codeReview($code);
                $response['result'] = ['reply' => $result ?: 'Use ollamadev terminal for code review'];
            } elseif ($action === 'generate') {
                $code = $params['code'] ?? '';
                $prompt = "Improve this code:\n" . $code . "\n\nReturn only the improved code:";
                $result = $ollama->completion($prompt);
                $response['result'] = ['reply' => $result ?: 'Use ollamadev terminal for code generation'];
            } else {
                $response['result'] = ['reply' => "OllamaDev: $action - use ollamadev terminal"];
            }
        } elseif ($method === 'shutdown') {
            $response['result'] = null;
        } elseif ($method === 'exit') {
            fclose($conn);
            exit(0);
        } else {
            $response['result'] = null;
        }
        $out = json_encode($response);
        fwrite($conn, "Content-Length: " . strlen($out) . "\r\n\r\n" . $out);
        fclose($conn);
    }
} elseif (empty($cmd)) {
    (new Session($config))->start();
} else {
    echo "Unknown command: $cmd\n";
    echo "Run 'ollamadev help <topic>' for available topics.\n";
    echo "Run 'ollamadev help' for general usage.\n";
    exit(1);
}
ENDOFFILE

chmod +x "$BUILD_DIR/ollamadev"
echo "Built: $BUILD_DIR/ollamadev"

# Create batch wrapper for Windows
cat > "$BUILD_DIR/ollamadev.bat" << 'BATEOF'
@echo off
php "%~dp0ollamadev" %*
BATEOF

# Create version info
echo "v3.9.2" > "$BUILD_DIR/VERSION"

# Show file info
ls -la "$BUILD_DIR/ollamadev"
ls -la "$BUILD_DIR/ollamadev.bat"
echo "Lines: $(wc -l < "$BUILD_DIR/ollamadev")"