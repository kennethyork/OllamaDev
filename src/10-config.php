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
            'ollama' => ['host' => getenv('OLLAMA_HOST') ?: 'http://localhost:11434', 'hosts' => [], 'defaultModel' => getenv('OLLAMA_MODEL') ?: 'llama3.2:latest', 'contextWindow' => (int)(getenv('OLLAMA_NUM_CTX') ?: 16384), 'maxContextWindow' => (int)(getenv('OLLAMA_MAX_NUM_CTX') ?: 32768), 'autoContext' => true, 'temperature' => 0.6, 'stream' => true],
            'agents' => ['coder' => ['temperature' => 0.7, 'maxTokens' => 4096], 'maxIterations' => 12, 'maxToolOutput' => 12000, 'subagentPermission' => 'readonly'],
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
