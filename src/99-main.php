// CLI Entry Point
// Terminal daemon: the backend worker for a single terminal. The desktop app
// and `terminal attach` are clients that write input.txt and poll response.txt;
// this process consumes input.txt, runs the agent, and writes response.txt.
if ($argc >= 2 && $argv[1] === '__terminal-daemon__') {
    $id = $argv[2] ?? '';
    $model = $argv[3] ?? '';
    if ($id === '') { fwrite(STDERR, "terminal daemon: missing terminal id\n"); exit(1); }

    $config = Config::load();
    if ($model !== '') { Config::set('ollama.defaultModel', $model); }

    $home = getenv('HOME') ?: '/tmp';
    $dir = $home . '/.ollamadev/terminals/' . $id;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $inputFile = "$dir/input.txt";
    $responseFile = "$dir/response.txt";
    $logFile = "$dir/session.log";

    // Daemon can't prompt for permission; run tools without blocking.
    Permission::setMode('auto');
    Permission::setInteractive(false);

    $session = new Session($config);
    @file_put_contents($logFile, "[terminal-daemon $id ready] model=" . $session->getModel() . "\n", FILE_APPEND);

    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
        pcntl_signal(SIGINT, function() use (&$running) { $running = false; });
    }

    while ($running) {
        if (file_exists($inputFile)) {
            $input = trim((string)file_get_contents($inputFile));
            @unlink($inputFile);
            if ($input === '__STOP__' || $input === 'exit' || $input === 'quit') break;
            if ($input !== '') {
                @file_put_contents($logFile, "\n> $input\n", FILE_APPEND);
                // Capture the agent's console output instead of printing it.
                ob_start();
                $final = $session->runSingle($input);
                $printed = ob_get_clean();
                $out = trim($final);
                if ($out === '') {
                    // Fall back to printed output, stripped of console chatter
                    // (spinner lines and the trailing cwd/context line).
                    $lines = preg_split('/\R/', (string)$printed);
                    $kept = [];
                    foreach ($lines as $ln) {
                        $t = trim($ln);
                        if ($t === '' || str_starts_with($t, '📁') || str_ends_with($t, '...') || str_starts_with($t, '✏️')) continue;
                        $kept[] = $ln;
                    }
                    $out = trim(implode("\n", $kept));
                }
                if ($out === '') $out = '(no response)';
                file_put_contents($responseFile, $out);
                @file_put_contents($logFile, $out . "\n", FILE_APPEND);
            }
        }
        usleep(200000);
    }
    @file_put_contents($logFile, "[terminal-daemon $id stopped]\n", FILE_APPEND);
    exit(0);
}

