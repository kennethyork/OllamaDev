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

    if (empty($changed)) return "No file changes detected within {$timeout}s";
    return "Changed:\n" . implode("\n", array_slice($changed, 0, 50));
});

Tools::register('write', function($p) {
    $path = $p['file_path'] ?? ''; $content = $p['content'] ?? '';
    if (empty($path)) return "missing file_path";
    if ($content === '') return "missing content";
    $content = unfence($content);   // strip a stray ```lang … ``` wrapper the model may add
    $old = file_exists($path) ? (file_get_contents($path) ?: '') : '';
    if (!DiffView::confirm($path, $old, $content)) return "Write to $path cancelled by user";
    $dir = dirname($path);
    if (!empty($dir) && !is_dir($dir)) mkdir($dir, 0755, true);
    Checkpoints::save($path);
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
    $newContent = substr_replace($content, $newStr, $pos, strlen($oldStr));
    if (!DiffView::confirm($path, $content, $newContent)) return "Edit of $path cancelled by user";
    Checkpoints::save($path);
    return file_put_contents($path, $newContent) !== false ? "FILE_EDIT:$path" : "Error writing file: $path";
});

// MultiEdit (Claude Code parity): apply several edits to ONE file atomically —
// all edits must apply or none do, then a single diff preview + write.
Tools::register('multi_edit', function($p) {
    $path = $p['file_path'] ?? '';
    $edits = $p['edits'] ?? [];
    if (empty($path)) return "missing file_path";
    if (!is_array($edits) || empty($edits)) return "missing edits (array of {old_string, new_string, [replace_all]})";
    $content = file_get_contents($path);
    if ($content === false) return "Error reading file: $path";
    $orig = $content; $applied = 0;
    foreach (array_values($edits) as $i => $e) {
        if (!is_array($e)) return "edit #" . ($i + 1) . ": not an object (no edits applied)";
        $old = (string)($e['old_string'] ?? ''); $new = (string)($e['new_string'] ?? '');
        if ($old === '') return "edit #" . ($i + 1) . ": missing old_string (no edits applied)";
        if (strpos($content, $old) === false) return "edit #" . ($i + 1) . ": old_string not found (no edits applied)";
        if (!empty($e['replace_all'])) { $content = str_replace($old, $new, $content, $cnt); $applied += $cnt; }
        else { $pos = strpos($content, $old); $content = substr_replace($content, $new, $pos, strlen($old)); $applied++; }
    }
    if (!DiffView::confirm($path, $orig, $content)) return "Multi-edit of $path cancelled by user";
    Checkpoints::save($path);
    return file_put_contents($path, $content) !== false ? "FILE_EDIT:$path" : "Error writing file: $path";
});

