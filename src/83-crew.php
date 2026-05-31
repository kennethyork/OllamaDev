// OLLAMADEV CREW — a local "bench" of agents (inspired by Plyrium Forge, Ollama-only).
// A Director decomposes a task into independent subtasks; each Coder works in its
// own git worktree/branch via the normal agent loop; an Auditor reviews every
// diff; audit-clean branches auto-merge, flagged/conflicting ones are held.
// 100% vanilla PHP + git + the local Ollama model. No parallel inference (one
// local model serialises anyway), so coders run sequentially in isolation.
class Crew {
    public static function run(string $task, array $opts = []): int {
        $task = trim($task);
        if ($task === '') { echo "Usage: ollamadev crew \"<high-level task>\"\n"; return 1; }
        if (!self::isGitRepo()) { echo "\033[31mcrew needs a git repository.\033[0m  Run `git init` first.\n"; return 1; }
        if (!self::gitWorktreeSupported()) { echo "\033[31mcrew needs `git worktree` (git 2.5+).\033[0m\n"; return 1; }

        $agent = new Agent();
        if (!$agent->checkConnection()) { echo "\033[31mCannot reach Ollama.\033[0m Start it with: ollama serve\n"; return 1; }
        $model = $agent->getModel();
        // Per-role models (Plyrium-style "mix and match per role"); each defaults
        // to the base model. Resolve names against what's installed.
        $rm = function ($key) use ($agent, $opts, $model) {
            $m = trim((string)($opts[$key] ?? ''));
            if ($m === '') return $model;
            return $agent->resolveModel($m) ?: $m;
        };
        $mDirector   = $rm('directorModel');
        $mResearcher = $rm('researcherModel');
        $mCoder      = $rm('coderModel');
        $mAuditor    = $rm('auditorModel');
        $maxCoders = max(1, min(6, (int)($opts['max'] ?? Config::get('crew.maxCoders', 4))));
        $maxIter = max(2, (int)($opts['iterations'] ?? Config::get('crew.coderIterations', 10)));
        $focus = trim((string)($opts['focus'] ?? '')); // domain/stack steer from a specialized team
        // Per-team skill packs: starter skills matched to the team's focus, loaded
        // into each coder's worktree so they pick up domain conventions on demand.
        $teamSkills = ($opts['skills'] ?? true) !== false ? CrewSkills::forFocus($focus) : [];

        $base = self::sh('git rev-parse --abbrev-ref HEAD');
        $baseCommit = self::sh('git rev-parse HEAD');
        if ($baseCommit === '') { echo "\033[31mNo commits yet.\033[0m Make an initial commit first.\n"; return 1; }
        $dirty = self::sh('git status --porcelain') !== '';
        if ($dirty) echo "\033[33m  ⚠ working tree has uncommitted changes — coders branch from the last commit (HEAD), not your working copy.\033[0m\n";

        $c = "\033[36m"; $d = "\033[2m"; $b = "\033[1m"; $g = "\033[32m"; $y = "\033[33m"; $r = "\033[0m";
        // runId may be supplied (by --panes, so the pane watcher knows it up front).
        $runId = (string)($opts['runId'] ?? '');
        if (!preg_match('/^crew_[0-9_]+$/', $runId)) $runId = 'crew_' . date('Ymd_His');
        echo "\n{$b}👥 OllamaDev Crew{$r}  {$d}model {$c}{$model}{$r}{$d} · base {$base}@" . substr($baseCommit, 0, 7) . "{$r}\n";
        if (!empty($teamSkills)) echo "  {$d}team skills: " . implode(', ', array_map(fn($s) => $s['name'], $teamSkills)) . "{$r}\n";
        // Show per-role models when any role differs from the base (mix-and-match).
        if ($mDirector !== $model || $mResearcher !== $model || $mCoder !== $model || $mAuditor !== $model) {
            echo "  {$d}roles:{$r} Director {$c}{$mDirector}{$r}{$d} · Researcher {$c}{$mResearcher}{$r}{$d} · Coder {$c}{$mCoder}{$r}{$d} · Auditor {$c}{$mAuditor}{$r}\n";
        }

        // ---- Researcher: survey the codebase, write a shared findings vault ----
        $research = '';
        if (($opts['research'] ?? true) !== false) {
            echo "\n{$b}▸ Researcher{$r} surveying the codebase…\n";
            $research = self::research($agent, $task, max(3, (int)Config::get("crew.researchIterations", 6)), $mResearcher);
            if ($research !== '') {
                $vaultDir = Config::dataDir() . '/crew/' . $runId;
                @mkdir($vaultDir, 0755, true);
                @file_put_contents($vaultDir . '/research.md', "# Crew research\n\nTask: $task\n\n" . $research);
                $brief = trim(preg_replace('/\s+/', ' ', $research));
                echo "  {$d}" . substr($brief, 0, 110) . (strlen($brief) > 110 ? '…' : '') . "{$r}\n";
            } else {
                echo "  {$d}(no findings){$r}\n";
            }
        }

        // ---- Director: decompose the task (informed by research) ----
        echo "\n{$b}▸ Director{$r} planning…\n";
        $subtasks = self::plan($agent, $task, $maxCoders, $research, $mDirector, $focus);
        if (empty($subtasks)) { echo "  Director produced no subtasks; treating the whole task as one.\n"; $subtasks = [['title' => 'Task', 'prompt' => $task]]; }
        $subtasks = array_slice($subtasks, 0, $maxCoders);
        foreach ($subtasks as $i => $st) echo "  {$c}" . ($i + 1) . ".{$r} " . ($st['title'] ?? 'subtask') . "\n";

        // Live kanban board the desktop polls ($HOME/.ollamadev/crew/current.json):
        // the Director's plan becomes To-do cards that move as the crew works.
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $boardFile = $home . '/.ollamadev/crew/current.json';
        @mkdir(dirname($boardFile), 0755, true);
        // Per-run log dir: each coder tees its activity to coder-<n>.log so the
        // desktop can show one live pane per coder (watch them work in parallel).
        $logDir = dirname($boardFile) . '/' . $runId;
        @mkdir($logDir, 0755, true);
        $board = ['task' => $task, 'runId' => $runId, 'active' => true, 'model' => $model, 'logDir' => $logDir, 'subtasks' => []];
        foreach ($subtasks as $i => $st) $board['subtasks'][] = ['n' => $i + 1, 'title' => $st['title'] ?? ('task ' . ($i + 1)), 'state' => 'todo'];
        $writeBoard = function () use (&$board, $boardFile) { $board['ts'] = time(); @file_put_contents($boardFile, json_encode($board)); };
        $setState = function (int $n, string $s) use (&$board, $writeBoard) { foreach ($board['subtasks'] as &$bs) { if ($bs['n'] === $n) $bs['state'] = $s; } unset($bs); $writeBoard(); };
        $writeBoard();

        // ---- Coders: one git worktree/branch each ----
        $wtRoot = sys_get_temp_dir() . '/ollamadev-crew/' . $runId;
        @mkdir($wtRoot, 0755, true);
        $results = [];

        // Phase 1 (sequential): create every worktree up front. `git worktree add`
        // mutates the shared repo, so it isn't safe to run concurrently.
        $jobs = [];
        foreach ($subtasks as $i => $st) {
            $n = $i + 1;
            $branch = 'crew/' . substr($runId, 6) . '-' . $n . '-' . self::slug($st['title'] ?? ('task' . $n));
            $wt = $wtRoot . '/c' . $n;
            $add = self::sh('git worktree add -b ' . escapeshellarg($branch) . ' ' . escapeshellarg($wt) . ' ' . escapeshellarg($baseCommit) . ' 2>&1');
            if (!is_dir($wt)) { echo "  {$y}skipped (worktree failed): {$add}{$r}\n"; $setState($n, 'held'); continue; }
            if (!empty($teamSkills)) CrewSkills::materialize($teamSkills, $wt); // seed domain skills (git-excluded)
            $setState($n, 'doing');
            $jobs[] = ['n' => $n, 'st' => $st, 'branch' => $branch, 'wt' => $wt, 'log' => $logDir . '/coder-' . $n . '.log'];
        }

        // Host pool for spreading coders across machines/GPUs (real parallel inference,
        // since one Ollama serves one model at a time). Base host always included.
        $extraHosts = [];
        if (!empty($opts['hosts']) && is_array($opts['hosts'])) $extraHosts = $opts['hosts'];
        elseif (is_array(Config::get('crew.hosts', null))) $extraHosts = Config::get('crew.hosts');
        elseif (is_array(Config::get('ollama.hosts', null))) $extraHosts = Config::get('ollama.hosts');
        $baseHost = Config::get('ollama.host', 'http://localhost:11434');
        $hosts = array_values(array_unique(array_filter(array_merge([$baseHost], array_map('trim', $extraHosts)))));
        $canFork = function_exists('pcntl_fork');
        $parallel = count($jobs) > 1 && count($hosts) > 1 && $canFork;

        // Phase 2: run coders. Parallel across hosts when possible; else sequential.
        if ($parallel) {
            echo "\n{$b}▸ Coders{$r} {$d}running " . count($jobs) . " in parallel across " . count($hosts) . " hosts (" . implode(', ', $hosts) . ")…{$r}\n";
            $pids = [];
            foreach ($jobs as $idx => $job) {
                $host = $hosts[$idx % count($hosts)];
                $resFile = $wtRoot . '/result-' . $job['n'] . '.json';
                $pid = pcntl_fork();
                if ($pid === 0) { // child: build in isolation, write the result, exit
                    self::runCoder($job['wt'], $job['st'], $mCoder, $maxIter, $research, $task, $focus, $host, $job['log']);
                    @file_put_contents($resFile, json_encode(self::collectCoderResult($job, $baseCommit)));
                    exit(0);
                } elseif ($pid > 0) {
                    $pids[$pid] = ['job' => $job, 'file' => $resFile];
                } else { // fork failed: run inline as a fallback
                    self::runCoder($job['wt'], $job['st'], $mCoder, $maxIter, $research, $task, $focus, $host, $job['log']);
                    $results[] = self::collectCoderResult($job, $baseCommit);
                }
            }
            foreach ($pids as $pid => $meta) {
                $status = 0; pcntl_waitpid($pid, $status);
                $cres = json_decode((string) @file_get_contents($meta['file']), true);
                if (is_array($cres)) $results[] = $cres;
                else $results[] = ['n' => $meta['job']['n'], 'title' => $meta['job']['st']['title'] ?? 'task', 'branch' => $meta['job']['branch'], 'wt' => $meta['job']['wt'], 'diff' => '', 'files' => [], 'empty' => true];
                $nfiles = is_array($cres) ? count($cres['files'] ?? []) : 0;
                echo "  {$g}✓{$r} Coder {$meta['job']['n']} {$d}" . ($nfiles ? "$nfiles file(s)" : 'no changes') . "{$r}\n";
            }
            usort($results, fn($a, $b2) => $a['n'] <=> $b2['n']);
        } else {
            $spread = count($hosts) > 1;
            foreach ($jobs as $idx => $job) {
                $host = $spread ? $hosts[$idx % count($hosts)] : '';
                echo "\n{$b}▸ Coder {$job['n']}{$r} {$d}{$job['branch']}" . ($host !== '' ? " · {$host}" : '') . "{$r}\n";
                self::runCoder($job['wt'], $job['st'], $mCoder, $maxIter, $research, $task, $focus, $host, $job['log']);
                $res = self::collectCoderResult($job, $baseCommit);
                echo "  " . ($res['empty'] ? "{$d}no changes{$r}" : count($res['files']) . " file(s) changed") . "\n";
                $results[] = $res;
            }
        }

        // ---- Auditor: review each diff (optional; without it, nothing auto-merges) ----
        $doAudit = ($opts['audit'] ?? true) !== false;
        echo "\n{$b}▸ Auditor{$r} " . ($doAudit ? "reviewing…" : "{$d}(disabled — all branches held for your review){$r}") . "\n";
        foreach ($results as &$res) {
            if ($res['empty']) { $res['audit'] = ['clean' => false, 'summary' => 'no changes', 'issues' => []]; echo "  {$d}#{$res['n']} skipped (empty){$r}\n"; continue; }
            if (!$doAudit) { $res['audit'] = ['clean' => false, 'summary' => 'no auditor — review manually', 'issues' => []]; continue; }
            $res['audit'] = self::audit($agent, $res['title'], $res["diff"], $task, $mAuditor);
            $vc = $res['audit']['clean'] ? "{$g}clean{$r}" : "{$y}flagged{$r}";
            echo "  #{$res['n']} {$vc} {$d}" . substr((string)($res['audit']['summary'] ?? ''), 0, 80) . "{$r}\n";
            foreach (($res['audit']['issues'] ?? []) as $iss) echo "      {$y}- " . substr((string)$iss, 0, 100) . "{$r}\n";
        }
        unset($res);

        // ---- Landing — gated. 'review': nothing auto-merges (default-safe for
        // self-modification). 'auto': merge audit-clean, hold flagged.
        // Self-modification safeguard: when the crew is run on the OllamaDev source
        // itself, default to review (hold everything) unless the user EXPLICITLY
        // opted into auto-merge with --auto-merge. Other repos keep the auto default.
        $explicit = $opts['land'] ?? '';
        if ($explicit !== '' ) {
            $land = $explicit;
        } elseif (self::isSelfRepo()) {
            $land = 'review';
            echo "  {$y}⚠ self-modification detected (this is the OllamaDev source) — review mode forced; nothing auto-merges. Use --auto-merge to override.{$r}\n";
        } else {
            $land = Config::get('crew.land', 'auto');
        }
        echo "\n{$b}▸ Landing{$r}" . ($land === 'review' ? " {$d}(review mode — nothing auto-merges){$r}" : '') . "\n";
        $merged = []; $held = [];
        foreach ($results as $res) {
            if ($res['empty']) { $setState($res['n'], 'held'); continue; }
            if ($land === 'review') { $held[] = $res; $setState($res['n'], 'held'); echo "  {$y}held{$r} #{$res['n']} {$res['branch']} {$d}(review mode){$r}\n"; continue; }
            if (empty($res['audit']['clean'])) { $held[] = $res; $setState($res['n'], 'held'); echo "  {$y}held{$r} #{$res['n']} {$res['branch']} {$d}(audit flagged){$r}\n"; continue; }
            $m = self::sh('git merge --no-ff --no-edit ' . escapeshellarg($res['branch']) . ' 2>&1');
            if (self::sh('git status --porcelain') !== '' && self::sh('git ls-files -u') !== '') {
                self::sh('git merge --abort 2>&1');
                $held[] = $res; $setState($res['n'], 'held'); echo "  {$y}held{$r} #{$res['n']} {$res['branch']} {$d}(merge conflict — review manually){$r}\n";
            } else {
                $merged[] = $res; $setState($res['n'], 'done'); echo "  {$g}merged{$r} #{$res['n']} {$res['branch']}\n";
            }
        }
        $board['active'] = false; $writeBoard();

        // ---- Cleanup worktrees (keep branches) ----
        foreach ($results as $res) self::sh('git worktree remove --force ' . escapeshellarg($res['wt']) . ' 2>&1');
        @rmdir($wtRoot);

        echo "\n{$b}Summary{$r}  {$g}" . count($merged) . " merged{$r} · {$y}" . count($held) . " held{$r}\n";
        if ($held) {
            echo "  {$d}Review held branches, then merge or discard:{$r}\n";
            foreach ($held as $res) echo "    git diff {$base}..{$res['branch']}   {$d}# then: git merge {$res['branch']}  (or: git branch -D {$res['branch']}){$r}\n";
        }
        return 0;
    }

