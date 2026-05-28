Tools::register('view', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return CmdError::missingParam('file_path', 'view');
    if (!file_exists($path)) return CmdError::fileNotFound($path, 'view');
    $lines = file($path);
    if ($lines === false) return CmdError::invalidArg($path, 'Unable to read file. Check permissions.', 'view');
    $offset = isset($p['offset']) ? (int)$p['offset'] : 0;
    $limit = isset($p['limit']) ? (int)$p['limit'] : count($lines);
    $out = '';
    for ($i = $offset; $i < min($offset + $limit, count($lines)); $i++) $out .= sprintf("%4d  %s", $i + 1, $lines[$i]);
    return $out;
});

Tools::register('read', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

// Aliases for common tools
Tools::register('read', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

Tools::register('cat', function($p) {
    $p['file_path'] = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    return Tools::run('view', $p);
});

Tools::register('head', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $n = $p['n'] ?? 10;
    $out = '';
    for ($i = 0; $i < min($n, count($lines)); $i++) $out .= $lines[$i];
    return $out;
});

Tools::register('tail', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Error reading file: $path";
    $n = $p['n'] ?? 10;
    $start = max(0, count($lines) - $n);
    $out = '';
    for ($i = $start; $i < count($lines); $i++) $out .= $lines[$i];
    return $out;
});

Tools::register('changes', function($p) {
    $path = $p['path'] ?? '.';
    $since = $p['since'] ?? '1 hour ago';
    $sinceTime = strtotime($since);
    $changes = [];
    exec("find " . escapeshellarg($path) . " -type f -newermt '" . date('Y-m-d H:i:s', $sinceTime) . "' 2>/dev/null", $files);
    foreach ($files as $f) {
        if (strpos($f, '.git') !== false || strpos($f, 'node_modules') !== false) continue;
        $status = trim(shell_exec("cd " . escapeshellarg(dirname($f)) . " && git status --porcelain " . escapeshellarg(basename($f)) . " 2>/dev/null") ?: '??');
        $changes[] = [$status, $f];
    }
    if (empty($changes)) return "No changes since $since";
    $out = "\033[1;34mChanges since $since:\033[0m\n";
    foreach ($changes as [$status, $f]) {
        $s1 = $status[0] ?? ' ';
        $s2 = $status[1] ?? ' ';
        $color = match(true) {
            $s1 === 'M' => "\033[31m",  // red - modified
            $s1 === 'A' => "\033[32m",  // green - added
            $s1 === 'D' => "\033[33m",  // yellow - deleted
            $s1 === 'R' => "\033[36m",  // cyan - renamed
            $s1 === '?' => "\033[90m",  // gray - untracked
            default => "\033[0m"
        };
        $typeIcon = is_dir($f) ? "\033[1;36m[dir]\033[0m" : "\033[1;37m[file]\033[0m";
        $out .= "$color$status\033[0m $typeIcon $f\n";
    }
    return $out;
});

Tools::register('watch', function($p) {
    $path = $p['path'] ?? '.';
    $exts = $p['extensions'] ?? 'php,js,ts,py,go,rs,html,css,json,yaml';
    $timeout = (int)($p['timeout'] ?? 30);
    $extensions = str_replace(',', '|', $exts);
    $extensions = str_replace('.', '\\.', $extensions);

    // Polling-based file watching
    $lastMtime = [];
    $files = [];
    exec("find " . escapeshellarg($path) . " -type f 2>/dev/null", $files);
    foreach ($files as $f) {
        if (preg_match('/\.(' . $extensions . ')$/i', $f)) {
            $lastMtime[$f] = filemtime($f);
        }
    }

    sleep(min($timeout, 60));
    clearstatcache();
    $changed = [];
    exec("find " . escapeshellarg($path) . " -type f 2>/dev/null", $files);
    foreach ($files as $f) {
        if (preg_match('/\.(' . $extensions . ')$/i', $f)) {
            $mtime = filemtime($f);
            if (!isset($lastMtime[$f]) || $lastMtime[$f] < $mtime) {
                $changed[] = $f;
            }
        }
    }

    if (empty($changed)) return "No file changes detected within ${timeout}s";
    return "Changed:\n" . implode("\n", array_slice($changed, 0, 50));
});

Tools::register('write', function($p) {
    $path = $p['file_path'] ?? ''; $content = $p['content'] ?? '';
    if (empty($path)) return "missing file_path";
    if ($content === '') return "missing content";
    $dir = dirname($path);
    if (!empty($dir) && !is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, $content) !== false ? "FILE_WRITE:$path" : "Error writing file: $path";
});

