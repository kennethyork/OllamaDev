// WATCH — an always-on local agent. Polls the working tree for file changes and,
// whenever something changes, runs a task against the changed files (run tests,
// auto-fix, lint, document, …). Continuous agents are cheap here because the
// compute is local and free — a cloud tool would meter every tick.
//   ollamadev watch "run the tests and fix any failures"
//   ollamadev watch "update docs for changed files" src docs --interval 3
//   ollamadev watch "lint" --once          # run once on the next change, then exit
class Watcher {
    // Source extensions worth reacting to (skip binaries, lockfiles, media).
    private const EXT = ['php','js','ts','jsx','tsx','py','go','rs','rb','java','c','h','cpp','cs',
        'html','css','json','yml','yaml','md','sh','sql','vue','svelte'];
    private const SKIP_DIRS = ['.git','.ollamadev','node_modules','vendor','dist','build','.build','.svn','__pycache__'];

    public static function run(string $task, array $paths, array $opts = []): int {
        $task = trim($task);
        if ($task === '') { echo "Usage: ollamadev watch \"<task>\" [paths...] [--interval N] [--once]\n"; return 1; }
        $interval = max(1, (int)($opts['interval'] ?? 2));
        $once = !empty($opts['once']);
        $maxIter = max(2, (int)($opts['iterations'] ?? Config::get('watch.iterations', 8)));
        $roots = $paths ?: [getcwd()];

        $agent = new Agent();
        if (!$agent->checkConnection()) { echo "\033[31mCannot reach the model backend.\033[0m Start Ollama (ollama serve) first.\n"; return 1; }

        $c = "\033[36m"; $d = "\033[2m"; $g = "\033[32m"; $y = "\033[33m"; $b = "\033[1m"; $r = "\033[0m";
        $mode = Permission::getMode();
        echo "\n{$b}👁  OllamaDev Watch{$r}  {$d}model {$c}" . $agent->getModel() . "{$r}{$d} · " . implode(', ', array_map('basename', $roots)) . " · every {$interval}s · {$mode} mode{$r}\n";
        echo "{$d}Task: {$task}{$r}\n";
        if ($mode === 'ask') echo "{$y}  tip: run with --auto so fixes apply without prompting on every change.{$r}\n";
        echo "{$d}Watching for changes… (Ctrl-C to stop){$r}\n";

        $prev = self::snapshot($roots);
        $runs = 0;
        while (true) {
            usleep($interval * 1000000);
            $now = self::snapshot($roots);
            $changed = self::diff($prev, $now);
            $prev = $now;
            if (empty($changed)) continue;

            $runs++;
            $list = array_slice($changed, 0, 12);
            echo "\n{$b}▸ change{$r} {$d}" . date('H:i:s') . " · " . count($changed) . " file(s): " . implode(', ', array_map(fn($f) => self::rel($f, $roots), $list)) . (count($changed) > 12 ? '…' : '') . "{$r}\n";
            self::act($agent, $task, array_map(fn($f) => self::rel($f, $roots), $changed), $maxIter);
            if ($once) { echo "\n{$d}--once: done.{$r}\n"; return 0; }
            // Re-snapshot so the agent's own edits don't immediately retrigger.
            $prev = self::snapshot($roots);
        }
    }

    // One bounded agent pass over the task, honoring the active permission mode
    // (so `--auto` applies fixes, `ask` prompts, `readonly` just reports).
    private static function act(Agent $agent, string $task, array $changed, int $maxIter): void {
        $prompt = "Files just changed: " . implode(', ', $changed) . "\n\n" .
            "Do the following, using your tools where needed, then stop:\n" . $task;
        $messages = [['role' => 'user', 'content' => $prompt]];
        $d = "\033[2m"; $r = "\033[0m";
        try {
            for ($i = 0; $i < $maxIter; $i++) {
                $turn = $agent->chatTurn($messages);
                $calls = $turn['calls'] ?? [];
                $think = trim(preg_replace('/\s+/', ' ', $agent->stripToolMarkup((string)($turn['content'] ?? ''))));
                if ($think !== '') echo "  {$d}" . substr($think, 0, 200) . "{$r}\n";
                $messages[] = ['role' => 'assistant', 'content' => (string)($turn['content'] ?? '')];
                if (empty($calls)) break;
                foreach ($agent->executeCalls($calls) as $rr) {
                    $name = $rr['name'] ?? '?';
                    $snip = substr(preg_replace('/\s+/', ' ', (string)($rr['content'] ?? '')), 0, 90);
                    echo "  {$d}→ $name  $snip{$r}\n";
                    $messages[] = ['role' => 'tool', 'content' => (string)($rr['content'] ?? ''), 'tool_name' => $name];
                }
            }
        } catch (\Throwable $e) {
            echo "  \033[31mwatch error: " . $e->getMessage() . "\033[0m\n";
        }
    }

    // path => mtime for every source file under the roots.
    private static function snapshot(array $roots): array {
        $snap = [];
        foreach ($roots as $root) {
            if (is_file($root)) { $snap[$root] = @filemtime($root) ?: 0; continue; }
            if (!is_dir($root)) continue;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                        function ($cur) {
                            $name = $cur->getFilename();
                            if ($cur->isDir()) return !in_array($name, self::SKIP_DIRS, true);
                            return in_array(strtolower($cur->getExtension()), self::EXT, true);
                        }
                    )
                );
                foreach ($it as $f) { if ($f->isFile()) $snap[$f->getPathname()] = $f->getMTime(); }
            } catch (\Throwable $e) { /* unreadable dir — skip */ }
        }
        return $snap;
    }

    // Files that are new or whose mtime advanced. (Deletions don't trigger work.)
    private static function diff(array $old, array $new): array {
        $changed = [];
        foreach ($new as $path => $mtime) {
            if (!isset($old[$path]) || $old[$path] < $mtime) $changed[] = $path;
        }
        return $changed;
    }

    private static function rel(string $path, array $roots): string {
        foreach ($roots as $root) {
            $root = is_dir($root) ? rtrim($root, '/') . '/' : $root;
            if (str_starts_with($path, $root)) return substr($path, strlen($root));
        }
        $cwd = rtrim((string)getcwd(), '/') . '/';
        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
    }
}
