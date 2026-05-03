<?php

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

    public static function reset(): void { self::$config = null; }
}

class OllamaClient {
    private string $host;
    private int $timeout;

    public function __construct(string $host = 'http://localhost:11434', int $timeout = 120) {
        $this->host = $host;
        $this->timeout = $timeout;
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
        $params = ['model' => $model ?: Config::get('ollama.defaultModel', 'llama3.2:latest'), 'prompt' => $prompt, 'stream' => false, 'options' => ['num_predict' => 150]];
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

class TerminalManager {
    private string $baseDir;

    public function __construct() {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        $this->baseDir = $home . '/.ollamadev/terminals';
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    public function list(): array {
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

    public function create(string $name, string $model, ?string $cwd = null): array {
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

    public function start(string $name): array {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        $logFile = $this->baseDir . '/' . $name . '/session.log';
        $cwd = $terminal['cwd'];
        $binary = defined('OLLAMADEV_BINARY') ? OLLAMADEV_BINARY : 'ollamadev';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a']
        ];
        $process = proc_open(
            "cd " . escapeshellarg($cwd) . " && " . $binary . " --model " . escapeshellarg($terminal['model']),
            $descriptors,
            $pipes,
            $cwd
        );
        $pid = proc_get_status($process)['pid'];
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $terminal['pid'] = $pid;
        $terminal['status'] = 'running';
        $terminal['last_used'] = date('Y-m-d H:i:s');
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function stop(string $name): array {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGTERM);
            usleep(100000);
            posix_kill((int)$terminal['pid'], SIGKILL);
        }
        $terminal['pid'] = null;
        $terminal['status'] = 'stopped';
        $terminal['last_used'] = date('Y-m-d H:i:s');
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function pause(string $name): array {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'running') return ['error' => "Terminal '$name' is not running"];
        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGSTOP);
        }
        $terminal['status'] = 'paused';
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function resume(string $name): array {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'paused') return ['error' => "Terminal '$name' is not paused"];
        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGCONT);
        }
        $terminal['status'] = 'running';
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function delete(string $name): bool {
        $dir = $this->baseDir . '/' . $name;
        if (!is_dir($dir)) return false;
        $this->stop($name);
        shell_exec("rm -rf " . escapeshellarg($dir));
        return true;
    }

    public function exists(string $name): bool {
        return is_dir($this->baseDir . '/' . $name);
    }

    public function loadTerminal(string $name): ?array {
        $file = $this->baseDir . '/' . $name . '/session.json';
        if (!file_exists($file)) return null;
        return json_decode(file_get_contents($file), true) ?: null;
    }

    public function saveTerminal(string $name, array $terminal): void {
        $file = $this->baseDir . '/' . $name . '/session.json';
        file_put_contents($file, json_encode($terminal, JSON_PRETTY_PRINT));
    }

    public function getLog(string $name, int $lines = 100): string {
        $logFile = $this->baseDir . '/' . $name . '/session.log';
        if (!file_exists($logFile)) return '';
        return shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($logFile));
    }

    public function status(): array {
        $terminals = $this->list();
        $running = 0;
        $paused = 0;
        $stopped = 0;
        foreach ($terminals as $t) {
            if ($t['status'] === 'running') $running++;
            elseif ($t['status'] === 'paused') $paused++;
            else $stopped++;
        }
        return [
            'total' => count($terminals),
            'running' => $running,
            'paused' => $paused,
            'stopped' => $stopped,
            'terminals' => $terminals
        ];
    }

    public function getBaseDir(): string { return $this->baseDir; }
}

class Tools {
    public static function diff(string $file1, string $file2): string {
        if (empty($file1) || empty($file2)) return "missing file1 or file2";
        if (!file_exists($file1) || !file_exists($file2)) return "File not found";
        $isGitDir = is_dir(dirname($file1) . '/.git') || is_dir($file1);
        if ($isGitDir || preg_match('/\.git[\/\\\]?$/', dirname($file1))) {
            return shell_exec("cd " . escapeshellarg(dirname($file1)) . " && git diff " . escapeshellarg(basename($file1)) . " 2>&1") ?: "No differences";
        }
        $output = shell_exec("diff -u " . escapeshellarg($file1) . " " . escapeshellarg($file2) . " 2>&1");
        return empty($output) ? "No differences" : $output;
    }
}

// ============== TESTS ==============
echo "Running OllamaDev Tests\n";
echo "========================\n\n";

$passed = 0;
$failed = 0;

function test($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ $name\n";
        $passed++;
    } else {
        echo "✗ $name\n";
        $failed++;
    }
}