// Real PTY daemon: runs an interactive shell in a pseudo-terminal (via the
// `script` utility) and bridges it to the desktop through two files - pty-in
// (keystrokes appended by the UI) and pty-out (raw terminal output, incl. ANSI).
if ($argc >= 2 && $argv[1] === '__pty-daemon__') {
    $id = $argv[2] ?? '';
    $startCwd = $argv[3] ?? getcwd();
    if ($id === '') { fwrite(STDERR, "pty daemon: missing terminal id\n"); exit(1); }

    $home = getenv('HOME') ?: '/tmp';
    $dir = "$home/.ollamadev/terminals/$id";
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $inFile = "$dir/pty-in";
    $outFile = "$dir/pty-out";
    file_put_contents($inFile, '');   // fresh input queue per launch
    file_put_contents($outFile, '');  // fresh output buffer

    // Shell integration (OSC 133) so we can capture command blocks: each
    // command's text (via $BASH_COMMAND), exit code, and timing.
    $rc = "$dir/od_rc.sh";
    file_put_contents($rc,
        "[ -f ~/.bashrc ] && source ~/.bashrc\n" .
        "__od_pc(){ local e=\$?; printf '\\033]133;D;%s\\007' \"\$e\"; }\n" .
        "PROMPT_COMMAND=\"__od_pc\${PROMPT_COMMAND:+; \$PROMPT_COMMAND}\"\n" .
        "__od_pre(){ case \"\$BASH_COMMAND\" in __od_*) return;; esac; printf '\\033]133;C;%s\\007' \"\$BASH_COMMAND\"; }\n" .
        "trap '__od_pre' DEBUG\n"
    );
    // Set a sane default window size, then cd into the project and exec bash
    // with our integration rcfile.
    $inner = 'stty rows 32 cols 120 2>/dev/null; cd ' . escapeshellarg($startCwd)
        . '; exec bash --rcfile ' . escapeshellarg($rc) . ' -i';
    // Linux util-linux `script`; allocates a real PTY for the shell.
    $cmd = 'script -qfc ' . escapeshellarg($inner) . ' /dev/null';
    $descr = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $proc = @proc_open($cmd, $descr, $pipes);
    if (!is_resource($proc)) { fwrite(STDERR, "pty: failed to start shell\n"); exit(1); }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $inOffset = 0;
    $running = true;
    $sizeFile = "$dir/pty-size";
    $lastSize = '';
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
        pcntl_signal(SIGINT, function() use (&$running) { $running = false; });
    }
    $out = fopen($outFile, 'ab');
    $blocks = [];        // command blocks parsed from OSC 133
    $parseBuf = '';
    $blocksFile = "$dir/blocks.json";

    $parseBlocks = function(string $add) use (&$parseBuf, &$blocks, $blocksFile) {
        $parseBuf .= $add;
        while (preg_match('/\x1b\]133;([CD]);([^\x07]*)\x07/', $parseBuf, $mm, PREG_OFFSET_CAPTURE)) {
            $type = $mm[1][0];
            $payload = $mm[2][0];
            $parseBuf = substr($parseBuf, $mm[0][1] + strlen($mm[0][0]));
            if ($type === 'C') {
                $blocks[] = ['command' => $payload, 'startedAt' => time(), 'exitCode' => null, 'endedAt' => null];
            } else {
                for ($k = count($blocks) - 1; $k >= 0; $k--) {
                    if ($blocks[$k]['exitCode'] === null) { $blocks[$k]['exitCode'] = (int)$payload; $blocks[$k]['endedAt'] = time(); break; }
                }
            }
            @file_put_contents($blocksFile, json_encode(array_slice($blocks, -200)));
        }
        if (strlen($parseBuf) > 8192) $parseBuf = substr($parseBuf, -4096);
    };

    while ($running) {
        // Honor resize requests by setting the shell's pts window size, which
        // makes the kernel deliver SIGWINCH to the shell.
        clearstatcache(true, $sizeFile);
        if (is_file($sizeFile)) {
            $sz = trim((string)@file_get_contents($sizeFile));
            if ($sz !== '' && $sz !== $lastSize && preg_match('/^(\d+)x(\d+)$/', $sz, $sm)) {
                $lastSize = $sz;
                // Walk descendants of our proc to find the process whose stdin is
                // the pts (the interactive shell), then resize that pts.
                $sp = (int)(proc_get_status($proc)['pid'] ?? 0);
                $pts = null;
                $queue = [$sp];
                while ($queue) {
                    $p = (int)array_shift($queue);
                    $link = @readlink("/proc/$p/fd/0");
                    if ($link && strpos($link, '/dev/pts/') !== false) { $pts = $link; break; }
                    foreach (preg_split('/\s+/', trim((string)@shell_exec("pgrep -P $p 2>/dev/null"))) as $c) {
                        if ($c !== '') $queue[] = (int)$c;
                    }
                }
                if ($pts) {
                    @shell_exec('stty -F ' . escapeshellarg($pts) . ' rows ' . (int)$sm[2] . ' cols ' . (int)$sm[1] . ' 2>/dev/null');
                }
            }
        }
        $chunk = fread($pipes[1], 65536);
        if ($chunk !== '' && $chunk !== false) { fwrite($out, $chunk); fflush($out); $parseBlocks($chunk); }
        $errc = fread($pipes[2], 65536);
        if ($errc !== '' && $errc !== false) { fwrite($out, $errc); fflush($out); $parseBlocks($errc); }

        clearstatcache(true, $inFile);
        $size = @filesize($inFile);
        if ($size !== false && $size > $inOffset) {
            $fh = fopen($inFile, 'rb');
            fseek($fh, $inOffset);
            $data = fread($fh, $size - $inOffset);
            fclose($fh);
            if ($data !== '' && $data !== false) { fwrite($pipes[0], $data); fflush($pipes[0]); }
            $inOffset = $size;
        }

        $st = proc_get_status($proc);
        if (!$st['running']) break;
        usleep(15000); // ~66 Hz
    }
    fclose($out);
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    @proc_terminate($proc);
    @proc_close($proc);
    exit(0);
}

