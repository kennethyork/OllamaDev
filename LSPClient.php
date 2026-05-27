class LSPClient {
    private string $command;
    private array $args;
    private array $caps;
    private $process;

    public function __construct(string $command, array $args = []) {
        $this->command = $command;
        $this->args = $args;
    }

    public function initialize(): void {
        $this->caps = [
            'textDocumentSync' => 1,
            'hoverProvider' => true,
            'definitionProvider' => true,
            'referencesProvider' => true,
            'documentSymbolProvider' => true,
            'completionProvider' => ['resolveProvider' => false]
        ];
    }

    public function sendRequest(string $method, array $params): ?array {
        if (!$this->process) {
            $cmd = $this->command . ' ' . implode(' ', $this->args);
            $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $this->process = proc_open($cmd, $descriptors, $pipes);
        }

        $id = uniqid();
        $request = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        fwrite($pipes[0], $request . "\n");
        fflush($pipes[0]);

        $response = fgets($pipes[1]);
        if ($response) {
            return json_decode($response, true);
        }
        return null;
    }

    public function diagnostics(string $filePath): array {
        if (!file_exists($filePath)) return [];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $diags = [];

        if ($ext === 'php') {
            $output = shell_exec("php -l " . escapeshellarg($filePath) . " 2>&1");
            if (strpos($output, 'error') !== false || strpos($output, 'Parse error') !== false) {
                if (preg_match('/Parse error.*on line (\d+)/', $output, $m)) {
                    $diags[] = ['line' => (int)$m[1], 'col' => 1, 'severity' => 'error', 'message' => trim($output)];
                }
            }
        } elseif ($ext === 'js' || $ext === 'ts') {
            $output = shell_exec("npx tsc --noEmit " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\d+):(\d+)\s+error\s+(.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        } elseif ($ext === 'py') {
            $output = shell_exec("python -m py_compile " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output)) {
                if (preg_match('/line (\d+)/', $output, $m)) {
                    $diags[] = ['line' => (int)$m[1], 'col' => 1, 'severity' => 'error', 'message' => trim($output)];
                }
            }
        } elseif (in_array($ext, ['go'])) {
            $output = shell_exec("cd " . escapeshellarg(dirname($filePath)) . " && go vet ./... 2>&1");
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\w+\.go):(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[2], 'col' => (int)$m[3], 'severity' => 'error', 'message' => $m[4]];
                }
            }
        } elseif (in_array($ext, ['c', 'cpp'])) {
            $output = shell_exec("gcc -fsyntax-only " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output)) {
                preg_match_all('/(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        } elseif ($ext === 'rs') {
            $output = shell_exec("rustc --crate-type lib " . escapeshellarg($filePath) . " 2>&1");
            if (!empty($output) && strpos($output, 'error') !== false) {
                preg_match_all('/(\d+):(\d+): (.*)/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $diags[] = ['line' => (int)$m[1], 'col' => (int)$m[2], 'severity' => 'error', 'message' => $m[3]];
                }
            }
        }

        return $diags;
    }

    public function hover(string $filePath, int $line, int $col): ?string {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        if ($line < 1 || $line > count($lines)) return null;

        $currentLine = $lines[$line - 1];
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $currentLine, $matches, PREG_OFFSET_CAPTURE);

        $word = null;
        foreach ($matches[0] as $match) {
            if ($col >= $match[1] && $col <= $match[1] + strlen($match[0])) {
                $word = $match[0];
                break;
            }
        }

        if (!$word) return null;

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($ext === 'php') {
            $output = shell_exec("grep -n 'function $word\\|class $word\\|const $word' " . escapeshellarg($filePath) . " 2>/dev/null | head -5");
        } elseif ($ext === 'py') {
            $output = shell_exec("grep -n 'def $word\\|class $word\\|import $word' " . escapeshellarg($filePath) . " 2>/dev/null | head -5");
        } else {
            $output = shell_exec("grep -rn '$word' " . escapeshellarg($filePath) . " 2>/dev/null | head -5");
        }

        return $output ?: null;
    }

    public function gotoDefinition(string $filePath, int $line, int $col): ?array {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        if ($line < 1 || $line > count($lines)) return null;

        $currentLine = $lines[$line - 1];
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $currentLine, $matches, PREG_OFFSET_CAPTURE);

        $word = null;
        foreach ($matches[0] as $match) {
            if ($col >= $match[1] && $col <= $match[1] + strlen($match[0])) {
                $word = $match[0];
                break;
            }
        }

        if (!$word) return null;

        $dir = dirname($filePath);
        $output = shell_exec("grep -rn 'function $word\\|class $word\\|def $word\\|interface $word' " . escapeshellarg($dir) . " 2>/dev/null | head -1");

        if ($output && preg_match('/^(.*):(\d+):/', $output, $m)) {
            return ['file' => $m[1], 'line' => (int)$m[2]];
        }

        return null;
    }

    public function documentSymbols(string $filePath): array {
        $symbols = [];
        $content = file_get_contents($filePath);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        if ($ext === 'php') {
            preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'function', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
            preg_match_all('/class\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'class', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
        } elseif ($ext === 'py') {
            preg_match_all('/def\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'function', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
            preg_match_all('/class\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $symbols[] = ['name' => $m[1], 'kind' => 'class', 'line' => substr_count(substr($content, 0, strpos($content, $m[0])), "\n") + 1];
            }
        }

        return $symbols;
    }

    public function close(): void {
        if ($this->process) {
            proc_close($this->process);
            $this->process = null;
        }
    }
}