// TodoWrite (Claude Code parity): maintain a structured todo list so the agent
// can plan and track multi-step work. Replaces the whole list each call.
// Clear the live crew kanban board ONLY on an explicit user request. Shared engine
// path with `crew clear` + the desktop. Guarded twice: requires confirm=true (so the
// model can't fire it as incidental cleanup) and Crew::clearBoard refuses mid-run.
Tools::register('clear_board', function($p) {
    $confirm = filter_var($p['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$confirm) return "Board NOT cleared. clear_board only runs when the user explicitly asked to clear/dismiss the board — pass confirm=true only then. Do not call this on your own initiative.";
    $r = Crew::clearBoard();
    return !empty($r['ok']) ? "Crew board cleared (crew cards, ideas, and manual cards)." : ("Could not clear the board: " . ($r['error'] ?? 'unknown'));
});

Tools::register('todo_write', function($p) {
    $todos = $p['todos'] ?? $p['items'] ?? [];
    if (!is_array($todos)) return "missing todos (array of {content, status})";
    $norm = [];
    foreach ($todos as $t) {
        if (!is_array($t)) continue;
        $content = trim((string)($t['content'] ?? $t['task'] ?? $t['text'] ?? ''));
        if ($content === '') continue;
        $status = (string)($t['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) $status = 'pending';
        $norm[] = ['content' => $content, 'status' => $status, 'activeForm' => (string)($t['activeForm'] ?? '')];
    }
    $dir = Config::dataDir() . '/todos'; @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/current.json', json_encode($norm));
    if (empty($norm)) return "Todo list cleared.";
    $mark = ['pending' => '☐', 'in_progress' => '▣', 'completed' => '☑'];
    $done = count(array_filter($norm, fn($t) => $t['status'] === 'completed'));
    $out = "Todo list ($done/" . count($norm) . " done):\n";
    foreach ($norm as $t) $out .= "  " . ($mark[$t['status']] ?? '☐') . " " . $t['content'] . "\n";
    return rtrim($out);
});

// NotebookEdit (Claude Code parity): edit a Jupyter .ipynb cell (replace the
// source, insert a new cell, or delete one), addressing by index or cell id.
Tools::register('notebook_edit', function($p) {
    $path = $p['notebook_path'] ?? $p['file_path'] ?? '';
    if ($path === '') return "missing notebook_path";
    if (!is_file($path)) return "notebook not found: $path";
    $nb = json_decode((string)file_get_contents($path), true);
    if (!is_array($nb) || !isset($nb['cells']) || !is_array($nb['cells'])) return "not a valid .ipynb (no cells array)";
    $mode = strtolower((string)($p['edit_mode'] ?? 'replace'));
    $src = unfence((string)($p['new_source'] ?? $p['source'] ?? ''));
    $type = (((string)($p['cell_type'] ?? 'code')) === 'markdown') ? 'markdown' : 'code';
    $idx = null;
    if (isset($p['cell_id']) && $p['cell_id'] !== '') {
        foreach ($nb['cells'] as $i => $c) if (($c['id'] ?? null) === $p['cell_id']) { $idx = $i; break; }
        if ($idx === null) return "cell_id not found: " . $p['cell_id'];
    } elseif (isset($p['cell_number'])) { $idx = (int)$p['cell_number']; }
    $mkCell = fn($t, $s) => $t === 'markdown'
        ? ['cell_type' => 'markdown', 'metadata' => (object)[], 'source' => explode("\n", $s)]
        : ['cell_type' => 'code', 'metadata' => (object)[], 'source' => explode("\n", $s), 'outputs' => [], 'execution_count' => null];
    if ($mode === 'delete') {
        if ($idx === null || !isset($nb['cells'][$idx])) return "delete: invalid cell index";
        array_splice($nb['cells'], $idx, 1);
    } elseif ($mode === 'insert') {
        array_splice($nb['cells'], $idx === null ? count($nb['cells']) : $idx, 0, [$mkCell($type, $src)]);
    } else {
        if ($idx === null || !isset($nb['cells'][$idx])) return "replace: invalid cell index";
        $nb['cells'][$idx]['source'] = explode("\n", $src);
        if (isset($p['cell_type'])) $nb['cells'][$idx]['cell_type'] = $type;
    }
    Checkpoints::save($path);
    $json = json_encode($nb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json) !== false ? "FILE_EDIT:$path" : "Error writing notebook: $path";
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
    // When the agent is operating inside a live PTY terminal, run the command
    // there so the user watches it execute (full shell access is intended).
    if (isset($GLOBALS['ptyBridge']) && $GLOBALS['ptyBridge'] instanceof PtyBridge) {
        foreach (['rm -rf /', 'mkfs', ':(){', 'sudo rm', '> /dev/sd'] as $b) {
            if (str_contains($cmd, $b)) return "Dangerous command blocked: $b";
        }
        return $GLOBALS['ptyBridge']->run($cmd);
    }
    // In auto mode the user has opted into "run every tool without asking" — this is
    // also the Crew coder context (an isolated git worktree). Allow full shell there
    // except clearly destructive commands, mirroring the PTY path above. ask/readonly
    // modes stay restricted to a safe readonly allowlist.
    $dangerous = ['rm -rf /', 'rm -rf ~', 'rm -rf $HOME', 'mkfs', ':(){', 'sudo rm', '> /dev/sd', 'dd if='];
    if (class_exists('Permission') && Permission::getMode() === 'auto') {
        foreach ($dangerous as $b) { if (str_contains($cmd, $b)) return "Dangerous command blocked: $b"; }
        return shell_exec($cmd . ' 2>&1') ?: "(no output)";
    }
    $readonly = ['ls', 'pwd', 'cat', 'head', 'tail', 'grep', 'find', 'git', 'echo', 'wc', 'sort', 'uniq', 'awk', 'sed', 'cut', 'tr', 'file', 'stat', 'diff', 'tree'];
    $first = strtok($cmd, ' ');
    if (!in_array($first, $readonly)) return "Command not allowed (readonly only, or switch to auto mode): $first";
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

// Background shells (Claude Code parity: Bash run_in_background + BashOutput +
// KillShell). bg starts a tracked job (real PID + a named log); bash_output
// reads new output by id; kill_bash stops it. Jobs live under a temp dir.
function ollamadevBgDir(): string { $d = sys_get_temp_dir() . '/ollamadev-bg'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function ollamadevBgAlive(int $pid): bool { return $pid > 0 && (function_exists('posix_kill') ? @posix_kill($pid, 0) : (trim((string)@shell_exec('kill -0 ' . $pid . ' 2>&1')) === '')); }

Tools::register('bg', function($p) {
    $cmd = trim((string)($p['command'] ?? $p['cmd'] ?? ''));
    $cmd = rtrim($cmd, " &");
    if ($cmd === '') return "missing command";
    $dir = ollamadevBgDir();
    $id = 'bg_' . substr(md5($cmd . microtime(true) . mt_rand()), 0, 6);
    $log = "$dir/$id.log"; $pidf = "$dir/$id.pid";
    // Launch detached; record the real child PID so it can be polled/killed.
    $inner = '(' . $cmd . ') > ' . escapeshellarg($log) . ' 2>&1 & echo $! > ' . escapeshellarg($pidf);
    @shell_exec('sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1');
    usleep(60000); // let the pid file land
    return "Started background job $id. Read output: bash_output(bg_id=\"$id\"); stop: kill_bash(bg_id=\"$id\"). cmd: $cmd";
});

Tools::register('bash_output', function($p) {
    $id = basename((string)($p['bg_id'] ?? $p['id'] ?? ''));
    $dir = ollamadevBgDir(); $log = "$dir/$id.log"; $pidf = "$dir/$id.pid"; $offf = "$dir/$id.off";
    if ($id === '' || !is_file($log)) return "no such background job: " . ($id ?: '(none)');
    $content = (string)@file_get_contents($log);
    $off = is_file($offf) ? (int)@file_get_contents($offf) : 0;
    if ($off > strlen($content)) $off = 0;
    $new = substr($content, $off);
    @file_put_contents($offf, (string)strlen($content));
    $pid = is_file($pidf) ? (int)trim((string)@file_get_contents($pidf)) : 0;
    $state = ollamadevBgAlive($pid) ? 'running' : 'finished';
    return ($new === '' ? "(no new output)" : $new) . "\n[$id — $state]";
});

Tools::register('kill_bash', function($p) {
    $id = basename((string)($p['bg_id'] ?? $p['id'] ?? ''));
    $dir = ollamadevBgDir(); $pidf = "$dir/$id.pid";
    if ($id === '' || !is_file($pidf)) return "no such background job: " . ($id ?: '(none)');
    $pid = (int)trim((string)@file_get_contents($pidf));
    if ($pid <= 0) return "no PID recorded for $id";
    if (!ollamadevBgAlive($pid)) return "background job $id already finished";
    @shell_exec('kill ' . $pid . ' 2>/dev/null');
    return "stopped background job $id (pid $pid)";
});

Tools::register('wait_bg', function($p) {
    // Wait for a specific job to finish (if bg_id given), else a fixed delay.
    $maxWait = (int)($p['seconds'] ?? 60);
    $id = basename((string)($p['bg_id'] ?? $p['id'] ?? ''));
    $start = time();
    if ($id !== '') {
        $pidf = ollamadevBgDir() . "/$id.pid";
        $pid = is_file($pidf) ? (int)trim((string)@file_get_contents($pidf)) : 0;
        while (time() - $start < $maxWait) { if (!ollamadevBgAlive($pid)) return "background job $id finished after " . (time() - $start) . "s"; usleep(200000); }
        return "background job $id still running after {$maxWait}s";
    }
    while (time() - $start < $maxWait) usleep(100000);
    return "Waited $maxWait seconds";
});

// AskUserQuestion parity: let the agent ask the user a question mid-task. Only
// works interactively (a TTY); in one-shot/crew runs it returns a note so the
// agent proceeds with a sensible default instead of blocking.
Tools::register('ask_user', function($p) {
    $q = trim((string)($p['question'] ?? $p['prompt'] ?? $p['text'] ?? ''));
    if ($q === '') return "missing question";
    $opts = $p['options'] ?? $p['choices'] ?? [];
    if (!is_array($opts)) $opts = array_filter(array_map('trim', explode(',', (string)$opts)));
    $interactive = (function_exists('posix_isatty') && @posix_isatty(STDIN));
    if (class_exists('Permission') && !Permission::isInteractive()) $interactive = false;
    if (!$interactive) return "Cannot ask the user right now (non-interactive run). Proceed with the most reasonable default and state the assumption.";
    echo "\n\033[36m? " . $q . "\033[0m\n";
    foreach (array_values($opts) as $i => $o) echo "  " . ($i + 1) . ") " . $o . "\n";
    echo "\033[2m> \033[0m";
    $ans = trim((string)fgets(STDIN));
    if ($opts && ctype_digit($ans) && isset(array_values($opts)[(int)$ans - 1])) $ans = array_values($opts)[(int)$ans - 1];
    return "User answered: " . ($ans !== '' ? $ans : '(no answer)');
});

Tools::register('search', function($p) {
    $q = $p['query'] ?? $p['q'] ?? '';
    if (empty($q)) return "missing query";
    // Search kill switch: disable web search while leaving fetch + remote git available.
    if (!Config::get('search.enabled', true)) return "Web search is disabled (search.enabled is false). Enable it with: ollamadev config set search.enabled true";
    $limit = max(1, min(10, (int)($p['limit'] ?? 5)));
    // Pluggable backend: DuckDuckGo (default, no key), a self-hosted SearXNG
    // instance (most local-first), or the Brave Search API (opt-in, needs a key).
    // None is an AI provider — only the query leaves the machine.
    $provider = strtolower(trim((string)($p['provider'] ?? Config::get('search.provider', 'duckduckgo'))));
    $results = []; $err = '';

    if ($provider === 'searxng' || $provider === 'searx') {
        $host = rtrim((string)($p['host'] ?? Config::get('search.host', '')), '/');
        if ($host === '') return "SearXNG selected but no instance configured. Set search.host (e.g. http://localhost:8888).";
        $ch = curl_init($host . '/search?' . http_build_query(['q' => $q, 'format' => 'json']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'ollamadev']);
        $body = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        $j = json_decode((string)$body, true);
        foreach (($j['results'] ?? []) as $r) {
            $results[] = ['title' => trim((string)($r['title'] ?? '')), 'url' => (string)($r['url'] ?? ''), 'snippet' => trim((string)($r['content'] ?? ''))];
            if (count($results) >= $limit) break;
        }
    } elseif ($provider === 'brave') {
        $key = (string)($p['key'] ?? Config::get('search.key', getenv('BRAVE_API_KEY') ?: ''));
        if ($key === '') return "Brave selected but no API key set. Set search.key in config or the BRAVE_API_KEY env var.";
        $ch = curl_init('https://api.search.brave.com/res/v1/web/search?' . http_build_query(['q' => $q, 'count' => $limit]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Subscription-Token: ' . $key]]);
        $body = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        $j = json_decode((string)$body, true);
        foreach (($j['web']['results'] ?? []) as $r) {
            $results[] = ['title' => trim((string)($r['title'] ?? '')), 'url' => (string)($r['url'] ?? ''), 'snippet' => trim(strip_tags((string)($r['description'] ?? '')))];
            if (count($results) >= $limit) break;
        }
    } else {
        $provider = 'duckduckgo';
        $ch = curl_init('https://html.duckduckgo.com/html/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['q' => $q]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) ollamadev',
        ]);
        $html = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($html === false || $html === '') return "Search failed: " . ($err ?: 'no response');
        if (preg_match_all('#<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)</a>#s', $html, $m, PREG_SET_ORDER)) {
            // Snippets, parsed loosely in document order to pair with titles.
            preg_match_all('#class="result__snippet"[^>]*>(.*?)</a>#s', $html, $sn);
            foreach ($m as $i => $r) {
                $url = html_entity_decode($r[1]);
                if (preg_match('#uddg=([^&]+)#', $url, $u)) $url = urldecode($u[1]);
                if (str_starts_with($url, '//')) $url = 'https:' . $url;
                $title = trim(html_entity_decode(strip_tags($r[2])));
                $snippet = isset($sn[1][$i]) ? trim(html_entity_decode(strip_tags($sn[1][$i]))) : '';
                $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
                if (count($results) >= $limit) break;
            }
        }
    }

    if (empty($results)) return $err !== '' ? "Search failed: $err" : "No results for: $q";
    $out = "Web search results for \"$q\" ($provider):\n\n";
    foreach ($results as $i => $r) {
        $out .= ($i + 1) . ". {$r['title']}\n   {$r['url']}\n";
        if ($r['snippet'] !== '') $out .= "   {$r['snippet']}\n";
        $out .= "\n";
    }
    return trim($out);
});

Tools::register('web_search', function($p) { return Tools::run('search', $p); });

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

Tools::register('code_search', function($p) {
    $q = trim((string)($p['query'] ?? $p['q'] ?? ''));
    if ($q === '') return "missing query";
    $limit = max(1, min(20, (int)($p['limit'] ?? 8)));
    $r = CodeIndex::search($q, $limit);
    if (!empty($r['error'])) {
        if ($r['error'] === 'no_index') return "No semantic index yet. Build it first: ollamadev index build";
        if ($r['error'] === 'embed_failed') return "Embedding failed — is the model installed? Run: ollama pull " . CodeIndex::model();
        return "code_search failed";
    }
    if (empty($r['results'])) return "No semantic matches for: $q";
    $out = "Semantic code matches for \"$q\":\n\n";
    foreach ($r['results'] as $i => $m) {
        $out .= ($i + 1) . ". {$m['file']}:{$m['start']}-{$m['end']}  (score " . $m['score'] . ")\n";
        $out .= "   " . preg_replace('/\s+/', ' ', $m['snippet']) . "\n\n";
    }
    return trim($out);
});

Tools::register('run_tests', function($p) {
    $det = Verify::detect();
    if (!$det) return "No test command detected for this project. Set test.command in config to enable.";
    $res = Verify::run($det);
    $tail = implode("\n", array_slice(explode("\n", $res['output']), -80));
    return ($res['exit'] === 0 ? "TESTS PASSED" : "TESTS FAILED (exit {$res['exit']})") . "  [{$res['cmd']}]\n\n" . $tail;
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
            
            // Route through Tools::run so the permission gate, hooks, plan mode, and
            // tool allowlist all apply — calling $tool() directly bypassed every guard.
            $result = $tool ? Tools::run($toolName, $params) : "Error: tool '$toolName' not found";

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
    $current = OLLAMADEV_VERSION;
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


// Plan mode: research read-only, then propose a plan and wait for the user's OK
// before any edits. While Permission mode is 'plan' all mutating tools are blocked;
// calling exit_plan_mode presents the plan and (on approval) restores the prior mode.
Tools::register('exit_plan_mode', function($p) {
    $plan = trim((string)(is_array($p) ? ($p['plan'] ?? $p['steps'] ?? '') : $p));
    if (!Permission::inPlanMode()) return "Not in plan mode — just proceed with the task.";
    if ($plan === '') return "Provide the plan: exit_plan_mode(plan: \"...\"). Summarize the steps you intend to take.";
    echo "\n\033[1m📋 Proposed plan\033[0m\n" . $plan . "\n";
    // Approval is the USER's call, so it requires an interactive session and an
    // explicit yes. Non-interactive runs (crew/subagent/one-shot) can't approve, so
    // the model cannot self-exit plan mode — mutations stay blocked. A bare Enter/EOF
    // is NOT approval (defaults to keep-planning), so it can't be coerced via piped stdin.
    if (!Permission::isInteractive()) {
        return "Plan recorded. Leaving plan mode needs explicit user approval, which isn't available in a non-interactive run — stay read-only and do not attempt edits.";
    }
    echo "\033[36mProceed with this plan? [y]es / [n]o (keep planning): \033[0m";
    $ans = strtolower(trim((string)fgets(STDIN)));
    if ($ans === 'y' || $ans === 'yes') {
        $m = Permission::exitPlan();
        return "✅ Plan approved — plan mode off (now: $m). Implement it now by calling your write/edit/bash tools.";
    }
    return "Plan not approved. Stay in plan mode, incorporate the user's feedback, and propose a revised plan.";
});
