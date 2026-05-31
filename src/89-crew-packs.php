// CREW PACKS — saved, shareable crew team configurations. A pack bundles the
// reusable knobs of a crew (focus, per-role models, parallelism, amplify, land
// policy, hosts) so a team you tuned once can be reused or shared:
//   ollamadev crew pack save backend --focus "Go microservice" --coder-model codestral --amplify 3
//   ollamadev crew pack list
//   ollamadev crew --pack backend "add a /health route"
class CrewPacks {
    // Opt keys that make up a reusable team (NOT the one-off task/runId).
    private const KEYS = ['focus','directorModel','coderModel','auditorModel','researcherModel',
        'max','amplify','land','research','audit','skills','hosts'];

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
        @file_put_contents($path, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    // Load a pack's opts, or null if there's no such pack.
    public static function load(string $name): ?array {
        $path = self::path($name);
        if (!is_file($path)) return null;
        $j = json_decode((string)@file_get_contents($path), true);
        if (!is_array($j)) return null;
        $out = [];
        foreach (self::KEYS as $k) if (array_key_exists($k, $j)) $out[$k] = $j[$k];
        return $out;
    }

    // name => summary line, for `crew pack list`.
    public static function all(): array {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) continue;
            $bits = [];
            if (!empty($j['focus'])) $bits[] = 'focus: ' . $j['focus'];
            if (!empty($j['coderModel'])) $bits[] = 'coder: ' . $j['coderModel'];
            if (!empty($j['amplify']) && (int)$j['amplify'] > 1) $bits[] = 'amplify ×' . (int)$j['amplify'];
            if (!empty($j['max'])) $bits[] = (int)$j['max'] . ' coders';
            $out[basename($f, '.json')] = implode(' · ', $bits) ?: '(empty pack)';
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
