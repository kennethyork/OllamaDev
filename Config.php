<?php

class Config {
    private static $config;

    public static function load(): array {
        if (self::$config) {
            return self::$config;
        }

        $defaults = [
            'ollama' => [
                'host' => 'http://localhost:11434',
                'defaultModel' => 'codellama'
            ],
            'agents' => [
                'coder' => [
                    'temperature' => 0.7,
                    'maxTokens' => 4096
                ]
            ],
            'data' => [
                'directory' => '.ollamadev'
            ]
        ];

        $home = getenv('HOME') ?: '/tmp';
        $paths = [
            $home . '/.ollamadev/config.json',
            $home . '/.config/ollamadev/config.json',
            '.ollamadev.json'
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $json = json_decode(file_get_contents($path), true);
                if ($json) {
                    self::$config = array_replace_recursive($defaults, $json);
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
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public static function dataDir(): string {
        $dir = self::get('data.directory', '.ollamadev');
        if (!str_starts_with($dir, '/')) {
            $dir = getcwd() . '/' . $dir;
        }
        return $dir;
    }

    public static function sessionsDir(): string {
        return self::dataDir() . '/sessions';
    }
}