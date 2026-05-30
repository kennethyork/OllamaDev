// SKILLS — on-demand capabilities for local models (Claude-Code-style).
// A skill is a folder with a SKILL.md (frontmatter: name, description; body:
// instructions) plus optional helper files. Only the name+description are put in
// the system prompt; the model loads a skill's full instructions on demand via
// the `skill` tool (progressive disclosure — keeps prompts small).
//   Project skills:  <cwd>/.ollamadev/skills/<name>/SKILL.md
//   Global skills:   ~/.ollamadev/skills/<name>/SKILL.md
class Skills {
    public static function baseDirs(): array {
        $home = getenv('HOME') ?: '/tmp';
        return array_values(array_unique([getcwd() . '/.ollamadev/skills', $home . '/.ollamadev/skills']));
    }

    // All discovered skills, keyed by lowercase name (project overrides global).
    public static function all(): array {
        $skills = [];
        foreach (self::baseDirs() as $base) {
            if (!is_dir($base)) continue;
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $md = $dir . '/SKILL.md';
                if (!is_file($md)) continue;
                $meta = self::parse($md, basename($dir));
                $key = strtolower($meta['name']);
                if (!isset($skills[$key])) $skills[$key] = $meta + ['dir' => $dir, 'file' => $md];
            }
        }
        ksort($skills);
        return $skills;
    }

    private static function parse(string $md, string $fallback): array {
        $content = (string) @file_get_contents($md);
        $name = $fallback; $desc = '';
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $m)) {
            foreach (preg_split('/\n/', $m[1]) as $line) {
                if (preg_match('/^\s*name\s*:\s*(.+)$/i', $line, $mm)) $name = trim($mm[1], " \"'");
                elseif (preg_match('/^\s*description\s*:\s*(.+)$/i', $line, $mm)) $desc = trim($mm[1], " \"'");
            }
        }
        if ($desc === '') {
            $body = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
            foreach (preg_split('/\n/', (string) $body) as $line) {
                $t = trim($line);
                if ($t !== '' && $t[0] !== '#') { $desc = $t; break; }
            }
        }
        return ['name' => $name !== '' ? $name : $fallback, 'description' => $desc];
    }

    // Full skill (body + helper file list) by name, or null.
    public static function get(string $name): ?array {
        foreach (self::all() as $s) {
            if (strcasecmp($s['name'], $name) === 0) {
                $files = array_values(array_filter(array_map('basename', glob($s['dir'] . '/*') ?: []), fn($f) => $f !== 'SKILL.md'));
                return $s + ['body' => (string) @file_get_contents($s['file']), 'files' => $files];
            }
        }
        return null;
    }

    // "- name: description" lines for the system prompt (empty if no skills).
    public static function catalog(): string {
        $lines = [];
        foreach (self::all() as $s) $lines[] = '- ' . $s['name'] . ': ' . $s['description'];
        return implode("\n", $lines);
    }

    // Scaffold a new skill folder; returns the SKILL.md path.
    public static function scaffold(string $name): string {
        $home = getenv('HOME') ?: '/tmp';
        $dir = $home . '/.ollamadev/skills/' . preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name);
        @mkdir($dir, 0755, true);
        $md = $dir . '/SKILL.md';
        if (!is_file($md)) {
            @file_put_contents($md, "---\nname: $name\ndescription: One line on when to use this skill.\n---\n\n" .
                "# $name\n\nStep-by-step instructions the model should follow when this skill is loaded.\n\n" .
                "- You can reference helper files placed next to this SKILL.md.\n");
        }
        return $md;
    }
}

// The `skill` tool: load a skill's full instructions on demand.
Tools::register('skill', function ($p) {
    $name = trim((string) ($p['name'] ?? $p['skill'] ?? (is_string($p) ? $p : '')));
    $names = array_map(fn($s) => $s['name'], Skills::all());
    if ($name === '') return "Usage: skill(name). Available skills: " . (empty($names) ? '(none)' : implode(', ', $names));
    $s = Skills::get($name);
    if (!$s) return "No skill named '$name'. Available: " . (empty($names) ? '(none)' : implode(', ', $names));
    $extra = !empty($s['files']) ? "\n\n(Helper files in {$s['dir']}: " . implode(', ', $s['files']) . " — read them with the view tool if needed.)" : '';
    return "# Skill: {$s['name']}\n{$s['description']}\n\n" . $s['body'] . $extra;
});
