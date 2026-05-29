<?php

namespace OllamaDev;

// Manages real PTY shell terminals. Each terminal is backed by the CLI's
// `__pty-daemon__` process (an interactive shell in a pseudo-terminal). The UI
// streams keystrokes in (pty-in) and reads raw terminal output out (pty-out),
// base64-encoded so binary/ANSI data survives the JS<->PHP binding boundary.
class PtyManager
{
    private static string $baseDir;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/tmp';
        self::$baseDir = $home . '/.ollamadev/terminals';
        if (!is_dir(self::$baseDir)) {
            mkdir(self::$baseDir, 0755, true);
        }
    }

    // Locate the ollamadev CLI binary: explicit override, then installed copy,
    // then PATH, then a repo checkout.
    private static function resolveBinary(): string
    {
        if (defined('OLLAMADEV_BINARY') && OLLAMADEV_BINARY) return OLLAMADEV_BINARY;
        if ($env = getenv('OLLAMADEV_BINARY')) return $env;
        $home = getenv('HOME') ?: '';
        $candidates = [
            $home . '/.local/bin/ollamadev',
            trim((string)@shell_exec('command -v ollamadev 2>/dev/null')),
            dirname(__DIR__, 3) . '/ollamadev',
        ];
        foreach ($candidates as $c) {
            if ($c && is_file($c)) return $c;
        }
        return 'ollamadev';
    }

    public function create(string $id, string $model, ?string $cwd = null): array
    {
        $dir = self::$baseDir . '/' . $id;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $terminal = [
            'id' => $id,
            'model' => $model,
            'cwd' => $cwd ?? getcwd(),
            'pid' => null,
            'status' => 'stopped',
            'created' => date('c'),
        ];
        $this->saveTerminal($id, $terminal);
        return $terminal;
    }

    public function start(string $id): array
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) return ['error' => "Terminal $id not found"];

        $cwd = $terminal['cwd'] ?? getcwd();
        $binary = self::resolveBinary();
        $debugFile = self::$baseDir . '/' . $id . '/daemon.log';

        $cmd = 'php ' . escapeshellarg($binary) . ' __pty-daemon__ '
            . escapeshellarg($id) . ' ' . escapeshellarg($cwd)
            . ' > ' . escapeshellarg($debugFile) . ' 2>&1 & echo $!';
        $pid = trim((string)shell_exec($cmd));

        $terminal['pid'] = $pid ?: null;
        $terminal['status'] = 'running';
        $this->saveTerminal($id, $terminal);
        return $terminal;
    }

    public function stop(string $id): array
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) return ['error' => "Terminal $id not found"];

        $pid = $this->daemonPid($id);
        if ($pid) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
            usleep(150000);
            @exec('kill -9 ' . (int)$pid . ' 2>/dev/null');
        }
        $terminal['pid'] = null;
        $terminal['status'] = 'stopped';
        $this->saveTerminal($id, $terminal);
        return $terminal;
    }

    // Append keystroke data (base64 from the UI) to the shell's input queue.
    public function write(string $id, string $b64): bool
    {
        $dir = self::$baseDir . '/' . $id;
        if (!is_dir($dir)) return false;
        $data = base64_decode($b64, true);
        if ($data === false) $data = $b64; // tolerate plain text
        return file_put_contents("$dir/pty-in", $data, FILE_APPEND | LOCK_EX) !== false;
    }

    // Return base64 of the raw terminal output produced after $offset bytes.
    public function read(string $id, int $offset = 0): array
    {
        $outFile = self::$baseDir . '/' . $id . '/pty-out';
        if (!is_file($outFile)) return ['data' => '', 'offset' => $offset];
        clearstatcache(true, $outFile);
        $size = (int)filesize($outFile);
        if ($size <= $offset) return ['data' => '', 'offset' => $size < $offset ? 0 : $offset];
        $fh = fopen($outFile, 'rb');
        fseek($fh, $offset);
        $data = (string)fread($fh, $size - $offset);
        fclose($fh);
        return ['data' => base64_encode($data), 'offset' => $offset + strlen($data)];
    }

    public function resize(string $id, int $cols, int $rows): bool
    {
        // Best-effort: write the size to a control file the daemon can honor.
        $dir = self::$baseDir . '/' . $id;
        if (!is_dir($dir)) return false;
        return file_put_contents("$dir/pty-size", $cols . 'x' . $rows) !== false;
    }

    public function list(): array
    {
        $terminals = [];
        if (is_dir(self::$baseDir)) {
            foreach (scandir(self::$baseDir) as $name) {
                if ($name === '.' || $name === '..' || !is_dir(self::$baseDir . '/' . $name)) continue;
                $term = $this->loadTerminal($name);
                if ($term) $terminals[] = $term;
            }
        }
        return $terminals;
    }

    public function delete(string $id): bool
    {
        $this->stop($id);
        $dir = self::$baseDir . '/' . $id;
        if (is_dir($dir)) shell_exec('rm -rf ' . escapeshellarg($dir));
        return true;
    }

    public function loadTerminal(string $id): ?array
    {
        $file = self::$baseDir . '/' . $id . '/session.json';
        if (!file_exists($file)) return null;
        $data = json_decode((string)file_get_contents($file), true);
        if (!$data) return null;
        if (!empty($data['pid']) && !$this->daemonPid($id)) {
            $data['status'] = 'stopped';
            $data['pid'] = null;
        }
        return $data;
    }

    private function daemonPid(string $id): ?int
    {
        $pid = trim((string)shell_exec('pgrep -f ' . escapeshellarg('__pty-daemon__ ' . $id) . ' | head -1'));
        return $pid !== '' ? (int)$pid : null;
    }

    private function saveTerminal(string $id, array $terminal): void
    {
        $dir = self::$baseDir . '/' . $id;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . '/session.json', json_encode($terminal, JSON_PRETTY_PRINT));
    }
}
