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
class Hooks {
    // Run the shell command configured at hooks.<event>, if any. Extra args are
    // appended (shell-escaped) and also exposed via $OLLAMADEV_EDITED_FILES for
    // afterEdit. Failures are swallowed; output is shown only in verbose mode.
    public static function run(string $event, array $args = []): void {
        $cmd = Config::get('hooks.' . $event, null);
        if (!is_string($cmd) || trim($cmd) === '') return;
        $full = trim($cmd);
        foreach ($args as $a) $full .= ' ' . escapeshellarg((string)$a);
        $env = '';
        if ($event === 'afterEdit' && !empty($args)) {
            $env = 'OLLAMADEV_EDITED_FILES=' . escapeshellarg(implode(' ', $args)) . ' ';
        }
        $output = @shell_exec($env . $full . ' 2>&1');
        if (!empty($GLOBALS['verbose']) && is_string($output) && trim($output) !== '') {
            echo "\033[2m  [hook:$event] " . trim($output) . "\033[0m\n";
        }
    }
}
