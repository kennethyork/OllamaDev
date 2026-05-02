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
            'ollama' => ['host' => 'http://localhost:11434', 'defaultModel' => 'codellama'],
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

    public function listModels(): array {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) { $data = json_decode($response, true); if (isset($data['models'])) return array_map(fn($m) => $m['name'], $data['models']); }
        return [];
    }

    public function chat(array $messages, callable $handler = null): string {
        $model = Config::get('ollama.defaultModel', 'codellama');
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
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($ext === 'php') return shell_exec("php -l " . escapeshellarg($path) . " 2>&1") ?: "No syntax errors";
    return shell_exec("go vet " . escapeshellarg($path) . " 2>&1") ?: "No diagnostics";
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
        $this->model = Config::get('ollama.defaultModel', 'codellama');
        $this->systemPrompt = ['role' => 'system', 'content' => 'You are OllamaDev, an AI coding assistant running locally via Ollama.

You have access to tools: view, write, edit, glob, grep, ls, bash (readonly), fetch, diagnostics

Guidelines:
- Be helpful and precise
- Use tools when needed
- Ask for confirmation before destructive actions
- Prefer showing code over explaining'
        ];
    }

    public function setModel(string $model): void { $this->model = $model; }
    public function getModel(): string { return $this->model; }
    public function listModels(): array { return $this->client->listModels(); }
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

class Session {
    private array $config;
    private string $id;
    private string $title;
    private string $model;
    private array $messages = [];
    private Agent $agent;

    public function __construct(array $config, ?string $sessionId = null) {
        $this->config = $config;
        if ($sessionId) { $this->load($sessionId); }
        else { $this->id = 'session_' . time() . '_' . substr(md5(mt_rand()), 0, 8); $this->title = "Session " . date('Y-m-d H:i'); $this->model = Config::get('ollama.defaultModel', 'codellama'); }
        $this->ensureDataDir();
        $this->agent = new Agent();
    }

    private function ensureDataDir(): void { $dir = Config::sessionsDir(); if (!is_dir($dir)) mkdir($dir, 0755, true); }

    public function createNew(): void { $this->save(); }

    public function load(string $sessionId): void {
        $path = Config::sessionsDir() . '/' . $sessionId . '.json';
        $data = json_decode(file_get_contents($path), true);
        $this->id = $data['id'] ?? $sessionId;
        $this->title = $data['title'] ?? '';
        $this->model = $data['model'] ?? Config::get('ollama.defaultModel', 'codellama');
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

    private function renderBanner(): void {
        echo "\n╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                     OllamaDev                                ║\n";
        echo "║  Local AI coding agent powered by Ollama                     ║\n";
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
                else { echo "Current: {$this->model}\nAvailable: " . implode(', ', $this->agent->listModels()) . "\n"; }
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
        echo "\033[2J\033[H";
        $this->renderBanner();
        if (!$this->agent->checkConnection()) { echo "⚠️  Cannot connect to Ollama at " . Config::get('ollama.host') . "\n   Make sure Ollama is running: `ollama serve`\n\n"; }
        if (!empty($this->messages)) {
            echo "📜 Loading previous messages...\n";
            foreach ($this->messages as $msg) { $icon = $msg['role'] === 'user' ? '👤' : ($msg['role'] === 'assistant' ? '🤖' : '🔧'); echo "\n{$icon} [{$msg['role']}]\n{$msg['content']}\n"; }
            echo "\n";
        }
        $this->renderStatus();

        while (true) {
            $this->renderPrompt();
            $input = trim(fgets(STDIN));
            if ($this->handleCommand($input)) break;
            if (empty($input)) continue;

            $this->addMessage('user', $input);
            echo "\n🤖 [assistant]\n";
            $response = '';
            $this->agent->run($this->getMessages(), function($chunk) use (&$response) { echo $chunk; $response .= $chunk; });
            echo "\n";

            $toolResults = $this->agent->parseAndExecuteTools($response);
            foreach ($toolResults as $result) {
                $this->addMessage($result['role'], $result['content']);
                echo "\n🔧 [tool]\n{$result['content']}\n";
            }

            if (!empty($toolResults)) {
                echo "\n🤖 [follow-up]\n";
                $followUp = '';
                $this->agent->run($this->getMessages(), function($chunk) use (&$followUp) { echo $chunk; $followUp .= $chunk; });
                if (!empty(trim($followUp))) $this->addMessage('assistant', $followUp);
            } else {
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