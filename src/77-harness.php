// HARNESS EXTRAS — output styles, status line, and file-defined agent types.
// These three round out the CLI's harness to match Claude Code's surface; each is
// vanilla PHP, opt-in, and shared across the CLI/desktop/web via config + dotfiles.

// ── Output styles ────────────────────────────────────────────────────────────
// A named preset that adjusts HOW the agent writes (tone/verbosity), appended to
// the system prompt. `config set outputStyle concise` or /output-style concise.
class OutputStyles {
    private static function styles(): array {
        return [
            'default'     => ['desc' => 'Balanced — the standard OllamaDev voice.', 'prompt' => ''],
            'concise'     => ['desc' => 'Terse — the shortest correct answer, no preamble.',
                'prompt' => "\n\nOUTPUT STYLE: Be extremely concise. Give the shortest correct answer; skip preamble, restating the question, and closing summaries. Prefer lists over paragraphs."],
            'explanatory' => ['desc' => 'Mentor mode — explain the why behind each step.',
                'prompt' => "\n\nOUTPUT STYLE: Be explanatory. Briefly explain the reasoning and trade-offs behind what you do, as a mentor would — without becoming verbose."],
            'formal'      => ['desc' => 'Professional and precise — no slang or emoji.',
                'prompt' => "\n\nOUTPUT STYLE: Write formally and precisely. No slang, no emoji; complete sentences and exact terminology."],
            'bullets'     => ['desc' => 'Bullet-first — structure answers as lists.',
                'prompt' => "\n\nOUTPUT STYLE: Structure every answer as bullet points wherever possible; use prose only when a list won't do."],
        ];
    }
    public static function current(): string {
        $s = strtolower(trim((string)Config::get('outputStyle', 'default')));
        return isset(self::styles()[$s]) ? $s : 'default';
    }
    public static function set(string $name): bool {
        $name = strtolower(trim($name));
        if (!isset(self::styles()[$name])) return false;
        Config::persist('outputStyle', $name);
        return true;
    }
    public static function promptSuffix(): string { return (string)(self::styles()[self::current()]['prompt'] ?? ''); }
    public static function names(): array { return array_keys(self::styles()); }
    // Human listing for `/output-style` / `ollamadev config` discovery.
    public static function render(): string {
        $cur = self::current(); $c = "\033[36m"; $d = "\033[2m"; $g = "\033[32m"; $r = "\033[0m";
        $out = "\n  Output styles:\n";
        foreach (self::styles() as $name => $s) {
            $mark = $name === $cur ? "{$g}●{$r}" : ' ';
            $out .= "  $mark {$c}$name{$r} {$d}— {$s['desc']}{$r}\n";
        }
        $out .= "  {$d}Set: /output-style <name>  (or: ollamadev config set outputStyle <name>){$r}\n";
        return $out;
    }
}

