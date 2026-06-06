// Project-memory generator behind the `/init` command and the `init` top-level
// command. Scans the current project into a compact, deterministic digest
// (offline, no model) and then asks the active model to turn that digest into
// an OLLAMADEV.md file, which Agent::buildSystemPrompt() auto-loads as project
// context on subsequent runs.
class ProjectInit {
    // The memory file we generate. buildSystemPrompt() looks for this name first.
    const MEMORY_FILE = 'OLLAMADEV.md';

    // Directories never worth scanning (vendored / generated / VCS noise).
    private static array $skipDirs = [
        '.git', 'node_modules', 'vendor', '.svn', '.hg', 'dist', 'build',
        '.idea', '.vscode', '__pycache__', '.cache', 'target', '.next',
        'venv', '.venv', 'coverage',
    ];

    // Extension -> language label, used to summarise the codebase composition.
    private static array $langByExt = [
        'php' => 'PHP', 'js' => 'JavaScript', 'ts' => 'TypeScript', 'jsx' => 'JavaScript',
        'tsx' => 'TypeScript', 'py' => 'Python', 'go' => 'Go', 'rs' => 'Rust',
        'rb' => 'Ruby', 'java' => 'Java', 'kt' => 'Kotlin', 'c' => 'C', 'h' => 'C',
        'cpp' => 'C++', 'cc' => 'C++', 'cs' => 'C#', 'swift' => 'Swift',
        'sh' => 'Shell', 'html' => 'HTML', 'css' => 'CSS', 'scss' => 'CSS',
        'sql' => 'SQL', 'md' => 'Markdown',
    ];

    // Filenames that strongly signal the project's stack / tooling.
    private static array $keyFiles = [
        'composer.json', 'package.json', 'Cargo.toml', 'go.mod', 'pom.xml',
        'build.gradle', 'requirements.txt', 'pyproject.toml', 'Pipfile',
        'Gemfile', 'Makefile', 'Dockerfile', 'docker-compose.yml',
        '.editorconfig', 'tsconfig.json', 'build.sh', 'CMakeLists.txt',
    ];

    // Build a compact, human-readable digest of the project rooted at $dir.
    // Pure and offline so it can be unit-tested without a model or network.
    public static function scan(string $dir): string {
        $dir = rtrim($dir, '/');
        if ($dir === '') $dir = '.';
        $out = [];
        $out[] = 'PROJECT: ' . basename(realpath($dir) ?: $dir);

        // Language composition by file count (top entries only).
        $langCounts = [];
        $tree = [];
        $fileTotal = 0;
        self::walk($dir, $dir, 0, $langCounts, $tree, $fileTotal);
        arsort($langCounts);
        if (!empty($langCounts)) {
            $parts = [];
            foreach (array_slice($langCounts, 0, 8, true) as $lang => $n) $parts[] = "$lang ($n)";
            $out[] = 'LANGUAGES: ' . implode(', ', $parts);
        }
        $out[] = 'FILE COUNT (scanned): ' . $fileTotal;

        // Key/config files present at the root or nearby.
        $found = [];
        foreach (self::$keyFiles as $kf) {
            if (is_file($dir . '/' . $kf)) $found[] = $kf;
        }
        if (!empty($found)) $out[] = 'KEY FILES: ' . implode(', ', $found);

        // Git remote, if any (read-only, best-effort).
        $gitConfig = $dir . '/.git/config';
        if (is_file($gitConfig)) {
            $cfg = (string)@file_get_contents($gitConfig);
            if (preg_match('/url\s*=\s*(\S+)/', $cfg, $m)) $out[] = 'GIT REMOTE: ' . $m[1];
            else $out[] = 'GIT: repository (no remote)';
        }

        // Top-level structure (directories + a sample of files).
        if (!empty($tree)) {
            sort($tree);
            $out[] = "STRUCTURE:\n" . implode("\n", array_slice($tree, 0, 60));
        }

        // README excerpt gives the model the project's own description.
        foreach (['README.md', 'README', 'README.txt', 'readme.md'] as $rd) {
            if (is_file($dir . '/' . $rd)) {
                $txt = trim((string)@file_get_contents($dir . '/' . $rd));
                if ($txt !== '') {
                    if (strlen($txt) > 4000) $txt = substr($txt, 0, 4000) . "\n…[truncated]";
                    $out[] = "README ($rd):\n" . $txt;
                }
                break;
            }
        }

        return implode("\n\n", $out);
    }

