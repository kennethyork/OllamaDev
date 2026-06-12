class Config {
    private static $config;

    public static function load(): array {
        if (self::$config) return self::$config;
        $envOverrides = [];
        if (getenv('OLLAMA_HOST')) $envOverrides['ollama']['host'] = getenv('OLLAMA_HOST');
        if (getenv('OLLAMA_MODEL')) $envOverrides['ollama']['defaultModel'] = getenv('OLLAMA_MODEL');
        $defaults = [
            'provider' => getenv('OLLAMADEV_PROVIDER') ?: 'ollama', // 'ollama' | 'lmstudio'
            'lmstudio' => ['host' => getenv('LMSTUDIO_HOST') ?: 'http://localhost:1234/v1'],
            'ollama' => ['host' => getenv('OLLAMA_HOST') ?: 'http://localhost:11434', 'hosts' => [], 'defaultModel' => getenv('OLLAMA_MODEL') ?: 'qwen3.5:9b', 'contextWindow' => (int)(getenv('OLLAMA_NUM_CTX') ?: 16384), 'maxContextWindow' => (int)(getenv('OLLAMA_MAX_NUM_CTX') ?: 32768), 'autoContext' => true, 'temperature' => 0.3, 'stream' => true],
            'agents' => ['coder' => ['temperature' => 0.7, 'maxTokens' => 4096], 'maxIterations' => 12, 'maxToolOutput' => 12000, 'subagentPermission' => 'readonly'],
            'session' => ['autoResume' => true], // bare `ollamadev` resumes this repo's last session (use `new`/--new for a fresh one)
            'memory' => ['autoRemember' => true], // distill durable facts into graph memory after real work (--no-memory / false to disable)
            'stt' => ['host' => getenv('STT_HOST') ?: '', 'command' => getenv('STT_COMMAND') ?: '', 'model' => getenv('STT_MODEL') ?: 'whisper-1'], // local speech-to-text (bring your own engine)
            'data' => ['directory' => '.ollamadev']
        ];
        $home = getenv('HOME') ?: '/tmp';
        $paths = [$home.'/.ollamadev/config.json', $home.'/.config/ollamadev/config.json', '.ollamadev.json'];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $json = json_decode(file_get_contents($path), true);
                if ($json) {
                    // Precedence: defaults < config file < environment. Env vars are
                    // documented as overrides, so they must win over a config file
                    // that hardcodes the same key (e.g. ollama.host).
                    self::$config = array_replace_recursive($defaults, $json, $envOverrides);
                    return self::$config;
                }
            }
        }
        self::$config = $defaults;
        return self::$config;
    }

    public static function get(string $key, $default = null) {
        $config = self::load();
        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $k) { if (!isset($value[$k])) return $default; $value = $value[$k]; }
        return $value;
    }

    // Read a key ONLY from the user's trusted home/global config — never the
    // project-local `.ollamadev.json`, which a cloned/untrusted repo could plant.
    // Used for settings that EXECUTE shell commands (statusline, hooks) so a
    // checked-out repo can't run code just by being opened. Returns $default if the
    // key is absent from every trusted file.
    public static function trustedGet(string $key, $default = null) {
        $home = getenv('HOME') ?: '/tmp';
        foreach ([$home . '/.ollamadev/config.json', $home . '/.config/ollamadev/config.json'] as $path) {
            if (!is_file($path)) continue;
            $json = json_decode((string) @file_get_contents($path), true);
            if (!is_array($json)) continue;
            $value = $json; $found = true;
            foreach (explode('.', $key) as $k) { if (!is_array($value) || !array_key_exists($k, $value)) { $found = false; break; } $value = $value[$k]; }
            if ($found) return $value;
        }
        return $default;
    }

    // Set a dotted key on the cached config so Config::get() reflects it.
    public static function set(string $key, $value): void {
        self::load();
        $keys = explode('.', $key);
        $ref = &self::$config;
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
            $ref = &$ref[$k];
        }
        $ref = $value;
    }

    // Persist a dotted key to the user's config file on disk (and reflect it in
    // the live cache). Writes ~/.ollamadev/config.json, preserving other keys —
    // only the user's overrides are stored, never the merged defaults.
    public static function persist(string $key, $value): void {
        self::set($key, $value);
        $home = getenv('HOME') ?: '/tmp';
        $file = $home . '/.ollamadev/config.json';
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];
        $keys = explode('.', $key);
        $ref = &$data;
        while (count($keys) > 1) {
            $k = array_shift($keys);
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
            $ref = &$ref[$k];
        }
        $ref[$keys[0]] = $value;
        unset($ref);
        @mkdir(dirname($file), 0755, true);
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function dataDir(): string {
        $dir = self::get('data.directory', '.ollamadev');
        return str_starts_with($dir, '/') ? $dir : getcwd() . '/' . $dir;
    }

    public static function binaryPath(): string {
        return $_SERVER['argv'][0] ?? 'ollamadev';
    }

    public static function sessionsDir(): string { return self::dataDir() . '/sessions'; }
    public static function checkpointsDir(): string { return self::dataDir() . '/checkpoints'; }
    public static function costsDir(): string { return self::dataDir() . '/costs'; }
}

// Model Context Protocol client. Supports the standard stdio transport
// (JSON-RPC 2.0 with LSP-style Content-Length framing) and an HTTP/SSE
// fallback for remote servers.
