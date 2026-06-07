// MODELS — curated presets + a graceful fallback chain.
//
// Local models vary wildly in how well they drive tools. This class gives the
// engine two things: (1) a small, opinionated catalog of models known to work
// for agentic coding (so `models` can recommend/pull the right one instead of
// leaving users to guess), and (2) a fallback chain so that when the active
// model can't do native tool-calling, OllamaDev switches to a capable installed
// model for tool turns rather than silently degrading to brittle text parsing.
//
// Vanilla PHP, local-only — this just inspects installed Ollama models and the
// config; it pulls only on explicit request via Puller (the one network seam).
class Models {
    // Opinionated catalog: short alias => {tag, size, tools, role, note}.
    // `tools` = reliably supports native function-calling (the agentic path).
    public static function presets(): array {
        return [
            'qwen2.5-coder'    => ['tag' => 'qwen2.5-coder:7b',   'size' => '~4.7 GB', 'tools' => true,  'role' => 'agentic coding', 'note' => 'Best all-round local coder for tool use. Recommended default.'],
            'qwen2.5-coder-14b'=> ['tag' => 'qwen2.5-coder:14b',  'size' => '~9 GB',   'tools' => true,  'role' => 'agentic coding', 'note' => 'Stronger reasoning; needs more VRAM.'],
            'qwen2.5-coder-32b'=> ['tag' => 'qwen2.5-coder:32b',  'size' => '~20 GB',  'tools' => true,  'role' => 'agentic coding', 'note' => 'Top local coder; for big GPUs / lots of RAM.'],
            'llama3.1'         => ['tag' => 'llama3.1:8b',        'size' => '~4.9 GB', 'tools' => true,  'role' => 'general + tools', 'note' => 'Solid tool-caller and generalist.'],
            'mistral'          => ['tag' => 'mistral:latest',     'size' => '~4.1 GB', 'tools' => true,  'role' => 'general + tools', 'note' => 'Fast, dependable tool-calling.'],
            'codestral'        => ['tag' => 'codestral:latest',   'size' => '~13 GB',  'tools' => true,  'role' => 'coding',          'note' => 'Strong code model with tool support.'],
            'deepseek-coder-v2'=> ['tag' => 'deepseek-coder-v2:16b','size' => '~9 GB', 'tools' => true,  'role' => 'coding',          'note' => 'Good code completion and edits.'],
            'llama3.2'         => ['tag' => 'llama3.2:latest',    'size' => '~2 GB',   'tools' => false, 'role' => 'small / chat',    'note' => 'Tiny & fast; tool use is unreliable — use /chat.'],
            'llava'            => ['tag' => 'llava:7b',           'size' => '~4.7 GB', 'tools' => false, 'vision' => true, 'role' => 'vision',        'note' => 'Image understanding. Attach with @img.png or /image <path>.'],
            'llama3.2-vision'  => ['tag' => 'llama3.2-vision:11b','size' => '~7.8 GB', 'tools' => false, 'vision' => true, 'role' => 'vision',        'note' => 'Stronger image understanding; needs more VRAM.'],
            'moondream'        => ['tag' => 'moondream:latest',   'size' => '~1.7 GB', 'tools' => false, 'vision' => true, 'role' => 'vision / tiny', 'note' => 'Tiny, fast vision model for quick image Q&A.'],
            'nomic-embed-text' => ['tag' => 'nomic-embed-text',   'size' => '~270 MB', 'tools' => false, 'role' => 'embeddings',      'note' => 'Powers semantic code search (index).'],
        ];
    }

    // Preferred order for the agentic tool path, most→least preferred. Used both
    // to pick a sane default when none is configured and to choose a fallback
    // when the active model can't do native tools.
    public static function defaultChain(): array {
        return ['qwen2.5-coder:7b', 'llama3.1:8b', 'mistral:latest', 'qwen2.5-coder:14b', 'codestral:latest', 'deepseek-coder-v2:16b'];
    }

    // The configured fallback chain (config model.fallback, array or CSV string),
    // else the built-in default chain.
    public static function chain(): array {
        $c = Config::get('model.fallback', null);
        if (is_string($c) && trim($c) !== '') $c = array_map('trim', explode(',', $c));
        if (is_array($c) && $c) return array_values(array_filter($c, fn($x) => is_string($x) && $x !== ''));
        return self::defaultChain();
    }

    // Match a desired tag against an installed list: exact, then ":latest", then
    // unique prefix. Returns the installed tag or '' if not present.
    public static function match(string $want, array $installed): string {
        $want = trim($want);
        if ($want === '') return '';
        if (in_array($want, $installed, true)) return $want;
        if (!str_contains($want, ':') && in_array("$want:latest", $installed, true)) return "$want:latest";
        $base = explode(':', $want)[0];
        foreach ($installed as $m) if ($m === $want || str_starts_with($m, $base . ':') || $m === "$base:latest") return $m;
        return '';
    }

