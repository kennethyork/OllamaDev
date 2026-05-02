<?php

class Session {
    private array $config;
    private string $id;
    private string $title;
    private string $model;
    private array $messages = [];
    private Agent $agent;
    private bool $verbose = false;

    public function __construct(array $config, ?string $sessionId = null) {
        $this->config = $config;

        if ($sessionId) {
            $this->load($sessionId);
        } else {
            $this->id = $this->generateId();
            $this->title = "Session " . date('Y-m-d H:i');
            $this->model = Config::get('ollama.defaultModel', 'codellama');
        }

        $this->ensureDataDir();
        $this->agent = new Agent();
    }

    private function generateId(): string {
        return 'session_' . time() . '_' . substr(md5(mt_rand()), 0, 8);
    }

    private function ensureDataDir(): void {
        $dir = Config::sessionsDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function createNew(): void {
        $this->save();
    }

    public function load(string $sessionId): void {
        $path = Config::sessionsDir() . '/' . $sessionId . '.json';
        if (!file_exists($path)) {
            throw new Exception("Session not found: $sessionId");
        }

        $data = json_decode(file_get_contents($path), true);
        $this->id = $data['id'] ?? $sessionId;
        $this->title = $data['title'] ?? '';
        $this->model = $data['model'] ?? Config::get('ollama.defaultModel', 'codellama');
        $this->messages = $data['messages'] ?? [];
    }

    public function save(): void {
        $path = Config::sessionsDir() . '/' . $this->id . '.json';
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'model' => $this->model,
            'messages' => $this->messages,
            'created_at' => date('c'),
            'updated_at' => date('c')
        ];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function addMessage(string $role, string $content): void {
        $this->messages[] = [
            'id' => 'msg_' . time() . '_' . substr(md5(mt_rand()), 0, 6),
            'role' => $role,
            'content' => $content,
            'created_at' => date('c')
        ];
        $this->save();
    }

    public function getMessages(): array {
        return $this->messages;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getTitle(): string {
        return $this->title;
    }

    public static function listAll(array $config): array {
        $dir = Config::sessionsDir();
        if (!is_dir($dir)) {
            return [];
        }

        $sessions = [];
        foreach (glob($dir . '/session_*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $sessions[] = [
                    'id' => basename($file, '.json'),
                    'title' => $data['title'] ?? 'Untitled',
                    'model' => $data['model'] ?? 'unknown',
                    'created_at' => $data['created_at'] ?? '',
                    'updated_at' => $data['updated_at'] ?? ''
                ];
            }
        }

        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        return $sessions;
    }

    private function renderBanner(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                     OllamaDev                                ║\n";
        echo "║  Local AI coding agent powered by Ollama                     ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Available Tools:                                           ║\n";
        echo "║    view, write, edit, glob, grep, ls, bash, fetch, patch     ║\n";
        echo "║    diagnostics, agent                                       ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Commands:                                                  ║\n";
        echo "║    exit, new, mode [auto/think/diff], verbose [on/off]       ║\n";
        echo "║    model <name>, clear, help                                 ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    private function renderPrompt(): void {
        echo "[{$this->model}] > ";
    }

    private function handleCommand(string $input): bool {
        $parts = preg_split('/\s+/', trim($input), 2);
        $cmd = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        switch ($cmd) {
            case 'exit':
            case 'quit':
            case 'q':
                echo "Goodbye!\n";
                return true;

            case 'new':
                $this->save();
                $session = new Session($this->config);
                $session->start();
                return true;

            case 'mode':
                $mode = trim($args) ?: 'auto';
                echo "Mode set to: $mode\n";
                return false;

            case 'verbose':
                $this->verbose = trim($args) === 'on';
                echo "Verbose: " . ($this->verbose ? 'on' : 'off') . "\n";
                return false;

            case 'model':
                if (!empty($args)) {
                    $this->agent->setModel($args);
                    $this->model = $args;
                    echo "Model: $args\n";
                } else {
                    $models = $this->agent->listModels();
                    echo "Current: {$this->model}\n";
                    echo "Available: " . implode(', ', $models) . "\n";
                }
                return false;

            case 'clear':
                echo "\033[2J\033[H";
                return false;

            case 'help':
                $this->renderBanner();
                return false;

            case '':
                return false;

            default:
                if (str_starts_with($cmd, '/')) {
                    echo "Unknown command: $cmd\n";
                    return false;
                }
                return false;
        }
    }

    private function countTokens(): int {
        $total = 0;
        foreach ($this->messages as $msg) {
            $total += strlen($msg['content'] ?? '') / 4;
        }
        return (int)$total;
    }

    private function renderStatus(): void {
        $tokens = $this->countTokens();
        $msgs = count($this->messages);
        echo "\n[Model: {$this->model} | Tokens: ~{$tokens} | Messages: {$msgs}]\n";
    }

    public function start(): void {
        echo "\033[2J\033[H";
        $this->renderBanner();

        if (!$this->agent->checkConnection()) {
            echo "⚠️  Cannot connect to Ollama at " . Config::get('ollama.host') . "\n";
            echo "   Make sure Ollama is running: `ollama serve`\n\n";
        }

        if (!empty($this->messages)) {
            echo "📜 Loading previous messages...\n";
            foreach ($this->messages as $msg) {
                $roleIcon = $msg['role'] === 'user' ? '👤' : ($msg['role'] === 'assistant' ? '🤖' : '🔧');
                echo "\n{$roleIcon} [{$msg['role']}]\n{$msg['content']}\n";
            }
            echo "\n";
        }

        $this->renderStatus();

        while (true) {
            $this->renderPrompt();
            $input = trim(fgets(STDIN));

            if ($this->handleCommand($input)) {
                break;
            }

            if (empty($input)) {
                continue;
            }

            $this->addMessage('user', $input);
            echo "\n🤖 [assistant]\n";

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

            if (!empty($toolResults)) {
                echo "\n🤖 [follow-up]\n";
                $followUp = '';
                $this->agent->run($this->getMessages(), function($chunk) use (&$followUp) {
                    echo $chunk;
                    $followUp .= $chunk;
                });

                if (!empty(trim($followUp))) {
                    $this->addMessage('assistant', $followUp);
                }
            } else {
                $this->addMessage('assistant', $response);
            }

            $this->save();
            $this->renderStatus();
        }
    }
}