    // Researcher worker: read-only survey of the codebase → shared findings text.
    private static function research(Agent $agent, string $task, int $maxIter, string $model = ''): string {
        $prevMode = Permission::getMode(); $prevInt = Permission::isInteractive();
        Permission::setMode('readonly'); Permission::setInteractive(false);
        $findings = '';
        try {
            $a = new Agent(); $a->setModel($model !== '' ? $model : $agent->getModel());
            $sys = ['role' => 'system', 'content' =>
                "You are the Researcher. Investigate the codebase using READ-ONLY tools (ls, grep, view, glob, find) " .
                "to gather context relevant to the task. Then give a concise findings briefing: key files and where " .
                "they are, conventions, entry points, and anything the coders must know to avoid mistakes. Do NOT edit files."];
            $messages = [$sys, ['role' => 'user', 'content' => "Task:\n$task\n\nInvestigate, then summarize your findings."]];
            $last = '';
            for ($i = 0; $i < $maxIter; $i++) {
                echo "\033[2m·\033[0m"; @flush();   // heartbeat so it never looks hung
                $turn = $a->chatTurn($messages); $calls = $turn['calls'] ?? [];
                $clean = $a->stripToolMarkup((string)($turn['content'] ?? ''));
                $messages[] = ['role' => 'assistant', 'content' => (string)($turn['content'] ?? '')];
                if (empty($calls)) { if ($clean !== '') $last = $clean; break; }
                foreach ($a->executeCalls($calls) as $rr) $messages[] = ['role' => 'tool', 'content' => (string)($rr['content'] ?? ''), 'tool_name' => $rr['name'] ?? 'tool'];
            }
            echo "\n";
            $findings = $last;
        } catch (\Throwable $e) {} finally {
            Permission::setMode($prevMode); Permission::setInteractive($prevInt);
        }
        return trim($findings);
    }

