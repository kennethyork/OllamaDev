<?php

class TerminalManager
{
    private string $baseDir;

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
                    $terminals[] = $this->loadTerminal($name);
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

        $logFile = $this->baseDir . '/' . $name . '/session.log';
        $cwd = $terminal['cwd'];

        $binary = defined('OLLAMADEV_BINARY') ? OLLAMADEV_BINARY : 'ollamadev';

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a']
        ];

        $process = proc_open(
            "cd " . escapeshellarg($cwd) . " && " . $binary . " --model " . escapeshellarg($terminal['model']),
            $descriptors,
            $pipes,
            $cwd
        );

        $pid = proc_get_status($process)['pid'];
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $terminal['pid'] = $pid;
        $terminal['status'] = 'running';
        $terminal['last_used'] = date('Y-m-d H:i:s');
        $this->saveTerminal($name, $terminal);

        return $terminal;
    }

    public function stop(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];

        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGTERM);
            usleep(100000);
            posix_kill((int)$terminal['pid'], SIGKILL);
        }

        $terminal['pid'] = null;
        $terminal['status'] = 'stopped';
        $terminal['last_used'] = date('Y-m-d H:i:s');
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function delete(string $name): bool
    {
        $dir = $this->baseDir . '/' . $name;
        if (!is_dir($dir)) return false;

        $this->stop($name);
        shell_exec("rm -rf " . escapeshellarg($dir));
        return true;
    }

    public function exists(string $name): bool
    {
        return is_dir($this->baseDir . '/' . $name);
    }

    public function loadTerminal(string $name): ?array
    {
        $file = $this->baseDir . '/' . $name . '/session.json';
        if (!file_exists($file)) return null;
        return json_decode(file_get_contents($file), true) ?: null;
    }

    public function saveTerminal(string $name, array $terminal): void
    {
        $file = $this->baseDir . '/' . $name . '/session.json';
        file_put_contents($file, json_encode($terminal, JSON_PRETTY_PRINT));
    }

    public function getLog(string $name, int $lines = 100): string
    {
        $logFile = $this->baseDir . '/' . $name . '/session.log';
        if (!file_exists($logFile)) return '';
        return shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($logFile));
    }

    public function send(string $name, string $input): string
    {
        $fifo = $this->baseDir . '/' . $name . '/input.fifo';
        if (!file_exists($fifo)) {
            shell_exec("mkfifo " . escapeshellarg($fifo));
        }
        file_put_contents($fifo, $input . "\n");
        return "Sent input to $name";
    }

    public function status(): array
    {
        $terminals = $this->list();
        $running = 0;
        $paused = 0;
        $stopped = 0;

        foreach ($terminals as $t) {
            if ($t['status'] === 'running') $running++;
            elseif ($t['status'] === 'paused') $paused++;
            else $stopped++;
        }

        return [
            'total' => count($terminals),
            'running' => $running,
            'paused' => $paused,
            'stopped' => $stopped,
            'terminals' => $terminals
        ];
    }

    public function pause(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'running') return ['error' => "Terminal '$name' is not running"];

        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGSTOP);
        }

        $terminal['status'] = 'paused';
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }

    public function resume(string $name): array
    {
        $terminal = $this->loadTerminal($name);
        if (!$terminal) return ['error' => "Terminal '$name' not found"];
        if ($terminal['status'] !== 'paused') return ['error' => "Terminal '$name' is not paused"];

        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGCONT);
        }

        $terminal['status'] = 'running';
        $this->saveTerminal($name, $terminal);
        return $terminal;
    }
}