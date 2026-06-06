// WORKSPACES — a named, persistent list of project folders you switch between.
// One global store ($HOME/.ollamadev/workspaces.json) shared by the CLI, the
// desktop app, and the web mode, so a workspace added in one shows up in all.
// Each entry is { id, name, path, lastOpened, state }: `state` is an opaque blob
// the GUI uses to restore its window (terminals, editor tabs, layout, view) — the
// CLI never reads it but always preserves it. Sessions/memory/Crew are already
// keyed by the working directory, so opening a workspace's path auto-resumes them.
final class Workspaces {
    // The global store lives next to the global config, NOT in a project's
    // per-repo .ollamadev — the whole point is that it spans every project.
    public static function file(): string {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        return $home . '/.ollamadev/workspaces.json';
    }

    // Returns ['active' => ?string, 'workspaces' => array]. Never throws.
    public static function load(): array {
        $f = self::file();
        $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        if (!is_array($d)) $d = [];
        $d['workspaces'] = array_values(array_filter($d['workspaces'] ?? [], 'is_array'));
        if (!array_key_exists('active', $d)) $d['active'] = null;
        return $d;
    }

    private static function save(array $d): void {
        $f = self::file();
        $dir = dirname($f);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function all(): array { return self::load()['workspaces']; }
    public static function active(): ?string { return self::load()['active']; }

    // A stable id from the absolute path, so adding the same folder twice is a
    // no-op update rather than a duplicate.
    private static function idFor(string $absPath): string { return 'ws_' . substr(sha1($absPath), 0, 10); }

    // Expand ~ and resolve to an absolute path (kept as-typed if it doesn't exist).
    public static function resolve(string $path): string {
        $path = trim($path);
        if ($path === '') return getcwd() ?: $path;
        if ($path[0] === '~') $path = (getenv('HOME') ?: '') . substr($path, 1);
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    // Look up by id, exact path, or name (case-insensitive). Null if not found.
    public static function find(string $idPathOrName): ?array {
        $needle = trim($idPathOrName);
        $abs = self::resolve($needle);
        foreach (self::all() as $w) {
            if (($w['id'] ?? '') === $needle) return $w;
            if (($w['path'] ?? '') === $abs) return $w;
            if (strcasecmp((string)($w['name'] ?? ''), $needle) === 0) return $w;
        }
        return null;
    }

    // Add a workspace for $path (default: cwd), or update its name/lastOpened if
    // the path is already tracked. Marks it active. Returns the entry.
    public static function add(string $path = '', string $name = ''): array {
        $abs = self::resolve($path !== '' ? $path : (getcwd() ?: '.'));
        $d = self::load();
        foreach ($d['workspaces'] as &$w) {
            if (($w['path'] ?? '') === $abs) {
                if ($name !== '') $w['name'] = $name;
                $w['lastOpened'] = date('c');
                $d['active'] = $w['id'];
                $entry = $w;
                self::save($d);
                return $entry;
            }
        }
        unset($w);
        $entry = [
            'id'         => self::idFor($abs),
            'name'       => $name !== '' ? $name : (basename($abs) ?: $abs),
            'path'       => $abs,
            'lastOpened' => date('c'),
            'state'      => new \stdClass(),
        ];
        $d['workspaces'][] = $entry;
        $d['active'] = $entry['id'];
        self::save($d);
        return $entry;
    }

    public static function remove(string $idPathOrName): bool {
        $w = self::find($idPathOrName);
        if ($w === null) return false;
        $d = self::load();
        $d['workspaces'] = array_values(array_filter($d['workspaces'], fn($x) => ($x['id'] ?? '') !== $w['id']));
        if (($d['active'] ?? null) === $w['id']) $d['active'] = $d['workspaces'][0]['id'] ?? null;
        self::save($d);
        return true;
    }

    // Mark a workspace active and bump its lastOpened (called when one is opened).
    public static function touch(string $id): bool {
        $d = self::load();
        $hit = false;
        foreach ($d['workspaces'] as &$w) {
            if (($w['id'] ?? '') === $id) { $w['lastOpened'] = date('c'); $hit = true; break; }
        }
        unset($w);
        if (!$hit) return false;
        $d['active'] = $id;
        self::save($d);
        return true;
    }

    // Persist the GUI's opaque window state for a workspace (terminals, editor
    // tabs, layout, view). The CLI never interprets it.
    public static function saveState(string $id, array $state): bool {
        $d = self::load();
        $hit = false;
        foreach ($d['workspaces'] as &$w) {
            if (($w['id'] ?? '') === $id) { $w['state'] = $state; $hit = true; break; }
        }
        unset($w);
        if ($hit) self::save($d);
        return $hit;
    }
}