    // Director: ask the model for a JSON list of independent subtasks.
    private static function plan(Agent $agent, string $task, int $max, string $research = '', string $model = '', string $focus = ''): array {
        $sys = ['role' => 'system', 'content' =>
            "You are the Director of a team of coding agents. Decompose the user's task into at most $max " .
            "INDEPENDENT subtasks that, where possible, touch DIFFERENT files so they can be built in parallel " .
            "without conflicts. If the task is small, return a single subtask. Output ONLY JSON: " .
            '{"subtasks":[{"title":"short label","prompt":"a complete, self-contained instruction for one coder"}]}'];
        $ctx = $research !== '' ? "\n\nResearcher findings:\n" . substr($research, 0, 6000) : '';
        $fc = $focus !== '' ? "\n\nDomain/stack focus: $focus" : '';
        $user = ['role' => 'user', 'content' => "Task:\n$task" . $fc . $ctx];
        $j = ModelClient::default()->chatJson($model !== "" ? $model : $agent->getModel(), [$sys, $user]);
        $subs = is_array($j) && isset($j['subtasks']) && is_array($j['subtasks']) ? $j['subtasks'] : [];
        $clean = [];
        foreach ($subs as $s) {
            if (!is_array($s)) continue;
            $p = trim((string)($s['prompt'] ?? $s['task'] ?? ''));
            if ($p === '') continue;
            $clean[] = ['title' => trim((string)($s['title'] ?? 'subtask')) ?: 'subtask', 'prompt' => $p];
        }
        return $clean;
    }

