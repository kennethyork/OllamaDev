<?php

namespace OllamaDev;

class PtyManager
{
    private static array $terminals = [];
    private static string $baseDir;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/tmp';
        self::$baseDir = $home . '/.ollamadev/terminals';
        if (!is_dir(self::$baseDir)) {
            mkdir(self::$baseDir, 0755, true);
        }
    }

    public function create(string $id, string $model, ?string $cwd = null): array
    {
        $dir = self::$baseDir . '/' . $id;
        mkdir($dir, 0755, true);
        mkdir($dir . '/history', 0755, true);

        $terminal = [
            'id' => $id,
            'model' => $model,
            'cwd' => $cwd ?? getcwd(),
            'pid' => null,
            'pty' => null,
            'stdin' => null,
            'stdout' => null,
            'status' => 'stopped',
            'created' => date('c'),
        ];

        $this->saveTerminal($id, $terminal);
        self::$terminals[$id] = $terminal;

        return $terminal;
    }

    public function start(string $id): array
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) {
            return ['error' => "Terminal $id not found"];
        }

        $logFile = self::$baseDir . '/' . $id . '/session.log';
        $cwd = $terminal['cwd'];

        $binary = defined('OLLAMADEV_BINARY') ? OLLAMADEV_BINARY : 'ollamadev';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = proc_open(
            "cd " . escapeshellarg($cwd) . " && " . $binary . " chat --model " . escapeshellarg($terminal['model']),
            $descriptors,
            $pipes,
            $cwd
        );

        $pid = proc_get_status($process)['pid'];

        $terminal['pid'] = $pid;
        $terminal['status'] = 'running';
        $terminal['process'] = $process;
        $terminal['stdin'] = $pipes[0];
        $terminal['stdout'] = $pipes[1];
        $terminal['logFile'] = $logFile;

        $this->saveTerminal($id, $terminal);
        self::$terminals[$id] = $terminal;

        return $terminal;
    }

    public function stop(string $id): array
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) {
            return ['error' => "Terminal $id not found"];
        }

        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGTERM);
            usleep(100000);
            posix_kill((int)$terminal['pid'], SIGKILL);
        }

        $terminal['pid'] = null;
        $terminal['status'] = 'stopped';
        $this->saveTerminal($id, $terminal);
        self::$terminals[$id] = $terminal;

        return $terminal;
    }

    public function write(string $id, string $input): bool
    {
        $terminal = self::$terminals[$id] ?? $this->loadTerminal($id);
        if (!$terminal || !$terminal['stdin']) {
            return false;
        }

        fwrite($terminal['stdin'], $input . "\n");
        return true;
    }

    public function getOutput(string $id, int $lines = 100): string
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) {
            return "";
        }

        $logFile = self::$baseDir . '/' . $id . '/session.log';
        if (!file_exists($logFile)) {
            return "";
        }

        return shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($logFile));
    }

    public function resize(string $id, int $cols, int $rows): bool
    {
        $terminal = self::$terminals[$id] ?? $this->loadTerminal($id);
        if (!$terminal || !$terminal['pid']) {
            return false;
        }

        $tty = '/proc/' . $terminal['pid'] . '/fd/0';
        if (file_exists($tty)) {
            exec("stty -F $tty rows $rows cols $cols 2>/dev/null");
        }

        return true;
    }

    public function list(): array
    {
        $terminals = [];
        if (is_dir(self::$baseDir)) {
            foreach (scandir(self::$baseDir) as $name) {
                if ($name === '.' || $name === '..' || !is_dir(self::$baseDir . '/' . $name)) {
                    continue;
                }
                $term = $this->loadTerminal($name);
                if ($term) {
                    $terminals[] = $term;
                }
            }
        }
        return $terminals;
    }

    public function delete(string $id): bool
    {
        $this->stop($id);
        $dir = self::$baseDir . '/' . $id;
        if (is_dir($dir)) {
            shell_exec("rm -rf " . escapeshellarg($dir));
        }
        unset(self::$terminals[$id]);
        return true;
    }

    private function loadTerminal(string $id): ?array
    {
        $file = self::$baseDir . '/' . $id . '/session.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        if (isset($data['pid']) && $data['pid']) {
            $status = proc_get_status($data['process'] ?? null);
            if ($status && !$status['running']) {
                $data['status'] = 'stopped';
                $data['pid'] = null;
            }
        }
        return $data;
    }

    private function saveTerminal(string $id, array $terminal): void
    {
        $dir = self::$baseDir . '/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/session.json';
        $saveData = $terminal;
        unset($saveData['process'], $saveData['stdin'], $saveData['stdout']);
        file_put_contents($file, json_encode($saveData, JSON_PRETTY_PRINT));
    }
}