// Config Tests
echo "Config Tests:\n";
Config::reset();
test('Config::get returns default for missing key', Config::get('nonexistent', 'default') === 'default');
test('Config::get returns nested value', Config::get('ollama.host') === 'http://localhost:11434');
test('Config::get returns nested defaultModel', Config::get('ollama.defaultModel') === 'llama3.2:latest');
test('Config::dataDir returns string', is_string(Config::dataDir()) && strlen(Config::dataDir()) > 0);
test('Config::sessionsDir returns dataDir/sessions', strpos(Config::sessionsDir(), 'sessions') !== false);
Config::reset();

// OllamaClient Tests
echo "\nOllamaClient Tests:\n";
$client = new OllamaClient();
test('OllamaClient instantiated', $client instanceof OllamaClient);
test('OllamaClient has completion method', method_exists($client, 'completion'));
test('OllamaClient has codeComplete method', method_exists($client, 'codeComplete'));
test('OllamaClient has codeReview method', method_exists($client, 'codeReview'));
test('OllamaClient has chat method', method_exists($client, 'chat'));
test('OllamaClient has listModels method', method_exists($client, 'listModels'));
test('OllamaClient has checkConnection method', method_exists($client, 'checkConnection'));

// TerminalManager Tests
echo "\nTerminalManager Tests:\n";
$tm = new TerminalManager();
test('TerminalManager instantiated', $tm instanceof TerminalManager);
test('TerminalManager has create method', method_exists($tm, 'create'));
test('TerminalManager has start method', method_exists($tm, 'start'));
test('TerminalManager has stop method', method_exists($tm, 'stop'));
test('TerminalManager has pause method', method_exists($tm, 'pause'));
test('TerminalManager has resume method', method_exists($tm, 'resume'));
test('TerminalManager has delete method', method_exists($tm, 'delete'));
test('TerminalManager has status method', method_exists($tm, 'status'));
test('TerminalManager has list method', method_exists($tm, 'list'));
test('TerminalManager baseDir is set', is_dir($tm->getBaseDir()));

// Terminal Lifecycle Tests
echo "\nTerminal Lifecycle Tests:\n";
$testTermName = 'test_term_' . time();
$result = $tm->create($testTermName, 'llama3.2:latest', '/tmp');
test('TerminalManager::create returns array', is_array($result));
test('TerminalManager::create sets name', $result['name'] === $testTermName);
test('TerminalManager::create sets model', $result['model'] === 'llama3.2:latest');
test('TerminalManager::create sets cwd', $result['cwd'] === '/tmp');
test('TerminalManager::create sets status to stopped', $result['status'] === 'stopped');
test('TerminalManager::exists returns true for created', $tm->exists($testTermName));
$loaded = $tm->loadTerminal($testTermName);
test('TerminalManager::loadTerminal returns array', is_array($loaded));
test('TerminalManager::loadTerminal preserves name', $loaded['name'] === $testTermName);
$status = $tm->status();
test('TerminalManager::status returns total count', $status['total'] >= 1);
test('TerminalManager::status returns stopped count', $status['stopped'] >= 1);
$tm->delete($testTermName);
clearstatcache(true, $tm->getBaseDir() . '/' . $testTermName);
test('TerminalManager::delete removes directory', !file_exists($tm->getBaseDir() . '/' . $testTermName));
test('TerminalManager::exists returns false after delete', !$tm->exists($testTermName));

// Tools Tests
echo "\nTools Tests:\n";
$testDir = '/tmp/ollamadev_test_' . time();
mkdir($testDir, 0755, true);
file_put_contents("$testDir/file1.txt", "line1\nline2\nline3\n");
file_put_contents("$testDir/file2.txt", "line1\nmodified\nline3\n");
file_put_contents("$testDir/file3.txt", "line1\nline2\nline3\n");
test('Tools::diff detects differences', strpos(Tools::diff("$testDir/file1.txt", "$testDir/file2.txt"), '<') !== false || strpos(Tools::diff("$testDir/file1.txt", "$testDir/file2.txt"), '---') !== false);
test('Tools::diff shows no differences for identical files', strpos(Tools::diff("$testDir/file1.txt", "$testDir/file3.txt"), 'No differences') !== false || trim(Tools::diff("$testDir/file1.txt", "$testDir/file3.txt")) === '');
test('Tools::diff returns error for missing file', strpos(Tools::diff("$testDir/nonexistent.txt", "$testDir/file1.txt"), 'not found') !== false);
exec("rm -rf $testDir");

// String/Misc Tests
echo "\nString/Misc Tests:\n";
test('basename works correctly', basename('/path/to/file.txt') === 'file.txt');
test('escapeshellarg works correctly', escapeshellarg("file with spaces") === "'file with spaces'");
test('json_encode/decode roundtrip', (array)json_decode(json_encode(['a' => 1, 'b' => 'test'])) === ['a' => 1, 'b' => 'test']);

// Summary
echo "\n========================\n";
echo "Results: $passed passed, $failed failed\n";
if ($failed > 0) exit(1);
echo "ALL TESTS PASSED\n";
exit(0);