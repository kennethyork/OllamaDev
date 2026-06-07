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

// Hooks editor CLI Command — view/add/remove shell hooks (persisted to config.json).
if ($argc >= 2 && $argv[1] === 'hooks') {
    // JSON surface for the desktop/web Hooks panel.
    if (in_array('--json', $argv, true)) {
        echo json_encode(['hooks' => Hooks::configuredData(), 'events' => Hooks::knownEvents()]) . "\n";
        exit(0);
    }
    echo rtrim(Hooks::editorCommand(array_slice($argv, 2))) . "\n";
    exit(0);
}

// Custom agent types CLI Command — list/show file-defined subagent personas.
if ($argc >= 2 && $argv[1] === 'agents') {
    $sub = $argv[2] ?? 'list';
    if ($sub === 'show' && isset($argv[3])) {
        $a = AgentDefs::get($argv[3]);
        if (!$a) { echo "No such agent: {$argv[3]}\n"; exit(1); }
        echo "Agent: {$a['name']}\n  desc:       " . ($a['description'] ?: '(none)') . "\n";
        echo "  model:      " . ($a['model'] ?: '(session model)') . "\n  permission: " . ($a['permission'] ?: '(default)') . "\n";
        echo "  tools:      " . (!empty($a['tools']) ? implode(', ', $a['tools']) : '(all)') . "\n  prompt:\n    " . str_replace("\n", "\n    ", $a['prompt']) . "\n";
        exit(0);
    }
    if (in_array('--json', $argv, true)) { echo json_encode(['agents' => array_values(AgentDefs::all())]) . "\n"; exit(0); }
    echo rtrim(AgentDefs::render()) . "\n"; exit(0);
}

// MCP CLI Command
if ($argc >= 2 && $argv[1] === 'mcp') {
    // Server mode: expose THIS CLI's tool registry to any MCP client over stdio.
    if (($argv[2] ?? '') === 'serve') { exit(McpServer::serve()); }
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

// Workspace CLI Command — a named project list shared with the desktop/web app.
// Stored globally so a workspace added anywhere shows up everywhere.
if ($argc >= 2 && ($argv[1] === 'workspace' || $argv[1] === 'ws')) {
    $action = $argv[2] ?? 'list';
    if ($action === 'list' || $action === '') {
        $d = Workspaces::load();
        if (empty($d['workspaces'])) { echo "No workspaces yet. Add one:\n  ollamadev workspace add [path] [name]\n"; exit(0); }
        echo "Workspaces:\n";
        foreach ($d['workspaces'] as $w) {
            $mark = (($w['id'] ?? '') === ($d['active'] ?? null)) ? '*' : ' ';
            $when = !empty($w['lastOpened']) ? date('Y-m-d H:i', strtotime($w['lastOpened'])) : '';
            echo sprintf("  %s %-20s %s  %s\n", $mark, (string)($w['name'] ?? ''), (string)($w['path'] ?? ''), $when);
        }
        echo "\n  * = active.  Jump to one with:  cd \$(ollamadev workspace open <name>)\n";
    } elseif ($action === 'add') {
        $w = Workspaces::add($argv[3] ?? '', $argv[4] ?? '');
        echo "Added workspace '{$w['name']}' → {$w['path']}\n";
    } elseif ($action === 'remove' || $action === 'rm') {
        $key = $argv[3] ?? '';
        if ($key === '') { echo "Usage: ollamadev workspace remove <name|path|id>\n"; exit(1); }
        echo Workspaces::remove($key) ? "Removed: $key\n" : "Workspace not found: $key\n";
    } elseif ($action === 'open') {
        $key = $argv[3] ?? '';
        $w = Workspaces::find($key);
        if ($w === null) { fwrite(STDERR, "Workspace not found: $key\n"); exit(1); }
        Workspaces::touch($w['id']);
        echo $w['path'] . "\n";   // prints the path so `cd $(ollamadev workspace open foo)` works
    } else {
        echo "Usage: ollamadev workspace [list | add [path] [name] | remove <name> | open <name>]\n";
    }
    exit(0);
}

// Serve/Web Command
if ($argc >= 2 && $argv[1] === 'serve') {
    $config = Config::load();
    $port = $argv[2] ?? 41434;
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
$flags = ['model' => null, 'continue' => false, 'resume' => false, 'session' => null, 'fork' => false, 'prompt' => null, 'agent' => null, 'pure' => false, 'port' => 0, 'hostname' => '127.0.0.1', 'mdns' => false, 'help' => false, 'version' => false, 'cwd' => null, 'permission' => null, 'max' => null, 'directorModel' => null, 'coderModel' => null, 'auditorModel' => null, 'researcherModel' => null, 'focus' => null];
$positional = [];
$cmdIdx = null; // original-argv index of the first positional (the subcommand)
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '-m' || $a === '--model') { $flags['model'] = $argv[++$i] ?? null; }
    elseif ($a === '-c' || $a === '--continue') { $flags['continue'] = true; }
    elseif ($a === '-r' || $a === '--resume') { $flags['resume'] = true; }
    elseif ($a === '--max') { $flags['max'] = (int)($argv[++$i] ?? 0); }
    elseif ($a === '--director-model') { $flags['directorModel'] = $argv[++$i] ?? null; }
    elseif ($a === '--coder-model') { $flags['coderModel'] = $argv[++$i] ?? null; }
    elseif ($a === '--auditor-model') { $flags['auditorModel'] = $argv[++$i] ?? null; }
    elseif ($a === '--researcher-model') { $flags['researcherModel'] = $argv[++$i] ?? null; }
    elseif ($a === '--focus') { $flags['focus'] = $argv[++$i] ?? null; }
    elseif ($a === '--desc') { $flags['desc'] = $argv[++$i] ?? null; }
    elseif ($a === '--hosts') { $flags['hosts'] = $argv[++$i] ?? null; }
    elseif ($a === '--amplify') {
        // Bare --amplify means the default panel size; --amplify N sets it.
        if (isset($argv[$i + 1]) && ctype_digit((string)$argv[$i + 1])) $flags['amplify'] = (int)$argv[++$i];
        else $flags['amplify'] = 3;
    }
    elseif ($a === '--offline' || $a === '--air-gapped') { $flags['offline'] = true; }
    elseif ($a === '--interval') { $flags['interval'] = (int)($argv[++$i] ?? 2); }
    elseif ($a === '--once') { $flags['once'] = true; }
    elseif ($a === '--new') { $flags['new'] = true; }
    elseif ($a === '--pack') { $flags['pack'] = $argv[++$i] ?? null; }
    elseif ($a === '--num-ctx') { $flags['numCtx'] = (int)($argv[++$i] ?? 0); }
    elseif ($a === '--lmstudio') { $flags['lmstudio'] = true; }
    elseif ($a === '--host') { $flags['host'] = $argv[++$i] ?? null; }
    elseif ($a === '--panes') { $flags['panes'] = true; }
    elseif ($a === '--run-id') { $flags['runId'] = $argv[++$i] ?? null; }
    elseif ($a === '-s' || $a === '--session') { $flags['session'] = $argv[++$i] ?? null; }
    elseif ($a === '--fork') { $flags['fork'] = true; }
    elseif ($a === '-p' || $a === '--prompt') { $flags['prompt'] = $argv[++$i] ?? null; }
    elseif ($a === '--agent') { $flags['agent'] = $argv[++$i] ?? null; }
    elseif ($a === '--pure') { $flags['pure'] = true; }
    elseif ($a === '--readonly') { $flags['permission'] = 'readonly'; }
    elseif ($a === '--plan') { $flags['permission'] = 'plan'; }
    elseif ($a === '--auto' || $a === '--yolo') { $flags['permission'] = 'auto'; }
    elseif ($a === '--ask') { $flags['permission'] = 'ask'; }
    elseif ($a === '--port') { $flags['port'] = (int)($argv[++$i] ?? 0); }
    elseif ($a === '--hostname') { $flags['hostname'] = $argv[++$i] ?? '127.0.0.1'; }
    elseif ($a === '--mdns') { $flags['mdns'] = true; }
    elseif ($a === '--cwd') { $flags['cwd'] = $argv[++$i] ?? null; }
    elseif ($a === '-h' || $a === '--help') { $flags['help'] = true; }
    elseif ($a === '-v' || $a === '--version') { $flags['version'] = true; }
    elseif (!str_starts_with($a, '-')) { if ($cmdIdx === null) $cmdIdx = $i; $positional[] = $a; }
}

// Normalize argv so a global flag placed BEFORE the subcommand (e.g.
// `ollamadev --offline search "x"` or `-m qwen eval`) doesn't hide the command
// from the raw-$argv[1] dispatch below. Re-root argv at the first positional;
// the command's own trailing args/flags are preserved, and global flags were
// already captured into $flags above regardless of where they appeared.
if ($cmdIdx !== null && $cmdIdx > 1) {
    $argv = array_merge([$argv[0]], array_slice($argv, $cmdIdx));
    $argc = count($argv);
}

// Apply env overrides
if (empty($flags['model']) && getenv('OLLAMA_MODEL')) $flags['model'] = getenv('OLLAMA_MODEL');
if (empty($flags['model']) && getenv('MODEL')) $flags['model'] = getenv('MODEL');

// Provider override for this run: --host <url> (auto-detects backend) or --lmstudio
// (LM Studio's local OpenAI-compatible server). Both stay 100% local.
if (!empty($flags['host'])) ModelClient::$override = $flags['host'];
elseif (!empty($flags['lmstudio'])) ModelClient::$override = Config::get('lmstudio.host', 'http://localhost:1234/v1');

