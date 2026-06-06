class TerminalManager
{
    public string $baseDir;

    public function __construct()
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        $this->baseDir = $home . '/.ollamadev/terminals';
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    public function list(): array
    {
        $terminals = [];
        if (is_dir($this->baseDir)) {
            foreach (scandir($this->baseDir) as $name) {
                if ($name === '.' || $name === '..') continue;
                $dir = $this->baseDir . '/' . $name;
                if (is_dir($dir)) {
                    $t = $this->loadTerminal($name);
                    if ($t !== null) $terminals[] = $t;
                }
            }
        }
        return $terminals;
    }

    public function create(string $name, string $model, ?string $cwd = null): array
    {
        if ($this->exists($name)) {
            return ['error' => "Terminal '$name' already exists"];
        }
        $dir = $this->baseDir . '/' . $name;
        mkdir($dir, 0755, true);
        mkdir($dir . '/history', 0755, true);
        $terminal = [
            'name' => $name,
            'model' => $model,
            'created' => date('Y-m-d H:i:s'),
            'last_used' => date('Y-m-d H:i:s'),
            'cwd' => $cwd ?? getcwd(),
            'pid' => null,
            'status' => 'stopped'
        ];
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function start(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        $terminal['status'] = 'running';
        $terminal['last_used'] = date('Y-m-d H:i:s');
        $this->saveTerminal($name, $terminal);
        return ['name' => $name, 'status' => 'running'];
    }

    public function stop(string $name): bool
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return false;
        if ($terminal['pid']) { shell_exec("kill " . (int)$terminal['pid'] . " 2>/dev/null"); }
        $terminal['status'] = 'stopped';
        $this->saveTerminal($name, $terminal);
        return true;
    }

    public function pause(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'running') return ['error' => "Terminal '$name' is not running"];
        $terminal['status'] = 'paused';
        $this->saveTerminal($name, $terminal);
        return ['success' => true, 'name' => $name, 'status' => 'paused'];
    }

    public function resume(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'paused') return ['error' => "Terminal '$name' is not paused"];
        $terminal['status'] = 'running';
        $this->saveTerminal($name, $terminal);
        return ['success' => true, 'name' => $name, 'status' => 'running'];
    }

    public function delete(string $name): bool
    {
        $dir = $this->baseDir . '/' . $name;
        if (!is_dir($dir)) return false;
        $this->stop($name);
        shell_exec("rm -rf " . escapeshellarg($dir));
        return true;
    }

    public function exists(string $name): bool { return is_dir($this->baseDir . '/' . $name); }

    public function loadTerminal(string $name): ?array
    {
        $file = $this->baseDir . '/' . $name . '/session.json';
        if (!file_exists($file)) return null;
        $t = json_decode((string) file_get_contents($file), true);
        if (!is_array($t)) return null;
        // Normalize across schemas: the desktop app writes its own session.json in
        // this same dir, keyed by 'id' with no 'name'/'status'. Backfill from the
        // directory name so `terminal list` renders any record without warnings.
        $t['name'] = $t['name'] ?? ($t['id'] ?? $name);
        $t['status'] = $t['status'] ?? 'running';
        $t['model'] = $t['model'] ?? 'unknown';
        $t['cwd'] = $t['cwd'] ?? '';
        return $t;
    }

    public function saveTerminal(string $name, array $terminal): void
    {
        file_put_contents($this->baseDir . '/' . $name . '/session.json', json_encode($terminal, JSON_PRETTY_PRINT));
    }

    public function getLog(string $name, int $lines = 100): string
    {
        $logFile = $this->baseDir . '/' . $name . '/session.log';
        return file_exists($logFile) ? shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($logFile)) : '';
    }

    public function status(): array
    {
        $terminals = $this->list();
        $running = $stopped = 0;
        foreach ($terminals as $t) {
            if ($t['status'] === 'running') $running++; else $stopped++;
        }
        return ['total' => count($terminals), 'running' => $running, 'stopped' => $stopped, 'terminals' => $terminals];
    }
}