Tools::register('edit', function($p) {
    $path = $p['file_path'] ?? ''; $oldStr = $p['old_string'] ?? ''; $newStr = $p['new_string'] ?? '';
    if (empty($path)) return "missing file_path";
    if (empty($oldStr)) return "missing old_string";
    $content = file_get_contents($path);
    if ($content === false) return "Error reading file: $path";
    $pos = strpos($content, $oldStr);
    if ($pos === false) return "old_string not found in file";
    return file_put_contents($path, substr_replace($content, $newStr, $pos, strlen($oldStr))) !== false ? "FILE_EDIT:$path" : "Error writing file: $path";
});

Tools::register('glob', function($p) {
    $pattern = $p['pattern'] ?? '';
    if (empty($pattern) && isset($p[0])) $pattern = $p[0];
    if (empty($pattern)) return "missing pattern";
    $basePath = $p['path'] ?? $p['file_path'] ?? '.';
    if (strpos($pattern, '*') === false) $pattern = '**/*' . $pattern;
    $files = glob(rtrim($basePath, '/') . '/' . $pattern, GLOB_BRACE);
    return empty($files) ? "No files found" : implode("\n", $files);
});

Tools::register('grep', function($p) {
    $pattern = $p['pattern'] ?? '';
    if (empty($pattern)) return "missing pattern";
    $path = $p['path'] ?? '.';
    $include = $p['include'] ?? '';
    $cmd = "grep -rn --color=never " . escapeshellarg($pattern) . " " . escapeshellarg($path);
    if (!empty($include)) $cmd .= " --include=" . escapeshellarg($include);
    return shell_exec($cmd . ' 2>&1') ?: "No matches found";
});

Tools::register('ls', function($p) {
    $path = $p['path'] ?? '.';
    return crossPlatformLs($path);
});
Tools::register('list_directory', function($p) {
    $path = $p['path'] ?? '.';
    return crossPlatformLs($path);
});
Tools::register('list_files', function($p) {
    $path = $p['path'] ?? '.';
    return crossPlatformLs($path);
});
Tools::register('execute_command', function($p) {
    $cmd = $p['command'] ?? '';
    if (empty($cmd)) return "missing command";
    return shell_exec($cmd . " 2>&1") ?: "Command failed";
});

Tools::register('pwd', function($p) {
    return getcwd();
});

Tools::register('cd', function($p) {
    $path = $p['path'] ?? $p['dir'] ?? '';
    if (empty($path)) return "missing path";
    if (!is_dir($path)) return "Not a directory: $path";
    if (!chdir($path)) return "Failed to change directory: $path";
    return "Changed to: " . getcwd();
});

Tools::register('find', function($p) {
    $path = $p['path'] ?? '.';
    $name = $p['name'] ?? '*';
    $type = $p['type'] ?? '';
    return crossPlatformFind($path, $name);
});

Tools::register('tree', function($p) {
    $path = $p['path'] ?? '.';
    $depth = isset($p['depth']) ? (int)$p['depth'] : 2;
    return crossPlatformTree($path, $depth);
});

Tools::register('stat', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing path";
    if (!file_exists($path)) return "Not found: $path";
    $stat = stat($path);
    $type = is_dir($path) ? 'directory' : 'file';
    return sprintf("%s (%s)\nSize: %d bytes\nModified: %s\nPermissions: %o", $path, $type, $stat['size'], date('Y-m-d H:i:s', $stat['mtime']), $stat['mode'] & 0777);
});

Tools::register('diff', function($p) {
    $file1 = $p['file1'] ?? $p['file_path'] ?? $p['file'] ?? $p['source'] ?? '';
    $file2 = $p['file2'] ?? $p['dest'] ?? '';
    if (empty($file1) || empty($file2)) return "missing file1 or file2";
    if (!file_exists($file1) || !file_exists($file2)) return "File not found";
    $isGitDir = is_dir(dirname($file1) . '/.git') || is_dir($file1);
    if ($isGitDir || preg_match('/\.git[\/\\\]?$/', dirname($file1))) {
        $output = shell_exec("cd " . escapeshellarg(dirname($file1)) . " && git diff " . escapeshellarg(basename($file1)) . " 2>&1") ?: "No differences";
    } else {
        $output = shell_exec("diff -u " . escapeshellarg($file1) . " " . escapeshellarg($file2) . " 2>&1") ?: "No differences";
    }
    if (empty(trim($output))) return "No differences";
    $tmp = tempnam('/tmp', 'ollamadev_diff_') . '.txt';
    file_put_contents($tmp, $output);
    passthru("less -R " . escapeshellarg($tmp) . " 2>/dev/null");
    @unlink($tmp);
    return '';
});