// Air-gapped mode (--offline / --air-gapped, OLLAMADEV_OFFLINE=1, or config
// offline:true): hard-block every network tool for the rest of this process.
$offlineMode = !empty($flags['offline']) || getenv('OLLAMADEV_OFFLINE') || Config::get('offline', false);
if ($offlineMode) Permission::setOffline(true);

// --num-ctx N: pin the context window to exactly N for this run (overrides auto).
if (!empty($flags['numCtx']) && $flags['numCtx'] > 0) {
    Config::set('ollama.contextWindow', (int)$flags['numCtx']);
    Config::set('ollama.maxContextWindow', max((int)$flags['numCtx'], (int)Config::get('ollama.maxContextWindow', 32768)));
    Config::set('ollama.autoContext', false);
}

// Attestation Command — audit + prove the air-gap posture.
if ($argc >= 2 && $argv[1] === 'attest') {
    echo Attest::render(in_array('--json', $argv, true));
    exit(0);
}

// Context Tuner — probe hardware + model, recommend a safe num_ctx.
if ($argc >= 2 && $argv[1] === 'context') {
    echo ContextTuner::report();
    exit(0);
}

// Transcribe — local speech-to-text via the configured engine (used by the
// desktop mic button, and usable directly: `ollamadev transcribe clip.wav`).
if ($argc >= 2 && $argv[1] === 'transcribe') {
    $arg = $argv[2] ?? '';
    if ($arg === '--enabled') { echo SttClient::available() ? "1\n" : "0\n"; exit(0); }
    if ($arg === '') { echo "Usage: ollamadev transcribe <audio-file>\nUses a local Whisper engine if installed (whisper / whisper.cpp / faster-whisper), or stt.host / stt.command from config. 100% local.\n"; exit(1); }
    if (!SttClient::available()) { echo "No STT engine found. Install one (open source): pip install -U openai-whisper — or set stt.host / stt.command in ~/.ollamadev/config.json (local only).\n"; exit(1); }
    $text = SttClient::transcribe($arg);
    if ($text === '') { echo "Transcription failed or empty.\n"; exit(1); }
    echo $text . "\n";
    exit(0);
}

