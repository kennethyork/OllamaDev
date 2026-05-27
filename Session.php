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

    private function renderPrompt(): void { echo "[{$this->model}] > "; }
    private function countTokens(): int { $total = 0; foreach ($this->messages as $msg) $total += strlen($msg['content'] ?? '') / 4; return (int)$total; }
    private function renderStatus(): void { echo "\n[Model: {$this->model} | Tokens: ~" . $this->countTokens() . " | Messages: " . count($this->messages) . "]\n"; }
    private function showContext(): void {
        $pwd = getcwd();
        $edited = $GLOBALS['editedFiles'] ?? [];
        echo "\n📁 $pwd";
        if (!empty($edited)) {
            echo "\n✏️  Edited: " . implode(', ', $edited);
            $GLOBALS['editedFiles'] = [];
        }
    }

    private function handleCommand(string $input): bool {
        $parts = preg_split('/\s+/', trim($input), 2);
        $cmd = strtolower($parts[0]);
        if (str_starts_with($cmd, '/')) $cmd = substr($cmd, 1);
        $args = $parts[1] ?? '';

        switch ($cmd) {
            case 'exit': case 'quit': case 'q': echo "Goodbye!\n"; return true;
            case 'new': $this->save(); (new Session($this->config))->start(); return true;
            case 'mode': echo "Mode set to: " . ($args ?: 'auto') . "\n"; return false;
            case 'verbose': $GLOBALS['verbose'] = trim($args) === 'on'; echo "Verbose: " . ($GLOBALS['verbose'] ? 'on' : 'off') . "\n"; return false;
            case 'models':
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