Tools::register('wc', function($p) {
    $path = $p['file_path'] ?? $p['file'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = count(file($path));
    $chars = strlen(file_get_contents($path));
    $words = str_word_count(file_get_contents($path));
    return sprintf("%d lines, %d words, %d chars: %s", $lines, $words, $chars, $path);
});

Tools::register('sort', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Failed to read: $path";
    sort($lines);
    return implode('', $lines);
});

Tools::register('uniq', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "missing file_path";
    if (!file_exists($path)) return "File not found: $path";
    $lines = file($path);
    if ($lines === false) return "Failed to read: $path";
    return implode('', array_unique($lines));
});

Tools::register('mkdir', function($p) {
    $path = $p['path'] ?? $p['dir'] ?? '';
    $parents = $p['parents'] ?? false;
    if (empty($path)) return "missing path";
    if (is_dir($path)) return "Already exists: $path";
    if ($parents) {
        if (mkdir($path, 0755, true)) return "Created: $path";
    } else {
        if (mkdir($path, 0755)) return "Created: $path";
    }
    return "Failed to create: $path";
});

Tools::register('touch', function($p) {
    $path = $p['path'] ?? $p['file_path'] ?? '';
    if (empty($path)) return "missing path";
    if (file_exists($path)) {
        touch($path);
        return "Updated timestamp: $path";
    }
    if (touch($path)) return "Created: $path";
    return "Failed to create: $path";
});

Tools::register('cp', function($p) {
    $src = $p['src'] ?? $p['source'] ?? $p['target'] ?? '';
    $dst = $p['dst'] ?? $p['dest'] ?? $p['destination'] ?? $p['target'] ?? '';
    if (empty($src) || empty($dst)) return "missing src or dst";
    $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
    $src = $src === '~' ? $home : (str_starts_with($src, '~/') ? $home . substr($src, 1) : (isWindows() && str_starts_with($src, '~\\') ? $home . substr($src, 1) : $src));
    $dst = $dst === '~' ? $home : (str_starts_with($dst, '~/') ? $home . substr($dst, 1) : (isWindows() && str_starts_with($dst, '~\\') ? $home . substr($dst, 1) : $dst));
    if (!file_exists($src)) return "Source not found: $src";
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iter as $file) {
            $dstPath = $dst . '/' . $iter->getSubPathName();
            if ($file->isDir()) mkdir($dstPath, 0755, true);
            else copy($file->getPathname(), $dstPath);
        }
        return "Copied directory to $dst";
    }
    $dstDir = dirname($dst);
    if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
    return copy($src, $dst) ? "Copied to $dst" : "Copy failed";
});

Tools::register('rm', function($p) {
    $path = $p['path'] ?? $p['file_path'] ?? '';
    $recursive = $p['recursive'] ?? $p['r'] ?? false;
    $dryRun = $p['dry_run'] ?? $p['dry-run'] ?? false;
    if (empty($path)) return CmdError::missingParam('path', 'rm');
    if (str_contains($path, 'node_modules') || str_contains($path, '.git')) return CmdError::invalidArg('path', 'Cannot remove system directories (node_modules, .git)', 'rm');
    $cmd = ($recursive ? 'rm -rf' : 'rm') . ' ' . escapeshellarg($path);
    if ($dryRun) {
        return "[DRY RUN] Would execute: $cmd\n[DRY RUN] Target: $path\n[DRY RUN] Recursive: " . ($recursive ? 'yes' : 'no');
    }
    $result = shell_exec("$cmd 2>&1") ?: "Removed: $path";
    return $result;
});

Tools::register('mv', function($p) {
    $src = $p['src'] ?? $p['source'] ?? '';
    $dst = $p['dst'] ?? $p['dest'] ?? $p['destination'] ?? '';
    if (empty($src) || empty($dst)) return "missing src or dst";
    return shell_exec("mv " . escapeshellarg($src) . " " . escapeshellarg($dst) . " 2>&1") ?: "Moved to $dst";
});

Tools::register('editor', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "Usage: editor file_path=<path>";
    if (!file_exists($path)) return "File not found: $path";
    $editor = getenv('EDITOR') ?: (getenv('VISUAL') ?: 'nano');
    $cmd = "$editor " . escapeshellarg($path) . " 2>&1";
    exec($cmd, $out, $code);
    return $code === 0 ? "Edited $path" : "Editor error: " . implode("\n", $out);
});