    // Commit a coder's work (excluding .ollamadev) and capture its diff/files.
    private static function collectCoderResult(array $job, string $baseCommit): array {
        $wt = $job['wt']; $st = $job['st']; $n = $job['n'];
        self::sh('git -C ' . escapeshellarg($wt) . ' add -A -- . ' . escapeshellarg(':(exclude).ollamadev'));
        $changed = self::sh('git -C ' . escapeshellarg($wt) . ' diff --cached --name-only') !== '';
        if ($changed) self::sh('git -C ' . escapeshellarg($wt) . ' commit -q -m ' . escapeshellarg('crew: ' . ($st['title'] ?? 'task ' . $n)) . ' 2>&1');
        $diff = self::sh('git -C ' . escapeshellarg($wt) . ' diff ' . escapeshellarg($baseCommit) . ' HEAD');
        $files = array_values(array_filter(explode("\n", self::sh('git -C ' . escapeshellarg($wt) . ' diff --name-only ' . escapeshellarg($baseCommit) . ' HEAD'))));
        return ['n' => $n, 'title' => $st['title'] ?? 'task', 'branch' => $job['branch'], 'wt' => $wt, 'diff' => $diff, 'files' => $files, 'empty' => $diff === ''];
    }

    // Coder: a bounded agent loop in the worktree (auto permissions, isolated).
    // $host pins this coder to a specific Ollama host for parallel runs.
    private static function runCoder(string $wt, array $st, string $model, int $maxIter, string $research = '', string $goal = '', string $focus = '', string $host = '', string $logFile = ''): void {
        $prevCwd = getcwd();
        $oldMode = Permission::getMode(); $oldInt = Permission::isInteractive();
        @chdir($wt);
        Permission::setMode('auto'); Permission::setInteractive(false);
        // Tee coder activity to a per-coder log so the desktop can show a live pane.
        $log = function (string $s) use ($logFile) { if ($logFile !== '') @file_put_contents($logFile, $s, FILE_APPEND); };
        $log("▸ " . ($st['title'] ?? 'subtask') . "\n" . ($host !== '' ? "host: $host\n" : '') . "model: " . ($model ?: '(base)') . "\n\n");
        try {
            $agent = new Agent();
            if ($host !== '') $agent->setHost($host);
            $resolved = $agent->resolveModel($model); $agent->setModel($resolved ?: $model);
            $ctx = $research !== '' ? "\n\nShared research (from the Researcher):\n" . substr($research, 0, 4000) : '';
            $goalLine = $goal !== '' ? "Overall goal: $goal\n\n" : '';
            $focusLine = $focus !== '' ? "Domain/stack focus (follow its conventions): $focus\n\n" : '';
            $prompt = "You are a coding agent in an isolated git worktree. You MUST actually make the changes by " .
                "calling your tools (write/edit/bash) — do not merely describe them. Keep changes focused; when the " .
                "files are written, stop.\n\n" . $focusLine . $goalLine .
                "Your subtask: " . ($st['title'] ?? '') . "\n" . ($st['prompt'] ?? '') . $ctx;
            $messages = [['role' => 'user', 'content' => $prompt]];
            $dbg = (bool)getenv('CREW_DEBUG');
            for ($i = 0; $i < $maxIter; $i++) {
                echo "\033[2m·\033[0m"; @flush();   // heartbeat
                $turn = $agent->chatTurn($messages);
                $calls = $turn['calls'] ?? [];
                $think = trim(preg_replace('/\s+/', ' ', $agent->stripToolMarkup((string)($turn['content'] ?? ''))));
                if ($dbg) fwrite(STDERR, "    [coder iter $i] calls=" . count($calls) . " content=" . substr($think, 0, 120) . "\n");
                if ($think !== '') $log("· " . substr($think, 0, 300) . "\n");
                $messages[] = ['role' => 'assistant', 'content' => (string)($turn['content'] ?? '')];
                if (empty($calls)) break;
                foreach ($agent->executeCalls($calls) as $rr) {
                    $name = $rr['name'] ?? '?';
                    $args = isset($rr['arguments']) && is_array($rr['arguments']) ? implode(' ', array_map(fn($v) => is_scalar($v) ? substr((string)$v, 0, 40) : '', $rr['arguments'])) : '';
                    $snippet = substr(preg_replace('/\s+/', ' ', (string)($rr['content'] ?? '')), 0, 100);
                    if ($dbg) fwrite(STDERR, "      -> $name: " . substr($snippet, 0, 80) . "\n");
                    $log("  → $name " . trim($args) . ($snippet !== '' ? "  ⇒ $snippet" : '') . "\n");
                    $messages[] = ['role' => 'tool', 'content' => (string)($rr['content'] ?? ''), 'tool_name' => $name];
                }
            }
            echo "\n";
            $log("\n✓ done\n");
        } catch (\Throwable $e) {
            echo "  \033[31mcoder error: " . $e->getMessage() . "\033[0m\n";
            $log("\n✗ error: " . $e->getMessage() . "\n");
        } finally {
            Permission::setMode($oldMode); Permission::setInteractive($oldInt);
            @chdir($prevCwd);
        }
    }

