#!/usr/bin/env php
<?php
// OllamaDev - Single-file PHP binary
// Built from modular source

define('OLLAMADEV_VERSION', '4.8.27');
$GLOBALS['editedFiles'] = [];

// Shipped binary: keep warnings/errors visible but never spew engine
// deprecation notices at users (the standalone php-micro runtime shows them
// by default). Set OLLAMADEV_DEBUG=1 to see everything.
if (!getenv('OLLAMADEV_DEBUG')) error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

function isWindows(): bool { return stripos(PHP_OS, 'WIN') === 0; }

// Local models habitually wrap a whole file's content in a markdown code fence
// (```php … ```). Written verbatim, the file then starts with ``` instead of
// <?php and won't run. Strip a SINGLE fully-enclosing fence (exactly one opening
// and one closing ```), so legit multi-block markdown files are left untouched.
function unfence(string $s): string {
    $t = trim($s);
    if (substr_count($t, '```') !== 2) return $s;            // not a single enclosing block
    if (!preg_match('/^```[^\n]*\n(.*)\n```\s*$/s', $t, $m)) return $s;
    return $m[1] . "\n";
}

function crossPlatformLs(string $path): string {
    $path = rtrim($path, '/\\');
    if (!is_dir($path)) return "Not a directory: $path";
    $files = [];
    try {
        $iterator = new DirectoryIterator($path);
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if ($name === '.' || $name === '..') continue;
            $type = $file->isDir() ? 'd' : '-';
            $size = $file->getSize();
            $mtime = date('M d H:i', $file->getMTime());
            $perms = $file->isDir() ? 'drwxr-xr-x' : '-rw-r--r--';
            $files[] = sprintf("%s %s %5d %s %s", $perms, $type === 'd' ? '2' : '1', $size, $mtime, $name);
        }
        return empty($files) ? "Empty directory" : implode("\n", $files);
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

function crossPlatformFind(string $dir, string $pattern): string {
    $dir = rtrim($dir, '/\\');
    $results = [];
    // Glob -> regex. preg_quote turns * and ? into \* and \?, so translate
    // THOSE (not bare * / ?, which would mangle the already-escaped pattern and
    // make every wildcard search fail).
    $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            if (preg_match($regex, $file->getFilename())) {
                $results[] = $file->getPathname();
            }
        }
        return empty($results) ? "No matches found" : implode("\n", array_slice($results, 0, 100));
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

function crossPlatformTree(string $dir, int $depth = 2): string {
    $dir = rtrim($dir, '/\\');
    $output = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $maxDepth = $depth;
        foreach ($iterator as $file) {
            $depth = $iterator->getDepth();
            if ($depth > $maxDepth) continue;
            $prefix = str_repeat('  ', $depth);
            $name = $file->getFilename();
            if ($file->isDir()) {
                $output[] = $prefix . "📁 " . $name;
            } else {
                $output[] = $prefix . "📄 " . $name;
            }
        }
        return empty($output) ? "Empty" : implode("\n", $output);
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