Tools::register('bash', function($p) {
    $cmd = '';
    if (is_array($p)) {
        if (isset($p['command']) && is_string($p['command'])) $cmd = $p['command'];
        elseif (isset($p['cmd']) && is_string($p['cmd'])) $cmd = $p['cmd'];
        elseif (isset($p[0]) && is_string($p[0])) {
            if (count($p) >= 2 && $p[0] === 'echo' && is_string($p[1])) $cmd = 'echo ' . $p[1];
            else $cmd = $p[0];
        }
    } else { $cmd = (string)$p; }
    $cmd = trim($cmd, '"\'');
    if (empty($cmd)) return "missing command";
    $cmd = preg_replace('/^command=/', '', $cmd);
    $cmd = preg_replace('/^cmd=/', '', $cmd);
    $cmd = preg_replace('/"\s*$/', '', $cmd);
    $readonly = ['ls', 'pwd', 'cat', 'head', 'tail', 'grep', 'find', 'git', 'echo', 'wc', 'sort', 'uniq', 'awk', 'sed', 'cut', 'tr', 'file', 'stat', 'diff', 'tree'];
    $first = strtok($cmd, ' ');
    if (!in_array($first, $readonly)) return "Command not allowed (readonly only): $first";
    foreach (['curl', 'wget', 'chmod', 'sudo', 'rm -rf', 'mkfs'] as $b) { if (str_contains($cmd, $b)) return "Dangerous command blocked: $b"; }
    if (preg_match('/echo\s+\$?\(\s*\([^)]+\)\s*\)/', $cmd)) {
        if (preg_match_all('/(\d+)\s*([+\-*\/])\s*(\d+)/', $cmd, $m, PREG_SET_ORDER)) {
            if ($m && isset($m[0][0])) { $result = eval('return ' . str_replace(' ', '', $m[0][0]) . ';'); return (string)$result; }
        }
    }
    return shell_exec($cmd . ' 2>&1') ?: "(no output)";
});

Tools::register('calc', function($p) {
    $expr = '';
    if (is_array($p)) {
        $expr = $p['expr'] ?? $p['expression'] ?? $p['calculation'] ?? $p['equation'] ?? $p['value'] ?? ($p[0] ?? '');
    } else { $expr = (string)$p; }
    if (empty($expr)) return "missing expression";
    $expr = preg_replace('/[^0-9+\-*\/(). ]/', '', $expr);
    $expr = str_replace(' ', '', $expr);
    if (strpos($expr, 'scale=') !== false) $expr = preg_replace('/scale=\d+;/', '', $expr);
    try { return (string)@eval("return $expr;"); } catch (Exception $e) { return "Invalid expression"; }
});

Tools::register('print', function($p) {
    return $p['text'] ?? $p['message'] ?? $p['content'] ?? ($p[0] ?? '');
});

Tools::register('say', function($p) {
    $text = is_array($p) ? ($p['text'] ?? $p['message'] ?? $p['content'] ?? $p['t'] ?? $p['s'] ?? ($p[0] ?? '')) : (string)$p;
    return (string)$text;
});

Tools::register('echo', function($p) {
    $text = is_array($p) ? ($p['text'] ?? $p['message'] ?? $p['content'] ?? $p['s'] ?? $p['t'] ?? $p[0] ?? '') : (string)$p;
    return (string)$text;
});

Tools::register('reply', function($p) {
    return $p['text'] ?? $p['message'] ?? $p['content'] ?? ($p[0] ?? '');
});

Tools::register('ok', function($p) {
    return 'ok';
});

Tools::register('OK', function($p) {
    return 'ok';
});

Tools::register('error', function($p) {
    return $p['message'] ?? $p['text'] ?? ($p[0] ?? 'error');
});

Tools::register('git', function($p) {
    $cmd = $p['cmd'] ?? $p['command'] ?? '';
    if (empty($cmd)) return "missing cmd";
    return shell_exec("git $cmd 2>&1") ?: "(no output)";
});

Tools::register('bg', function($p) {
    $cmd = $p['command'] ?? $p['cmd'] ?? '';
    if (empty($cmd)) return "missing command";
    $background = $p['background'] ?? false;
    if ($background || str_ends_with(trim($cmd), '&')) {
        $cmd = trim($cmd, ' &');
        $cmd .= ' > /tmp/ollamadev_bg_' . substr(md5(mt_rand()), 0, 6) . '.log 2>&1 &';
        shell_exec($cmd);
        return "Started in background (PID: " . getmypid() . ")";
    }
    return shell_exec($cmd . ' 2>&1') ?: "(no output)";
});

