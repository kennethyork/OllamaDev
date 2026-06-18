#!/usr/bin/env php
<?php
// OllamaDev - Single-file PHP binary
// Built from modular source

define('OLLAMADEV_VERSION', '0.9.43');
$GLOBALS['editedFiles'] = [];

// Shipped binary: keep warnings/errors visible but never spew engine
// deprecation notices at users (the standalone php-micro runtime shows them
// by default). Set OLLAMADEV_DEBUG=1 to see everything.
if (!getenv('OLLAMADEV_DEBUG')) error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

function isWindows(): bool { return stripos(PHP_OS, 'WIN') === 0; }

// Crash-safe / concurrent-safe file write: write to a temp file in the same
// directory, then rename() over the target — atomic on POSIX, so a crash or a
// second writer mid-write can never leave a half-written (corrupt) session,
// config, or crew-board file. Falls back to a direct write if the temp path
// isn't usable. Returns true on success.
function atomicWrite(string $path, string $content): bool {
    $dir = dirname($path);
    if ($dir !== '' && !is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $path . '.tmp.' . getmypid() . '.' . substr(bin2hex(random_bytes(3)), 0, 6);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return @file_put_contents($path, $content) !== false;   // temp not writable — best effort
    }
    if (@rename($tmp, $path)) return true;
    @unlink($tmp);
    return @file_put_contents($path, $content) !== false;
}

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
        // SKIP_DOTS + CATCH_GET_CHILD: a permission-denied subdirectory is SKIPPED
        // instead of aborting the whole search. RecursiveDirectoryIterator does not
        // follow symlinked directories by default, so there's no symlink-loop risk.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
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
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD   // skip unreadable dirs instead of aborting
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

