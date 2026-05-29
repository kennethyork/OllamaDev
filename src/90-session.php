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
        Permission::setMode(Config::get('permissions.mode', 'ask'));
        $this->agent = new Agent();
        if ($sessionId) { $this->load($sessionId); }
        else { $this->id = 'session_' . time() . '_' . substr(md5(mt_rand()), 0, 8); $this->title = "Session " . date('Y-m-d H:i'); $this->model = $this->agent->getModel(); }
        // Keep the agent and session pointed at the same model.
        $this->agent->setModel($this->model);
        $GLOBALS['currentSessionModel'] = $this->model;
        // Small models do tool-calling poorly; default them to pure chat.
        $this->agent->setChatMode($this->agent->isSmallModel());
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

    private function handleSlashCommand(string $input): string|false {
        if (!str_starts_with($input, '/')) return false;

        $parts = preg_split('/\s+/', trim($input), 2);
        $cmd = strtolower($parts[0]);
        $cmd = substr($cmd, 1);
        $args = $parts[1] ?? '';

        return match($cmd) {
            'help' => $this->renderBanner(),
            'models' => $this->listModels($args),
            'model' => $this->switchModel($args),
            'new' => $this->newSession(),
            'exit', 'quit' => $this->exitCli(),
            'clear' => $this->clearScreen(),
            'verbose' => $this->toggleVerbose($args),
            'compact' => $this->compactSession(),
            'save' => $this->saveSession(),
            'session' => $this->showSession(),
            'git' => $this->runGit($args),
            'status' => "[Model: {$this->model} | Tokens: ~" . $this->countTokens() . " | Messages: " . count($this->messages) . "]\n",
            'tools' => $this->listTools(),
            'context' => "📁 " . getcwd() . "\nMessages: " . count($this->messages) . " | Model: {$this->model}\n",
            'pwd' => getcwd() . "\n",
            'cd' => $this->changeDir($args),
            'ls' => crossPlatformLs($args ?: '.') . "\n",
            'permission', 'permissions' => $this->managePermission($args),
            'chat', 'agent' => $this->toggleChatMode($cmd, $args),
            default => false
        };
    }

    private function managePermission(string $args): string {
        $parts = preg_split('/\s+/', trim($args), 2);
        $sub = strtolower($parts[0] ?? '');
        $val = $parts[1] ?? '';
        switch ($sub) {
            case 'mode':
                if ($val === '') return "Current mode: " . Permission::getMode() . "\nUsage: /permission mode <auto|ask|readonly>\n";
                Permission::setMode(trim($val));
                return "Permission mode: " . Permission::getMode() . "\n";
            case 'allow':
                if ($val === '') return "Usage: /permission allow <tool>\n";
                Permission::allow(trim($val));
                return "Allowed: " . trim($val) . "\n";
            case 'deny':
                if ($val === '') return "Usage: /permission deny <tool>\n";
                Permission::deny(trim($val));
                return "Denied: " . trim($val) . "\n";
            case '':
            case 'status':
                $allowed = Permission::listAllowed();
                $denied = Permission::listDenied();
                $out = "Permission mode: " . Permission::getMode() . "\n";
                $out .= "  auto     - run every tool without asking\n";
                $out .= "  ask      - prompt before mutating tools (default)\n";
                $out .= "  readonly - block all mutating tools\n";
                $out .= "Session allowed: " . (empty($allowed) ? '(none)' : implode(', ', $allowed)) . "\n";
                $out .= "Session denied:  " . (empty($denied) ? '(none)' : implode(', ', $denied)) . "\n";
                return $out;
            default:
                return "Usage: /permission [mode <auto|ask|readonly> | allow <tool> | deny <tool> | status]\n";
        }
    }

    private function listTools(): string {
        $tools = Tools::all();
        sort($tools);
        return "\nAvailable tools (" . count($tools) . "):\n" . wordwrap(implode(', ', $tools), 70) . "\n";
    }

    private function changeDir(string $dir): string {
        $dir = trim($dir);
        if ($dir === '') return getcwd() . "\n";
        if (!is_dir($dir)) return "Not a directory: $dir\n";
        chdir($dir);
        return "📁 " . getcwd() . "\n";
    }

    private function renderBanner(): string {
        $models = $this->agent->listModelsDetailed();
        $modelCount = count($models);
        $c = "\033[36m"; $b = "\033[1m"; $d = "\033[2m"; $r = "\033[0m"; // cyan, bold, dim, reset
        $art = <<<'ART'
  ___  _ _                       ____
 / _ \| | | __ _ _ __ ___   __ _|  _ \  _____   __
| | | | | |/ _` | '_ ` _ \ / _` | | | |/ _ \ \ / /
| |_| | | | (_| | | | | | | (_| | |_| |  __/\ V /
 \___/|_|_|\__,_|_| |_| |_|\__,_|____/ \___| \_/
ART;
        $out  = "\n{$c}{$b}{$art}{$r}\n";
        $out .= "{$d}  Local AI coding assistant · powered by Ollama{$r}\n\n";
        $mode = $this->agent->isChatMode() ? 'chat' : 'agent';
        $out .= "  {$d}model{$r} {$c}{$this->model}{$r}   {$d}· {$modelCount} available · {$mode} mode{$r}\n";
        $out .= "  {$d}/help · /model · /chat · /exit{$r}\n\n";
        return $out;
    }

    // Models known to do tool-calling / agentic work well locally. Marked with
    // a star when listing, and suggested when the current model is weak.
    private static array $recommended = [
        'qwen2.5-coder', 'qwen3-coder', 'qwen2.5', 'mistral', 'codestral',
        'llama3.1', 'llama3.3', 'deepseek-coder-v2', 'devstral', 'gpt-oss',
    ];

    private function isRecommended(string $name): bool {
        foreach (self::$recommended as $r) {
            if (stripos($name, $r) !== false) return true;
        }
        return false;
    }

    private function listModels(string $args = ''): string {
        if (!empty($args)) return $this->switchModel($args);
        $models = $this->agent->listModelsDetailed();
        $c = "\033[36m"; $d = "\033[2m"; $r = "\033[0m";
        $out = "\n  Installed models:\n";
        foreach ($models as $m) {
            $name = $m['name'] ?? 'unknown';
            $size = isset($m['size']) ? $this->formatBytes($m['size']) : '?';
            $cur = $name === $this->model ? "{$c} ← current{$r}" : '';
            $star = $this->isRecommended($name) ? "{$c}★{$r}" : ' ';
            $out .= sprintf("  %s %-22s %s%s%s\n", $star, $name, $d, $size . $r, $cur);
        }
        $out .= "\n  {$c}★{$r} {$d}= recommended for agentic/tool use{$r}\n";
        $out .= "  {$d}Best local agents: qwen2.5-coder, mistral, codestral — e.g. `ollama pull qwen2.5-coder`{$r}\n";
        return $out;
    }

    // /chat [on|off] toggles pure-conversation mode; /agent enables tools.
    private function toggleChatMode(string $cmd, string $args): string {
        $arg = strtolower(trim($args));
        if ($cmd === 'agent') {
            $this->agent->setChatMode(false);
        } elseif ($arg === 'off') {
            $this->agent->setChatMode(false);
        } elseif ($arg === 'on' || $arg === '') {
            $this->agent->setChatMode($cmd === 'chat' ? true : !$this->agent->isChatMode());
        }
        return $this->agent->isChatMode()
            ? "\033[36mChat mode\033[0m — pure conversation, tools disabled.\n"
            : "\033[36mAgent mode\033[0m — tools enabled.\n";
    }

    private function switchModel(string $name): string {
        if (empty($name)) return "Usage: /model <name>\n";
        $this->agent->setModel($name);
        $this->model = $name;
        $GLOBALS['currentSessionModel'] = $name;
        // Re-evaluate chat mode for the new model's size.
        $this->agent->setChatMode($this->agent->isSmallModel());
        $mode = $this->agent->isChatMode() ? " \033[2m(chat mode — small model)\033[0m" : '';
        return "Model: $name$mode\n";
    }

    private function newSession(): string {
        $this->save();
        (new Session($this->config))->start();
        return '';
    }

    private function exitCli(): string {
        echo "Goodbye!\n";
        exit(0);
    }

    private function clearScreen(): string {
        return "\033[2J\033[H";
    }

    private function toggleVerbose(string $arg): string {
        $GLOBALS['verbose'] = trim($arg) === 'on';
        return "Verbose: " . ($GLOBALS['verbose'] ? 'on' : 'off') . "\n";
    }

    private function compactSession(): string {
        $this->compactMessages();
        return '';
    }

    private function saveSession(): string {
        $this->save();
        return "Session saved.\n";
    }

    private function showSession(): string {
        return "Session: {$this->id} | Model: {$this->model} | Messages: " . count($this->messages) . "\n";
    }

    private function runGit(string $args): string {
        if (empty($args)) return "Usage: /git <command>\n";
        $gitAliases = [
            'status' => 'git status',
            'diff' => 'git diff',
            'log' => 'git log --oneline -n 10',
            'branch' => 'git branch -a',
            'checkout' => 'git checkout',
            'commit' => 'git commit',
            'add' => 'git add',
            'push' => 'git push',
            'pull' => 'git pull',
            'stash' => 'git stash',
            'fetch' => 'git fetch',
        ];
        foreach ($gitAliases as $alias => $cmd) {
            if (str_starts_with($args, $alias)) {
                $gitArgs = substr($args, strlen($alias));
                return shell_exec(trim($cmd . ' ' . $gitArgs));
            }
        }
return "Available: " . implode(', ', array_keys($gitAliases)) . "\n";
    }

    private function countTokens(): int { $total = 0; foreach ($this->messages as $msg) $total += strlen($msg['content'] ?? '') / 4; return (int)$total; }
    private function renderStatus(): void { echo "\n[Model: {$this->model} | Tokens: ~" . $this->countTokens() . " | Messages: " . count($this->messages) . "]\n"; }
    private function renderPrompt(): void { echo "\n› "; }
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
        // Slash commands share one implementation with the -p path.
        if (str_starts_with(trim($input), '/')) {
            $result = $this->handleSlashCommand($input);
            if ($result !== false) { echo $result; }
            return false; // exit/quit terminate via exit(0) inside their handlers
        }

        $parts = preg_split('/\s+/', trim($input), 2);
        $cmd = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        switch ($cmd) {
            case 'exit': case 'quit': case 'q': echo "Goodbye!\n"; return true;
            case 'new': $this->save(); (new Session($this->config))->start(); return true;
            case 'mode': echo "Mode set to: " . ($args ?: 'auto') . "\n"; return false;
            case 'verbose': $GLOBALS['verbose'] = trim($args) === 'on'; echo "Verbose: " . ($GLOBALS['verbose'] ? 'on' : 'off') . "\n"; return false;
            case 'models':
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
                return false;
        }
    }

    public function start(): void {
        Permission::setInteractive(true); // enable approval prompts for mutating tools
        echo $this->renderBanner();
        if (!$this->agent->checkConnection()) {
            echo "\033[33m  ⚠ Cannot connect to Ollama at " . Config::get('ollama.host') . "\033[0m\n";
            echo "\033[2m    Start it with: ollama serve\033[0m\n\n";
        }

        if (!empty($this->messages)) {
            echo "\033[2m  resumed session · " . count($this->messages) . " messages\033[0m\n";
        }

        // Nudge new users toward a capable model for agentic work.
        if (!$this->isRecommended($this->model)) {
            echo "\033[2m  tip: for reliable tool use try a recommended model — \033[0m\033[36mollama pull qwen2.5-coder\033[0m\033[2m, then /model qwen2.5-coder:latest\033[0m\n";
        }

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

            if (empty($input)) continue;

            // Slash commands are handled here and never sent to the model.
            if (str_starts_with($input, '/')) {
                $result = $this->handleSlashCommand($input);
                if ($result === false) {
                    echo "Unknown command: {$input}\nType /help for available commands.\n";
                } else {
                    echo $result;
                }
                continue;
            }

            if ($this->handleCommand($input)) break;

            $this->addMessage('user', $input);

            // Minimal "thinking" indicator that is erased as soon as output
            // arrives, so a plain reply just appears under the prompt.
            echo "\n\033[2m  thinking…\033[0m";
            $cleared = false;
            $this->agenticLoop(function($chunk) use (&$cleared) {
                if (!$cleared) { echo "\r\033[K"; $cleared = true; }
                echo $chunk;
            });
            if (!$cleared) echo "\r\033[K";
            echo "\n";

            // Quietly note any files that were changed this turn.
            $edited = $GLOBALS['editedFiles'] ?? [];
            if (!empty($edited)) {
                echo "\033[2m  ✎ " . implode(', ', array_unique($edited)) . "\033[0m\n";
                $GLOBALS['editedFiles'] = [];
            }

            if (count($this->messages) > 20) {
                $this->compactMessages();
            }
            $this->save();
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
        Permission::setInteractive(false); // one-shot: can't prompt, so don't block

        $cmdResult = $this->handleSlashCommand($prompt);
        if ($cmdResult !== false) {
            echo $cmdResult;
            return '';
        }

        $this->addMessage('user', $prompt);
        echo "Thinking...\n";
        $final = $this->agenticLoop(null);
        echo "\n" . $final . "\n";
        $this->showContext();
        $this->save();
        return $final;
    }

    // Run the model, execute any tool calls, feed results back, and repeat
    // until the model produces an answer with no tool calls (or we hit the cap).
    // $emit is an optional callback for streaming output to the terminal.
    private function agenticLoop(?callable $emit): string {
        $maxIter = (int)Config::get('agents.maxIterations', 8);
        $final = '';
        for ($i = 0; $i < $maxIter; $i++) {
            $turn = $this->agent->chatTurn($this->getMessages());
            $response = $turn['content'];
            $calls = $turn['calls'];

            $clean = $this->agent->stripToolMarkup($response);
            // Store the assistant turn (cleaned, so markup doesn't pollute history).
            $this->addMessage('assistant', $clean !== '' ? $clean : $response);

            $toolResults = $this->agent->executeCalls($calls);
            if (empty($toolResults)) {
                if ($emit) $emit($clean !== '' ? $clean : trim($response));
                return $clean !== '' ? $clean : trim($response);
            }

            // Show the model's reasoning text (without the tool markup) before results.
            if ($clean !== '' && $emit) $emit($clean . "\n");
            foreach ($toolResults as $result) {
                $this->addMessage('tool', $result['content']);
                if ($emit) {
                    // Compact, dimmed tool line: "⏺ name  result-preview".
                    $name = $result['name'] ?? 'tool';
                    $preview = trim(preg_replace('/\s+/', ' ', (string)$result['content']));
                    if (preg_match('/^FILE_(WRITE|EDIT):(.+)/', $preview, $m)) {
                        $preview = ($m[1] === 'WRITE' ? 'wrote ' : 'edited ') . $m[2];
                    }
                    if (strlen($preview) > 100) $preview = substr($preview, 0, 100) . '…';
                    $emit("\033[2m  ⏺ {$name}  {$preview}\033[0m\n");
                }
            }
            $final = $clean;
        }
        if ($emit) $emit("\n(reached max tool iterations)\n");
        return $final;
    }
}

