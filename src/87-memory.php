// GRAPH MEMORY — a persistent, linked knowledge base that lives next to the code.
// Each memory is a markdown file with frontmatter (title, tags) and a body that can
// link to other memories with [[wiki-links]]. Files live in:
//   Project:  <cwd>/.ollamadev/memory/<slug>.md   (committable, co-located with code)
//   Global:   ~/.ollamadev/memory/<slug>.md
// The agent gets a short index of memory titles in its system prompt and pulls full
// notes on demand via the `recall` tool; it writes new facts with `remember`. The
// links form a graph the desktop renders (Memory::graph()).
class Memory {
    public static function baseDirs(): array {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        return array_values(array_unique([getcwd() . '/.ollamadev/memory', $home . '/.ollamadev/memory']));
    }
    public static function projectDir(): string { return getcwd() . '/.ollamadev/memory'; }

    // All memories keyed by slug (project overrides global). Each: slug,title,tags[],path,links[].
    public static function all(): array {
        $mem = [];
        foreach (self::baseDirs() as $base) {
            if (!is_dir($base)) continue;
            foreach (glob($base . '/*.md') ?: [] as $file) {
                $slug = basename($file, '.md');
                if (isset($mem[$slug])) continue;
                $mem[$slug] = self::parse($file, $slug) + ['slug' => $slug, 'path' => $file];
            }
        }
        ksort($mem);
        return $mem;
    }

    private static function parse(string $file, string $slug): array {
        $content = (string) @file_get_contents($file);
        $title = $slug; $tags = []; $body = $content;
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $m)) {
            $body = $m[2];
            foreach (preg_split('/\n/', $m[1]) as $line) {
                if (preg_match('/^\s*title\s*:\s*(.+)$/i', $line, $mm)) $title = trim($mm[1], " \"'");
                elseif (preg_match('/^\s*tags\s*:\s*(.+)$/i', $line, $mm)) $tags = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $mm[1]))));
            }
        }
        $links = [];
        if (preg_match_all('/\[\[([^\]]+)\]\]/', $body, $lm)) $links = array_values(array_unique(array_map('trim', $lm[1])));
        return ['title' => $title !== '' ? $title : $slug, 'tags' => $tags, 'links' => $links, 'body' => $body];
    }

    public static function get(string $slug): ?array {
        $all = self::all();
        if (isset($all[$slug])) return $all[$slug];
        // also resolve by title (case-insensitive)
        foreach ($all as $m) if (strcasecmp($m['title'], $slug) === 0) return $m;
        return null;
    }

    public static function slugify(string $s): string {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
        return trim(substr($s, 0, 48), '-') ?: 'note';
    }

    // Save a memory into the project memory dir. Returns the slug.
    public static function save(string $title, string $body, array $tags = [], string $slug = ''): string {
        $slug = $slug !== '' ? self::slugify($slug) : self::slugify($title);
        $dir = self::projectDir();
        @mkdir($dir, 0755, true);
        $fm = "---\ntitle: " . trim($title) . "\n";
        if ($tags) $fm .= "tags: " . implode(', ', array_map('trim', $tags)) . "\n";
        $fm .= "---\n\n";
        atomicWrite($dir . '/' . $slug . '.md', $fm . rtrim($body) . "\n");
        return $slug;
    }

    public static function remove(string $slug): bool {
        $m = self::get($slug);
        if (!$m) return false;
        @unlink($m['path']);
        return !is_file($m['path']);
    }

    // Match query against title, tags, and body. Returns matching metas.
    public static function search(string $query): array {
        $q = strtolower(trim($query));
        if ($q === '') return self::all();
        $hits = [];
        foreach (self::all() as $slug => $m) {
            $hay = strtolower($m['title'] . ' ' . implode(' ', $m['tags']) . ' ' . $m['body']);
            if (strpos($hay, $q) !== false) $hits[$slug] = $m;
        }
        return $hits;
    }

    // Short "slug — title" index for the system prompt (bounded).
    public static function index(int $cap = 24): string {
        $lines = [];
        foreach (self::all() as $slug => $m) {
            $lines[] = '- ' . $slug . ($m['title'] !== $slug ? ' — ' . $m['title'] : '');
            if (count($lines) >= $cap) { $lines[] = '- … (' . (count(self::all()) - $cap) . ' more — search with recall)'; break; }
        }
        return implode("\n", $lines);
    }

    // Auto-capture: from recent work ($context), extract DURABLE, reusable project
    // facts and save them as notes — deduped against existing ones. Best-effort:
    // any failure (model down, unparseable JSON) returns []. Returns saved slugs.
    public static function autoRemember(string $context, string $model = ''): array {
        $context = trim($context);
        if ($context === '' || !class_exists('ModelClient')) return [];
        $have = self::all();
        $existing = [];
        foreach ($have as $slug => $m) $existing[] = $slug . ' (' . $m['title'] . ')';
        $sys = ['role' => 'system', 'content' =>
            "Extract DURABLE, reusable facts about THIS PROJECT worth remembering across sessions — architecture, " .
            "conventions, key decisions, gotchas, where things live. NOT transient task details or chatter. Skip " .
            "anything already covered by the existing notes. Output ONLY JSON: " .
            '{"notes":[{"title":"short title","body":"1-3 sentences; link related notes with [[slug]]"}]} — at most 4, ' .
            "or an empty array if nothing durable is worth saving."];
        $ex = $existing ? "Existing notes (do NOT duplicate):\n- " . implode("\n- ", array_slice($existing, 0, 40)) . "\n\n" : '';
        $j = ModelClient::default()->chatJson($model !== '' ? $model : Config::get('ollama.defaultModel', ''),
            [$sys, ['role' => 'user', 'content' => $ex . "Recent work:\n" . substr($context, 0, 6000) . "\n\nExtract durable facts worth keeping."]]);
        $notes = (is_array($j) && isset($j['notes']) && is_array($j['notes'])) ? $j['notes'] : [];
        $saved = [];
        foreach (array_slice($notes, 0, 4) as $n) {
            if (!is_array($n)) continue;
            $title = trim((string)($n['title'] ?? '')); $body = trim((string)($n['body'] ?? ''));
            if ($title === '' || $body === '') continue;
            $slug = self::slugify($title);
            if (isset($have[$slug])) continue;                          // exact dedupe
            $dupe = false; foreach ($have as $m) if (strcasecmp($m['title'], $title) === 0) { $dupe = true; break; }
            if ($dupe) continue;                                        // title dedupe
            self::save($title, $body);
            $saved[] = $slug;
            $have[$slug] = ['title' => $title, 'tags' => [], 'links' => [], 'body' => $body]; // dedupe within this batch
        }
        return $saved;
    }

    // Graph of nodes + edges (edges resolved to existing memories by slug or title).
    public static function graph(): array {
        $all = self::all();
        $titleToSlug = [];
        foreach ($all as $slug => $m) { $titleToSlug[strtolower($m['title'])] = $slug; $titleToSlug[strtolower($slug)] = $slug; }
        $nodes = []; $edges = []; $seen = [];
        foreach ($all as $slug => $m) {
            $nodes[] = ['id' => $slug, 'title' => $m['title'], 'tags' => $m['tags'], 'degree' => 0];
            foreach ($m['links'] as $link) {
                $target = $titleToSlug[strtolower(trim($link))] ?? null;
                if ($target && $target !== $slug) {
                    $key = $slug . '->' . $target;
                    if (!isset($seen[$key])) { $edges[] = ['from' => $slug, 'to' => $target]; $seen[$key] = true; }
                }
            }
        }
        // degree count for sizing in the graph view
        $deg = [];
        foreach ($edges as $e) { $deg[$e['from']] = ($deg[$e['from']] ?? 0) + 1; $deg[$e['to']] = ($deg[$e['to']] ?? 0) + 1; }
        foreach ($nodes as &$n) $n['degree'] = $deg[$n['id']] ?? 0;
        unset($n);
        return ['nodes' => $nodes, 'edges' => $edges];
    }
}