Tools::register('wait_bg', function($p) {
    $maxWait = $p['seconds'] ?? 60;
    $start = time();
    while (time() - $start < $maxWait) {
        usleep(100000);
    }
    return "Waited $maxWait seconds";
});

Tools::register('fetch', function($p) {
    $url = $p['url'] ?? '';
    if (empty($url)) return "missing url";
    $timeout = (int)($p['timeout'] ?? 30);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return "Failed to fetch $url: $error";
    }
    curl_close($ch);
    return $result ?: "Empty response from $url";
});

Tools::register('diagnostics', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $diags = LSP::diagnostics($path);
    if (empty($diags)) return "No diagnostics";
    $out = '';
    foreach ($diags as $d) {
        $out .= "Line {$d['line']}: [{$d['severity']}] {$d['message']}\n";
    }
    return $out;
});

Tools::register('hover', function($p) {
    $path = $p['file_path'] ?? '';
    $line = isset($p['line']) ? (int)$p['line'] : 1;
    $col = isset($p['col']) ? (int)$p['col'] : 1;
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    return LSP::hover($path, $line, $col) ?? "No hover info at position";
});

Tools::register('goto', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    $line = isset($p['line']) ? (int)$p['line'] : 1;
    $col = isset($p['col']) ? (int)$p['col'] : 1;
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $result = LSP::gotoDefinition($path, $line, $col);
    if ($result) {
        return "Found at: {$result['file']}:{$result['line']}";
    }
    return "No definition found";
});

Tools::register('symbols', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $symbols = LSP::documentSymbols($path);
    if (empty($symbols)) return "No symbols found";
    $out = '';
    foreach ($symbols as $s) {
        $out .= "[{$s['kind']}] {$s['name']} (line {$s['line']})\n";
    }
    return $out;
});

Tools::register('find_refs', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    $pattern = $p['pattern'] ?? $p['symbol'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    if (empty($pattern)) return "No pattern specified";
    return shell_exec("grep -rn --color=never " . escapeshellarg($pattern) . " " . escapeshellarg(dirname($path)) . " 2>/dev/null | head -20") ?: "No references found";
});

Tools::register('refs', function($p) {
    $path = $p['file_path'] ?? $p['path'] ?? '';
    $symbol = $p['symbol'] ?? $p['pattern'] ?? '';
    return Tools::run('find_refs', ['file_path' => $path, 'pattern' => $symbol]);
});

Tools::register('goto_definition', function($p) {
    return Tools::run('goto', $p);
});

Tools::register('definition', function($p) {
    return Tools::run('goto', $p);
});

Tools::register('format', function($p) {
    $path = $p['file_path'] ?? '';
    if (empty($path)) return "No file specified";
    if (!file_exists($path)) return "File not found: $path";
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $formatted = false;

    if ($ext === 'php') {
        $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
        if (strpos($output, 'No syntax errors') !== false) {
            return "PHP syntax OK - use phpcbf for auto-formatting";
        }
        return $output;
    } elseif ($ext === 'js' || $ext === 'ts') {
        $output = shell_exec("npx prettier --check " . escapeshellarg($path) . " 2>&1");
        return $output ?: "Formatted";
    } elseif ($ext === 'py') {
        $output = shell_exec("python -m black --check " . escapeshellarg($path) . " 2>&1");
        return $output ?: "Formatted";
    }
    return "No formatter available for $ext";
});

Tools::register('lsp', function($p) {
    $action = $p['action'] ?? '';
    $path = $p['file_path'] ?? '';
    $line = isset($p['line']) ? (int)$p['line'] : 1;
    $col = isset($p['col']) ? (int)$p['col'] : 1;

    return match($action) {
        'diag' => Tools::run('diagnostics', $p),
        'hover' => Tools::run('hover', $p),
        'goto' => Tools::run('goto', $p),
        'symbols' => Tools::run('symbols', $p),
        'refs' => Tools::run('find_refs', $p),
        default => "Usage: lsp action=(diag|hover|goto|symbols|refs) file_path=<path> [line=<n>] [col=<n>]"
    };
});

Tools::register('mcp', function($p) {
    $server = $p['server'] ?? '';
    $tool = $p['tool'] ?? '';
    $name = $server . '/' . $tool;
    if (empty($server) || empty($tool)) return "missing server or tool";
    $args = array_diff_key($p, ['server' => '', 'tool' => '']);
    return MCP::call($name, $args);
});

Tools::register('mcp_servers', function($p) {
    $servers = MCP::listTools();
    if (empty($servers)) return "No MCP servers configured";
    return "Available MCP tools:\n" . implode("\n", array_map(fn($s) => "  - $s", $servers));
});

