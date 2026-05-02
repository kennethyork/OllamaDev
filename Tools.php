<?php

class Tools {
    private static array $tools = [];

    public static function register(string $name, callable $fn): void {
        self::$tools[$name] = $fn;
    }

    public static function find(string $name): ?callable {
        return self::$tools[$name] ?? null;
    }

    public static function run(string $name, array $params): string {
        $fn = self::find($name);
        if (!$fn) {
            return "Error: tool '$name' not found";
        }

        try {
            return $fn($params);
        } catch (Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }

    public static function all(): array {
        return array_keys(self::$tools);
    }
}

Tools::register('view', function($params) {
    $path = $params['file_path'] ?? '';
    if (empty($path)) {
        return "missing file_path";
    }

    if (!file_exists($path)) {
        return "File not found: $path";
    }

    $lines = file($path);
    if ($lines === false) {
        return "Error reading file: $path";
    }

    $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
    $limit = isset($params['limit']) ? (int)$params['limit'] : count($lines);

    $output = '';
    for ($i = $offset; $i < min($offset + $limit, count($lines)); $i++) {
        $output .= sprintf("%4d  %s", $i + 1, $lines[$i]);
    }
    return $output;
});

Tools::register('write', function($params) {
    $path = $params['file_path'] ?? '';
    $content = $params['content'] ?? '';

    if (empty($path)) {
        return "missing file_path";
    }
    if ($content === '') {
        return "missing content";
    }

    $dir = dirname($path);
    if (!empty($dir) && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (file_put_contents($path, $content) === false) {
        return "Error writing file: $path";
    }
    return "Written to $path";
});

Tools::register('edit', function($params) {
    $path = $params['file_path'] ?? '';
    $oldStr = $params['old_string'] ?? '';
    $newStr = $params['new_string'] ?? '';

    if (empty($path)) {
        return "missing file_path";
    }
    if (empty($oldStr)) {
        return "missing old_string";
    }
    if (empty($newStr)) {
        return "missing new_string";
    }

    if (!file_exists($path)) {
        return "File not found: $path";
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return "Error reading file: $path";
    }

    $pos = strpos($content, $oldStr);
    if ($pos === false) {
        return "old_string not found in file";
    }

    $content = substr_replace($content, $newStr, $pos, strlen($oldStr));

    if (file_put_contents($path, $content) === false) {
        return "Error writing file: $path";
    }
    return "Edited $path";
});

Tools::register('glob', function($params) {
    $pattern = $params['pattern'] ?? '';
    if (empty($pattern)) {
        return "missing pattern";
    }

    $basePath = $params['path'] ?? '.';
    if (!str_contains($pattern, '*')) {
        $pattern = '**/*' . $pattern;
    }
    $pattern = rtrim($basePath, '/') . '/' . $pattern;

    $files = glob($pattern, GLOB_BRACE);
    if ($files === false) {
        return "Error: pattern not valid";
    }

    if (empty($files)) {
        return "No files found";
    }

    return implode("\n", $files);
});

Tools::register('grep', function($params) {
    $pattern = $params['pattern'] ?? '';
    if (empty($pattern)) {
        return "missing pattern";
    }

    $path = $params['path'] ?? '.';
    $include = $params['include'] ?? '';

    $cmd = "grep -rn --color=never " . escapeshellarg($pattern) . " " . escapeshellarg($path);
    if (!empty($include)) {
        $cmd .= " --include=" . escapeshellarg($include);
    }

    $output = shell_exec($cmd . ' 2>&1');
    return $output ?: "No matches found";
});

Tools::register('ls', function($params) {
    $path = $params['path'] ?? '.';
    if (!is_dir($path)) {
        return "Not a directory: $path";
    }

    $output = shell_exec("ls -la " . escapeshellarg($path) . " 2>&1");
    return $output ?: "Empty directory";
});

Tools::register('bash', function($params) {
    $cmd = $params['command'] ?? '';
    if (empty($cmd)) {
        return "missing command";
    }

    $readonlyCmds = ['ls', 'pwd', 'cat', 'head', 'tail', 'grep', 'find', 'git', 'echo', 'wc', 'sort', 'uniq', 'awk', 'sed', 'cut', 'tr', 'file', 'stat', 'diff', 'tree'];
    $firstWord = strtok($cmd, ' ');

    if (!in_array($firstWord, $readonlyCmds)) {
        return "Command not allowed (readonly only): $firstWord";
    }

    $banned = ['curl', 'wget', 'chmod', 'sudo', 'rm -rf', 'mkfs', 'shutdown', 'reboot'];
    foreach ($banned as $b) {
        if (str_contains($cmd, $b)) {
            return "Dangerous command blocked: $b";
        }
    }

    $output = shell_exec($cmd . ' 2>&1');
    return $output ?: "(no output)";
});

Tools::register('fetch', function($params) {
    $url = $params['url'] ?? '';
    if (empty($url)) {
        return "missing url";
    }

    $timeout = isset($params['timeout']) ? (int)$params['timeout'] : 30;
    $output = shell_exec("curl -fsSL --max-time $timeout " . escapeshellarg($url) . " 2>&1");
    return $output ?: "Failed to fetch $url";
});

Tools::register('diagnostics', function($params) {
    $path = $params['file_path'] ?? '';
    if (empty($path)) {
        return "No file specified";
    }

    if (!file_exists($path)) {
        return "File not found: $path";
    }

    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($ext === 'php') {
        $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
        return $output ?: "No syntax errors";
    }

    $output = shell_exec("go vet " . escapeshellarg($path) . " 2>&1");
    return $output ?: "No diagnostics";
});