// Voice (STT) settings + history — non-interactive surface shared by the CLI
// /voice command and the desktop/web mic UI (via the sttModel/sttHistory
// bindings). 100% local.
//   voice model [<size>]            get or set the Whisper model size
//   voice history [--json] [N]      list recent transcriptions
//   voice clear-history             wipe the history
//   voice status --json             engine/model/recorder/availability
if ($argc >= 2 && $argv[1] === 'voice') {
    $sub = $argv[2] ?? 'status';
    if ($sub === 'model') {
        $size = $argv[3] ?? '';
        if ($size !== '') SttClient::setModel($size);
        echo SttClient::model() . "\n"; exit(0);
    }
    if ($sub === 'sizes') { echo implode("\n", SttClient::modelSizes()) . "\n"; exit(0); }
    if ($sub === 'clear-history') { SttClient::clearHistory(); echo "cleared\n"; exit(0); }
    // Auto-provision the self-contained engine + model (no manual install).
    if ($sub === 'install' || $sub === 'download') {
        if (Permission::isOffline()) { echo "✗ air-gap mode blocks the download. Bring your own engine into ~/.ollamadev/stt.\n"; exit(1); }
        $size = $argv[3] ?? SttClient::model();
        $quiet = in_array('--quiet', $argv, true) || in_array('--json', $argv, true);
        if (SttClient::hasBundledEngine() && SttClient::ggmlModelFile($size) !== '' && !in_array('--force', $argv, true)) {
            echo $quiet ? "ok\n" : "✓ already installed (engine + {$size} model in ~/.ollamadev/stt).\n"; exit(0);
        }
        if (!$quiet) echo "Downloading whisper.cpp engine + {$size} model to ~/.ollamadev/stt (one-time, local after)…\n";
        $last = -1;
        $ok = SttClient::provision($quiet ? null : function ($label, $done, $total) use (&$last) {
            if ($total <= 0) return; $pct = (int) floor($done * 100 / $total); if ($pct === $last) return; $last = $pct;
            fprintf(STDERR, "\r  %s: %d%% (%.1f/%.1f MB)   ", $label, $pct, $done / 1048576, $total / 1048576);
        }, $size);
        if (!$quiet) fwrite(STDERR, "\n");
        echo $ok ? ($quiet ? "ok\n" : "✓ voice ready (local, offline).\n") : "✗ download failed (network?).\n";
        exit($ok ? 0 : 1);
    }
    if ($sub === 'history') {
        $json = in_array('--json', $argv, true);
        $n = 20; foreach (array_slice($argv, 3) as $a) { if (ctype_digit((string)$a)) { $n = (int)$a; break; } }
        $rows = SttClient::history($n);
        if ($json) { echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"; exit(0); }
        if (!$rows) { echo "No voice history yet.\n"; exit(0); }
        foreach ($rows as $r) echo date('Y-m-d H:i', (int)($r['ts'] ?? 0)) . "  [" . ($r['model'] ?? '?') . "]  " . ($r['text'] ?? '') . "\n";
        exit(0);
    }
    // status (default)
    if (in_array('--json', $argv, true)) {
        echo json_encode([
            'engine'    => SttClient::detectedEngine() ?: (SttClient::enabled() ? 'configured' : ''),
            'model'     => SttClient::model(),
            'sizes'     => SttClient::modelSizes(),
            'recorder'  => SttClient::canRecord(),
            'available' => SttClient::available(),
        ], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
    echo "engine: " . (SttClient::detectedEngine() ?: 'none') . " · model: " . SttClient::model()
       . " · recorder: " . (SttClient::canRecord() ? 'yes' : 'no') . " · available: " . (SttClient::available() ? 'yes' : 'no') . "\n";
    exit(0);
}

// Web search — run the search tool directly from the CLI. Honors air-gap mode
// (--offline / OLLAMADEV_OFFLINE / config offline) like the agent tool does.
if ($argc >= 2 && $argv[1] === 'search') {
    $args = array_slice($argv, 2);
    $query = ''; $limit = 5; $provider = '';
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if ($a === '--limit') { $limit = (int)($args[++$i] ?? 5); }
        elseif ($a === '--provider') { $provider = $args[++$i] ?? ''; }
        elseif (!str_starts_with($a, '-')) { $query .= ($query === '' ? '' : ' ') . $a; }
    }
    if (trim($query) === '') { echo "Usage: ollamadev search \"<query>\" [--limit N] [--provider duckduckgo|searxng|brave]\n"; exit(1); }
    if (Permission::isOffline()) { echo "\033[33m✈️  Offline/air-gapped mode — web search is disabled.\033[0m\n"; exit(1); }
    if (!Config::get('search.enabled', true)) { echo "\033[33m🔍 Web search is turned off (search.enabled:false).\033[0m Enable it: ollamadev config set search.enabled true\n"; exit(1); }
    $sp = ['query' => $query, 'limit' => $limit];
    if ($provider !== '') $sp['provider'] = $provider;
    echo Tools::run('search', $sp) . "\n";
    exit(0);
}

// Config — inspect or persist settings in ~/.ollamadev/config.json.
//   ollamadev config get [key]      ollamadev config set <key> <value>
if ($argc >= 2 && $argv[1] === 'config') {
    $sub = $argv[2] ?? 'list';
    if ($sub === 'set') {
        $key = $argv[3] ?? ''; $raw = $argv[4] ?? '';
        if ($key === '') { echo "Usage: ollamadev config set <key> <value>\n"; exit(1); }
        if ($raw === 'true') $val = true;
        elseif ($raw === 'false') $val = false;
        elseif ($raw === 'null') $val = null;
        elseif (is_numeric($raw)) $val = $raw + 0;
        else $val = $raw;
        Config::persist($key, $val);
        echo "set $key = " . json_encode($val) . "\n";
        exit(0);
    }
    if ($sub === 'get') {
        $key = $argv[3] ?? '';
        if ($key === '') { echo json_encode(Config::load(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; exit(0); }
        echo json_encode(Config::get($key)) . "\n";
        exit(0);
    }
    echo json_encode(Config::load(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// AI commit message — generate a Conventional Commit message from the staged diff.
if ($argc >= 2 && $argv[1] === 'commit') {
    if (!GitFlow::isRepo()) { echo "Not a git repository.\n"; exit(1); }
    $all = in_array('--all', $argv, true) || in_array('-a', $argv, true);
    $msg = '';
    for ($i = 2; $i < $argc; $i++) if ($argv[$i] === '-m' || $argv[$i] === '--message') $msg = $argv[$i + 1] ?? '';
    if ($all) GitFlow::sh('git add -A 2>&1');
    $diff = GitFlow::sh('git diff --cached 2>/dev/null');
    if ($diff === '') { echo "Nothing staged. Stage changes (`git add`) or pass -a/--all.\n"; exit(1); }
    if ($msg === '') {
        echo "Generating commit message…\n";
        $msg = GitFlow::message($diff);
        if ($msg === '') { echo "Couldn't generate a message. Pass one with -m \"...\".\n"; exit(1); }
    }
    echo "\n\033[1m" . $msg . "\033[0m\n\n";
    if (posix_isatty(STDIN)) {
        echo "Commit with this message? [Y/n] ";
        $a = strtolower(trim((string)fgets(STDIN)));
        if (!($a === '' || $a[0] === 'y')) { echo "Aborted (changes stay staged).\n"; exit(1); }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'odvc'); file_put_contents($tmp, $msg);
    echo GitFlow::sh('git commit -F ' . escapeshellarg($tmp) . ' 2>&1') . "\n";
    @unlink($tmp);
    exit(0);
}

// PR workflow — open a PR from this branch, or review a PR. Reaches GitHub via gh.
if ($argc >= 2 && $argv[1] === 'pr') {
    $sub = $argv[2] ?? '';
    if (Permission::isOffline()) { echo "\033[33m✈️  Offline mode — PR commands reach GitHub and are blocked.\033[0m\n"; exit(1); }
    if (GitFlow::gh() === '') { echo "Needs the GitHub CLI (gh): https://cli.github.com/  (then `gh auth login`).\n"; exit(1); }
    if (!GitFlow::isRepo()) { echo "Not a git repository.\n"; exit(1); }
    if ($sub === 'create') {
        $base = 'main';
        for ($i = 3; $i < $argc; $i++) if ($argv[$i] === '--base') $base = $argv[$i + 1] ?? 'main';
        $branch = GitFlow::sh('git rev-parse --abbrev-ref HEAD');
        if ($branch === $base) { echo "You're on '$base'. Create a feature branch first.\n"; exit(1); }
        echo "Pushing $branch…\n" . GitFlow::sh('git push -u origin ' . escapeshellarg($branch) . ' 2>&1') . "\n";
        $commits = GitFlow::sh('git log --pretty=format:%s ' . escapeshellarg($base . '..' . $branch));
        $diff = GitFlow::sh('git diff ' . escapeshellarg($base . '...' . $branch));
        if ($diff === '') { echo "No diff vs $base — nothing to open a PR for.\n"; exit(1); }
        echo "Drafting PR title/body…\n";
        [$title, $body] = GitFlow::prText($commits, $diff);
        $tmp = tempnam(sys_get_temp_dir(), 'odvpr'); file_put_contents($tmp, $body);
        $out = GitFlow::sh('gh pr create --base ' . escapeshellarg($base) . ' --head ' . escapeshellarg($branch) .
            ' --title ' . escapeshellarg($title) . ' --body-file ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        echo "\n\033[1m$title\033[0m\n" . $out . "\n";
        exit(0);
    }
    if ($sub === 'review') {
        $n = $argv[3] ?? '';
        if ($n === '' || !ctype_digit((string)$n)) { echo "Usage: ollamadev pr review <number> [--comment]\n"; exit(1); }
        $diff = GitFlow::sh('gh pr diff ' . escapeshellarg($n) . ' 2>/dev/null');
        if ($diff === '') { echo "Couldn't fetch PR #$n diff (is the number right and `gh` authed?).\n"; exit(1); }
        echo "Reviewing PR #$n…\n\n";
        $review = GitFlow::review($diff);
        echo $review . "\n";
        if (in_array('--comment', $argv, true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'odvrv'); file_put_contents($tmp, "🤖 OllamaDev local review\n\n" . $review);
            echo "\n" . GitFlow::sh('gh pr comment ' . escapeshellarg($n) . ' --body-file ' . escapeshellarg($tmp) . ' 2>&1') . "\n";
            @unlink($tmp);
        }
        exit(0);
    }
    echo "Usage:\n  ollamadev pr create [--base main]\n  ollamadev pr review <number> [--comment]\n";
    exit(0);
}

// Run the project's tests (auto-detected). `verify` adds a fix-until-green loop.
if ($argc >= 2 && ($argv[1] === 'test' || $argv[1] === 'verify')) {
    $det = Verify::detect();
    if (!$det) { echo "No test command detected (looked for npm/phpunit/composer/go/cargo/pytest/make).\n  Set one: ollamadev config set test.command \"<cmd>\"\n"; exit(1); }
    if ($argv[1] === 'test') {
        echo "Running tests: \033[36m{$det['cmd']}\033[0m\n\n";
        $res = Verify::run($det);
        echo $res['output'] . "\n";
        echo $res['exit'] === 0 ? "\n\033[32m✓ tests passed\033[0m\n" : "\n\033[31m✗ tests failed (exit {$res['exit']})\033[0m\n";
        exit($res['exit'] === 0 ? 0 : 1);
    }
    // verify: run, and on failure let the agent fix and re-run until green.
    $max = 4;
    for ($i = 2; $i < $argc; $i++) if ($argv[$i] === '--max') $max = max(1, (int)($argv[$i + 1] ?? 4));
    echo "\033[1mVerify\033[0m \033[2m[{$det['cmd']}] · up to {$max} fix attempt(s)\033[0m\n";
    exit(Verify::fixLoop($det, $max));
}

// Semantic code index — build/search a local embeddings index over this repo.
if ($argc >= 2 && $argv[1] === 'index') {
    $sub = $argv[2] ?? 'status';
    if ($sub === 'build' || $sub === 'rebuild') {
        echo "Building semantic index (model " . CodeIndex::model() . ")…\n";
        $last = '';
        $res = CodeIndex::build(function ($file, $n) use (&$last) {
            if ($file !== $last) { $last = $file; echo "\033[2m  · $file\033[0m\n"; }
        });
        if (!empty($res['error'])) {
            echo "\033[31mIndex failed.\033[0m ";
            echo $res['error'] === 'embed_failed'
                ? "Embedding model not available — run: ollama pull " . CodeIndex::model() . "\n"
                : "error: " . $res['error'] . "\n";
            exit(1);
        }
        $sk = !empty($res['skipped']) ? " (skipped {$res['skipped']})" : '';
        echo "\033[32m✓ indexed {$res['files']} files → {$res['chunks']} chunks$sk.\033[0m  Search: ollamadev code-search \"<query>\"\n";
        exit(0);
    }
    if ($sub === 'clear') { echo CodeIndex::clear() ? "Index cleared.\n" : "Nothing to clear.\n"; exit(0); }
    $st = CodeIndex::status();
    if (in_array('--json', $argv, true)) { echo json_encode($st) . "\n"; exit(0); }
    if (empty($st['exists'])) { echo "No index yet. Build one: ollamadev index build\n"; exit(0); }
    echo "Semantic index:\n  model:  {$st['model']}\n  built:  {$st['built']}\n  files:  {$st['files']}\n  chunks: {$st['chunks']} (dim {$st['dim']})\n";
    exit(0);
}

// Semantic code search — query the local code index by meaning.
if ($argc >= 2 && $argv[1] === 'code-search') {
    $args = array_slice($argv, 2);
    $query = ''; $limit = 8; $asJson = in_array('--json', $args, true);
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if ($a === '--limit') { $limit = (int)($args[++$i] ?? 8); }
        elseif ($a === '--json') { continue; }
        elseif (!str_starts_with($a, '-')) { $query .= ($query === '' ? '' : ' ') . $a; }
    }
    if (trim($query) === '') { echo "Usage: ollamadev code-search \"<query>\" [--limit N] [--json]\n"; exit(1); }
    if ($asJson) { echo json_encode(CodeIndex::search($query, $limit)) . "\n"; exit(0); }
    echo Tools::run('code_search', ['query' => $query, 'limit' => $limit]) . "\n";
    exit(0);
}

// Tool — directly invoke a single agent tool, bypassing the model. For testing
// and debugging the tool layer. Usage: ollamadev tool <name> ['<json-args>']
// (or `ollamadev tool list` to see every registered tool).
if ($argc >= 2 && $argv[1] === 'tool') {
    $name = $argv[2] ?? '';
    if ($name === '' || $name === 'list') {
        $all = Tools::all(); sort($all);
        if (in_array('--json', $argv, true)) { echo json_encode($all) . "\n"; exit(0); }
        echo "Registered tools (" . count($all) . "):\n  " . implode("\n  ", $all) . "\n\n";
        echo "Invoke one:  ollamadev tool <name> '{\"file_path\":\"x\"}'\n";
        exit($name === '' ? 1 : 0);
    }
    if (!Tools::find($name)) { echo "No such tool: $name  (see: ollamadev tool list)\n"; exit(1); }
    $params = json_decode((string)($argv[3] ?? '{}'), true);
    if (!is_array($params)) { echo "Invalid JSON args: " . ($argv[3] ?? '') . "\n"; exit(1); }
    Permission::setInteractive(false);
    Permission::autoAllow(); // an explicit `tool` invoke is an explicit allow
    echo Tools::run($name, $params) . "\n";
    exit(0);
}

// Diff — the working-tree diff for review (tracked changes vs HEAD + untracked).
// Local-only; never gated. Powers the desktop/web read-only diff-review panel.
if ($argc >= 2 && $argv[1] === 'diff') {
    if (!GitFlow::isRepo()) {
        if (in_array('--json', $argv, true)) { echo json_encode(['repo' => false, 'diff' => '']) . "\n"; exit(0); }
        echo "Not a git repository.\n"; exit(1);
    }
    $d = GitFlow::workingDiff();
    if (in_array('--json', $argv, true)) { echo json_encode(['repo' => true, 'diff' => $d]) . "\n"; exit(0); }
    echo $d === '' ? "No working-tree changes.\n" : $d . "\n";
    exit(0);
}

// Eval — measure the agent's success rate on a fixed task suite with the current
// model. Each task runs isolated in a temp dir; a deterministic check scores it.
if ($argc >= 2 && $argv[1] === 'eval') {
    $config = Config::load();
    $args = array_slice($argv, 2);
    $sub = (!empty($args) && !str_starts_with($args[0], '-')) ? $args[0] : 'run';
    $only = ''; $model = ''; $asJson = in_array('--json', $args, true); $keep = in_array('--keep', $args, true);
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if ($a === '--only') $only = (string)($args[++$i] ?? '');
        elseif ($a === '--model' || $a === '-m') $model = (string)($args[++$i] ?? '');
    }
    $tasks = Evals::suite($only);
    if ($sub === 'list') {
        if ($asJson) { echo json_encode(array_map(fn($t) => $t['name'], $tasks)) . "\n"; exit(0); }
        echo "Eval tasks (" . count($tasks) . "):\n";
        foreach ($tasks as $t) echo "  \033[1m{$t['name']}\033[0m \033[2m— " . substr((string)$t['prompt'], 0, 70) . "…\033[0m\n";
        echo "\n\033[2mRun all: ollamadev eval · one: ollamadev eval --only <name> · add your own JSON in ./evals/\033[0m\n";
        exit(0);
    }
    if (empty($tasks)) { echo "No eval tasks" . ($only !== '' ? " matching \"$only\"" : '') . ".\n"; exit(1); }

    $client = ModelClient::default();
    if (!$client->checkConnection()) { echo "✗ can't reach the model backend (" . ModelClient::activeLabel() . "). Start it, then retry.\n"; exit(1); }
    if ($model !== '') {
        $resolved = Models::match($model, $client->listModels());
        // Set it on the live config cache so the Agent (which reads Config::get,
        // not the passed $config array) actually uses the requested model.
        Config::set('ollama.defaultModel', $resolved !== '' ? $resolved : $model);
    }
    $config['_eval_keep'] = $keep;
    $usedModel = (string)Config::get('ollama.defaultModel', '');

    if (!$asJson) echo "\033[1mEval\033[0m \033[2m" . count($tasks) . " task(s) · model " . ($usedModel !== '' ? $usedModel : '(auto)') . ($keep ? ' · keeping failed temp dirs' : '') . "\033[0m\n\n";
    $results = []; $pass = 0;
    foreach ($tasks as $idx => $t) {
        if (!$asJson) { echo sprintf("  %-16s ", $t['name']); if (function_exists('fflush')) @fflush(STDOUT); }
        $r = Evals::runOne($t, $config, (string)$idx);
        $results[] = $r; if ($r['pass']) $pass++;
        if (!$asJson) echo ($r['pass'] ? "\033[32m✓ pass\033[0m" : "\033[31m✗ fail\033[0m") . "  \033[2m{$r['ms']}ms — {$r['detail']}\033[0m\n";
    }
    $total = count($tasks); $rate = $total ? (int)round($pass * 100 / $total) : 0;
    if ($asJson) { echo json_encode(['model' => $usedModel, 'pass' => $pass, 'total' => $total, 'rate' => $rate, 'results' => $results]) . "\n"; exit($pass === $total ? 0 : 1); }
    $color = $rate >= 75 ? "\033[32m" : ($rate >= 40 ? "\033[33m" : "\033[31m");
    echo "\n{$color}Pass rate: \033[1m$pass/$total\033[0m{$color} ($rate%)\033[0m  \033[2mmodel: " . ($usedModel !== '' ? $usedModel : '(auto)') . "\033[0m\n";
    exit($pass === $total ? 0 : 1);
}

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
            COMPREPLY=($(compgen -W 'chat new list load resume pull init crew terminal git lsp models eval diff verify test commit pr help --help --version --model --prompt --continue --resume --dry-run -h -v' -- "${cur}"))
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
        'resume:Resume a recent session'
        'pull:Download a model from Ollama'
        'init:Generate OLLAMADEV.md project memory'
        'crew:Run the agent bench (Director/Coders/Auditor)'
        'terminal:Terminal multiplexer'
        'git:Git commands'
        'lsp:LSP server for IDEs'
        'models:List available models (presets, pull, chain)'
        'eval:Measure the agent pass rate on a fixed task suite'
        'diff:Show the working-tree diff for review'
        'help:Show help'
    )
    _describe 'command' commands
}

_ollamadev "$@"
ZSH;
    } elseif ($shell === 'fish') {
        echo <<<'FISH'
# OllamaDev Fish Shell Completion

complete -c ollamadev -n '__fish_use_subcommand' -a 'chat new list load resume pull init crew terminal git lsp models help' -d 'Command'
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

// Hidden render subcommand: `ollamadev render` reads markdown on stdin and
// writes the ANSI-rendered output. Deterministic and offline; used by tests.
if ($argc >= 2 && $argv[1] === 'render') {
    $in = stream_get_contents(STDIN);
    echo Render::md((string)$in) . "\n";
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
    echo "Current: v" . OLLAMADEV_VERSION . "\n";
    echo "Run 'ollamadev update' to check for and install the latest release.\n";
    exit(0);
}

// Pull Command - stream a model pull from Ollama.
if ($argc >= 2 && $argv[1] === 'pull') {
    $config = Config::load();
    $model = $argv[2] ?? '';
    if ($model === '') { echo "Usage: ollamadev pull <model>\n"; exit(1); }
    $ok = Puller::pull($model, $config['ollama']['host'] ?? 'http://localhost:11434');
    exit($ok ? 0 : 1);
}

// Models Command — list installed, browse curated presets, pull a preset, or
// show the tool-calling fallback chain.
if ($argc >= 2 && $argv[1] === 'models') {
    $config = Config::load();
    $client = ModelClient::default(); // honors --lmstudio / --host / configured provider
    $sub = $argv[2] ?? '';

    // Curated catalog of models known to work well for agentic coding.
    if ($sub === 'presets' || $sub === 'recommended') {
        $installed = $client->listModels();
        if (in_array('--json', $argv, true)) {
            $out = [];
            foreach (Models::presets() as $alias => $p) $out[] = $p + ['alias' => $alias, 'installed' => Models::match($p['tag'], $installed) !== ''];
            echo json_encode($out) . "\n"; exit(0);
        }
        echo "Recommended models (pull with: \033[36mollamadev models pull <alias>\033[0m)\n\n";
        foreach (Models::presets() as $alias => $p) {
            $have = Models::match($p['tag'], $installed) !== '' ? "\033[32m✓ installed\033[0m" : "\033[2mnot installed\033[0m";
            $tools = $p['tools'] ? "\033[32mtools\033[0m" : "\033[2mno-tools\033[0m";
            echo sprintf("  \033[1m%-18s\033[0m %-22s %-7s %-5s %s\n      \033[2m%s\033[0m\n",
                $alias, $p['tag'], $p['size'], $tools, $have, $p['note']);
        }
        exit(0);
    }

    // Pull a curated preset by alias (or any tag) via Ollama.
    if ($sub === 'pull') {
        if (Permission::isOffline()) { echo "✗ offline mode is on — pulling a model reaches the network and is blocked.\n"; exit(1); }
        $name = $argv[3] ?? '';
        if ($name === '') { echo "Usage: ollamadev models pull <alias|tag>   (see: ollamadev models presets)\n"; exit(1); }
        $tag = Models::resolveTag($name);
        echo "Pulling \033[36m$tag\033[0m" . ($tag !== $name ? " (alias \"$name\")" : '') . "…\n";
        exit(Puller::pull($tag, $config['ollama']['host'] ?? 'http://localhost:11434') ? 0 : 1);
    }

    // Show the fallback chain and which entries are installed.
    if ($sub === 'chain') {
        $installed = $client->listModels();
        $chain = Models::chain();
        $best = Models::bestInstalled($installed, $chain);
        if (in_array('--json', $argv, true)) {
            $rows = array_map(fn($t) => ['tag' => $t, 'installed' => Models::match($t, $installed) !== ''], $chain);
            echo json_encode(['chain' => $rows, 'best' => $best, 'autoFallback' => (bool)Config::get('model.autoFallback', true)]) . "\n"; exit(0);
        }
        echo "Tool-calling fallback chain" . (Config::get('model.fallback', null) ? " (from config model.fallback)" : " (default)") . ":\n";
        foreach ($chain as $t) {
            $hit = Models::match($t, $installed);
            echo "  " . ($hit !== '' ? "\033[32m✓\033[0m " : "\033[2m·\033[0m ") . $t . ($hit !== '' && $hit !== $t ? " \033[2m($hit)\033[0m" : '') . "\n";
        }
        echo "\n  Best installed: " . ($best !== '' ? "\033[36m$best\033[0m" : "\033[33mnone — pull one: ollamadev models pull qwen2.5-coder\033[0m") . "\n";
        echo "  Auto-fallback when a model lacks tool support: " . (Config::get('model.autoFallback', true) ? "on" : "off") . " \033[2m(config model.autoFallback)\033[0m\n";
        exit(0);
    }

    // Machine-readable status - single source for the desktop app.
    if (in_array('--json', $argv, true)) {
        $connected = $client->checkConnection();
        echo json_encode([
            'connected' => $connected,
            'models' => $connected ? $client->listModelsDetailed() : [],
            // The configured default, so the desktop topbar selects YOUR model (not
            // whatever Ollama happens to list first) — new terminals then launch with it.
            'default' => (string)Config::get('ollama.defaultModel', ''),
        ]);
        exit(0);
    }
    $models = $client->listModels();
    echo "Available Models:\n";
    foreach ($models as $m) echo "  $m\n";
    echo "\n\033[2mBrowse recommended models: ollamadev models presets · fallback chain: ollamadev models chain\033[0m\n";
    exit(0);
}

// Skills Command - list / scaffold on-demand skills
if ($argc >= 2 && $argv[1] === 'skills') {
    $sub = $argv[2] ?? 'list';
    $jsonMode = in_array('--json', $argv, true);
    // JSON surfaces for the desktop Skills manager (list / show / save).
    if (($sub === 'list' || $sub === 'ls') && $jsonMode) {
        // The manager shows your disk skills AND the built-in team-skills (so the
        // 28 starters are browsable, not just materialized during a crew run).
        $out = [];
        foreach (Skills::listForManager() as $s) $out[] = ['name' => $s['name'], 'description' => $s['description'] ?? '', 'dir' => $s['dir'] ?? '', 'builtin' => !empty($s['builtin'])];
        echo json_encode(['skills' => $out]); exit(0);
    }
    if ($sub === 'show' || $sub === 'get') {
        $name = $argv[3] ?? '';
        $s = $name !== '' ? Skills::get($name) : null;
        if ($jsonMode) {
            if (!$s) { echo json_encode(['error' => 'not found']); exit(0); }
            $body = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', (string)($s['body'] ?? ''));
            echo json_encode(['name' => $s['name'], 'description' => $s['description'] ?? '', 'body' => ltrim((string)$body), 'files' => $s['files'] ?? [], 'builtin' => !empty($s['builtin'])]); exit(0);
        }
        if (!$s) { echo "No such skill: $name\n"; exit(1); }
        echo ($s['body'] ?? '') . "\n"; exit(0);
    }
    if ($sub === 'save') {
        $name = $argv[3] ?? '';
        if ($name === '') { echo "Usage: ollamadev skills save <name>  (JSON {description, body} on stdin)\n"; exit(1); }
        $payload = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($payload)) $payload = [];
        $slug = Skills::save($name, (string)($payload['description'] ?? ''), (string)($payload['body'] ?? ''));
        echo $slug ? "Saved: $slug\n" : "Save failed.\n"; exit($slug ? 0 : 1);
    }
    if ($sub === 'new' || $sub === 'add') {
        $name = $argv[3] ?? '';
        if ($name === '') { echo "Usage: ollamadev skills new <name>\n"; exit(1); }
        $md = Skills::scaffold($name);
        echo "Created skill: $md\nEdit it, then run `ollamadev skills` to confirm it's discovered.\n";
        exit(0);
    }
    if ($sub === 'install') {
        $rest = array_slice($argv, 3);
        $force = in_array('--force', $rest, true);
        $rest = array_values(array_filter($rest, fn($a) => $a !== '--force'));
        $source = $rest[0] ?? '';
        if ($source === '') { echo "Usage: ollamadev skills install <dir | git-url | .tar.gz/.zip> [--force]\n"; exit(1); }
        echo "Installing skills from: $source\n";
        $res = Skills::install($source, $force);
        foreach ($res['messages'] as $m) echo "  $m\n";
        if (empty($res['installed'])) { echo "No skills installed.\n"; exit(1); }
        echo "Installed: " . implode(', ', $res['installed']) . "\n";
        echo "\033[2mNote: a skill is instructions the model may follow. Review what you installed — the agent's\n";
        echo "permission mode (auto/ask/readonly) still gates writes and shell.\033[0m\n";
        exit(0);
    }
    if ($sub === 'export') {
        $name = $argv[3] ?? '';
        if ($name === '') { echo "Usage: ollamadev skills export <name> [outpath]\n"; exit(1); }
        $path = Skills::export($name, $argv[4] ?? '');
        if (!$path) { echo "Export failed (no such skill?): $name\n"; exit(1); }
        echo "Exported: $path\n  Share it; others install with: ollamadev skills install " . basename($path) . "\n";
        exit(0);
    }
    if ($sub === 'remove' || $sub === 'rm' || $sub === 'delete') {
        $name = $argv[3] ?? '';
        if ($name === '') { echo "Usage: ollamadev skills remove <name>\n"; exit(1); }
        if (Skills::remove($name)) { echo "Removed: $name\n"; exit(0); }
        echo "No such skill: $name\n"; exit(1);
    }
    if ($sub === 'search' || $sub === 'find') {
        $q = trim(implode(' ', array_slice($argv, 3)));
        $hits = Skills::search($q);
        if (!$hits) { echo "No skills match" . ($q !== '' ? " \"$q\"" : '') . ".\n"; exit(0); }
        echo "Skills (" . count($hits) . ($q !== '' ? " matching \"$q\"" : '') . "):\n";
        foreach ($hits as $s) {
            $tag = !empty($s['installed']) ? "\033[32m[installed]\033[0m" : "\033[2m[available]\033[0m";
            echo "  " . $s['name'] . " $tag — " . ($s['description'] ?: '(no description)') . "\n";
        }
        echo "\n\033[2mInstall an available one with: ollamadev skills add <name>\033[0m\n";
        exit(0);
    }
    if ($sub === 'browse') {
        $avail = Skills::browse();
        if (!$avail) {
            echo "No registry skills found. Registry sources are scanned from:\n";
            foreach (Skills::registries() as $rdir) echo "  $rdir/<name>/SKILL.md\n";
            echo "Add local dirs or git URLs under \"skills.registries\" in config, or drop skills in the first path.\n";
            exit(0);
        }
        echo "Registry skills (" . count($avail) . "):\n";
        foreach ($avail as $s) {
            $tag = $s['installed'] ? "\033[32m[installed]\033[0m" : "\033[36m[available]\033[0m";
            echo "  " . $s['name'] . " $tag — " . ($s['description'] ?: '(no description)') . "\n";
        }
        echo "\n\033[2mInstall with: ollamadev skills add <name>\033[0m\n";
        exit(0);
    }
    if ($sub === 'add') {
        $rest = array_slice($argv, 3);
        $force = in_array('--force', $rest, true);
        $name = trim((string)($rest[array_key_first(array_filter($rest, fn($a) => $a !== '--force')) ?? 0] ?? ''));
        $name = '';
        foreach ($rest as $rv) { if ($rv !== '--force') { $name = $rv; break; } }
        if ($name === '') { echo "Usage: ollamadev skills add <name> [--force]   (discover names with: ollamadev skills browse)\n"; exit(1); }
        $res = Skills::addFromRegistry($name, $force);
        foreach ($res['messages'] as $m) echo "  $m\n";
        if (empty($res['installed'])) { echo "Nothing installed.\n"; exit(1); }
        echo "Installed: " . implode(', ', $res['installed']) . "\n";
        exit(0);
    }
    $all = Skills::all();
    if (!$all) {
        echo "No skills found.\n";
        echo "Skills live in:\n";
        foreach (Skills::baseDirs() as $d) echo "  $d/<name>/SKILL.md\n";
        echo "Create one with:  ollamadev skills new <name>\n";
        echo "Install shared:   ollamadev skills install <dir | git-url | .tar.gz/.zip>\n";
        exit(0);
    }
    echo "Skills (" . count($all) . "):\n";
    foreach ($all as $s) {
        echo "  " . $s['name'] . " — " . ($s['description'] ?: '(no description)') . "\n";
        echo "      " . $s['dir'] . "\n";
    }
    echo "\n\033[2mnew <name> · install <src> [--force] · export <name> [out] · remove <name>\033[0m\n";
    exit(0);
}

// Memory Command - graph knowledge base (list / new / show / search / graph / rm)
if ($argc >= 2 && $argv[1] === 'memory') {
    $sub = $argv[2] ?? 'list';
    if ($sub === 'new' || $sub === 'add') {
        $title = trim(implode(' ', array_slice($argv, 3)));
        if ($title === '') { echo "Usage: ollamadev memory new <title>\n"; exit(1); }
        $slug = Memory::save($title, "Write the note here. Link related notes with [[other-slug]].", []);
        echo "Created memory: " . Memory::projectDir() . "/$slug.md\n";
        exit(0);
    }
    if ($sub === 'show' || $sub === 'read') {
        $m = Memory::get($argv[3] ?? '');
        if (!$m) { echo "No such memory.\n"; exit(1); }
        echo "# {$m['title']}  ({$m['slug']})" . ($m['tags'] ? "  [" . implode(', ', $m['tags']) . "]" : '') . "\n\n" . trim($m['body']) . "\n";
        if ($m['links']) echo "\nLinks: " . implode(', ', array_map(fn($l) => "[[$l]]", $m['links'])) . "\n";
        exit(0);
    }
    if ($sub === 'search') {
        $hits = Memory::search(trim(implode(' ', array_slice($argv, 3))));
        if (!$hits) { echo "No matches.\n"; exit(0); }
        foreach ($hits as $s => $m) echo "  $s — {$m['title']}\n";
        exit(0);
    }
    if ($sub === 'rm' || $sub === 'remove' || $sub === 'delete') {
        $ok = Memory::remove($argv[3] ?? '');
        echo $ok ? "Removed.\n" : "No such memory.\n";
        exit($ok ? 0 : 1);
    }
    if ($sub === 'graph') {
        $g = Memory::graph();
        if (in_array('--json', $argv, true)) { echo json_encode($g) . "\n"; exit(0); }
        echo "Memory graph: " . count($g['nodes']) . " notes, " . count($g['edges']) . " links\n\n";
        foreach ($g['nodes'] as $n) {
            $out = array_values(array_filter($g['edges'], fn($e) => $e['from'] === $n['id']));
            echo "  " . $n['id'] . ($n['title'] !== $n['id'] ? " ({$n['title']})" : '') . "\n";
            foreach ($out as $e) echo "      → " . $e['to'] . "\n";
        }
        exit(0);
    }
    $all = Memory::all();
    if (!$all) {
        echo "No memories yet. They live in:\n";
        foreach (Memory::baseDirs() as $d) echo "  $d/<slug>.md\n";
        echo "Create one with: ollamadev memory new <title>\n";
        exit(0);
    }
    echo "Memory (" . count($all) . " notes):\n";
    foreach ($all as $slug => $m) {
        echo "  $slug — {$m['title']}" . ($m['tags'] ? "  [" . implode(', ', $m['tags']) . "]" : '') . ($m['links'] ? "  → " . implode(', ', $m['links']) : '') . "\n";
    }
    echo "\n\033[2mnew <title> · show <slug> · search <q> · graph [--json] · rm <slug>\033[0m\n";
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
        $offered = false;
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            fwrite(STDERR, "⚠️  Model '{$flags['model']}' is not installed. Pull it now? [y/N] ");
            $ans = strtolower(trim((string)fgets(STDIN)));
            if ($ans === 'y' || $ans === 'yes') { Puller::pull($flags['model'], $config['ollama']['host'] ?? 'http://localhost:11434'); $offered = true; }
        }
        if (!$offered) {
            fwrite(STDERR, "⚠️  Model '{$flags['model']}' is not installed. Pull it with: ollamadev pull {$flags['model']}\n");
            fwrite(STDERR, "   Falling back to an available model.\n");
        }
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
    // Build the `tools` topic from the live registry so the count and the listing
    // can never drift from what's actually registered. Tools have no category
    // metadata, so we keep a curated grouping for the well-known ones and sweep
    // everything else into "Other" — a tool can be missed from a group, but never
    // omitted from the page entirely.
    $allToolNames = Tools::all();
    sort($allToolNames);
    $toolCats = [
        'File'   => ['view', 'cat', 'head', 'tail', 'read', 'write', 'edit', 'multi_edit', 'patch', 'touch', 'mkdir', 'rm', 'cp', 'mv', 'ls'],
        'Search' => ['grep', 'find', 'tree', 'glob', 'wc', 'stat', 'diff', 'sort', 'uniq'],
        'Git'    => ['git_status', 'git_diff', 'git_log', 'git_branch', 'git_checkout', 'git_commit'],
        'Code'   => ['goto', 'goto_definition', 'find_refs', 'symbols', 'hover', 'diagnostics', 'format', 'lsp'],
        'System' => ['bash', 'execute_command', 'editor', 'watch', 'fetch', 'bg', 'wait_bg', 'agent'],
    ];
    $catLines = ''; $covered = [];
    foreach ($toolCats as $cat => $names) {
        $present = array_values(array_intersect($names, $allToolNames));
        foreach ($present as $p) $covered[$p] = true;
        if ($present) $catLines .= "  $cat: " . implode(', ', $present) . "\n";
    }
    $other = array_values(array_diff($allToolNames, array_keys($covered)));
    if ($other) $catLines .= "  Other: " . implode(', ', $other) . "\n";
    $toolCount = count($allToolNames);
    $toolsText = "Tools - Use in chat or directly:\n" . $catLines . "\n"
        . "Examples:\n"
        . "  view file_path=src/main.php\n"
        . "  write file_path=src/test.php content=\"<php code>\"\n"
        . "  grep pattern=\"function foo\" path=src/\n"
        . "  bash command=\"ls -la\"\n\n"
        . "Invoke one directly:  ollamadev tool <name> '{\"file_path\":\"x\"}'  (full list: ollamadev tool list)";
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
  ollamadev resume     Pick a recent session to resume
  ollamadev pull <m>   Download a model from Ollama
  ollamadev init       Generate OLLAMADEV.md project memory
  ollamadev crew <task> Run OllamaDev Crew (Director/Coders/Auditor)
                        (--amplify N for a self-consistency + adversarial panel;
                         --pack <name> to reuse a saved team; crew pack save/list;
                         crew role list/add — roles the Director assigns per subtask)
  ollamadev watch <task> Re-run a task whenever files change (background agent)
  ollamadev search <q>  Web search (--provider duckduckgo|searxng|brave); blocked when air-gapped
  ollamadev index build Build a local semantic code index (embeddings); also: status, clear
  ollamadev code-search <q>   Semantic search over the repo by meaning (local embeddings)
  ollamadev test        Run the project's tests (auto-detected)
  ollamadev verify      Run tests, then let the agent fix failures until green (--max N)
  ollamadev eval        Measure the agent's pass rate on a fixed task suite (--only, --model, --json)
  ollamadev models      List models; also: presets (recommended), pull <alias>, chain (fallback)
  ollamadev diff        Show the working-tree diff for review (--json); powers the desktop Review panel
  ollamadev commit      Commit with an AI-generated message (-a stages all, -m to override)
  ollamadev pr create|review <n>   Open a PR, or review one with the local model (needs gh)
  ollamadev config get|set <key> [value]   Inspect/persist ~/.ollamadev/config.json
  ollamadev skills      Manage skills (list/search/browse/add/install/export)
  ollamadev agents      List file-defined custom agent types (.ollamadev/agents/*.md)
  ollamadev hooks       View/add/remove shell hooks (list|add <event> <cmd>|remove <event> <i>)
  ollamadev mcp serve   Expose this CLI's tools to any MCP client over stdio
  ollamadev attest      Audit + prove the air-gap posture (--offline to lock down)
  ollamadev git        Git commands (status, diff, commit, etc.)
  ollamadev terminal   Terminal multiplexer
  ollamadev lsp        LSP server for IDEs (AI completions, linter diagnostics)
  ollamadev help [topic] Show help

In-chat: type /help for slash commands (/undo, /pull, /init, /retry,
  /checkpoints, /image, @file mentions). Ctrl-C interrupts a response.

See 'ollamadev help <topic>' for detailed help.
COMMANDS
        ],
        'tools' => [
            'description' => "Available AI tools ($toolCount total)",
            'text' => $toolsText,
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
    if (Permission::isOffline()) { echo "✗ offline mode is on — `update` reaches GitHub and is blocked. Drop --offline to update.\n"; exit(1); }
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
    // Pick the standalone binary matching this platform (ollamadev-<os>-<arch>),
    // e.g. ollamadev-linux-x64 / ollamadev-mac-arm64 / ollamadev-windows-x64.exe.
    $pos = isWindows() ? 'windows' : (stripos(PHP_OS, 'DARWIN') === 0 ? 'mac' : 'linux');
    $m = strtolower(php_uname('m'));
    $parch = (strpos($m, 'arm') !== false || strpos($m, 'aarch64') !== false) ? 'arm64' : 'x64';
    $want = "ollamadev-$pos-$parch" . ($pos === 'windows' ? '.exe' : '');
    $binary = null;
    foreach ($assets as $a) { if (($a['name'] ?? '') === $want) { $binary = $a; break; } }
    if (!$binary) foreach ($assets as $a) { if (($a['name'] ?? '') === 'ollamadev') { $binary = $a; break; } } // legacy
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
} elseif ($cmd === 'init') {
    // Generate OLLAMADEV.md project memory from a scan + the active model.
    $agent = new Agent();
    echo ProjectInit::run($agent, $flags['cwd'] ?? getcwd(), posix_isatty(STDIN));
} elseif ($cmd === 'crew') {
    // Bench of agents: Researcher → Director → Coders (git worktrees) → Auditor → land.
    // --review gates landing: nothing auto-merges, every branch is held for review.

    // Build the team-shaped opts from the current flags (used for `pack save` and
    // as the override layer on top of a loaded --pack).
    $flagOpts = [];
    foreach (['directorModel', 'coderModel', 'auditorModel', 'researcherModel', 'focus'] as $fk) if (!empty($flags[$fk])) $flagOpts[$fk] = $flags[$fk];
    if (!empty($flags['max'])) $flagOpts['max'] = (int)$flags['max'];
    if (!empty($flags['amplify'])) $flagOpts['amplify'] = (int)$flags['amplify'];
    if (in_array('--review', $argv, true)) $flagOpts['land'] = 'review';
    elseif (in_array('--auto-merge', $argv, true)) $flagOpts['land'] = 'auto';
    if (in_array('--no-research', $argv, true)) $flagOpts['research'] = false;
    if (in_array('--no-audit', $argv, true)) $flagOpts['audit'] = false;
    if (in_array('--no-skills', $argv, true)) $flagOpts['skills'] = false;
    // --skill <name> (repeatable): force a built-in team-skill in regardless of
    // focus. Lets a crew template guarantee its matching skill (e.g. tests →
    // testing-discipline). Also accepts --skills a,b,c.
    $forceSkills = [];
    for ($si = 0; $si < count($argv); $si++) {
        if ($argv[$si] === '--skill' && isset($argv[$si + 1])) $forceSkills[] = $argv[++$si];
        elseif ($argv[$si] === '--skills' && isset($argv[$si + 1])) foreach (explode(',', $argv[++$si]) as $sk) { $sk = trim($sk); if ($sk !== '') $forceSkills[] = $sk; }
    }
    if ($forceSkills) $flagOpts['forceSkills'] = array_values(array_unique($forceSkills));
    if (in_array('--no-ideas', $argv, true)) $flagOpts['ideas'] = false;
    if (in_array('--no-memory', $argv, true)) $flagOpts['memory'] = false;
    if (!empty($flags['hosts'])) $flagOpts['hosts'] = array_values(array_filter(array_map('trim', explode(',', $flags['hosts']))));

    // Crew team-packs: save/list/remove shareable team configs.
    if ($arg1 === 'pack') {
        $packSub = $positional[2] ?? 'list';
        if ($packSub === 'save') {
            $pn = $positional[3] ?? '';
            if ($pn === '') { echo "Usage: ollamadev crew pack save <name> [--focus .. --coder-model .. --amplify N ..]\n"; exit(1); }
            $path = CrewPacks::save($pn, $flagOpts);
            echo "Saved crew pack: $pn\n  $path\n  Use it with: ollamadev crew --pack $pn \"<task>\"\n";
            exit(0);
        }
        if ($packSub === 'rm' || $packSub === 'remove' || $packSub === 'delete') {
            $pn = $positional[3] ?? '';
            echo CrewPacks::remove($pn) ? "Removed pack: $pn\n" : "No such pack: $pn\n";
            exit(CrewPacks::load($pn) === null ? 0 : 1);
        }
        $packs = CrewPacks::all();
        if (!$packs) { echo "No crew packs yet. Create one:\n  ollamadev crew pack save <name> --focus \"...\" --coder-model <m>\n"; exit(0); }
        echo "Crew packs (" . count($packs) . "):\n";
        foreach ($packs as $pn => $summary) echo "  $pn — \033[2m$summary\033[0m\n";
        echo "\n\033[2mUse one: ollamadev crew --pack <name> \"<task>\"\033[0m\n";
        exit(0);
    }

    // Crew roles: the catalog the Director assigns per subtask. list/show/add/remove.
    if ($arg1 === 'role' || $arg1 === 'roles') {
        $sub = $positional[2] ?? 'list';
        if ($sub === 'add') {
            $rn = $positional[3] ?? '';
            // Persona is a positional ("<persona>") to avoid colliding with the
            // global -p/--prompt one-shot flag.
            $persona = trim((string)($positional[4] ?? ''));
            if ($rn === '') { echo "Usage: ollamadev crew role add <name> \"<persona>\" [--desc \"...\"] [--model <m>] [--readonly]\n"; exit(1); }
            if ($persona === '') { echo "A role needs a persona. Pass it as a quoted argument:\n  ollamadev crew role add $rn \"You are a … agent in an isolated git worktree. …\"\n"; exit(1); }
            $ropts = ['desc' => (string)($flags['desc'] ?? ''), 'model' => (string)($flags['model'] ?? ''), 'permission' => ($flags['permission'] === 'readonly') ? 'readonly' : 'auto'];
            $path = CrewRoles::add($rn, $persona, $ropts);
            echo "Saved crew role: " . CrewRoles::normName($rn) . "\n  $path\n  The Director can now assign it. List them: ollamadev crew role list\n";
            exit(0);
        }
        if ($sub === 'rm' || $sub === 'remove' || $sub === 'delete') {
            $rn = $positional[3] ?? '';
            echo CrewRoles::remove($rn) ? "Removed role: " . CrewRoles::normName($rn) . "\n" : "No such custom role (built-ins can't be removed): $rn\n";
            exit(0);
        }
        if ($sub === 'show') {
            $role = CrewRoles::get($positional[3] ?? '');
            echo "Role: {$role['name']}\n";
            echo "  desc:       " . ($role['desc'] !== '' ? $role['desc'] : '(none)') . "\n";
            echo "  model:      " . (($role['model'] ?? '') !== '' ? $role['model'] : '(crew coder model)') . "\n";
            echo "  permission: " . ($role['permission'] ?? 'auto') . "\n";
            echo "  prompt:\n    " . str_replace("\n", "\n    ", $role['prompt']) . "\n";
            exit(0);
        }
        // list (--json for the desktop/web role manager)
        $roles = CrewRoles::all();
        if (in_array('--json', $argv, true)) {
            $out = [];
            foreach ($roles as $name => $def) {
                $out[] = ['name' => $name, 'desc' => $def['desc'] ?? '', 'model' => $def['model'] ?? '',
                    'permission' => $def['permission'] ?? 'auto', 'builtin' => empty($def['custom'])];
            }
            echo json_encode(['roles' => $out]) . "\n";
            exit(0);
        }
        echo "Crew roles (" . count($roles) . "):\n";
        foreach ($roles as $name => $def) {
            $tag = empty($def['custom']) ? " \033[2m(built-in)\033[0m" : '';
            $extra = [];
            if (($def['model'] ?? '') !== '') $extra[] = 'model: ' . $def['model'];
            if (($def['permission'] ?? 'auto') === 'readonly') $extra[] = 'readonly';
            $sx = $extra ? " \033[2m[" . implode(', ', $extra) . "]\033[0m" : '';
            echo "  \033[36m$name\033[0m$tag — \033[2m" . ($def['desc'] !== '' ? $def['desc'] : 'no description') . "\033[0m$sx\n";
        }
        echo "\n\033[2mThe Director assigns one per subtask. Add your own: ollamadev crew role add <name> \"<persona>\"\033[0m\n";
        exit(0);
    }

    // Resume an interrupted run from disk: `crew resume [runId]`.
    if ($arg1 === 'resume') { exit(Crew::resume($positional[2] ?? '')); }
    if ($arg1 === 'clear') {
        $cr = Crew::clearBoard();
        if (!empty($cr['ok'])) { echo "\033[32m✓\033[0m crew board cleared\n"; exit(0); }
        echo "\033[31m" . ($cr['error'] ?? 'could not clear the board') . "\033[0m\n"; exit(1);
    }
    // Separate Director: redirect a running coder (or "all") from another pane/terminal.
    if ($arg1 === 'steer') {
        $tok = strtolower((string)($positional[2] ?? ''));
        $tgt = in_array($tok, ['all', '*', 'everyone'], true) ? 0 : (int)($positional[2] ?? -1);
        $msg = trim(implode(' ', array_slice($positional, 3)));
        $sr = Crew::steer($tgt, $msg);
        $who = $tgt === 0 ? 'the crew' : "coder {$tgt}";
        if (!empty($sr['ok'])) { echo "\033[32m✓\033[0m steered {$who}\n"; exit(0); }
        echo "\033[31m" . ($sr['error'] ?? 'could not steer') . "\033[0m\n  Usage: ollamadev crew steer <coder#|all> \"<instruction>\"\n"; exit(1);
    }
    // The Director in its OWN terminal: a live steering console. Run it in a separate
    // tab/pane while the crew works elsewhere. It auto-refreshes the board as coders
    // change state, and steers one coder ("<#>: ..") or the whole crew ("all: ..").
    if ($arg1 === 'director') {
        if (!posix_isatty(STDIN)) { echo "Run `ollamadev crew director` in a terminal.\n"; exit(1); }
        $c = "\033[36m"; $d = "\033[2m"; $b = "\033[1m"; $g = "\033[32m"; $y = "\033[33m"; $r = "\033[0m";
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $boardPath = $home . '/.ollamadev/crew/current.json';
        $renderBoard = function () use ($boardPath, $d, $y, $g, $c, $r) {
            $bd = json_decode((string) @file_get_contents($boardPath), true);
            if (!is_array($bd) || empty($bd['subtasks'])) { echo "  {$d}(no active crew — start one in another tab, then steer it here){$r}\n"; return; }
            echo "  {$d}" . (!empty($bd['active']) ? 'running' : 'idle') . " · " . ($bd['task'] ?? '') . "{$r}\n";
            foreach ($bd['subtasks'] as $s) {
                $st = $s['state'] ?? '?'; $col = $st === 'done' ? $g : ($st === 'held' ? $y : $c);
                echo "  {$col}#{$s['n']}{$r} {$d}[" . ($s['role'] ?? 'coder') . "]{$r} " . ($s['title'] ?? '') . " {$col}— {$st}{$r}\n";
            }
        };
        $prompt = "\n{$c}🧭 ▸{$r} ";
        echo "\n{$b}🧭 Director console{$r} {$d}— live view of your crew. Steer one coder \"<#>: instruction\", or the whole crew \"all: instruction\". 'board' · 'exit'.{$r}\n";
        $renderBoard();
        echo $prompt;
        @stream_set_blocking(STDIN, false);
        $lastTs = -1;
        while (true) {
            // Auto-refresh: reprint the board whenever the crew's state changes.
            $bd = json_decode((string) @file_get_contents($boardPath), true);
            $ts = is_array($bd) ? (int)($bd['ts'] ?? 0) : 0;
            if ($ts !== $lastTs) {
                if ($lastTs !== -1) { echo "\n"; $renderBoard(); echo $prompt; }
                $lastTs = $ts;
            }
            $line = fgets(STDIN);
            if ($line === false) { usleep(200000); continue; }   // nothing typed yet
            $line = trim($line);
            if ($line === '') { echo $prompt; continue; }
            if (in_array(strtolower($line), ['exit', 'quit', 'q', ':q'], true)) break;
            if (strtolower($line) === 'board') { $renderBoard(); echo $prompt; continue; }
            if (preg_match('/^(\d+|all|\*|everyone)\s*[:>\-]\s*(.+)$/i', $line, $m)) {
                $tgt = ctype_digit($m[1]) ? (int)$m[1] : 0;   // 0 = the whole crew
                $sr = Crew::steer($tgt, trim($m[2]));
                $who = $tgt === 0 ? 'the crew' : "coder {$tgt}";
                echo !empty($sr['ok']) ? "  {$g}✓{$r} {$who} steered\n" : "  {$y}" . ($sr['error'] ?? 'could not steer') . "{$r}\n";
            } else {
                echo "  {$d}use \"<#>: instruction\", \"all: instruction\", 'board', or 'exit'{$r}\n";
            }
            echo $prompt;
        }
        @stream_set_blocking(STDIN, true);
        echo "\n{$d}Director console closed.{$r}\n";
        exit(0);
    }

    $taskParts = array_slice($positional, 1);
    $task = $arg1 === '' ? '' : implode(' ', $taskParts);
    // --pack <name>: load a saved team as the base; explicit flags override it.
    $copts = [];
    if (!empty($flags['pack'])) {
        $loaded = CrewPacks::load($flags['pack']);
        if ($loaded === null) { echo "No such crew pack: {$flags['pack']}  (list with: ollamadev crew pack list)\n"; exit(1); }
        $copts = $loaded;
        echo "\033[2mLoaded crew pack: {$flags['pack']}\033[0m\n";
    }
    $copts = array_merge($copts, $flagOpts);          // explicit flags win over the pack
    if (!array_key_exists('max', $copts)) $copts['max'] = $flags['max'] ?? null;
    if (!empty($flags['runId'])) $copts['runId'] = $flags['runId']; // one-off, never part of a pack

    // No task given: drop into an interactive crew — the team is configured and the
    // Director simply waits for you to prompt it (and keeps prompting after each run).
    if ($task === '') {
        if (!posix_isatty(STDIN)) {
            echo "Usage: ollamadev crew \"<task>\" [options]   (or run with no task in a terminal to prompt the Director live)\n";
            echo "Subcommands:\n";
            echo "  crew steer <coder#|all> \"<msg>\"   redirect a running coder (or the whole crew)\n";
            echo "  crew director                      live steering console in its own terminal\n";
            echo "  crew clear                         clear the kanban board (idle only)\n";
            echo "  crew resume [runId]                resume an interrupted run\n";
            echo "  crew pack save|list|rm             saved team configs\n";
            echo "  crew role list|add|remove|show     roles the Director assigns per subtask\n";
            echo "In the live Director: \"ask\"/\"task\" toggles answer mode, \"?<q>\" answers once, \"clear board\".\n";
            exit(1);
        }
        $c = "\033[36m"; $d = "\033[2m"; $b = "\033[1m"; $y = "\033[33m"; $g = "\033[32m"; $r = "\033[0m";
        $bits = [];
        if (!empty($copts['focus'])) $bits[] = 'focus set';
        if (($copts['land'] ?? '') === 'review') $bits[] = 'review mode';
        if (($copts['research'] ?? true) === false) $bits[] = 'no research';
        if (($copts['audit'] ?? true) === false) $bits[] = 'no audit';
        if (!empty($copts['hosts'])) $bits[] = count($copts['hosts']) . ' hosts';
        echo "\n{$b}👥 OllamaDev Crew{$r} {$d}— the Director is ready" . ($bits ? ' · ' . implode(' · ', $bits) : '') . "{$r}\n";
        // Offer to resume an interrupted run for this repo before taking a new task.
        $interrupted = Crew::findResumable();
        if ($interrupted) {
            echo "{$y}  ⚠ an unfinished crew run is here:{$r} {$d}\"" . substr((string)($interrupted['task'] ?? ''), 0, 60) . "\"{$r}\n";
            echo "{$c}Resume it? [Y/n]{$r} ";
            $ans = strtolower(trim((string)fgets(STDIN)));
            if ($ans === '' || $ans === 'y' || $ans === 'yes') { Crew::resume((string)$interrupted['runId']); }
        }
        echo "{$d}Type a task for the Director. \"ask\" = answer mode (just answers, no tasking) · \"task\" = build · \"?<question>\" answers once · \"clear board\" · 'exit'.{$r}\n";
        $answerMode = false;
        while (true) {
            echo "\n{$c}Director" . ($answerMode ? " (ask)" : "") . " ▸{$r} ";
            $line = fgets(STDIN);
            if ($line === false) break;                       // Ctrl-D / EOF
            $line = trim($line);
            if ($line === '') continue;
            $low = strtolower($line);
            if (in_array($low, ['exit', 'quit', 'q', ':q'], true)) break;
            // Answer mode: the Director just answers questions (read-only), no crew run.
            if (in_array($low, ['ask', 'chat', 'answer'], true)) { $answerMode = true; echo "{$d}Answer mode — I'll just answer (read-only, no tasking). Type 'task' to build again.{$r}\n"; continue; }
            if (in_array($low, ['task', 'build'], true)) { $answerMode = false; echo "{$d}Task mode — I'll dispatch the crew on what you ask.{$r}\n"; continue; }
            // Board meta-commands are handled directly — typing it here IS the explicit
            // request, so clear it instead of spending a whole crew run on the phrase.
            if (preg_match('/^(clear|reset|wipe|empty)\s+(the\s+)?board$/i', $line)) {
                $cr = Crew::clearBoard();
                echo !empty($cr['ok']) ? "{$g}✓{$r} board cleared\n" : "{$y}" . ($cr['error'] ?? 'could not clear the board') . "{$r}\n";
                continue;
            }
            // In answer mode, or a one-off "?question", answer instead of tasking.
            if ($answerMode || substr($line, 0, 1) === '?') {
                Crew::answer(ltrim($line, "? \t"), (string)($copts['directorModel'] ?? ''));
                continue;
            }
            unset($copts['runId']);                            // fresh run id per task
            Crew::run($line, $copts);
        }
        echo "\n{$d}Crew session ended.{$r}\n";
        exit(0);
    }

    // --panes: show one live tmux pane per coder (each tailing its log), with the
    // main crew output in pane 0. Needs tmux; otherwise runs normally (logs still written).
    if (!empty($flags['panes'])) {
        $tmux = trim((string) @shell_exec('command -v tmux 2>/dev/null'));
        if ($tmux === '') {
            fwrite(STDERR, "  --panes needs tmux (not found). Running normally; per-coder logs are still written to ~/.ollamadev/crew/<runId>/.\n");
        } else {
            $home = getenv('HOME') ?: sys_get_temp_dir();
            $runId = $copts['runId'] ?? ('crew_' . date('Ymd_His'));
            $copts['runId'] = $runId;
            $self = $argv[0];
            $bg = trim((string) @shell_exec('command -v setsid 2>/dev/null')) !== '' ? 'setsid ' : 'nohup ';
            if (getenv('TMUX') !== false) {
                // Already in tmux: split the current window; run the crew inline here.
                @shell_exec($bg . escapeshellarg($self) . ' crew-watch ' . escapeshellarg($runId) . ' --in-tmux >/dev/null 2>&1 &');
                exit(Crew::run($task, $copts));
            }
            // Not in tmux: launch a detached session running the crew, split panes, attach.
            $inner = [];
            for ($k = 1; $k < count($argv); $k++) { if ($argv[$k] === '--panes') continue; $inner[] = $argv[$k]; }
            if (!in_array('--run-id', $inner, true)) { $inner[] = '--run-id'; $inner[] = $runId; }
            $innerCmd = escapeshellarg($self) . ' ' . implode(' ', array_map('escapeshellarg', $inner));
            $script = sys_get_temp_dir() . '/odv-crew-' . $runId . '.sh';
            @file_put_contents($script, "#!/usr/bin/env bash\n" . $innerCmd . "\nprintf '\\n[crew finished — press any key to close this pane]'\nread -n1\n");
            @chmod($script, 0755);
            $sess = 'odv-' . substr($runId, 5);
            @shell_exec('tmux new-session -d -s ' . escapeshellarg($sess) . ' -x 220 -y 50 ' . escapeshellarg('bash ' . $script) . ' 2>&1');
            @shell_exec($bg . escapeshellarg($self) . ' crew-watch ' . escapeshellarg($runId) . ' --session ' . escapeshellarg($sess) . ' >/dev/null 2>&1 &');
            $st = 0; system('tmux attach -t ' . escapeshellarg($sess), $st);
            @unlink($script);
            exit(0);
        }
    }
    exit(Crew::run($task, $copts));
} elseif ($cmd === 'crew-watch' && $arg1) {
    // Internal: split a tmux pane per coder as their logs appear (used by --panes).
    $inTmux = in_array('--in-tmux', $argv, true);
    $sess = '';
    foreach ($argv as $ai => $av) if ($av === '--session') { $sess = $argv[$ai + 1] ?? ''; break; }
    Crew::watchPanes($arg1, $sess, $inTmux);
    exit(0);
} elseif ($cmd === 'watch') {
    // Always-on local agent: re-run a task whenever files change.
    $watchTask = $arg1;
    $watchPaths = array_slice($positional, 2); // positional[0]=watch, [1]=task, rest=paths
    Permission::setMode(Config::get('permissions.mode', 'ask'));
    Permission::setInteractive(posix_isatty(STDIN));
    $wopts = [];
    if (!empty($flags['interval'])) $wopts['interval'] = (int)$flags['interval'];
    if (!empty($flags['once'])) $wopts['once'] = true;
    exit(Watcher::run($watchTask, $watchPaths, $wopts));
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
    // Per-repo resume: bare `ollamadev` continues this directory's last session
    // (so reopening in a project — CLI or desktop — picks up where you left off).
    // `ollamadev --new` or `ollamadev new` forces a fresh session; disable globally
    // with session.autoResume:false in config.
    $resumeId = null;
    if (empty($flags['new']) && Config::get('session.autoResume', true)) {
        $resumeId = Session::latestForCwd($config, getcwd() ?: '.');
    }
    $session = $resumeId ? new Session($config, $resumeId) : new Session($config);
    // An explicit -m (the desktop passes one with every terminal) OVERRIDES the
    // resumed session's saved model — otherwise reopening always reverts to the old
    // model and "changing the model from the desktop" silently does nothing.
    if (!empty($flags['model'])) $session->useModel($flags['model']);
    $session->start();
} else {
    echo "Unknown command: $cmd\n";
    echo "Run 'ollamadev help <topic>' for available topics.\n";
    echo "Run 'ollamadev help' for general usage.\n";
    exit(1);
}