Tools::register('permission', function($p) {
    $action = $p['action'] ?? '';
    $tool = $p['tool'] ?? '';
    if ($action === 'allow') {
        Permission::allow($tool);
        return "Allowed: $tool";
    } elseif ($action === 'deny') {
        Permission::deny($tool);
        return "Denied: $tool";
    } elseif ($action === 'list') {
        $allowed = Permission::listAllowed();
        return empty($allowed) ? "No permissions set" : "Allowed: " . implode(', ', $allowed);
    }
    return "Usage: permission action=(allow|deny|list) tool=<name>";
});

Tools::register('summarize', function($p) {
    $msgs = $p['messages'] ?? [];
    $context = $p['context'] ?? '';
    if (empty($msgs)) return "No messages to summarize";
    $text = implode("\n", array_map(fn($m) => $m['role'] . ': ' . substr($m['content'], 0, 200), $msgs));
    return "Summary placeholder - configure MCP summarizer for full functionality";
});

Tools::register('patch', function($p) {
    $path = $p['file_path'] ?? '';
    $diff = $p['diff'] ?? '';
    if (empty($path)) return "missing file_path";
    if (empty($diff)) return "missing diff";
    $tmpFile = tempnam(sys_get_temp_dir(), 'patch_');
    file_put_contents($tmpFile, $diff);
    $output = shell_exec("patch -p1 -i " . escapeshellarg($tmpFile) . " 2>&1");
    unlink($tmpFile);
    return $output ?: "Patched $path";
});

Tools::register('agent', function($p) {
    $prompt = $p['prompt'] ?? $p['task'] ?? '';
    $context = $p['context'] ?? '';
    $maxIterations = (int)($p['max_iterations'] ?? 5);
    $model = $GLOBALS['currentSessionModel'] ?? Config::get('ollama.defaultModel', 'llama3.2:latest');

    $blocked = ['mistral', 'smollm', 'starcoder', 'qwen', 'phi', 'firefunction', 'tinyllama', 'phi-2', 'llava', 'nanollm', 'mixtral', 'codellama', 'code-llama'];
    foreach ($blocked as $b) { if (stripos($model, $b) !== false) return "Model $model does not support tool calling. Try: gpt-oss, llama3.2, codestral, or deepseek-r1."; }

    if (empty($prompt)) return "missing prompt (need 'prompt' or 'task' parameter)";

    $subAgent = new Agent();
    $subAgent->setModel($model);
    $isGptOss = str_contains($model, 'gpt-oss');

    $systemPrompt = $subAgent->buildSystemPrompt();
    if (!empty($context)) {
        $systemPrompt['content'] .= "\n\nCONTEXT: $context";
    }
    
    $messages = [['role' => 'system', 'content' => $systemPrompt['content']], ['role' => 'user', 'content' => "Task: $prompt\n\nWork through this step by step. Use tools as needed and report results."]];
    
    $output = [];
    $iteration = 0;
    
    while ($iteration < $maxIterations) {
        $iteration++;
        $response = $subAgent->run($messages);
        $output[] = "=== Iteration $iteration ===\n$response\n";

        $toolCalls = $subAgent->parseToolCalls($response);
        if ($isGptOss && empty($toolCalls)) {
            $toolCalls = $subAgent->parseGptOssToolCalls($response);
        }
        if (empty($toolCalls) && (str_contains($model, 'command') || str_contains($model, 'gemma'))) {
            $toolCalls = $subAgent->parseGptOssToolCalls($response);
        }
        $messages[] = ['role' => 'assistant', 'content' => $response];

        if (empty($toolCalls)) {
            $lastMsg = end($messages);
            if (strpos(strtolower($lastMsg['content']), 'task complete') !== false || 
                strpos(strtolower($response), 'task complete') !== false ||
                strpos(strtolower($response), 'done') !== false) {
                break;
            }
            $messages[] = ['role' => 'user', 'content' => 'Continue or finish? If done, say "TASK_COMPLETE".'];
            continue;
        }
        
        foreach ($toolCalls as $call) {
            $toolName = $call['name'];
            $params = $call['params'] ?? [];
            $tool = Tools::find($toolName);
            
            if (!$tool) {
                $result = "Error: tool '$toolName' not found";
            } else {
                $result = $tool($params);
            }
            
            $messages[] = ['role' => 'tool', 'content' => "[$toolName] result: $result"];
            $output[] = "[$toolName] → " . substr($result, 0, 200) . (strlen($result) > 200 ? '...' : '') . "\n";
        }
    }
    
    return implode("\n", $output);
});