// recall — read-only: list / read / search memories (and see their links).
Tools::register('recall', function ($p) {
    $slug = trim((string) ($p['slug'] ?? ''));
    $query = trim((string) ($p['query'] ?? ''));
    if ($slug !== '') {
        $m = Memory::get($slug);
        if (!$m) return "No memory '$slug'. " . (($i = Memory::index()) !== '' ? "Known:\n$i" : 'Memory is empty.');
        $links = $m['links'] ? "\nLinks: " . implode(', ', array_map(fn($l) => "[[$l]]", $m['links'])) : '';
        return "# {$m['title']}  ({$m['slug']})" . ($m['tags'] ? "  tags: " . implode(', ', $m['tags']) : '') . "\n" . trim($m['body']) . $links;
    }
    if ($query !== '') {
        $hits = Memory::search($query);
        if (!$hits) return "No memories match '$query'.";
        $out = "Memories matching '$query':\n";
        foreach ($hits as $s => $m) $out .= "- $s — {$m['title']}\n";
        return $out . "Read one with recall(slug).";
    }
    $i = Memory::index();
    return $i === '' ? "Memory is empty. Save facts with the remember tool." : ("Project memory:\n" . $i . "\nRead one with recall(slug), or search with recall(query).");
});

// remember — write: persist a fact as a linked memory. Use [[other-slug]] in content to link.
Tools::register('remember', function ($p) {
    $title = trim((string) ($p['title'] ?? ''));
    $content = (string) ($p['content'] ?? $p['body'] ?? '');
    if ($title === '' && $content === '') return "remember needs a title and content.";
    if ($title === '') $title = substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 48);
    $tags = [];
    if (isset($p['tags'])) $tags = is_array($p['tags']) ? $p['tags'] : array_filter(array_map('trim', preg_split('/[,;]/', (string) $p['tags'])));
    $slug = Memory::save($title, $content, $tags, (string) ($p['slug'] ?? ''));
    return "Saved memory '$slug' (\"$title\"). Link to it from other notes with [[$slug]].";
});
