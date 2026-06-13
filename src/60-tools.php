class Tools {
    private static array $tools = [];

    public static function register(string $name, callable $fn): void { self::$tools[$name] = $fn; }
    public static function find(string $name): ?callable { return self::$tools[$name] ?? null; }
    public static function run(string $name, array $params): string {
        $fn = self::find($name);
        if (!$fn) return CmdError::toolNotFound($name);
        if (!Permission::check($name, $params)) return CmdError::permissionDenied($name);
        // PreToolUse hooks may block a tool (exit non-zero); the reason goes to the model.
        if (class_exists('Hooks')) { $block = Hooks::preToolUse($name, $params); if ($block !== null) return "Blocked by PreToolUse hook: $block"; }
        try { $out = $fn($params); } catch (Exception $e) { return CmdError::toolFailed($name, $e->getMessage()); }
        if (class_exists('Hooks')) Hooks::postToolUse($name, $params, $out);
        return $out;
    }
    public static function all(): array { return array_keys(self::$tools); }

    // Curated tool schemas for Ollama's native function-calling API.
    // A small, high-value set keeps local models reliable; everything else
    // remains reachable via the text-format fallback parser.
    public static function schemas(): array {
        $str = fn($d) => ['type' => 'string', 'description' => $d];
        $int = fn($d) => ['type' => 'integer', 'description' => $d];
        // NOTE: an EMPTY $props must serialize as a JSON object ({}), not an
        // array ([]). PHP json_encode turns [] into [], and Ollama (>=0.23)
        // rejects the whole request with HTTP 400 ("Value looks like object,
        // but can't find closing '}' symbol") — which silently kills native
        // tool-calling for every model. Cast to object so {} is emitted.
        $fn = fn($name, $desc, $props, $required) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $desc,
                'parameters' => ['type' => 'object', 'properties' => (object)$props, 'required' => $required],
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
            $fn('multi_edit', 'Apply several edits to ONE file in a single atomic operation (all apply or none). Prefer this over multiple edit calls on the same file.', [
                'file_path' => $str('Path of the file to edit'),
                'edits' => ['type' => 'array', 'description' => 'Edits applied in order: [{"old_string":..,"new_string":..,"replace_all":false}]', 'items' => ['type' => 'object']],
            ], ['file_path', 'edits']),
            $fn('todo_write', 'Create or replace the session todo list to plan and track multi-step work. Pass the FULL list each time; mark items in_progress/completed as you go.', [
                'todos' => ['type' => 'array', 'description' => 'Full list: [{"content":"..","status":"pending|in_progress|completed","activeForm":".."}]', 'items' => ['type' => 'object']],
            ], ['todos']),
            $fn('clear_board', 'Clear/dismiss the crew kanban board (crew cards, ideas, AND manual cards). ONLY call this when the user EXPLICITLY asks to clear/dismiss/reset the board in their latest message — NEVER on your own initiative or as cleanup. Refused while a crew run is active.', [
                'confirm' => ['type' => 'boolean', 'description' => 'Must be true. Set true ONLY when the user explicitly asked to clear the board in their latest message.'],
            ], ['confirm']),
            $fn('exit_plan_mode', 'Call this ONLY in plan mode, after you have researched (read-only) and are ready to act. Presents your plan to the user for approval; on yes, plan mode ends and you may edit. Do NOT call it for pure research/answer tasks.', [
                'plan' => $str('The plan: the concrete steps you intend to take, in markdown.'),
            ], ['plan']),
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
            $fn('code_search', 'Semantic search over THIS repo by meaning (local embeddings), not keywords. Use it to locate where a concept/feature lives when you do not know the exact name. Returns file:line ranges + snippets. Requires a built index (ollamadev index build).', [
                'query' => $str('What to find, in natural language'),
                'limit' => $int('Max results (default 8)'),
            ], ['query']),
            $fn('run_tests', 'Run this project\'s test suite (auto-detected) and return pass/fail + output. Use it to VERIFY your changes actually work before finishing.', [], []),
            $fn('skill', 'Load a skill: returns detailed instructions for a named capability listed under AVAILABLE SKILLS. Call this BEFORE doing specialized work that matches a skill, then follow the returned steps.', [
                'name' => $str('The skill name to load'),
            ], ['name']),
            $fn('recall', 'Read project memory: pass slug to read one note, query to search, or neither to list. Memories are durable facts about this project (listed under PROJECT MEMORY).', [
                'slug' => $str('A memory slug to read in full'),
                'query' => $str('Text to search memory titles/tags/bodies'),
            ], []),
            $fn('remember', 'Save a durable fact about this project as a linked memory note. Link related notes inside content with [[slug]]. Use for decisions, conventions, gotchas worth keeping across sessions.', [
                'title' => $str('Short title for the note'),
                'content' => $str('The fact/notes to store (may contain [[slug]] links)'),
                'tags' => $str('Optional comma-separated tags'),
            ], ['content']),
            $fn('task', 'Delegate a focused sub-task to a fresh nested agent (its own short context, same model, bounded iterations). The sub-agent is READ-ONLY by default (it can read/search/analyze but not write files or run shell). Returns a concise result string. Use for self-contained research/analysis you want handled in isolation.', [
                'prompt' => $str('The sub-task to perform, described fully and self-contained'),
                'context' => $str('Optional extra context the sub-agent needs'),
                'max_iterations' => $int('Max tool/think iterations (default 6, capped at 12)'),
                'allow_writes' => ['type' => 'boolean', 'description' => 'Set true ONLY if the sub-task must create/edit files or run commands (default false = read-only)'],
            ], ['prompt']),
        ];
    }

    // Additional tools advertised in text-protocol mode beyond the curated
    // native set — files, code-intelligence, and the full git suite. Params are
    // taken verbatim from the registrations (aliases like read/cat and internal
    // helpers like print/echo/ok are intentionally omitted; `bash` covers the
    // long tail). Format: name => [required[], optional[], description].
    public static function extraTextTools(): array {
        return [
            // Files & directories
            'head'      => [['file_path'], ['n'], 'First N lines of a file (default 10).'],
            'tail'      => [['file_path'], ['n'], 'Last N lines of a file (default 10).'],
            'find'      => [[], ['path', 'name', 'type'], 'Find files by name/type under a path.'],
            'tree'      => [[], ['path', 'depth'], 'Directory tree (depth default 2).'],
            'stat'      => [['file_path'], [], 'File size, mtime, and permissions.'],
            'diff'      => [['file1', 'file2'], [], 'Unified diff between two files.'],
            'wc'        => [['file_path'], [], 'Line / word / byte counts.'],
            'sort'      => [['file_path'], [], 'File contents sorted.'],
            'uniq'      => [['file_path'], [], 'Collapse adjacent duplicate lines.'],
            'mkdir'     => [['path'], ['parents'], 'Create a directory (parents=true for -p).'],
            'touch'     => [['path'], [], 'Create an empty file or update its mtime.'],
            'cp'        => [['src', 'dst'], [], 'Copy a file or directory.'],
            'mv'        => [['src', 'dst'], [], 'Move or rename a file or directory.'],
            'rm'        => [['path'], ['recursive', 'dry_run'], 'Remove a file/dir (recursive, dry_run supported).'],
            'changes'   => [[], ['path', 'since'], 'Recently changed files (git-aware).'],
            'watch'     => [[], ['path', 'extensions', 'timeout'], 'Watch files for changes for a while.'],
            'patch'     => [['file_path', 'diff'], [], 'Apply a unified diff to a file.'],
            'notebook_edit' => [['notebook_path', 'new_source'], ['cell_number', 'cell_id', 'cell_type', 'edit_mode'], 'Edit a Jupyter .ipynb cell (edit_mode: replace|insert|delete).'],
            // Background shells (run / read / stop / wait) + ask the user
            'bg'          => [['command'], [], 'Run a shell command in the background; returns a job id.'],
            'bash_output' => [['bg_id'], [], 'Read new output from a background job.'],
            'kill_bash'   => [['bg_id'], [], 'Stop a background job.'],
            'wait_bg'     => [[], ['bg_id', 'seconds'], 'Wait for a background job to finish (or N seconds).'],
            'ask_user'    => [['question'], ['options'], 'Ask the user a question (interactive runs only).'],
            // Code intelligence (LSP-backed)
            'diagnostics' => [['file_path'], [], 'Linter / type diagnostics for a file.'],
            'hover'     => [['file_path', 'line', 'col'], [], 'Type / signature info at a position.'],
            'symbols'   => [['file_path'], [], 'List the symbols (functions/classes) in a file.'],
            'find_refs' => [['file_path', 'pattern'], [], 'Find references to a symbol.'],
            'format'    => [['file_path'], [], 'Format a file with the project formatter.'],
            // Git (local + remote ops via gh/git)
            'git_status'   => [[], ['path'], 'Show working-tree status.'],
            'git_diff'     => [[], ['path', 'file', 'cached'], 'Show a diff (cached=true for staged).'],
            'git_log'      => [[], ['path', 'n'], 'Recent commits (n default 10).'],
            'git_branch'   => [[], ['path', 'all'], 'List branches.'],
            'git_checkout' => [['branch'], ['path', 'new'], 'Switch branch (new=true to create).'],
            'git_add'      => [[], ['files', 'all'], 'Stage files (default all).'],
            'git_commit'   => [['message'], ['path', 'all', 'amend'], 'Commit staged changes.'],
            'git_merge'    => [['branch'], ['path'], 'Merge a branch into the current one.'],
            'git_rebase'   => [['branch'], ['path', 'onto'], 'Rebase the current branch.'],
            'git_stash'    => [[], ['path', 'pop', 'list', 'drop'], 'Stash / pop / list / drop changes.'],
            'git_push'     => [[], ['path', 'force', 'upstream'], 'Push to the remote.'],
            'git_pull'     => [[], ['path', 'rebase'], 'Pull from the remote.'],
            'git_fetch'    => [[], ['path', 'all', 'prune'], 'Fetch from the remote.'],
            'git_show'     => [[], ['path', 'ref', 'stat'], 'Show a commit (ref default HEAD).'],
            'git_remote'   => [[], ['path'], 'List configured remotes.'],
            'git_clone'    => [['url'], ['path'], 'Clone a repository.'],
        ];
    }

    // Render a tool catalog for text-protocol tool-calling (when we don't rely
    // on Ollama's native function-calling). Covers the curated native set plus
    // extraTextTools(), so the model knows every callable tool — not just 15.
    // Required params are bare; optional ones are wrapped in [brackets].
    public static function textCatalog(): string {
        $row = function (string $name, array $req, array $opt, string $desc): string {
            $parts = array_merge($req, array_map(fn($o) => "[$o]", $opt));
            if (strlen($desc) > 140) $desc = substr($desc, 0, 137) . '…';
            return "- $name(" . implode(', ', $parts) . ") — $desc";
        };
        $lines = [];
        foreach (self::schemas() as $t) {
            $f = $t['function'] ?? [];
            $name = $f['name'] ?? '';
            if ($name === '') continue;
            $props = (array)($f['parameters']['properties'] ?? []);
            $required = (array)($f['parameters']['required'] ?? []);
            $req = array_values(array_filter(array_keys($props), fn($k) => in_array($k, $required, true)));
            $opt = array_values(array_filter(array_keys($props), fn($k) => !in_array($k, $required, true)));
            $lines[] = $row($name, $req, $opt, (string)($f['description'] ?? ''));
        }
        foreach (self::extraTextTools() as $name => [$req, $opt, $desc]) {
            $lines[] = $row($name, $req, $opt, $desc);
        }
        return implode("\n", $lines);
    }
}

class CmdError {
    // Does a tool result string look like one of our error envelopes (permission
    // denied, tool failed/not-found, hook block)? Used by the MCP server to set
    // isError on the response so a client distinguishes failures from real output.
    public static function isError(string $result): bool {
        return str_starts_with($result, 'ollamadev: ') || str_starts_with($result, 'Blocked by PreToolUse hook:');
    }

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

