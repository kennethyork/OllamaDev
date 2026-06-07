// Custom user-defined slash commands + optional config-driven shell hooks.
//
// Custom commands live as prompt-template files in a `commands/` directory under
// either the project data dir (./.ollamadev/commands) or the user's home
// (~/.ollamadev/commands). Each file is named <command>.md (or .txt/.prompt) and
// its contents are a prompt template. Typing /<command> [args] in a session
// expands the template ($ARGS = whole arg string, $1 $2 ... = positional words)
// and feeds the result to the model as if the user had typed it.
//
// Hooks are best-effort shell commands read from config (hooks.beforePrompt,
// hooks.afterEdit). They are entirely opt-in: with nothing configured these are
// no-ops, and any failure is swallowed so a bad hook never breaks the session.
class UserCmds {
    // Directories searched for command files, project dir first so a repo can
    // override a personal command of the same name.
    private static function dirs(): array {
        $dirs = [];
        $proj = getcwd() . '/.ollamadev/commands';
        $dirs[] = $proj;
        $home = getenv('HOME') ?: '';
        if ($home !== '') $dirs[] = $home . '/.ollamadev/commands';
        return $dirs;
    }

    private static array $exts = ['md', 'txt', 'prompt'];

    // Locate the template file for a command name, or null if none exists.
    private static function findFile(string $name): ?string {
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) return null;
        foreach (self::dirs() as $dir) {
            if (!is_dir($dir)) continue;
            foreach (self::$exts as $ext) {
                $path = $dir . '/' . $name . '.' . $ext;
                if (is_file($path)) return $path;
            }
        }
        return null;
    }

    // Does a custom command with this name exist?
    public static function exists(string $name): bool {
        return self::findFile($name) !== null;
    }

    // Expand the template for $name with $args, returning the final prompt text,
    // or null if no such command. $ARGS -> full arg string; $1.. -> positional.
    public static function expand(string $name, string $args): ?string {
        $path = self::findFile($name);
        if ($path === null) return null;
        $tpl = file_get_contents($path);
        if ($tpl === false) return null;
        $words = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Replace $ARGS first (longest token), then numbered positionals.
        $out = str_replace('$ARGS', trim($args), $tpl);
        // Replace from highest index down so $12 isn't eaten by $1.
        $out = preg_replace_callback('/\$(\d+)/', function ($m) use ($words) {
            $i = (int)$m[1];
            return $i >= 1 && $i <= count($words) ? $words[$i - 1] : '';
        }, $out);
        return rtrim($out, "\n");
    }

    // Discovered custom command names (deduped, project shadowing home).
    public static function listAll(): array {
        $seen = [];
        foreach (self::dirs() as $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext, self::$exts, true)) continue;
                $base = pathinfo($f, PATHINFO_FILENAME);
                if ($base !== '' && !isset($seen[$base])) $seen[$base] = true;
            }
        }
        $names = array_keys($seen);
        sort($names);
        return $names;
    }

    // Human-readable listing for the /commands built-in.
    public static function render(): string {
        $names = self::listAll();
        $d = "\033[2m"; $c = "\033[36m"; $r = "\033[0m";
        if (empty($names)) {
            return "\n  {$d}No custom commands found.{$r}\n"
                . "  {$d}Create one at ~/.ollamadev/commands/NAME.md (a prompt template;{$r}\n"
                . "  {$d}use \$ARGS or \$1 \$2 for arguments), then run /NAME.{$r}\n";
        }
        $out = "\n  Custom commands (" . count($names) . "):\n";
        foreach ($names as $n) $out .= "  {$c}/{$n}{$r}\n";
        $out .= "  {$d}Edit them under ~/.ollamadev/commands or ./.ollamadev/commands{$r}\n";
        return $out;
    }
}

