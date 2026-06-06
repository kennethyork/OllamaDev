// CODEINDEX — local semantic code search over the working repo. Embeds code
// chunks with a local Ollama embedding model (default nomic-embed-text) and
// ranks them against a query by cosine similarity. 100% local — embeddings run
// on your own Ollama, nothing leaves the machine. Vanilla PHP, no deps.
class CodeIndex {
    const MODEL_DEFAULT = 'nomic-embed-text';
    const CHUNK_LINES = 60;
    const OVERLAP = 12;
    const MAX_FILE_BYTES = 200000;

    // Embeddings are an Ollama endpoint; use ollama.host even under LM Studio.
    public static function model(): string { return trim((string)Config::get('embed.model', self::MODEL_DEFAULT)) ?: self::MODEL_DEFAULT; }
    private static function host(): string { return rtrim((string)Config::get('ollama.host', 'http://localhost:11434'), '/'); }

    public static function dir(): string {
        $d = Config::dataDir() . '/index';   // project-local: <repo>/.ollamadev/index
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }
    private static function file(): string { return self::dir() . '/code.json'; }

    // Embed one text → float[] (or null on failure, e.g. model not installed).
    public static function embed(string $text): ?array {
        $ch = curl_init(self::host() . '/api/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => self::model(), 'prompt' => mb_substr($text, 0, 8000)]),
        ]);
        $out = curl_exec($ch); curl_close($ch);
        $j = json_decode((string)$out, true);
        return (isset($j['embedding']) && is_array($j['embedding']) && $j['embedding']) ? $j['embedding'] : null;
    }

    private static function cosine(array $a, array $b): float {
        $dot = 0.0; $na = 0.0; $nb = 0.0; $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) { $dot += $a[$i] * $b[$i]; $na += $a[$i] * $a[$i]; $nb += $b[$i] * $b[$i]; }
        $d = sqrt($na) * sqrt($nb);
        return $d > 0 ? $dot / $d : 0.0;
    }

    private const IGNORE_DIRS = ['.git', '.build', 'node_modules', 'vendor', '.ollamadev', 'dist', 'build', 'out',
        '.venv', 'venv', '__pycache__', '.next', 'target', 'coverage', '.cache', '.idea', '.vscode'];
    private const TEXT_EXT = ['php','js','mjs','cjs','ts','tsx','jsx','py','go','rs','rb','java','c','h','cpp','cc',
        'hpp','cs','swift','kt','kts','scala','css','scss','less','html','htm','vue','svelte','json','jsonc','md',
        'mdx','yml','yaml','toml','ini','sh','bash','zsh','sql','graphql','proto','lua','pl','pm','r','dart','ex','exs'];

    // Walk the repo, returning repo-relative text-file paths worth indexing.
    private static function walk(string $root): array {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($cur) {
                    $name = $cur->getFilename();
                    if ($cur->isDir()) return !in_array($name, self::IGNORE_DIRS, true);
                    return true;
                }
            )
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, self::TEXT_EXT, true)) continue;
            if ($f->getSize() > self::MAX_FILE_BYTES) continue;
            $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    // Build the index. $progress($file, $chunkCount) is called as it goes.
    // Returns ['ok'=>true,'files'=>N,'chunks'=>M] or ['error'=>...].
    public static function build(?callable $progress = null): array {
        $root = getcwd();
        if (self::embed('ping') === null) return ['error' => 'embed_failed', 'model' => self::model()];
        $files = self::walk($root);
        $chunks = []; $skipped = 0;
        foreach ($files as $rel) {
            $src = (string)@file_get_contents($root . '/' . $rel);
            if (trim($src) === '') continue;
            $lines = explode("\n", $src);
            $total = count($lines);
            $step = max(1, self::CHUNK_LINES - self::OVERLAP);
            for ($i = 0; $i < $total; $i += $step) {
                $text = trim(implode("\n", array_slice($lines, $i, self::CHUNK_LINES)));
                if ($text === '') { if ($i + self::CHUNK_LINES >= $total) break; continue; }
                // The model proved live (ping); a stray empty/error response on one
                // chunk shouldn't abort the whole index — retry once, then skip it.
                $vec = self::embed($rel . "\n" . $text) ?: self::embed($rel . "\n" . $text);
                if ($vec) {
                    $chunks[] = ['file' => $rel, 'start' => $i + 1, 'end' => min($i + self::CHUNK_LINES, $total),
                        'text' => mb_substr($text, 0, 400), 'vec' => $vec];
                } else {
                    $skipped++;
                }
                if ($progress) $progress($rel, count($chunks));
                if ($i + self::CHUNK_LINES >= $total) break;
            }
        }
        if (!$chunks) return ['error' => 'embed_failed', 'model' => self::model()];
        $data = ['model' => self::model(), 'root' => $root, 'built' => date('c'),
            'dim' => count($chunks[0]['vec']), 'chunks' => $chunks];
        @file_put_contents(self::file(), json_encode($data));
        return ['ok' => true, 'files' => count($files), 'chunks' => count($chunks), 'skipped' => $skipped];
    }

    // Semantic search → ['ok'=>true,'results'=>[{file,start,end,score,snippet}]] or ['error'=>...].
    public static function search(string $query, int $limit = 8): array {
        $data = json_decode((string)@file_get_contents(self::file()), true);
        if (!is_array($data) || empty($data['chunks'])) return ['error' => 'no_index'];
        $qv = self::embed($query);
        if (!$qv) return ['error' => 'embed_failed', 'model' => self::model()];
        $scored = [];
        foreach ($data['chunks'] as $c) $scored[] = ['s' => self::cosine($qv, $c['vec']), 'c' => $c];
        usort($scored, fn($a, $b) => $b['s'] <=> $a['s']);
        $out = [];
        foreach (array_slice($scored, 0, $limit) as $r) {
            $out[] = ['file' => $r['c']['file'], 'start' => $r['c']['start'], 'end' => $r['c']['end'],
                'score' => round($r['s'], 3), 'snippet' => $r['c']['text']];
        }
        return ['ok' => true, 'results' => $out];
    }

    public static function status(): array {
        $data = json_decode((string)@file_get_contents(self::file()), true);
        if (!is_array($data) || empty($data['chunks'])) return ['exists' => false];
        $files = [];
        foreach ($data['chunks'] as $c) $files[$c['file']] = true;
        return ['exists' => true, 'model' => $data['model'] ?? '?', 'built' => $data['built'] ?? '?',
            'dim' => $data['dim'] ?? 0, 'files' => count($files), 'chunks' => count($data['chunks']), 'root' => $data['root'] ?? ''];
    }

    public static function clear(): bool { $f = self::file(); if (is_file($f)) { @unlink($f); return !is_file($f); } return true; }
}
