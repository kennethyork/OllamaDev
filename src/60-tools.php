class Tools {
    private static array $tools = [];

    public static function register(string $name, callable $fn): void { self::$tools[$name] = $fn; }
    public static function find(string $name): ?callable { return self::$tools[$name] ?? null; }
    public static function run(string $name, array $params): string {
        $fn = self::find($name);
        if (!$fn) return CmdError::toolNotFound($name);
        if (!Permission::check($name, $params)) return CmdError::permissionDenied($name);
        try { return $fn($params); } catch (Exception $e) { return CmdError::toolFailed($name, $e->getMessage()); }
    }
    public static function all(): array { return array_keys(self::$tools); }

    // Curated tool schemas for Ollama's native function-calling API.
    // A small, high-value set keeps local models reliable; everything else
    // remains reachable via the text-format fallback parser.
    public static function schemas(): array {
        $str = fn($d) => ['type' => 'string', 'description' => $d];
        $int = fn($d) => ['type' => 'integer', 'description' => $d];
        $fn = fn($name, $desc, $props, $required) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $desc,
                'parameters' => ['type' => 'object', 'properties' => $props, 'required' => $required],
            ],
        ];
        return [
            $fn('view', 'Read a file with line numbers.', [
                'file_path' => $str('Path to the file to read'),
                'offset' => $int('Start line (0-based, optional)'),
                'limit' => $int('Maximum number of lines (optional)'),
            ], ['file_path']),
            $fn('write', 'Create a new file or overwrite an existing file with the given content.', [
                'file_path' => $str('Path of the file to write'),
                'content' => $str('Full content to write to the file'),
            ], ['file_path', 'content']),
            $fn('edit', 'Replace the first occurrence of old_string with new_string in a file.', [
                'file_path' => $str('Path of the file to edit'),
                'old_string' => $str('Exact existing text to replace'),
                'new_string' => $str('Replacement text'),
            ], ['file_path', 'old_string', 'new_string']),
            $fn('ls', 'List the contents of a directory.', [
                'path' => $str('Directory path (defaults to current directory)'),
            ], []),
            $fn('grep', 'Search file contents recursively for a regular-expression pattern.', [
                'pattern' => $str('Regex pattern to search for'),
                'path' => $str('Directory or file to search (defaults to .)'),
                'include' => $str('Optional glob filter, e.g. *.php'),
            ], ['pattern']),
            $fn('glob', 'Find files matching a glob pattern.', [
                'pattern' => $str('Glob pattern, e.g. **/*.php'),
                'path' => $str('Base directory (defaults to .)'),
            ], ['pattern']),
            $fn('bash', 'Run a shell command and return its output.', [
                'command' => $str('The shell command to execute'),
            ], ['command']),
            $fn('search', 'Search the web and return result titles, URLs, and snippets.', [
                'query' => $str('The search query'),
                'limit' => $int('Max results (default 5)'),
            ], ['query']),
            $fn('fetch', 'Fetch the raw contents of a URL.', [
                'url' => $str('The URL to fetch'),
            ], ['url']),
            $fn('skill', 'Load a skill: returns detailed instructions for a named capability listed under AVAILABLE SKILLS. Call this BEFORE doing specialized work that matches a skill, then follow the returned steps.', [
                'name' => $str('The skill name to load'),
            ], ['name']),
            $fn('task', 'Delegate a focused sub-task to a fresh nested agent (its own short context, same model, bounded iterations). The sub-agent is READ-ONLY by default (it can read/search/analyze but not write files or run shell). Returns a concise result string. Use for self-contained research/analysis you want handled in isolation.', [
                'prompt' => $str('The sub-task to perform, described fully and self-contained'),
                'context' => $str('Optional extra context the sub-agent needs'),
                'max_iterations' => $int('Max tool/think iterations (default 6, capped at 12)'),
                'allow_writes' => ['type' => 'boolean', 'description' => 'Set true ONLY if the sub-task must create/edit files or run commands (default false = read-only)'],
            ], ['prompt']),
        ];
    }
}

class CmdError {
    public static function toolNotFound(string $tool): string {
        $suggestions = self::suggestSimilar($tool, Tools::all());
        $suggest = $suggestions ? " Did you mean: $suggestions?" : "";
        return "ollamadev: '$tool' is not a valid tool.$suggest\nRun 'ollamadev help tools' for available tools.";
    }

    public static function permissionDenied(string $tool): string {
        return "ollamadev: permission denied for tool '$tool'\nUse 'ollamadev permission allow $tool' to grant access.";
    }

    public static function toolFailed(string $tool, string $reason): string {
        return "ollamadev: tool '$tool' failed\n  Reason: $reason\nRun 'ollamadev help tools' for usage examples.";
    }

    public static function fileNotFound(string $path, string $tool = ''): string {
        $extra = $tool ? " for '$tool'" : '';
        return "ollamadev: file not found'$extra': $path\nHint: Use absolute paths or paths relative to current directory.";
    }

    public static function missingParam(string $param, string $tool = ''): string {
        $extra = $tool ? " in '$tool'" : '';
        return "ollamadev: missing required parameter '$param'$extra\nUsage: ollamadev help $tool";
    }

    public static function invalidArg(string $arg, string $reason, string $tool = ''): string {
        $extra = $tool ? " for '$tool'" : '';
        return "ollamadev: invalid argument '$arg'$extra\n  $reason";
    }

    private static function suggestSimilar(string $input, array $candidates): string {
        $closest = '';
        $minDist = PHP_INT_MAX;
        foreach ($candidates as $c) {
            $dist = levenshtein($input, $c);
            if ($dist < $minDist && $dist <= 3) {
                $minDist = $dist;
                $closest = $c;
            }
        }
        return $closest ? "'$closest'" : '';
    }
}