// Optional shell hooks fired at well-defined points. All opt-in via config.
//
// Each hooks.<event> may be a bare command STRING, or a LIST of entries — each a
// string or {command, matcher}. A matcher is a regex tested against a subject
// (the tool name for tool events); no matcher means "always". Events:
//   PreToolUse   — before a tool runs; a hook exiting non-zero BLOCKS it (output = reason)
//   PostToolUse  — after a tool runs (informational)
//   UserPromptSubmit, SessionStart, Stop, PreCompact, SubagentStop, Notification
//   beforePrompt, afterEdit — the original two (still supported, string or list)
class Hooks {
    // Resolve the configured commands for an event, filtered by $subject matcher.
    private static function forEvent(string $event, string $subject = ''): array {
        $cfg = Config::get('hooks.' . $event, null);
        $out = [];
        if (is_string($cfg)) { if (trim($cfg) !== '') $out[] = $cfg; return $out; }
        if (!is_array($cfg)) return $out;
        foreach ($cfg as $h) {
            if (is_string($h)) { if (trim($h) !== '') $out[] = $h; continue; }
            if (!is_array($h)) continue;
            $cmd = trim((string)($h['command'] ?? $h['cmd'] ?? ''));
            if ($cmd === '') continue;
            $matcher = trim((string)($h['matcher'] ?? $h['match'] ?? ''));
            if ($matcher !== '' && $subject !== '' && !@preg_match('/' . str_replace('/', '\\/', $matcher) . '/i', $subject)) continue;
            $out[] = $cmd;
        }
        return $out;
    }

    // Run a command with a JSON payload on stdin + context env. Returns [output, exitCode].
    private static function exec(string $cmd, string $stdin = '', array $env = []): array {
        $proc = @proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, array_merge(getenv() ?: [], $env));
        if (!is_resource($proc)) return ['', 0];
        if ($stdin !== '') @fwrite($pipes[0], $stdin);
        @fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]); $err = (string) stream_get_contents($pipes[2]);
        @fclose($pipes[1]); @fclose($pipes[2]);
        $code = proc_close($proc);
        $combined = trim($out . ($err !== '' ? "\n" . $err : ''));
        if (!empty($GLOBALS['verbose']) && $combined !== '') echo "\033[2m  [hook] " . $combined . "\033[0m\n";
        return [$combined, $code];
    }

    // The original simple events: run hooks.<event>, append args, swallow failures.
    public static function run(string $event, array $args = []): void {
        foreach (self::forEvent($event) as $cmd) {
            $full = trim($cmd);
            foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
            $env = ($event === 'afterEdit' && !empty($args)) ? ['OLLAMADEV_EDITED_FILES' => implode(' ', $args)] : [];
            self::exec($full, '', $env);
        }
    }

    // PreToolUse: a hook that exits non-zero BLOCKS the tool; its output is the
    // reason returned to the model. Returns the block reason, or null to allow.
    public static function preToolUse(string $tool, array $params): ?string {
        foreach (self::forEvent('PreToolUse', $tool) as $cmd) {
            $payload = (string) json_encode(['tool' => $tool, 'input' => $params]);
            [$out, $code] = self::exec($cmd, $payload, ['OLLAMADEV_TOOL_NAME' => $tool, 'OLLAMADEV_TOOL_INPUT' => $payload]);
            if ($code !== 0) return $out !== '' ? $out : "PreToolUse hook blocked '$tool' (exit $code)";
        }
        return null;
    }

    // PostToolUse: informational, never blocks.
    public static function postToolUse(string $tool, array $params, string $result): void {
        foreach (self::forEvent('PostToolUse', $tool) as $cmd) {
            $payload = (string) json_encode(['tool' => $tool, 'input' => $params, 'result' => substr($result, 0, 4000)]);
            self::exec($cmd, $payload, ['OLLAMADEV_TOOL_NAME' => $tool, 'OLLAMADEV_TOOL_INPUT' => (string) json_encode($params)]);
        }
    }

    // A generic event with a JSON payload on stdin (UserPromptSubmit, SessionStart,
    // Stop, PreCompact, SubagentStop, Notification). Non-blocking.
    public static function event(string $event, array $payload = []): void {
        foreach (self::forEvent($event, (string)($payload['_subject'] ?? '')) as $cmd) {
            self::exec($cmd, (string) json_encode($payload), ['OLLAMADEV_EVENT' => $event]);
        }
    }
}