    // Auditor: review a diff, return ['clean'=>bool,'summary'=>string,'issues'=>[]].
    private static function audit(Agent $agent, string $title, string $diff, string $goal = '', string $model = ''): array {
        $diff = substr($diff, 0, 16000);
        $sys = ['role' => 'system', 'content' =>
            "You are a meticulous code Auditor. Review the git diff for correctness, security (secrets, injection, " .
            "unsafe shell), and whether it accomplishes the subtask WITHIN the overall goal. Output ONLY JSON: " .
            '{"clean": true|false, "summary": "one line", "issues": ["..."]}. ' .
            "Mark clean=false if there are real problems, OR if the change is OFF-SCOPE: it adds files unrelated to " .
            "the goal, introduces a language/framework the project doesn't use, or includes scaffolding that wasn't " .
            "requested. Minor style nits do not block."];
        $user = ['role' => 'user', 'content' => ($goal !== '' ? "Overall goal: $goal\n" : '') . "Subtask: $title\n\nDiff:\n$diff"];
        $j = ModelClient::default()->chatJson($model !== "" ? $model : $agent->getModel(), [$sys, $user]);
        if (!is_array($j)) return ['clean' => false, 'summary' => 'audit unavailable (could not parse review)', 'issues' => []];
        return [
            'clean' => (bool)($j['clean'] ?? false),
            'summary' => (string)($j['summary'] ?? ''),
            'issues' => is_array($j['issues'] ?? null) ? $j['issues'] : [],
        ];
    }

