#!/usr/bin/env php
<?php
// OllamaDev - Single-file PHP binary
// Built from modular source

define('OLLAMADEV_VERSION', '4.0.0');
$GLOBALS['editedFiles'] = [];

function isWindows(): bool { return stripos(PHP_OS, 'WIN') === 0; }

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
    $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
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