// ── Status line ──────────────────────────────────────────────────────────────
// Config `statusline` is EITHER a template with {model} {cwd} {branch} {mode}
// tokens, OR a shell command whose first stdout line is shown. Empty → nothing.
class StatusLine {
    public static function configured(): bool {
        $cfg = Config::get('statusline', '');
        return is_string($cfg) && trim($cfg) !== '';
    }
    public static function render(string $model = '', string $mode = ''): string {
        $cfg = Config::get('statusline', '');
        if (!is_string($cfg) || trim($cfg) === '') return '';
        $cfg = trim($cfg);
        $branch = trim((string)@shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
        if ($mode === '' && class_exists('Permission')) $mode = Permission::getMode();
        if (strpos($cfg, '{') !== false) {
            return strtr($cfg, ['{model}' => $model, '{cwd}' => getcwd(), '{branch}' => $branch !== '' ? $branch : '-', '{mode}' => $mode]);
        }
        // Command form: pass context via env; show the first non-empty stdout line.
        $env = 'OLLAMADEV_MODEL=' . escapeshellarg($model) . ' OLLAMADEV_CWD=' . escapeshellarg(getcwd())
            . ' OLLAMADEV_BRANCH=' . escapeshellarg($branch) . ' OLLAMADEV_MODE=' . escapeshellarg($mode) . ' ';
        $out = (string)@shell_exec($env . $cfg . ' 2>/dev/null');
        return trim((string)strtok($out, "\n"));
    }
    public static function set(string $value): void { Config::persist('statusline', trim($value)); }
}

// ── File-defined agent types ─────────────────────────────────────────────────
// A custom subagent persona lives at .ollamadev/agents/<name>.md (project) or
// ~/.ollamadev/agents/<name>.md (global), with optional frontmatter:
//   name, description, model, permission (readonly|ask|auto), tools (comma list).
// The body is the agent's system prompt. SubAgent loads it by agent_type.
class AgentDefs {
    private static function dirs(): array {
        $d = [getcwd() . '/.ollamadev/agents'];
        $home = getenv('HOME') ?: '';
        if ($home !== '') $d[] = $home . '/.ollamadev/agents';
        return array_values(array_unique($d));
    }
    public static function all(): array {
        $out = [];
        foreach (self::dirs() as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*.md') ?: [] as $f) {
                $def = self::parse($f);
                $key = strtolower($def['name']);
                if ($key !== '' && !isset($out[$key])) $out[$key] = $def;   // project shadows home
            }
        }
        ksort($out);
        return $out;
    }
    public static function get(string $name): ?array {
        $name = strtolower(trim($name));
        if ($name === '') return null;
        return self::all()[$name] ?? null;
    }
    private static function parse(string $file): array {
        $content = (string) @file_get_contents($file);
        $name = pathinfo($file, PATHINFO_FILENAME);
        $desc = ''; $model = ''; $perm = ''; $tools = []; $body = $content;
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $m)) {
            $body = $m[2];
            foreach (preg_split('/\n/', $m[1]) as $line) {
                if (preg_match('/^\s*name\s*:\s*(.+)$/i', $line, $x)) $name = trim($x[1], " \"'");
                elseif (preg_match('/^\s*description\s*:\s*(.+)$/i', $line, $x)) $desc = trim($x[1], " \"'");
                elseif (preg_match('/^\s*model\s*:\s*(.+)$/i', $line, $x)) $model = trim($x[1], " \"'");
                elseif (preg_match('/^\s*permission\s*:\s*(.+)$/i', $line, $x)) $perm = trim($x[1], " \"'");
                elseif (preg_match('/^\s*tools\s*:\s*(.+)$/i', $line, $x)) $tools = array_values(array_filter(array_map('trim', explode(',', trim($x[1], " \"'[]")))));
            }
        }
        return ['name' => $name, 'description' => $desc, 'model' => $model, 'permission' => $perm, 'tools' => $tools, 'prompt' => trim($body), 'file' => $file];
    }
    public static function render(): string {
        $all = self::all(); $c = "\033[36m"; $d = "\033[2m"; $r = "\033[0m";
        if (empty($all)) {
            return "\n  {$d}No custom agents. Create one at ~/.ollamadev/agents/NAME.md{$r}\n"
                . "  {$d}(frontmatter: name, description, model, permission, tools; body = its system prompt),{$r}\n"
                . "  {$d}then delegate to it: task(prompt, agent_type=\"NAME\").{$r}\n";
        }
        $out = "\n  Custom agents (" . count($all) . "):\n";
        foreach ($all as $a) {
            $tags = [];
            if ($a['model'] !== '') $tags[] = 'model: ' . $a['model'];
            if ($a['permission'] !== '') $tags[] = $a['permission'];
            if (!empty($a['tools'])) $tags[] = count($a['tools']) . ' tools';
            $sx = $tags ? " {$d}[" . implode(', ', $tags) . "]{$r}" : '';
            $out .= "  {$c}{$a['name']}{$r} {$d}— " . ($a['description'] !== '' ? $a['description'] : 'no description') . "{$r}$sx\n";
        }
        return $out;
    }
}