    // Extract the first balanced JSON object from model text (handles ``` fences).
    public static function extractJson(string $s): ?array {
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] !== '{') continue;
            $depth = 0; $inStr = false; $esc = false;
            for ($j = $i; $j < $len; $j++) {
                $ch = $s[$j];
                if ($inStr) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $inStr = false; continue; }
                if ($ch === '"') $inStr = true;
                elseif ($ch === '{') $depth++;
                elseif ($ch === '}') { $depth--; if ($depth === 0) { $cand = substr($s, $i, $j - $i + 1); $d = json_decode($cand, true); if (is_array($d)) return $d; break; } }
            }
        }
        return null;
    }

    public static function slug(string $s): string {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
        return trim(substr($s, 0, 32), '-') ?: 'task';
    }

    // Split one tmux pane per coder as their logs appear (drives `crew --panes`).
    // Runs as a detached helper process alongside the crew. $sess is the tmux
    // session to target (ignored when $inTmux — then it uses the current session).
    public static function watchPanes(string $runId, string $sess, bool $inTmux): void {
        if (!preg_match('/^crew_[0-9_]+$/', $runId)) return;
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $dir = $home . '/.ollamadev/crew/' . $runId;
        $boardFile = $home . '/.ollamadev/crew/current.json';
        $tgt = $inTmux ? '' : (' -t ' . escapeshellarg($sess));
        $alive = fn() => $inTmux || trim((string)@shell_exec('tmux has-session -t ' . escapeshellarg($sess) . ' >/dev/null 2>&1 && echo ok')) === 'ok';
        $deadline = time() + 1800; // 30-minute safety cap
        if (!$inTmux) { while (time() < $deadline && !$alive()) usleep(200000); } // wait for the session
        $seen = [];
        while (time() < $deadline) {
            if (!$alive()) break;
            foreach (glob($dir . '/coder-*.log') ?: [] as $f) {
                if (!preg_match('/coder-(\d+)\.log$/', $f, $m)) continue;
                $n = (int)$m[1];
                if (isset($seen[$n])) continue;
                $seen[$n] = true;
                @shell_exec('tmux split-window' . $tgt . ' ' . escapeshellarg('tail -n +1 -f ' . $f) . ' >/dev/null 2>&1');
                @shell_exec('tmux select-layout' . $tgt . ' tiled >/dev/null 2>&1');
            }
            $board = json_decode((string)@file_get_contents($boardFile), true);
            if (is_array($board) && ($board['runId'] ?? '') === $runId && ($board['active'] ?? true) === false) break;
            sleep(1);
        }
    }

    private static function isGitRepo(): bool { return self::sh('git rev-parse --is-inside-work-tree 2>/dev/null') === 'true'; }
    // True when the working repo IS the OllamaDev source (self-modification): the
    // build header carrying OLLAMADEV_VERSION plus build.sh are a strong signature.
    private static function isSelfRepo(): bool {
        $root = self::sh('git rev-parse --show-toplevel 2>/dev/null');
        if ($root === '' || !is_file($root . '/build.sh')) return false;
        $header = $root . '/src/00-header.php';
        return is_file($header) && strpos((string)@file_get_contents($header), 'OLLAMADEV_VERSION') !== false;
    }
    private static function gitWorktreeSupported(): bool { return stripos(self::sh('git worktree -h 2>&1'), 'usage') !== false || self::sh('git worktree list 2>/dev/null') !== ''; }
    private static function sh(string $cmd): string { return trim((string)@shell_exec($cmd)); }
}
