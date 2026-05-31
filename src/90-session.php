class Session {
    private array $config;
    private string $id;
    private string $title;
    private string $model;
    private string $cwd = '';     // working dir this session belongs to (for per-repo resume)
    private array $messages = [];
    private array $history = []; // in-session input history for the line editor
    private Agent $agent;

    public function __construct(array $config, ?string $sessionId = null) {
        $this->config = $config;
        $this->ensureDataDir();
        MCP::load($config);
        LSP::load($config);
        Permission::setMode(Config::get('permissions.mode', 'ask'));
        $this->agent = new Agent();
        if ($sessionId) { $this->load($sessionId); }
        else { $this->id = 'session_' . time() . '_' . substr(md5(mt_rand()), 0, 8); $this->title = "Session " . date('Y-m-d H:i'); $this->model = $this->agent->getModel(); $this->cwd = getcwd() ?: ''; }
        // Keep the agent and session pointed at the same model.
        $this->agent->setModel($this->model);
        $GLOBALS['currentSessionModel'] = $this->model;
        // Load this session's cumulative token totals so /status survives resumes.
        Usage::bindSession($this->id);
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
        $this->cwd = $data['cwd'] ?? '';
        $this->messages = $data['messages'] ?? [];
        return true;
    }

    public function save(): void {
        $path = Config::sessionsDir() . '/' . $this->id . '.json';
        file_put_contents($path, json_encode(['id' => $this->id, 'title' => $this->title, 'model' => $this->model, 'cwd' => $this->cwd, 'messages' => $this->messages, 'created_at' => date('c'), 'updated_at' => date('c')], JSON_PRETTY_PRINT));
    }

    // $extra carries structured fields that must survive into the wire format
    // sent to Ollama, e.g. an assistant turn's 'tool_calls' or a tool result's
    // 'tool_name', so native function-calling models can correlate the two.
    public function addMessage(string $role, string $content, array $extra = []): void {
        $this->messages[] = array_merge(
            ['id' => 'msg_' . time() . '_' . substr(md5(mt_rand()), 0, 6), 'role' => $role, 'content' => $content, 'created_at' => date('c')],
            $extra
        );
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
            if ($data) {
                $msgs = $data['messages'] ?? [];
                // First user message makes a good human-readable preview.
                $preview = '';
                foreach ($msgs as $m) {
                    if (($m['role'] ?? '') === 'user') { $preview = trim((string)($m['content'] ?? '')); break; }
                }
                $sessions[] = [
                    'id' => basename($file, '.json'),
                    'title' => $data['title'] ?? 'Untitled',
                    'model' => $data['model'] ?? 'unknown',
                    'cwd' => $data['cwd'] ?? '',
                    'created_at' => $data['created_at'] ?? '',
                    'updated_at' => $data['updated_at'] ?? '',
                    'count' => count($msgs),
                    'preview' => $preview,
                ];
            }
        }
        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        return $sessions;
    }

    // Most-recent session id that belongs to $cwd (per-repo resume), or null.
    // Only considers sessions with at least one message, so opening in a repo
    // resumes real work rather than an empty just-created session.
    public static function latestForCwd(array $config, string $cwd): ?string {
        $target = realpath($cwd) ?: $cwd;
        foreach (self::listAll($config) as $s) {           // already sorted newest-first
            $scwd = (string)($s['cwd'] ?? '');
            if ($scwd === '' || ($s['count'] ?? 0) < 1) continue;
            if ((realpath($scwd) ?: $scwd) === $target) return $s['id'];
        }
        return null;
    }

    // Interactive "resume a previous session" picker. Lists recent sessions with
    // a preview and lets the user choose one by number; the chosen session is
    // then started. Returns false if there was nothing to resume.
    public static function pickAndResume(array $config): bool {
        $sessions = self::listAll($config);
        if (empty($sessions)) {
            echo "\033[2m  No previous sessions to resume.\033[0m\n";
            return false;
        }
        $sessions = array_slice($sessions, 0, 20); // most recent (listAll sorts desc)
        $c = "\033[36m"; $d = "\033[2m"; $b = "\033[1m"; $r = "\033[0m";
        echo "\n  {$b}Resume a session{$r}\n\n";
        foreach ($sessions as $i => $s) {
            $n = $i + 1;
            $when = self::relativeTime($s['updated_at'] ?? '');
            $preview = $s['preview'] !== '' ? $s['preview'] : $s['title'];
            $preview = preg_replace('/\s+/', ' ', $preview);
            if (mb_strlen($preview) > 50) $preview = mb_substr($preview, 0, 50) . '…';
            echo sprintf("  {$c}%2d{$r}  %-52s {$d}%s · %d msg · %s{$r}\n", $n, $preview, $s['model'], $s['count'], $when);
        }
        echo "\n  {$d}Enter a number to resume (or blank to cancel):{$r} ";
        $choice = trim((string)fgets(STDIN));
        if ($choice === '' || !ctype_digit($choice)) { echo "\033[2m  Cancelled.\033[0m\n"; return false; }
        $idx = (int)$choice - 1;
        if ($idx < 0 || $idx >= count($sessions)) { echo "\033[2m  Out of range.\033[0m\n"; return false; }
        (new Session($config, $sessions[$idx]['id']))->start();
        return true;
    }

    // Compact "3m ago" / "2h ago" / "5d ago" relative time from an ISO string.
    private static function relativeTime(string $iso): string {
        if ($iso === '') return 'unknown';
        $t = strtotime($iso);
        if ($t === false) return 'unknown';
        $diff = time() - $t;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
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
            'help' => $this->slashHelp(),
            'models' => $this->listModels($args),
            'model' => $this->switchModel($args),
            'pull' => $this->pullModel($args),
            'image' => 'PROMPT:/image ' . $args, // hand to Vision::extract as a message
            'new' => $this->newSession(),
            'exit', 'quit' => $this->exitCli(),
            'clear' => $this->clearScreen(),
            'verbose' => $this->toggleVerbose($args),
            'compact' => $this->compactSession(),
            'save' => $this->saveSession(),
            'session' => $this->showSession(),
            'git' => $this->runGit($args),
            'status' => $this->renderStatusLine(),
            'tools' => $this->listTools(),
            'context' => "📁 " . getcwd() . "\nContext: " . Usage::contextMeter($this->countTokens()) . "\nMessages: " . count($this->messages) . " | Model: {$this->model}\n",
            'pwd' => getcwd() . "\n",
            'cd' => $this->changeDir($args),
            'ls' => crossPlatformLs($args ?: '.') . "\n",
            'permission', 'permissions' => $this->managePermission($args),
            'chat', 'agent' => $this->toggleChatMode($cmd, $args),
            'undo' => Checkpoints::undoLast(),
            'checkpoints' => $this->listCheckpoints(),
            'init' => ProjectInit::run($this->agent, getcwd(), Permission::isInteractive()),
            'crew' => $this->runCrew($args),
            'skills' => $this->manageSkills($args),
            'memory', 'mem' => $this->manageMemory($args),
            'retry', 'regenerate' => $this->retryLast(),
            'commands' => UserCmds::render(),
            default => UserCmds::exists($cmd) ? ('PROMPT:' . UserCmds::expand($cmd, $args)) : false
        };
    }

    private function manageSkills(string $args): string {
        $parts = preg_split('/\s+/', trim($args), 2);
        $sub = strtolower($parts[0] ?? '');
        $rest = trim($parts[1] ?? '');
        if ($sub === 'install') {
            if ($rest === '') return "Usage: /skills install <dir | git-url | .tar.gz/.zip> [--force]\n";
            $force = (bool) preg_match('/(^|\s)--force(\s|$)/', $rest);
            if ($force) $rest = trim(preg_replace('/(^|\s)--force(\s|$)/', ' ', $rest));
            $res = Skills::install($rest, $force);
            $out = empty($res['installed']) ? "No skills installed.\n" : ("Installed: " . implode(', ', $res['installed']) . "\n");
            foreach ($res['messages'] as $m) $out .= "  $m\n";
            if (!empty($res['installed'])) $out .= "  \033[2mReview installed skills (/skills) — they are model instructions; your permission mode still gates writes/shell.\033[0m\n";
            return $out;
        }
        if ($sub === 'export') {
            if ($rest === '') return "Usage: /skills export <name> [outpath]\n";
            $p = preg_split('/\s+/', $rest, 2);
            $path = Skills::export($p[0], trim($p[1] ?? ''));
            return $path ? "Exported: $path\n" : "Export failed (no such skill?): {$p[0]}\n";
        }
        if ($sub === 'remove' || $sub === 'rm' || $sub === 'delete') {
            if ($rest === '') return "Usage: /skills remove <name>\n";
            return Skills::remove($rest) ? "Removed: $rest\n" : "No such skill: $rest\n";
        }
        if ($sub === 'new' || $sub === 'add') {
            if ($rest === '') return "Usage: /skills new <name>\n";
            return "Created: " . Skills::scaffold($rest) . "\n";
        }
        return $this->listSkills();
    }

    private function manageMemory(string $args): string {
        $parts = preg_split('/\s+/', trim($args), 2);
        $sub = strtolower($parts[0] ?? '');
        $rest = trim($parts[1] ?? '');
        $c = "\033[36m"; $d = "\033[2m"; $r = "\033[0m";
        if ($sub === 'new' || $sub === 'add') {
            if ($rest === '') return "Usage: /memory new <title>\n";
            $slug = Memory::save($rest, "Write the note here. Link related notes with [[other-slug]].", []);
            return "Created: " . Memory::projectDir() . "/$slug.md\n";
        }
        if ($sub === 'show' || $sub === 'read') {
            $m = Memory::get($rest);
            if (!$m) return "No such memory: $rest\n";
            $out = "{$c}{$m['title']}{$r} ({$m['slug']})\n" . trim($m['body']) . "\n";
            if ($m['links']) $out .= "{$d}links: " . implode(', ', $m['links']) . "{$r}\n";
            return $out;
        }
        if ($sub === 'search') {
            $hits = Memory::search($rest);
            if (!$hits) return "No matches.\n";
            $out = '';
            foreach ($hits as $s => $m) $out .= "  {$c}$s{$r} {$d}{$m['title']}{$r}\n";
            return $out;
        }
        if ($sub === 'rm' || $sub === 'remove' || $sub === 'delete') {
            return Memory::remove($rest) ? "Removed: $rest\n" : "No such memory: $rest\n";
        }
        if ($sub === 'graph') {
            $g = Memory::graph();
            $out = "Memory graph: " . count($g['nodes']) . " notes, " . count($g['edges']) . " links\n";
            foreach ($g['nodes'] as $n) {
                $out .= "  {$c}{$n['id']}{$r}\n";
                foreach (array_filter($g['edges'], fn($e) => $e['from'] === $n['id']) as $e) $out .= "      {$d}→ {$e['to']}{$r}\n";
            }
            return $out;
        }
        $all = Memory::all();
        if (!$all) return "Memory is empty. Save facts with /memory new <title>, or the agent's remember tool.\n";
        $out = "\nMemory (" . count($all) . " notes):\n";
        foreach ($all as $slug => $m) $out .= sprintf("  {$c}%-22s{$r}{$d}%s{$r}\n", $slug, $m['title']);
        return $out . "  {$d}/memory show <slug> · search <q> · graph · new <title> · rm <slug>{$r}\n\n";
    }

    private function listSkills(): string {
        $all = Skills::all();
        if (!$all) {
            $out = "No skills found. Skills are folders with a SKILL.md:\n";
            foreach (Skills::baseDirs() as $d) $out .= "  $d/<name>/SKILL.md\n";
            return $out . "Create one with: ollamadev skills new <name>\n";
        }
        $c = "\033[36m"; $d = "\033[2m"; $r = "\033[0m";
        $out = "\nSkills (" . count($all) . ") — the agent loads these on demand via the skill tool:\n\n";
        foreach ($all as $s) $out .= sprintf("  {$c}%-18s{$r}{$d}%s{$r}\n", $s['name'], $s['description'] ?: '(no description)');
        return $out . "\n  {$d}/skills install <src> · export <name> · remove <name> · new <name>{$r}\n\n";
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

    // Full in-chat command reference (shown by /help), grouped by purpose so the
    // newer commands are actually discoverable.
    private function slashHelp(): string {
        $c = "\033[36m"; $b = "\033[1m"; $d = "\033[2m"; $r = "\033[0m";
        $row = fn($cmd, $desc) => sprintf("  {$c}%-22s{$r}{$d}%s{$r}\n", $cmd, $desc);
        $out  = "\n  {$b}Commands{$r}\n\n";
        $out .= "  {$d}Conversation{$r}\n";
        $out .= $row('/chat · /agent', 'toggle chat-only vs. tool-using mode');
        $out .= $row('/retry · /regenerate', 're-run the last turn for a fresh answer');
        $out .= $row('/compact', 'summarize older messages to free context');
        $out .= $row('/new', 'start a fresh session');
        $out .= "\n  {$d}Models{$r}\n";
        $out .= $row('/model <name>', 'switch model (offers to pull if missing)');
        $out .= $row('/models', 'list installed models');
        $out .= $row('/pull <model>', 'download a model from Ollama');
        $out .= "\n  {$d}Files & edits{$r}\n";
        $out .= $row('@path/to/file', 'inline a file into your message');
        $out .= $row('/image <path>', 'attach an image (vision models)');
        $out .= $row('/undo', 'revert the most recent file edit');
        $out .= $row('/checkpoints', 'list saved edit checkpoints');
        $out .= "\n  {$d}Project & context{$r}\n";
        $out .= $row('/init', 'generate OLLAMADEV.md project memory');
        $out .= $row('/context · /status', 'show context fill & token usage');
        $out .= $row('/tools', 'list available tools');
        $out .= $row('/commands', 'list custom commands');
        $out .= $row('/skills', 'list skills the agent can load on demand');
        $out .= $row('/memory', 'browse the project knowledge graph (recall/remember)');
        $out .= $row('/permission <…>', 'manage tool approval (auto|ask|readonly)');
        $out .= "\n  {$d}Session{$r}\n";
        $out .= $row('/cd · /ls · /pwd', 'navigate the working directory');
        $out .= $row('/git <cmd>', 'run a git command');
        $out .= $row('/save · /session', 'save / show the current session');
        $out .= $row('/clear · /exit', 'clear screen / quit');
        $out .= "\n  {$d}Tip: Tab completes commands, paths, and model names. Ctrl-C interrupts a response.{$r}\n";
        return $out;
    }

    // /crew <task> [--max N] — run the OllamaDev Crew bench from inside a session.
    private function runCrew(string $args): string {
        $args = trim($args);
        if ($args === '') return "Usage: /crew <task> [options]\n" .
            "  --max N            number of parallel coders (default 4)\n" .
            "  --review           hold every branch for you to merge (nothing auto-merges)\n" .
            "  --auto-merge       merge audit-clean branches (override the self-modification guard)\n" .
            "  --no-research / --no-audit / --no-skills   skip that phase\n" .
            "  --focus \"text\"    domain/stack steer (also picks matching skill packs)\n" .
            "  --hosts a,b,c      run coders in parallel across multiple Ollama hosts\n" .
            "  per-role models:   --director-model / --coder-model / --auditor-model / --researcher-model <name>\n" .
            "  e.g. /crew add a /health route --coder-model qwen2.5-coder:7b --auditor-model deepseek-r1:32b\n";
        $opts = [];
        if (preg_match('/\s--max\s+(\d+)/', $args, $m)) { $opts['max'] = (int)$m[1]; $args = trim(preg_replace('/\s--max\s+\d+/', '', $args)); }
        if (preg_match('/(^|\s)--review(\s|$)/', $args)) { $opts['land'] = 'review'; $args = trim(preg_replace('/(^|\s)--review(\s|$)/', ' ', $args)); }
        elseif (preg_match('/(^|\s)--auto-merge(\s|$)/', $args)) { $opts['land'] = 'auto'; $args = trim(preg_replace('/(^|\s)--auto-merge(\s|$)/', ' ', $args)); }
        if (preg_match('/(^|\s)--no-research(\s|$)/', $args)) { $opts['research'] = false; $args = trim(preg_replace('/(^|\s)--no-research(\s|$)/', ' ', $args)); }
        if (preg_match('/(^|\s)--no-audit(\s|$)/', $args)) { $opts['audit'] = false; $args = trim(preg_replace('/(^|\s)--no-audit(\s|$)/', ' ', $args)); }
        if (preg_match('/(^|\s)--no-skills(\s|$)/', $args)) { $opts['skills'] = false; $args = trim(preg_replace('/(^|\s)--no-skills(\s|$)/', ' ', $args)); }
        if (preg_match('/\s--hosts\s+(\S+)/', $args, $m)) { $opts['hosts'] = array_values(array_filter(array_map('trim', explode(',', $m[1])))); $args = trim(preg_replace('/\s--hosts\s+\S+/', '', $args)); }
        // --focus accepts a quoted phrase or a single token.
        if (preg_match('/\s--focus\s+"([^"]*)"/', $args, $m)) { $opts['focus'] = $m[1]; $args = trim(preg_replace('/\s--focus\s+"[^"]*"/', '', $args)); }
        elseif (preg_match('/\s--focus\s+(\S+)/', $args, $m)) { $opts['focus'] = $m[1]; $args = trim(preg_replace('/\s--focus\s+\S+/', '', $args)); }
        // Per-role models: each takes one model name token.
        foreach (['director' => 'directorModel', 'coder' => 'coderModel', 'auditor' => 'auditorModel', 'researcher' => 'researcherModel'] as $role => $key) {
            if (preg_match('/\s--' . $role . '-model\s+(\S+)/', $args, $m)) { $opts[$key] = $m[1]; $args = trim(preg_replace('/\s--' . $role . '-model\s+\S+/', '', $args)); }
        }
        Crew::run(trim($args), $opts); // prints its own progress
        return '';
    }

    private function listCheckpoints(): string {
        $list = Checkpoints::list();
        if (empty($list)) return "No checkpoints.\n";
        $out = "\nCheckpoints (newest first):\n";
        foreach ($list as $i => $c) {
            $tag = $c['existed'] ? 'edit' : 'new';
            $out .= sprintf("  %2d  [%s]  %s  %s\n", $i + 1, $tag, $c['created_at'], $c['path']);
        }
        $out .= "  Use /undo to revert the most recent change.\n";
        return $out;
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
        $out .= "  {$d}/help for all commands · /model · /undo · @file · Ctrl-C interrupts{$r}\n\n";
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

    private function pullModel(string $name): string {
        $name = trim($name);
        if ($name === '') return "Usage: /pull <model>\n";
        if (Puller::pull($name)) { echo $this->switchModel($name); }
        return '';
    }

    private function switchModel(string $name): string {
        if (empty($name)) return "Usage: /model <name>\n";
        $resolved = $this->agent->resolveModel($name);
        if ($resolved === null) {
            $installed = $this->agent->listModels();
            if (!empty($installed) && Permission::isInteractive() && LineEditor::supported()) {
                echo "\033[33m  Model '$name' is not installed.\033[0m Pull it now? [y/N] ";
                $ans = strtolower(trim((string)fgets(STDIN)));
                if ($ans === 'y' || $ans === 'yes') {
                    if (Puller::pull($name)) { $resolved = $this->agent->resolveModel($name) ?? $name; }
                    else { return ''; }
                }
            }
        }
        if ($resolved === null) {
            $installed = $this->agent->listModels();
            return "\033[33mModel '$name' is not installed.\033[0m\n"
                . "  Installed: " . (empty($installed) ? '(none — is Ollama running?)' : implode(', ', $installed)) . "\n"
                . "  Pull it with: /pull $name\n";
        }
        $this->agent->setModel($resolved);
        $this->model = $resolved;
        $GLOBALS['currentSessionModel'] = $resolved;
        // Re-evaluate chat mode for the new model's size.
        $this->agent->setChatMode($this->agent->isSmallModel());
        // Clear the screen and redraw the banner so the header always reflects
        // the current model (the original banner is frozen in scrollback).
        return "\033[2J\033[H" . $this->renderBanner() . "\033[32m  ✓ switched to $resolved\033[0m\n";
    }

    private function retryLast(): string {
        $rewound = Regenerate::rewind($this->messages);
        if ($rewound === null) {
            return "\033[2m  Nothing to regenerate — no previous message.\033[0m\n";
        }
        $this->messages = $rewound;
        $this->save();
        echo "\033[2m  regenerating…\033[0m";
        $cleared = false;
        $this->agenticLoop(function($chunk) use (&$cleared) {
            if (!$cleared) { echo "\r\033[K"; $cleared = true; }
            echo $chunk;
        });
        if (!$cleared) echo "\r\033[K";
        echo "\n";
        $edited = $GLOBALS['editedFiles'] ?? [];
        if (!empty($edited)) {
            echo "\033[2m  \xe2\x9c\x8e " . implode(', ', array_unique($edited)) . "\033[0m\n";
            $GLOBALS['editedFiles'] = [];
        }
        $this->save();
        return '';
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
        $this->compactMessages(true);
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
    private function renderStatusLine(): string {
        $meter = Usage::contextMeter($this->countTokens());
        $turn = Usage::haveReal()
            ? " | last turn: " . Usage::lastPrompt() . " in + " . Usage::lastEval() . " out"
              . " | session total: " . Usage::totalPrompt() . " in + " . Usage::totalEval() . " out"
            : "";
        return "[Model: {$this->model} | Context: " . $meter . $turn . " | Messages: " . count($this->messages) . "]\n";
    }
    private function renderStatus(): void { echo "\n[Model: {$this->model} | Tokens: ~" . $this->countTokens() . " | Messages: " . count($this->messages) . "]\n"; }
    // Best-effort terminal width for drawing the prompt box.
    private function termWidth(): int {
        $cols = (int)getenv('COLUMNS');
        if ($cols <= 0) { $cols = (int)@exec('tput cols 2>/dev/null'); }
        if ($cols <= 0) $cols = 80;
        return $cols;
    }

    // Current directory with $HOME collapsed to ~.
    private function shortCwd(): string {
        $cwd = getcwd();
        $home = getenv('HOME') ?: '';
        if ($home !== '' && str_starts_with($cwd, $home)) $cwd = '~' . substr($cwd, strlen($home));
        return $cwd;
    }

    // Tab-completion for the line editor. Given the current line and cursor,
    // returns the glyph index where the active token starts and the full-token
    // replacement candidates: slash/base commands for the first word, then
    // context-aware completion (git subcommands, model names, file paths).
    private function completeInput(string $line, int $cur): array {
        $before = mb_substr($line, 0, $cur);
        preg_match('/(\S*)$/u', $before, $m);
        $token = $m[1] ?? '';
        $start = $cur - mb_strlen($token);
        $parts = preg_split('/\s+/', trim($before), -1, PREG_SPLIT_NO_EMPTY);
        $firstWord = count($parts) <= 1 && !preg_match('/\s$/u', $before);

        $cands = [];
        if ($firstWord) {
            $base = ['/help', '/model', '/models', '/pull', '/chat', '/agent', '/retry', '/regenerate', '/new', '/clear', '/compact',
                '/save', '/session', '/git', '/status', '/tools', '/context', '/pwd', '/cd', '/ls',
                '/permission', '/verbose', '/undo', '/checkpoints', '/init', '/crew', '/skills', '/memory', '/image', '/commands', '/exit', '/quit',
                'help', 'exit', 'quit', 'clear', 'model', 'models', 'tools', 'git', 'status', 'compact', 'context', 'new', 'cd', 'ls', 'init'];
            foreach ($base as $c) if ($token === '' || str_starts_with($c, $token)) $cands[] = $c;
        } else {
            $cmd = ltrim($parts[0], '/');
            if ($cmd === 'git') {
                foreach (['status', 'diff', 'log', 'branch', 'commit', 'push', 'pull', 'stash', 'checkout', 'add', 'fetch', 'merge', 'rebase'] as $g)
                    if (str_starts_with($g, $token)) $cands[] = $g;
            } elseif ($cmd === 'model' || $cmd === 'models' || $cmd === 'pull') {
                foreach ($this->agent->listModels() as $mn) if ($token === '' || str_starts_with($mn, $token)) $cands[] = $mn;
            } elseif ($cmd === 'cd' || in_array($cmd, ['view', 'cat', 'edit', 'write', 'grep', 'find', 'ls', 'diff', 'rm', 'cp', 'mv'])) {
                $cands = $this->completePath($token);
            }
        }
        sort($cands);
        return ['start' => $start, 'candidates' => $cands];
    }

    // Filesystem path completion: returns full replacement paths (with a trailing
    // slash on directories) for the given partial token.
    private function completePath(string $token): array {
        if (strpos($token, '/') !== false) {
            $parent = dirname($token);
            $prefix = $parent === '/' ? '/' : $parent . '/';
        } else {
            $parent = '.';
            $prefix = '';
        }
        if (!is_dir($parent)) return [];
        $needle = basename($token);
        $out = [];
        foreach (scandir($parent) as $f) {
            if ($f === '.' || $f === '..') continue;
            if ($needle !== '' && !str_starts_with($f, $needle)) continue;
            $full = $prefix . $f;
            $out[] = is_dir($parent . '/' . $f) ? $full . '/' : $full;
        }
        return $out;
    }

    // Read a line of user input. Uses the bordered raw-mode line editor on a real
    // terminal (typed text appears inside the box); falls back to a plain status
    // line + prompt for pipes, daemons, and other non-TTY contexts.
    private function readInput(): ?string {
        $mode = $this->agent->isChatMode() ? 'chat' : 'agent';
        if (LineEditor::supported()) {
            return LineEditor::readLine($this->model, $mode, $this->shortCwd(), $this->history,
                fn(string $line, int $cur) => $this->completeInput($line, $cur));
        }
        echo "\n\033[2m" . $this->model . '  ·  ' . $mode . '  ·  ' . $this->shortCwd() . "\033[0m\n\033[36m❯\033[0m ";
        $line = fgets(STDIN);
        return $line === false ? null : $line;
    }
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
                if (!empty($args)) { echo $this->switchModel($args); }
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

    // Provider-aware startup check. Returns true if the backend is reachable and
    // has at least one model; otherwise prints actionable, copy-pasteable steps so
    // a first run never dead-ends on a cryptic failure mid-prompt. The chat loop
    // still opens either way — the user can fix the backend and retry without restarting.
    private function preflight(): bool {
        $cyan = "\033[36m"; $dim = "\033[2m"; $yel = "\033[33m"; $r = "\033[0m";
        $host = $this->agent->host();
        $isLM = class_exists('ModelClient') && ModelClient::isOpenAiStyle($host);
        $name = $isLM ? 'LM Studio' : 'Ollama';

        if (!$this->agent->checkConnection()) {
            echo "\n{$yel}  ⚠ Can't reach {$name}{$r}{$dim} at " . ($host ?: 'the configured host') . "{$r}\n";
            if ($isLM) {
                echo "{$dim}    1. In LM Studio open the {$r}Developer{$dim} tab and {$r}Start Server{$dim} (default port 1234).{$r}\n";
                echo "{$dim}    2. Load a model in the server panel.{$r}\n";
                echo "{$dim}    Prefer Ollama? Drop {$r}{$cyan}--lmstudio{$r}{$dim} / unset {$r}{$cyan}OLLAMADEV_PROVIDER{$r}{$dim}.{$r}\n";
            } else {
                echo "{$dim}    1. Start the server:  {$r}{$cyan}ollama serve{$r}\n";
                echo "{$dim}    2. Pull a model:      {$r}{$cyan}ollama pull qwen2.5-coder{$r}\n";
                echo "{$dim}    Using a remote/other host? Set {$r}{$cyan}OLLAMA_HOST{$r}{$dim} or pass {$r}{$cyan}--host <url>{$r}{$dim}.{$r}\n";
            }
            echo "\n";
            return false;
        }

        // Reachable but empty — the next prompt would fail with no obvious reason.
        if (empty($this->agent->listModels())) {
            echo "\n{$yel}  ⚠ {$name} is running but has no models installed.{$r}\n";
            if ($isLM) echo "{$dim}    Download a model in LM Studio and load it in the server panel.{$r}\n";
            else echo "{$dim}    Pull one:  {$r}{$cyan}ollama pull qwen2.5-coder{$r}{$dim}  — or {$r}{$cyan}/pull <model>{$r}{$dim} once you're in.{$r}\n";
            echo "\n";
            return false;
        }
        return true;
    }

    public function start(): void {
        Permission::setInteractive(true); // enable approval prompts for mutating tools
        // Hosts that show their own model/title chrome (e.g. the desktop ADE) set
        // OLLAMADEV_NO_BANNER to suppress the ASCII banner and the model tip.
        $showBanner = !getenv('OLLAMADEV_NO_BANNER');
        if ($showBanner) echo $this->renderBanner();
        $ready = $this->preflight();

        if (!empty($this->messages)) {
            echo "\033[2m  resumed session · " . count($this->messages) . " messages\033[0m\n";
        }

        // Nudge new users toward a capable model for agentic work.
        if ($showBanner && $ready && !$this->isRecommended($this->model)) {
            echo "\033[2m  tip: for reliable tool use try a recommended model — \033[0m\033[36mollama pull qwen2.5-coder\033[0m\033[2m, then /model qwen2.5-coder:latest\033[0m\n";
        }

        // First-run hint: surface recovery/help so new users know edits are reversible.
        if ($showBanner && empty($this->messages)) {
            echo "\033[2m  /help for commands · edits preview a diff and are reversible with \033[0m\033[36m/undo\033[0m\033[2m (\033[0m\033[36m/checkpoints\033[0m\033[2m lists them)\033[0m\n";
        }

        while (true) {
            Hooks::run('beforePrompt');
            $raw = $this->readInput();
            if ($raw === null) { echo "\n"; break; } // EOF / Ctrl-D
            $input = trim($raw);
            if ($input === '') continue;
            $this->history[] = $input;

            // Slash commands are handled here and never sent to the model.
            if (str_starts_with($input, '/')) {
                $result = $this->handleSlashCommand($input);
                if ($result === false) {
                    echo "Unknown command: {$input}\nType /help for available commands.\n";
                    continue;
                }
                // Custom commands expand to a prompt that runs through the model.
                if (is_string($result) && str_starts_with($result, 'PROMPT:')) {
                    $input = substr($result, 7);
                    if (trim($input) === '') continue;
                } else {
                    echo $result;
                    continue;
                }
            }

            if ($this->handleCommand($input)) break;

            // Image attachments (@image / /image) are captured first; the
            // remaining text then has any non-image @path mentions inlined.
            $vin = Vision::extract($input);
            if (!empty($vin['images'])) {
                $this->addMessage('user', Mentions::expand($vin['text']), ['images' => $vin['images']]);
                echo "\033[2m  \xF0\x9F\x96\xBC attached " . count($vin['images']) . " image(s)\033[0m\n";
            } else {
                $this->addMessage('user', Mentions::expand($input));
            }

            // Minimal "thinking" indicator that is erased as soon as output
            // arrives, so a plain reply just appears under the prompt.
            echo "\n\033[2m  thinking…\033[0m";
            $cleared = false;
            $renderMd = Render::enabled();
            $finalBuf = '';
            $final = $this->agenticLoop(function($chunk) use (&$cleared, &$finalBuf, $renderMd) {
                if (!$cleared) { echo "\r\033[K"; $cleared = true; }
                if ($renderMd) { $finalBuf .= $chunk; }
                echo $chunk;
            });
            if (!$cleared) echo "\r\033[K";
            $fTrim = trim($final);
            if ($renderMd && $fTrim !== '' && str_ends_with(rtrim($finalBuf), $fTrim)) {
                $styled = Render::md($fTrim);
                if ($styled !== $fTrim) {
                    // Move to the first line of the just-streamed answer, then clear
                    // and reprint it styled. $back = number of line breaks (not +1;
                    // the cursor is already on the last line, and \033[0A would move
                    // up 1, so only emit the up-sequence when there's a line to climb).
                    $back = substr_count($fTrim, "\n");
                    if ($back > 0) echo "\033[" . $back . "A";
                    echo "\r\033[J" . $styled;
                }
            }
            if (class_exists('Interrupt') && Interrupt::aborted()) { Interrupt::reset(); echo "\033[2m  interrupted\033[0m"; }
            echo "\n";

            // Quietly note any files that were changed this turn.
            $edited = $GLOBALS['editedFiles'] ?? [];
            if (!empty($edited)) {
                echo "\033[2m  ✎ " . implode(', ', array_unique($edited)) . "\033[0m\n";
                Hooks::run('afterEdit', array_values(array_unique($edited)));
                $GLOBALS['editedFiles'] = [];
            }

            $this->compactMessages();
            $this->save();
        }
    }

    private function compactMessages(bool $force = false): void {
        $threshold = (int)Config::get('agents.compactThreshold', 30);
        $keepLast = (int)Config::get('agents.compactKeep', 8);
        if (!$force && count($this->messages) < $threshold) return;

        $toSummarize = array_slice($this->messages, 0, -$keepLast);
        $keep = array_slice($this->messages, -$keepLast);
        if (count($toSummarize) < 2) return;

        echo "\n\033[2m📝 compacting " . count($toSummarize) . " messages…\033[0m\n";

        // Smarter compaction: don't summarize away tool output the recent turns
        // still depend on. Collect file/path-like identifiers mentioned in the
        // kept window, then preserve verbatim the LATEST tool result that touches
        // each — so e.g. a `view foo.php` the next step edits survives compaction.
        $refs = [];
        foreach ($keep as $m) {
            if (preg_match_all('#[A-Za-z0-9_][A-Za-z0-9_./-]*\.[A-Za-z0-9]{1,8}#', (string)($m['content'] ?? ''), $mm)) {
                foreach ($mm[0] as $ref) $refs[$ref] = true;
            }
        }
        $preserved = [];
        foreach ($toSummarize as $msg) {
            if (($msg['role'] ?? '') !== 'tool') continue;
            $c = (string)($msg['content'] ?? '');
            foreach (array_keys($refs) as $ref) {
                if (strpos($c, $ref) !== false) $preserved[$ref] = ['t' => $msg['tool_name'] ?? 'tool', 'c' => substr($c, 0, 2000)];
            }
        }

        // Build a readable transcript and ask the model to summarize it. Fall
        // back to the old truncation join if the model is unavailable, so growth
        // is still bounded either way.
        $transcript = '';
        foreach ($toSummarize as $msg) {
            $content = trim((string)($msg['content'] ?? ''));
            if ($content === '') continue;
            $label = strtoupper($msg['role'] ?? '');
            if (!empty($msg['tool_name'])) $label .= '(' . $msg['tool_name'] . ')';
            $transcript .= "$label: $content\n";
        }

        $summary = $this->agent->summarize($transcript);
        if (trim($summary) === '') {
            $summary = '';
            foreach ($toSummarize as $msg) {
                $summary .= "- " . strtoupper($msg['role'] ?? '') . ": " . substr((string)($msg['content'] ?? ''), 0, 150) . "…\n";
            }
        }

        $kept = '';
        foreach ($preserved as $ref => $p) $kept .= "\n[still in use — {$p['t']} output for $ref]\n{$p['c']}\n";
        $content = "Summary of earlier conversation:\n" . $summary
            . ($kept !== '' ? "\n\nPreserved tool output the recent steps still reference:\n" . $kept : '');

        $this->messages = array_merge(
            [['id' => 'summary_' . time(), 'role' => 'system', 'content' => $content, 'created_at' => date('c')]],
            $keep
        );
        $this->save();

        $extra = $preserved ? ' (kept ' . count($preserved) . ' referenced tool output' . (count($preserved) === 1 ? '' : 's') . ')' : '';
        echo "\033[2m   compacted into a summary; keeping last $keepLast messages$extra.\033[0m\n";
    }

    public function runSingle(string $prompt): string {
        Permission::setInteractive(false); // one-shot: can't prompt, so don't block

        $cmdResult = $this->handleSlashCommand($prompt);
        if ($cmdResult !== false) {
            if (is_string($cmdResult) && str_starts_with($cmdResult, 'PROMPT:')) {
                $prompt = substr($cmdResult, 7);
            } else {
                echo $cmdResult;
                return '';
            }
        }

        $vin = Vision::extract($prompt);
        if (!empty($vin['images'])) {
            $this->addMessage('user', Mentions::expand($vin['text']), ['images' => $vin['images']]);
        } else {
            $this->addMessage('user', Mentions::expand($prompt));
        }
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
        if (class_exists('Interrupt')) Interrupt::begin();
        try {
        for ($i = 0; $i < $maxIter; $i++) {
            if (class_exists('Interrupt') && Interrupt::aborted()) break;
            $turn = $this->agent->chatTurn($this->getMessages());
            $response = $turn['content'];
            $calls = $turn['calls'];

            $clean = $this->agent->stripToolMarkup($response);
            // Store the assistant turn (cleaned, so markup doesn't pollute history).
            // When the model issued tool calls, attach them in Ollama's wire shape
            // so the follow-up tool results correlate back to the originating call
            // instead of the model re-issuing the same call every iteration.
            $assistantExtra = [];
            if (!empty($calls)) {
                $assistantExtra['tool_calls'] = array_map(
                    fn($c) => ['function' => ['name' => $c['name'], 'arguments' => (object)($c['params'] ?? [])]],
                    $calls
                );
            }
            $this->addMessage('assistant', $clean !== '' ? $clean : $response, $assistantExtra);

            $toolResults = $this->agent->executeCalls($calls);
            if (empty($toolResults)) {
                if ($emit) $emit($clean !== '' ? $clean : trim($response));
                return $clean !== '' ? $clean : trim($response);
            }

            // Show the model's reasoning text (without the tool markup) before results.
            if ($clean !== '' && $emit) $emit($clean . "\n");
            $maxOut = (int)Config::get('agents.maxToolOutput', 12000);
            foreach ($toolResults as $result) {
                // Cap result size so a single big read/grep can't overflow the
                // model's context window; tag it with tool_name so it pairs with
                // the assistant tool_call above.
                $content = (string)$result['content'];
                if (strlen($content) > $maxOut) {
                    $content = substr($content, 0, $maxOut)
                        . "\n…[truncated " . (strlen($content) - $maxOut) . " bytes]";
                }
                $name = $result['name'] ?? 'tool';
                $this->addMessage('tool', $content, ['tool_name' => $name]);
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
        if (!(class_exists('Interrupt') && Interrupt::aborted()) && $emit) $emit("\n(reached max tool iterations)\n");
        } finally {
            if (class_exists('Interrupt')) Interrupt::end();
        }
        return $final;
    }
}