Tools::register('git_status', function($p) {
    $path = $p['path'] ?? '.';
    return shell_exec("cd " . escapeshellarg($path) . " && git status --short 2>&1") ?: "Not a git repo";
});

Tools::register('git_diff', function($p) {
    $path = $p['path'] ?? '.';
    $file = $p['file'] ?? '';
    $cached = $p['cached'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git diff";
    if ($cached) $cmd .= " --cached";
    if (!empty($file)) $cmd .= " -- " . escapeshellarg($file);
    return shell_exec($cmd . " 2>&1") ?: "No changes";
});

Tools::register('git_log', function($p) {
    $path = $p['path'] ?? '.';
    $n = $p['n'] ?? 10;
    return shell_exec("cd " . escapeshellarg($path) . " && git log --oneline -n $n 2>&1") ?: "Not a git repo";
});

Tools::register('git_branch', function($p) {
    $path = $p['path'] ?? '.';
    $all = $p['all'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git branch";
    if ($all) $cmd .= " -a";
    return shell_exec($cmd . " 2>&1") ?: "Not a git repo";
});

Tools::register('git_checkout', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    $new = $p['new'] ?? false;
    if (empty($branch)) return "missing branch";
    $cmd = "cd " . escapeshellarg($path) . " && git checkout";
    if ($new) $cmd .= " -b";
    $cmd .= " " . escapeshellarg($branch);
    return shell_exec($cmd . " 2>&1") ?: "Checkout failed";
});

Tools::register('git_commit', function($p) {
    $path = $p['path'] ?? '.';
    $msg = $p['message'] ?? $p['m'] ?? '';
    $all = $p['all'] ?? false;
    $amend = $p['amend'] ?? false;
    if (empty($msg)) return "missing commit message";
    $cmd = "cd " . escapeshellarg($path) . " && git commit";
    if ($all) $cmd .= " -a";
    if ($amend) $cmd .= " --amend";
    $cmd .= " -m " . escapeshellarg($msg);
    return shell_exec($cmd . " 2>&1") ?: "Commit failed";
});

Tools::register('git_add', function($p) {
    $path = $p['path'] ?? '.';
    $files = $p['files'] ?? '.';
    $all = $p['all'] ?? false;
    if ($all) {
        return shell_exec("cd " . escapeshellarg($path) . " && git add -A 2>&1") ?: "Added all";
    }
    return shell_exec("cd " . escapeshellarg($path) . " && git add " . escapeshellarg($files) . " 2>&1") ?: "Added $files";
});

Tools::register('git_merge', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    if (empty($branch)) return "missing branch";
    return shell_exec("cd " . escapeshellarg($path) . " && git merge " . escapeshellarg($branch) . " 2>&1") ?: "Merge failed";
});

Tools::register('git_rebase', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    $onto = $p['onto'] ?? '';
    if (empty($branch)) return "missing branch";
    $cmd = "cd " . escapeshellarg($path) . " && git rebase";
    if (!empty($onto)) $cmd .= " --onto " . escapeshellarg($onto);
    $cmd .= " " . escapeshellarg($branch);
    return shell_exec($cmd . " 2>&1") ?: "Rebase failed";
});

