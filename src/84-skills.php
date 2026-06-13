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

    // Full skill (body + helper file list) by name, or null. Falls back to the
    // built-in team-skill library so the desktop manager can view a built-in's
    // full body even though it isn't on disk until a crew run materializes it.
    public static function get(string $name): ?array {
        foreach (self::all() as $s) {
            if (strcasecmp($s['name'], $name) === 0) {
                $files = array_values(array_filter(array_map('basename', glob($s['dir'] . '/*') ?: []), fn($f) => $f !== 'SKILL.md'));
                return $s + ['body' => (string) @file_get_contents($s['file']), 'files' => $files];
            }
        }
        $lib = CrewSkills::allBuiltins();   // capability skills + per-team starters
        $key = strtolower(trim($name));
        if (isset($lib[$key])) {
            return ['name' => $key, 'description' => $lib[$key]['description'],
                'body' => $lib[$key]['body'], 'files' => [], 'builtin' => true];
        }
        return null;
    }

    // Built-in team-skills (from CrewSkills) as skill-shaped entries, EXCLUDING any
    // name already defined on disk (a user skill of the same name wins).
    public static function builtins(): array {
        $onDisk = array_change_key_case(self::all(), CASE_LOWER);
        $out = [];
        foreach (CrewSkills::allBuiltins() as $name => $s) {   // capability + per-team starters
            if (isset($onDisk[strtolower($name)])) continue;
            $out[] = ['name' => $name, 'description' => $s['description'], 'builtin' => true];
        }
        return $out;
    }

    // Everything the desktop/web Skills manager should show: your disk skills first
    // (builtin=false), then the built-in team-skills (builtin=true). Each entry has
    // name, description, builtin; disk entries also carry dir.
    public static function listForManager(): array {
        $out = [];
        foreach (self::all() as $s) $out[] = ['name' => $s['name'], 'description' => $s['description'], 'dir' => $s['dir'], 'builtin' => false];
        foreach (self::builtins() as $s) $out[] = $s;
        return $out;
    }

    // "- name: description" lines for the system prompt (empty if no skills).
    public static function catalog(): string {
        $lines = [];
        foreach (self::all() as $s) $lines[] = '- ' . $s['name'] . ': ' . $s['description'];
        return implode("\n", $lines);
    }

    public static function homeDir(): string { return (getenv('HOME') ?: sys_get_temp_dir()) . '/.ollamadev/skills'; }

    // ---- Registry / discovery ------------------------------------------------
    // A registry is just a place to DISCOVER shareable skills before installing.
    // Sources (local-first): the local registry dir plus any
    // dirs/URLs in config `skills.registries`. Git/archive URLs are install-only
    // (browse can't list them without fetching); local dirs are browsable.
    public static function registryDir(): string { return (getenv('HOME') ?: sys_get_temp_dir()) . '/.ollamadev/registry'; }

    public static function registries(): array {
        $sources = [self::registryDir()];
        $cfg = Config::get('skills.registries', []);
        if (is_array($cfg)) foreach ($cfg as $s) { $s = trim((string)$s); if ($s !== '') $sources[] = $s; }
        return array_values(array_unique($sources));
    }

    // Skills available to install from local registry sources, keyed by name.
    // Each: ['name','description','dir','source','installed'=>bool].
    public static function browse(): array {
        $installed = array_change_key_case(self::all(), CASE_LOWER);
        $avail = [];
        foreach (self::registries() as $src) {
            if (!is_dir($src)) continue; // remote sources aren't browsable until installed
            foreach (self::findSkillDirs($src) as $dir) {
                $meta = self::parse($dir . '/SKILL.md', basename($dir));
                $key = strtolower($meta['name']);
                if (isset($avail[$key])) continue;
                $avail[$key] = $meta + ['dir' => $dir, 'source' => $src, 'installed' => isset($installed[$key])];
            }
        }
        ksort($avail);
        return $avail;
    }

    // Search installed + registry skills by name/description substring.
    public static function search(string $query): array {
        $q = strtolower(trim($query));
        $pool = [];
        foreach (self::all() as $s) $pool[strtolower($s['name'])] = $s + ['installed' => true];
        foreach (self::browse() as $k => $s) if (!isset($pool[$k])) $pool[$k] = $s;
        if ($q === '') { ksort($pool); return array_values($pool); }
        $hits = [];
        foreach ($pool as $s) {
            if (strpos(strtolower($s['name']), $q) !== false || strpos(strtolower((string)($s['description'] ?? '')), $q) !== false) $hits[] = $s;
        }
        return $hits;
    }

    // Install a skill discovered by name in a registry source.
    public static function addFromRegistry(string $name, bool $force = false): array {
        foreach (self::browse() as $s) {
            if (strcasecmp($s['name'], $name) === 0) return self::install($s['dir'], $force);
        }
        return ['installed' => [], 'messages' => ["not found in any registry: $name"]];
    }

    // Install skills from a local directory, a git URL, or a .tar.gz/.zip archive
    // (local path or http(s) URL). Each skill folder (one containing a SKILL.md) is
    // copied into ~/.ollamadev/skills/<name>. Returns ['installed'=>[], 'messages'=>[]].
    public static function install(string $source, bool $force = false): array {
        $installed = []; $msgs = [];
        $source = trim($source);
        if ($source === '') return ['installed' => [], 'messages' => ['no source given']];
        $tmp = sys_get_temp_dir() . '/odv_skill_install_' . getmypid() . '_' . substr(md5($source), 0, 6);
        @exec('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0755, true);
        $scanBase = '';
        $isArchive = (bool) preg_match('#\.(tar\.gz|tgz|zip)$#i', $source);
        $isHttp = (bool) preg_match('#^https?://#i', $source);
        $isGit = preg_match('#^git@#', $source) || preg_match('#\.git$#', $source)
            || ($isHttp && preg_match('#(github\.com|gitlab\.com|bitbucket\.org)#i', $source) && !$isArchive);
        try {
            if ($isGit) {
                $out = (string) @shell_exec('git clone --depth 1 ' . escapeshellarg($source) . ' ' . escapeshellarg($tmp . '/repo') . ' 2>&1');
                if (!is_dir($tmp . '/repo')) { $msgs[] = 'git clone failed: ' . trim($out); return ['installed' => [], 'messages' => $msgs]; }
                $scanBase = $tmp . '/repo';
            } elseif ($isArchive) {
                $archive = $source;
                if ($isHttp) {
                    $archive = $tmp . '/' . (basename((string) parse_url($source, PHP_URL_PATH)) ?: 'archive');
                    if (!self::download($source, $archive)) { $msgs[] = "download failed: $source"; return ['installed' => [], 'messages' => $msgs]; }
                }
                if (!is_file($archive)) { $msgs[] = "no such file: $archive"; return ['installed' => [], 'messages' => $msgs]; }
                @mkdir($tmp . '/x', 0755, true);
                if (preg_match('#\.zip$#i', $archive)) @shell_exec('unzip -q ' . escapeshellarg($archive) . ' -d ' . escapeshellarg($tmp . '/x') . ' 2>&1');
                else @shell_exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($tmp . '/x') . ' 2>&1');
                $scanBase = $tmp . '/x';
            } elseif (is_dir($source)) {
                $scanBase = $source;
            } else {
                $msgs[] = "unrecognized source (expected a directory, a .git URL, or a .tar.gz/.zip): $source";
                return ['installed' => [], 'messages' => $msgs];
            }
            $dirs = self::findSkillDirs($scanBase);
            if (!$dirs) { $msgs[] = "no SKILL.md found under: $source"; return ['installed' => [], 'messages' => $msgs]; }
            $home = self::homeDir();
            foreach ($dirs as $dir) {
                $meta = self::parse($dir . '/SKILL.md', basename($dir));
                $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $meta['name']);
                if ($name === '' || $name === '.' || $name === '..') { $msgs[] = "skipped (bad name): $dir"; continue; }
                $dst = $home . '/' . $name;
                if (is_dir($dst) && !$force) { $msgs[] = "exists, skipped (use --force to overwrite): $name"; continue; }
                if (is_dir($dst)) @exec('rm -rf ' . escapeshellarg($dst));
                if (self::copyDir($dir, $dst)) $installed[] = $name;
                else $msgs[] = "copy failed: $name";
            }
        } finally {
            @exec('rm -rf ' . escapeshellarg($tmp));
        }
        return ['installed' => $installed, 'messages' => $msgs];
    }

    // Package a skill folder as a shareable tarball; returns the output path or null.
    public static function export(string $name, string $out = ''): ?string {
        $s = self::get($name);
        if (!$s) return null;
        $base = basename($s['dir']);
        $out = $out !== '' ? $out : (getcwd() . '/' . $base . '.skill.tar.gz');
        @shell_exec('tar -czf ' . escapeshellarg($out) . ' -C ' . escapeshellarg(dirname($s['dir'])) . ' ' . escapeshellarg($base) . ' 2>&1');
        return is_file($out) ? $out : null;
    }

    // Delete an installed skill by name. Returns true if it's gone afterwards.
    public static function remove(string $name): bool {
        foreach (self::all() as $s) {
            if (strcasecmp($s['name'], $name) === 0) { @exec('rm -rf ' . escapeshellarg($s['dir'])); return !is_dir($s['dir']); }
        }
        return false;
    }

    // Find every directory containing a SKILL.md beneath $base (depth-limited).
    private static function findSkillDirs(string $base, int $depth = 4): array {
        if (!is_dir($base)) return [];
        if (is_file($base . '/SKILL.md')) return [$base];
        if ($depth <= 0) return [];
        $found = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) $found = array_merge($found, self::findSkillDirs($d, $depth - 1));
        return $found;
    }

    private static function copyDir(string $src, string $dst): bool {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) return false;
        foreach (scandir($src) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $s = $src . '/' . $f; $d = $dst . '/' . $f;
            if (is_dir($s)) { if (!self::copyDir($s, $d)) return false; }
            elseif (!@copy($s, $d)) return false;
        }
        return true;
    }

    private static function download(string $url, string $dest): bool {
        if (trim((string) @shell_exec('command -v curl 2>/dev/null')) !== '') {
            @shell_exec('curl -fsSL ' . escapeshellarg($url) . ' -o ' . escapeshellarg($dest) . ' 2>/dev/null');
            if (is_file($dest) && filesize($dest) > 0) return true;
        }
        $data = @file_get_contents($url);
        return $data !== false && @file_put_contents($dest, $data) !== false;
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

    // Create or overwrite a user skill at ~/.ollamadev/skills/<slug>/SKILL.md.
    // Returns the slug, or null on failure. Powers the desktop Skills manager.
    public static function save(string $name, string $description, string $body): ?string {
        $name = trim($name);
        if ($name === '') return null;
        $slug = trim(strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name)), '-._');
        if ($slug === '') return null;
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $dir = $home . '/.ollamadev/skills/' . $slug;
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
        $desc = trim(preg_replace('/\s+/', ' ', $description));
        $fm = "---\nname: " . $name . "\ndescription: " . ($desc !== '' ? $desc : 'One line on when to use this skill.') . "\n---\n\n";
        return @file_put_contents($dir . '/SKILL.md', $fm . ltrim($body) . "\n") !== false ? $slug : null;
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
