// CREW PACKS — saved, shareable crew team configurations. A pack bundles the
// reusable knobs of a crew (focus, per-role models, parallelism, amplify, land
// policy, hosts) so a team you tuned once can be reused or shared:
//   ollamadev crew pack save backend --focus "Go microservice" --coder-model codestral --amplify 3
//   ollamadev crew pack list
//   ollamadev crew --pack backend "add a /health route"
class CrewPacks {
    // Opt keys that make up a reusable team (NOT the one-off task/runId).
    private const KEYS = ['focus','directorModel','coderModel','auditorModel','researcherModel',
        'max','amplify','land','research','audit','skills','hosts','ideas','memory'];

    // Built-in starter packs so `crew pack list` is useful out of the box. The
    // `focus` string is what actually steers the Director's plan; amplify adds an
    // adversarial reviewer panel where it pays off. A user-saved pack of the same
    // name overrides the built-in.
    public static function builtins(): array {
        return [
            'web-app'       => ['focus' => 'a web application — an HTML/CSS/JS frontend plus its backend; prioritise a working UI, sensible routing, and a clean separation of concerns'],
            'rest-api'      => ['focus' => 'a REST API — clear resource endpoints, input validation, consistent error responses, and a test for each route'],
            'cli-tool'      => ['focus' => 'a command-line tool — argument parsing, a helpful --help, clear error messages, and correct exit codes'],
            'data-pipeline' => ['focus' => 'a data-processing pipeline — robust parsing, transformation, validation, and explicit handling of malformed or edge-case input'],
            'library'       => ['focus' => 'a reusable library/package — a small clear public API, docblocks, no side effects on import, and unit tests'],
            'bugfix'        => ['focus' => 'find and fix the bug with the smallest correct change, then add a regression test that fails before the fix and passes after', 'amplify' => 3],
            'refactor'      => ['focus' => 'refactor for clarity and structure WITHOUT changing behaviour; keep the public API stable and the diff reviewable', 'amplify' => 3],
            'tested'        => ['focus' => 'build with test-first discipline — every change covered by a test that runs green; do not finish with failing or missing tests'],
        ];
    }

    // One-line summary of a pack's reusable knobs (for `crew pack list`).
    private static function summary(array $j): string {
        $bits = [];
        if (!empty($j['focus']))      $bits[] = 'focus: ' . (strlen($j['focus']) > 60 ? substr($j['focus'], 0, 57) . '…' : $j['focus']);
        if (!empty($j['coderModel'])) $bits[] = 'coder: ' . $j['coderModel'];
        if (!empty($j['amplify']) && (int)$j['amplify'] > 1) $bits[] = 'amplify ×' . (int)$j['amplify'];
        if (!empty($j['max']))        $bits[] = (int)$j['max'] . ' coders';
        return implode(' · ', $bits) ?: '(empty pack)';
    }

    public static function dir(): string {
        $d = (getenv('HOME') ?: sys_get_temp_dir()) . '/.ollamadev/crew-packs';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    private static function path(string $name): string {
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($name));
        return self::dir() . '/' . $name . '.json';
    }

    // Persist the team-shaped subset of $opts under $name. Returns the file path.
    public static function save(string $name, array $opts): string {
        $pack = [];
        foreach (self::KEYS as $k) if (array_key_exists($k, $opts)) $pack[$k] = $opts[$k];
        $path = self::path($name);
        atomicWrite($path, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    // Load a pack's opts, or null if there's no such pack. A user-saved pack wins
    // over a built-in of the same name.
    public static function load(string $name): ?array {
        $src = null;
        $path = self::path($name);
        if (is_file($path)) { $j = json_decode((string)@file_get_contents($path), true); if (is_array($j)) $src = $j; }
        if ($src === null) { $b = self::builtins(); if (isset($b[$name])) $src = $b[$name]; }
        if ($src === null) return null;
        $out = [];
        foreach (self::KEYS as $k) if (array_key_exists($k, $src)) $out[$k] = $src[$k];
        return $out;
    }

    // name => summary line, for `crew pack list` — built-ins plus user packs.
    public static function all(): array {
        $out = [];
        foreach (self::builtins() as $name => $j) $out[$name] = self::summary($j) . '  (built-in)';
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) continue;
            $out[basename($f, '.json')] = self::summary($j);   // a user pack overrides a built-in of the same name
        }
        ksort($out);
        return $out;
    }

    public static function remove(string $name): bool {
        $path = self::path($name);
        if (is_file($path)) { @unlink($path); return !is_file($path); }
        return false;
    }
}