Tools::register('git_stash', function($p) {
    $path = $p['path'] ?? '.';
    $pop = $p['pop'] ?? false;
    $list = $p['list'] ?? false;
    $drop = $p['drop'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git stash";
    if ($list) return shell_exec($cmd . " list 2>&1") ?: "No stashes";
    if ($pop) return shell_exec($cmd . " pop 2>&1") ?: "Stash pop failed";
    if ($drop) return shell_exec($cmd . " drop 2>&1") ?: "Stash dropped";
    return shell_exec($cmd . " 2>&1") ?: "Stashed";
});

Tools::register('git_push', function($p) {
    $path = $p['path'] ?? '.';
    $force = $p['force'] ?? false;
    $upstream = $p['upstream'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git push";
    if ($force) $cmd .= " --force";
    if ($upstream) $cmd .= " -u";
    return shell_exec($cmd . " 2>&1") ?: "Push failed";
});

Tools::register('git_pull', function($p) {
    $path = $p['path'] ?? '.';
    $rebase = $p['rebase'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git pull";
    if ($rebase) $cmd .= " --rebase";
    return shell_exec($cmd . " 2>&1") ?: "Pull failed";
});

Tools::register('git_clone', function($p) {
    $url = $p['url'] ?? '';
    $path = $p['path'] ?? '.';
    if (empty($url)) return "missing url";
    return shell_exec("git clone " . escapeshellarg($url) . " " . escapeshellarg($path) . " 2>&1") ?: "Clone failed";
});

Tools::register('git_remote', function($p) {
    $path = $p['path'] ?? '.';
    $cmd = "cd " . escapeshellarg($path) . " && git remote -v 2>&1";
    return shell_exec($cmd) ?: "Not a git repo";
});

Tools::register('git_fetch', function($p) {
    $path = $p['path'] ?? '.';
    $all = $p['all'] ?? false;
    $prune = $p['prune'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git fetch";
    if ($all) $cmd .= " --all";
    if ($prune) $cmd .= " --prune";
    return shell_exec($cmd . " 2>&1") ?: "Fetch failed";
});

Tools::register('git_show', function($p) {
    $path = $p['path'] ?? '.';
    $ref = $p['ref'] ?? 'HEAD';
    $stat = $p['stat'] ?? false;
    $cmd = "cd " . escapeshellarg($path) . " && git show";
    if ($stat) $cmd .= " --stat";
    $cmd .= " " . escapeshellarg($ref);
    return shell_exec($cmd . " 2>&1") ?: "Show failed";
});

Tools::register('update', function($p) {
    $install = $p['install'] ?? false;
    $current = '3.9.5';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $json = @file_get_contents('https://api.github.com/repos/kennethyork/OllamaDev/releases/latest', false, $ctx);
    if (!$json) return "Error: Could not check for updates. Check your internet connection.";
    $data = json_decode($json, true);
    $tag = $data['tag_name'] ?? '';
    if (!$tag) return "Error: Could not parse release info.";
    if (version_compare($tag, $current, '<=')) {
        return "You're up to date (v$current)";
    }
    echo "Update available: $tag (current: $current)\n\n";
    $assets = $data['assets'] ?? [];
    $binary = null;
    foreach ($assets as $a) {
        if ($a['name'] === 'ollamadev') $binary = $a;
    }
    if (!$binary && count($assets) > 0) $binary = $assets[0];
    if ($binary) {
        echo "Download: {$binary['browser_download_url']}\n";
        echo "\nTo install:\n";
        if ($install) {
            $tmp = sys_get_temp_dir() . '/ollamadev_new';
            $url = $binary['browser_download_url'];
            echo "Downloading...\n";
            $downloaded = @file_put_contents($tmp, fopen($url, 'rb', false, $ctx));
            if ($downloaded) {
                chmod($tmp, 0755);
                $binPath = Config::binaryPath();
                rename($tmp, $binPath);
                echo "Updated to $tag. Restart to use new version.\n";
            } else {
                echo "Download failed. Try manually: curl -fsSL {$binary['browser_download_url']} -o /usr/local/bin/ollamadev\n";
            }
        } else {
            echo "  curl -fsSL {$binary['browser_download_url']} -o /usr/local/bin/ollamadev\n";
            echo "\nOr run: ollamadev update --install to auto-download\n";
        }
    }
    return "Run 'ollamadev update' to check again.";
});

Tools::register('git_cherry_pick', function($p) {
    $path = $p['path'] ?? '.';
    $ref = $p['ref'] ?? '';
    $no_commit = $p['no_commit'] ?? false;
    if (empty($ref)) return "missing ref (commit hash or branch)";
    $cmd = "cd " . escapeshellarg($path) . " && git cherry-pick";
    if ($no_commit) $cmd .= " --no-commit";
    $cmd .= " " . escapeshellarg($ref);
    return shell_exec($cmd . " 2>&1") ?: "Cherry-pick failed";
});

Tools::register('git_revert', function($p) {
    $path = $p['path'] ?? '.';
    $ref = $p['ref'] ?? '';
    $no_commit = $p['no_commit'] ?? false;
    if (empty($ref)) return "missing ref (commit hash or branch)";
    $cmd = "cd " . escapeshellarg($path) . " && git revert";
    if ($no_commit) $cmd .= " --no-commit";
    $cmd .= " " . escapeshellarg($ref);
    return shell_exec($cmd . " 2>&1") ?: "Revert failed";
});

Tools::register('git_merge', function($p) {
    $path = $p['path'] ?? '.';
    $branch = $p['branch'] ?? '';
    $no_ff = $p['no_ff'] ?? false;
    $squash = $p['squash'] ?? false;
    if (empty($branch)) return "missing branch";
    $cmd = "cd " . escapeshellarg($path) . " && git merge";
    if ($no_ff) $cmd .= " --no-ff";
    if ($squash) $cmd .= " --squash";
    $cmd .= " " . escapeshellarg($branch);
    return shell_exec($cmd . " 2>&1") ?: "Merge failed";
});