    // Does this tag have reliable native tool support, per the catalog?
    // true/false for a catalogued model (matched by base name), null if unknown.
    public static function toolsSupported(string $tag): ?bool {
        $base = explode(':', $tag)[0];
        foreach (self::presets() as $p) {
            if ($p['tag'] === $tag || explode(':', $p['tag'])[0] === $base) return $p['tools'];
        }
        return null;
    }

    // First installed model catalogued as tool-capable (ANY, not just the chain),
    // or ''. The looser net behind bestInstalled() for unusual installs.
    public static function anyToolCapable(array $installed): string {
        foreach ($installed as $m) if (self::toolsSupported($m) === true) return $m;
        return '';
    }

    // Parameter size (in billions) parsed from a tag, e.g. qwen2.5-coder:14b → 14.0.
    // Used to climb the escalation ladder. Returns null when no "<n>b" size is present.
    public static function paramSize(string $tag): ?float {
        // Match the size token after the ':' (…:7b, :14b, :70b, :30b-q5) — not the
        // family version (qwen2.5 / llama3.2). Fall back to any "<n>b" if no colon.
        $cand = strpos($tag, ':') !== false ? substr($tag, strpos($tag, ':') + 1) : $tag;
        // Mixture-of-experts tags (8x7b, 8x22b) are experts×size, far bigger than the
        // per-expert number — match these FIRST so e.g. 8x7b ≈ 56, not 7.
        if (preg_match('/(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*b\b/i', $cand, $x)) return (float)$x[1] * (float)$x[2];
        if (preg_match('/(\d+(?:\.\d+)?)\s*b\b/i', $cand, $m)) return (float)$m[1];
        return null;
    }

    // The next-bigger INSTALLED model to retry a failed task on, or null if none.
    // A configured ladder (models.escalation: ordered small→large tags) wins; else
    // it picks the smallest installed model strictly larger than $current by size.
    public static function escalate(string $current, array $installed): ?string {
        $current = trim($current);
        $ladder = Config::get('models.escalation', null);
        if (is_array($ladder) && $ladder) {
            // Find current on the ladder by exact tag, else by alias/base-name match
            // (so a ladder written with `qwen2.5-coder` still matches a resolved
            // `qwen2.5-coder:7b`) — otherwise an explicit ladder is silently ignored.
            $pos = array_search($current, $ladder, true);
            if ($pos === false) {
                foreach ($ladder as $i => $entry) {
                    if (self::match($entry, [$current]) === $current) { $pos = $i; break; }
                }
            }
            if ($pos !== false) {
                for ($i = $pos + 1; $i < count($ladder); $i++) {
                    if (in_array($ladder[$i], $installed, true)) return $ladder[$i];
                }
                return null;   // current is on the ladder but nothing bigger is installed
            }
        }
        $cur = self::paramSize($current);
        if ($cur === null) return null;
        $best = null; $bestSize = INF;
        foreach ($installed as $tag) {
            if ($tag === $current) continue;
            $s = self::paramSize($tag);
            if ($s === null || $s <= $cur) continue;
            if ($s < $bestSize) { $bestSize = $s; $best = $tag; }
        }
        return $best;
    }

    // Catalogued vision-model tags (for `models presets` and "pull a vision
    // model" hints when someone attaches an image with no multimodal model).
    public static function visionPresets(): array {
        $out = [];
        foreach (self::presets() as $p) if (!empty($p['vision'])) $out[] = $p['tag'];
        return $out;
    }

    // First catalogued vision model that is actually installed (installed tag), or ''.
    public static function installedVision(array $installed): string {
        foreach (self::visionPresets() as $want) {
            $hit = self::match($want, $installed);
            if ($hit !== '') return $hit;
        }
        return '';
    }

    // First chain entry that is actually installed (returns the installed tag), or ''.
    public static function bestInstalled(array $installed, ?array $chain = null): string {
        foreach ($chain ?? self::chain() as $want) {
            $hit = self::match($want, $installed);
            if ($hit !== '') return $hit;
        }
        return '';
    }

    // A tool-capable fallback DIFFERENT from $current that is installed, or ''.
    // Used when the active model reports it can't do native tool-calling.
    public static function toolFallback(array $installed, string $current): string {
        foreach (self::chain() as $want) {
            $hit = self::match($want, $installed);
            if ($hit !== '' && $hit !== $current) return $hit;
        }
        return '';
    }

    // Resolve a CLI argument (alias or tag) to a concrete Ollama tag to pull.
    public static function resolveTag(string $nameOrAlias): string {
        $n = trim($nameOrAlias);
        $p = self::presets();
        if (isset($p[$n])) return $p[$n]['tag'];
        return $n; // already a tag (e.g. "qwen2.5-coder:32b") or unknown — pass through
    }
}
