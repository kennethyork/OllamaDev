// CREW ROLES — a catalog of agent personas the Director assigns per subtask.
// Each role is a persona (system prompt) plus an optional pinned model and
// permission mode. Sensible roles ship built-in; you add your own as plain JSON
// in ~/.ollamadev/crew-roles/<name>.json (shared across CLI/desktop/web):
//   ollamadev crew role list
//   ollamadev crew role add reviewer "You are a strict reviewer…" --readonly
// At plan time the Director picks the most fitting role for each subtask; each
// coder then runs with that role's persona/model/permission. 100% vanilla PHP.
class CrewRoles {
    // Built-in roles, always present even with no files on disk. 'coder' is the
    // default/fallback. desc = what the Director sees when choosing; prompt = the
    // persona injected into the coder loop; model = optional pin ('' → crew coder
    // model); permission = 'auto' (may edit) or 'readonly' (survey/advise only).
    private static function builtins(): array {
        return [
            'coder' => [
                'desc' => 'General implementation — write or modify code to satisfy the subtask.',
                'prompt' => "You are a coding agent in an isolated git worktree. You MUST actually make the changes by calling your tools (write/edit/bash) — do not merely describe them. Keep changes focused; when the files are written, stop.",
                'model' => '', 'permission' => 'auto',
            ],
            'tester' => [
                'desc' => 'Write or extend automated tests for the subtask.',
                'prompt' => "You are a test-writing agent in an isolated git worktree. Add or extend AUTOMATED TESTS only — match the project's existing test framework, layout, and naming conventions. Do not change production code beyond trivial test hooks. Actually write the test files with your tools; when the tests are in place, stop.",
                'model' => '', 'permission' => 'auto',
            ],
            'docs' => [
                'desc' => 'Update documentation, README, comments, or changelog for the subtask.',
                'prompt' => "You are a documentation agent in an isolated git worktree. Update docs, README, code comments, or the changelog to match the change — clear, concise, and matching the project's tone. Do not alter program logic. Write the files with your tools; when done, stop.",
                'model' => '', 'permission' => 'auto',
            ],
            'refactor' => [
                'desc' => 'Restructure code for clarity/efficiency WITHOUT changing behavior.',
                'prompt' => "You are a refactoring agent in an isolated git worktree. Improve structure, naming, and clarity WITHOUT changing observable behavior or public interfaces, and without adding features. Make the edits with your tools; keep the diff focused; when done, stop.",
                'model' => '', 'permission' => 'auto',
            ],
            'security' => [
                'desc' => 'Harden the code: injection, unsafe shell, secrets, weak validation.',
                'prompt' => "You are a security-hardening agent in an isolated git worktree. Fix concrete vulnerabilities relevant to this subtask — injection, unsafe shell/eval, path traversal, weak input validation, leaked secrets — without breaking behavior. Make the edits with your tools; when done, stop.",
                'model' => '', 'permission' => 'auto',
            ],
        ];
    }

    public static function dir(): string {
        $d = (getenv('HOME') ?: sys_get_temp_dir()) . '/.ollamadev/crew-roles';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    public static function normName(string $name): string {
        $n = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($name)));
        return trim($n, '-') ?: 'role';
    }

    private static function path(string $name): string {
        return self::dir() . '/' . self::normName($name) . '.json';
    }

    // All roles: built-ins overlaid by user JSON files (a file may override a
    // built-in by reusing its name). Returns name => def (def has 'custom'=>true
    // for user-defined ones).
    public static function all(): array {
        $roles = self::builtins();
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) continue;
            $name = self::normName((string)($j['name'] ?? basename($f, '.json')));
            $prev = $roles[$name] ?? [];
            $roles[$name] = [
                'desc' => trim((string)($j['desc'] ?? ($prev['desc'] ?? ''))),
                'prompt' => trim((string)($j['prompt'] ?? ($prev['prompt'] ?? ''))),
                'model' => trim((string)($j['model'] ?? '')),
                'permission' => (($j['permission'] ?? '') === 'readonly') ? 'readonly' : 'auto',
                'custom' => true,
            ];
        }
        return $roles;
    }

    // Resolve a role by name; unknown names fall back to 'coder'. The returned
    // def always carries a 'name'.
    public static function get(string $name): array {
        $all = self::all();
        $n = self::normName($name);
        if (isset($all[$n])) return ['name' => $n] + $all[$n];
        return ['name' => 'coder'] + $all['coder'];
    }

    // The role names the Director may assign (used to validate its plan).
    public static function names(): array { return array_keys(self::all()); }

    // Persist a user role. Returns the file path.
    public static function add(string $name, string $prompt, array $opts = []): string {
        $n = self::normName($name);
        $def = [
            'name' => $n,
            'desc' => trim((string)($opts['desc'] ?? '')),
            'prompt' => trim($prompt),
            'model' => trim((string)($opts['model'] ?? '')),
            'permission' => (($opts['permission'] ?? '') === 'readonly') ? 'readonly' : 'auto',
        ];
        $path = self::path($n);
        @file_put_contents($path, json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    // Remove a user role file. Built-ins have no file, so they can't be removed.
    public static function remove(string $name): bool {
        $path = self::path($name);
        if (is_file($path)) { @unlink($path); return !is_file($path); }
        return false;
    }

    // Compact "name: desc" list the Director sees when assigning roles.
    public static function catalog(): string {
        $lines = [];
        foreach (self::all() as $name => $def) $lines[] = "- $name: " . ($def['desc'] !== '' ? $def['desc'] : 'no description');
        return implode("\n", $lines);
    }
}