    // Recursive directory walk: tallies languages, collects a shallow structure
    // listing, and counts files. Depth-limited and skip-listed so huge trees and
    // vendored dirs don't blow up the digest.
    private static function walk(string $root, string $dir, int $depth, array &$langCounts, array &$tree, int &$fileTotal): void {
        if ($depth > 3) return;
        $entries = @scandir($dir);
        if ($entries === false) return;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $path = $dir . '/' . $e;
            $rel = ltrim(substr($path, strlen($root)), '/');
            if (is_dir($path)) {
                if (in_array($e, self::$skipDirs, true) || $e[0] === '.') continue;
                if ($depth <= 1) $tree[] = $rel . '/';
                self::walk($root, $path, $depth + 1, $langCounts, $tree, $fileTotal);
            } elseif (is_file($path)) {
                $fileTotal++;
                $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                if (isset(self::$langByExt[$ext])) {
                    $lang = self::$langByExt[$ext];
                    $langCounts[$lang] = ($langCounts[$lang] ?? 0) + 1;
                }
                if ($depth === 0) $tree[] = $rel;
            }
        }
    }

    // Ask the model to turn the digest into a concise OLLAMADEV.md. Returns the
    // generated markdown, or '' on failure so the caller can report an error.
    public static function generate(Agent $agent, string $digest): string {
        $sys = ['role' => 'system', 'content' =>
            "You write a project-memory file (OLLAMADEV.md) for an AI coding assistant. " .
            "Given a scan of a codebase, produce concise Markdown that captures: what the " .
            "project is, its primary language(s) and stack, how it is built/run/tested, the " .
            "important directories and entry points, and any conventions a contributor must " .
            "follow. Be specific and terse. Use headings and bullet points. Do NOT invent " .
            "facts not supported by the scan, and do NOT call tools or ask questions — output " .
            "only the Markdown document."];
        $user = ['role' => 'user', 'content' => "Project scan:\n\n" . $digest .
            "\n\nWrite OLLAMADEV.md now."];
        try {
            return trim($agent->run([$sys, $user]));
        } catch (\Throwable $e) {
            return '';
        }
    }

    // Orchestrate the whole flow for a given working directory.
    // $interactive controls whether we prompt before overwriting an existing
    // file. Returns a status string to print. $emit (optional) streams progress.
    public static function run(Agent $agent, string $dir, bool $interactive): string {
        $target = rtrim($dir, '/') . '/' . self::MEMORY_FILE;
        if ($target[0] !== '/' && !preg_match('#^[A-Za-z]:#', $target)) {
            // Relative dir: resolve against cwd for the message only.
        }
        if (is_file($target)) {
            if (!$interactive) {
                return "\033[33m" . self::MEMORY_FILE . " already exists.\033[0m Run interactively or remove it first to regenerate.\n";
            }
            echo self::MEMORY_FILE . " already exists. Overwrite? [y/N] ";
            $ans = strtolower(trim((string)fgets(STDIN)));
            if ($ans !== 'y' && $ans !== 'yes') return "Cancelled — kept existing " . self::MEMORY_FILE . ".\n";
        }

        echo "\033[2m  scanning project…\033[0m\n";
        $digest = self::scan($dir);
        echo "\033[2m  generating " . self::MEMORY_FILE . " with the model…\033[0m\n";
        $md = self::generate($agent, $digest);
        if ($md === '') {
            return "\033[31mCould not generate " . self::MEMORY_FILE . " (is Ollama running?).\033[0m\n";
        }
        $ok = @file_put_contents($target, $md);
        if ($ok === false) return "\033[31mFailed to write " . self::MEMORY_FILE . ".\033[0m\n";
        return "\033[32m  ✓ wrote " . self::MEMORY_FILE . " (" . $ok . " bytes)\033[0m\n" .
            "  \033[2mIt will be loaded as project context on the next turn.\033[0m\n";
    }
}
