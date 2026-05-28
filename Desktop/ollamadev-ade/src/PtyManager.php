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
        $terminals = $this->list();
        $maxTerminals = 16;
        while (count($terminals) >= $maxTerminals) {
            usort($terminals, fn($a, $b) => ($a['last_used'] ?? '') <=> ($b['last_used'] ?? '') ?: ($a['id'] ?? '') <=> ($b['id'] ?? ''));
            $toDelete = array_shift($terminals);
            $this->delete($toDelete['id'] ?? '');
        }

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

    // Locate the ollamadev CLI binary. Prefer an explicit OLLAMADEV_BINARY,
    // then the installed copy on PATH / ~/.local/bin, then a repo checkout.
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

    public function start(string $id): array
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) {
            return ['error' => "Terminal $id not found"];
        }

        $logFile = self::$baseDir . '/' . $id . '/session.log';
        touch($logFile);
        $cwd = $terminal['cwd'] ?? getcwd();

        $binary = self::resolveBinary();
        $model = escapeshellarg($terminal['model'] ?? 'llama3.2:latest');
        $termName = escapeshellarg($id);

        // The daemon writes the clean transcript to session.log itself; send
        // its own stdout/stderr to a separate debug file so console chatter
        // (spinners, prompts) never pollutes the transcript.
        $debugFile = self::$baseDir . '/' . $id . '/daemon.log';
        $cmd = "php " . escapeshellarg($binary) . " __terminal-daemon__ $termName $model > " . escapeshellarg($debugFile) . " 2>&1 &";
        shell_exec($cmd);

        usleep(500000);

        $pid = shell_exec("pgrep -f \"ollamadev __terminal-daemon__ " . escapeshellarg($id) . "\" | head -1");
        $pid = trim($pid);

        $terminal['pid'] = $pid ?: null;
        $terminal['status'] = 'running';
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

        $inputFile = self::$baseDir . '/' . $id . '/input.txt';
        if (file_exists($inputFile)) {
            file_put_contents($inputFile, '__STOP__');
        }

        if ($terminal['pid']) {
            posix_kill((int)$terminal['pid'], SIGTERM);
            usleep(200000);
            exec("kill -9 " . (int)$terminal['pid'] . " 2>/dev/null");
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
        if (!$terminal) {
            return false;
        }

        $inputFile = self::$baseDir . '/' . $id . '/input.txt';
        file_put_contents($inputFile, $input);

        $responseFile = self::$baseDir . '/' . $id . '/response.txt';
        if (file_exists($responseFile)) {
            unlink($responseFile);
        }

        return true;
    }

    public function getOutput(string $id, int $lines = 100): string
    {
        $terminal = $this->loadTerminal($id);
        if (!$terminal) {
            return "";
        }

        // Return the cumulative transcript. The frontend appends the delta
        // (output minus what it already has), so this must grow over time -
        // returning only the last response would garble the terminal.
        $logFile = self::$baseDir . '/' . $id . '/session.log';
        if (!file_exists($logFile)) {
            return "";
        }
        return shell_exec("tail -n " . (int)$lines . " " . escapeshellarg($logFile)) ?? "";
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
                    if (!isset($term['id']) && isset($term['name'])) {
                        $term['id'] = $term['name'];
                    }
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
        if (!$data) {
            return null;
        }
        if (isset($data['pid']) && $data['pid']) {
            $pid = trim(shell_exec("pgrep -f \"ollamadev __terminal-daemon__ " . escapeshellarg($id) . "\" | head -1"));
            if (empty($pid)) {
                $data['status'] = 'stopped';
                $data['pid'] = null;
            }
        }
        if (!isset($data['id']) && isset($data['name'])) {
            $data['id'] = $data['name'];
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