// Agent-in-PTY: run the agent against a prompt, but route its shell/bash tool
// calls into an existing live PTY terminal so the user watches commands run.
if ($argc >= 2 && $argv[1] === '__agent-in-pty__') {
    $id = $argv[2] ?? '';
    $prompt = $argv[3] ?? '';
    if ($id === '' || $prompt === '') { fwrite(STDERR, "agent-in-pty: need <id> <prompt>\n"); exit(1); }

    $config = Config::load();
    Permission::setMode('auto');
    Permission::setInteractive(false);

    $home = getenv('HOME') ?: '/tmp';
    $inFile = "$home/.ollamadev/terminals/$id/pty-in";
    if (!is_file($inFile)) { fwrite(STDERR, "agent-in-pty: terminal $id not running\n"); exit(1); }

    // Announce the task in the terminal (cyan), then run the agent.
    @file_put_contents($inFile, "printf '\\n\\033[36m[agent ▸ %s]\\033[0m\\n' " . escapeshellarg($prompt) . "\n", FILE_APPEND | LOCK_EX);

    $GLOBALS['ptyBridge'] = new PtyBridge($id);
    $session = new Session($config);
    ob_start();
    $final = $session->runSingle($prompt);
    ob_end_clean();

    if (trim((string)$final) !== '') {
        @file_put_contents($inFile, "printf '\\033[32m[agent]\\033[0m %s\\n' " . escapeshellarg($final) . "\n", FILE_APPEND | LOCK_EX);
    }
    exit(0);
}

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
$flags = ['model' => null, 'continue' => false, 'resume' => false, 'session' => null, 'fork' => false, 'prompt' => null, 'agent' => null, 'pure' => false, 'port' => 0, 'hostname' => '127.0.0.1', 'mdns' => false, 'help' => false, 'version' => false, 'cwd' => null, 'permission' => null];
$positional = [];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '-m' || $a === '--model') { $flags['model'] = $argv[++$i] ?? null; }
    elseif ($a === '-c' || $a === '--continue') { $flags['continue'] = true; }
    elseif ($a === '-r' || $a === '--resume') { $flags['resume'] = true; }
    elseif ($a === '-s' || $a === '--session') { $flags['session'] = $argv[++$i] ?? null; }
    elseif ($a === '--fork') { $flags['fork'] = true; }
    elseif ($a === '-p' || $a === '--prompt') { $flags['prompt'] = $argv[++$i] ?? null; }
    elseif ($a === '--agent') { $flags['agent'] = $argv[++$i] ?? null; }
    elseif ($a === '--pure') { $flags['pure'] = true; }
    elseif ($a === '--readonly' || $a === '--plan') { $flags['permission'] = 'readonly'; }
    elseif ($a === '--auto' || $a === '--yolo') { $flags['permission'] = 'auto'; }
    elseif ($a === '--ask') { $flags['permission'] = 'ask'; }
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
    // Machine-readable status - single source for the desktop app.
    if (in_array('--json', $argv, true)) {
        $connected = $client->checkConnection();
        echo json_encode([
            'connected' => $connected,
            'models' => $connected ? $client->listModelsDetailed() : [],
        ]);
        exit(0);
    }
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
if (!empty($flags['model'])) {
    $config['ollama']['defaultModel'] = $flags['model'];
    Config::set('ollama.defaultModel', $flags['model']);
    // Warn (but don't fail) if the requested model isn't pulled locally.
    $installed = (new OllamaClient($config['ollama']['host'] ?? 'http://localhost:11434'))->listModels();
    if (!empty($installed) && !in_array($flags['model'], $installed, true)) {
        fwrite(STDERR, "⚠️  Model '{$flags['model']}' is not installed. Pull it with: ollama pull {$flags['model']}\n");
        fwrite(STDERR, "   Falling back to an available model.\n");
    }
}

// Apply permission mode from flags (--readonly/--auto/--ask)
if (!empty($flags['permission'])) { $config['permissions']['mode'] = $flags['permission']; Config::set('permissions.mode', $flags['permission']); }

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
  ollamadev lsp        LSP server for IDEs (AI completions, linter diagnostics)
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

  LSP for IDE (AI completions + linter diagnostics):
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

// Interactive resume picker: `ollamadev resume` or `-r`/`--resume`.
if ($flags['resume'] || $cmd === 'resume') {
    Session::pickAndResume($config);
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
    $current = OLLAMADEV_VERSION;
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: OllamaDev/" . OLLAMADEV_VERSION . "\r\n"]]);
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
