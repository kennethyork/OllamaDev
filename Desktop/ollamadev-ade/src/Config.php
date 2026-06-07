<?php

declare(strict_types=1);

namespace OllamaDev;

define('OLLAMADEV_VERSION', '4.8.53');

$GLOBALS['editedFiles'] = [];

function isWindows(): bool
{
    return stripos(PHP_OS, 'WIN') === 0;
}

function crossPlatformLs(string $path): string
{
    $path = rtrim($path, '/\\');
    if (!is_dir($path)) {
        return "Not a directory: $path";
    }
    $files = [];
    try {
        $iterator = new \DirectoryIterator($path);
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if ($name === '.' || $name === '..') {
                continue;
            }
            $type = $file->isDir() ? 'd' : '-';
            $size = $file->getSize();
            $mtime = date('M d H:i', $file->getMTime());
            $perms = $file->isDir() ? 'drwxr-xr-x' : '-rw-r--r--';
            $files[] = sprintf("%s %s %5d %s %s", $perms, $type === 'd' ? '2' : '1', $size, $mtime, $name);
        }
        return empty($files) ? "Empty directory" : implode("\n", $files);
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

function crossPlatformFind(string $dir, string $pattern): string
{
    $dir = rtrim($dir, '/\\');
    $results = [];
    $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
    try {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            if (preg_match($regex, $file->getFilename())) {
                $results[] = $file->getPathname();
            }
        }
        return empty($results) ? "No matches found" : implode("\n", array_slice($results, 0, 100));
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

function crossPlatformTree(string $dir, int $depth = 2): string
{
    $dir = rtrim($dir, '/\\');
    $output = [];
    try {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $maxDepth = $depth;
        foreach ($iterator as $file) {
            $depth = $iterator->getDepth();
            if ($depth > $maxDepth) {
                continue;
            }
            $prefix = str_repeat('  ', $depth);
            $name = $file->getFilename();
            if ($file->isDir()) {
                $output[] = $prefix . "📁 " . $name;
            } else {
                $output[] = $prefix . "📄 " . $name;
            }
        }
        return empty($output) ? "Empty" : implode("\n", $output);
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

class Config
{
    private static array $config = [];

    public static function load(): array
    {
        if (self::$config) {
            return self::$config;
        }
        $defaults = [
            'ollama' => ['host' => 'http://localhost:11434', 'defaultModel' => 'llama3.2:latest'],
            'agents' => ['coder' => ['temperature' => 0.7, 'maxTokens' => 4096]],
            'data' => ['directory' => '.ollamadev'],
        ];
        $home = getenv('HOME') ?: '/tmp';
        $paths = [$home . '/.ollamadev/config.json', $home . '/.config/ollamadev/config.json', '.ollamadev.json'];
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

    public static function get(string $key, mixed $default = null): mixed
    {
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

    public static function dataDir(): string
    {
        $dir = self::get('data.directory', '.ollamadev');
        return str_starts_with($dir, '/') ? $dir : getcwd() . '/' . $dir;
    }

    public static function sessionsDir(): string
    {
        return self::dataDir() . '/sessions';
    }
}