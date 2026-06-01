<?php

declare(strict_types=1);

namespace OllamaDev;

// Shared implementation of every frontend↔backend call, so the SAME logic backs
// both runtimes: the Boson desktop (native bindings) and the browser server mode
// (HTTP /api/<name>). One source of truth — add a method here and both get it.
// All model/agent/crew work still runs locally through the ollamadev CLI; the
// browser is just another front-end over the same local engine.
final class Bindings
{
    public function __construct(
        private PtyManager $pty,
        private FileBrowser $files,
        private string $cli,
    ) {}

    // Names callable over HTTP (and the arg order the web shim sends).
    public const PUBLIC = [
        'listModels', 'termCreate', 'termRead', 'termWrite', 'termKill', 'agentRun',
        'cliPath', 'sttEnabled', 'sttTranscribe', 'crewBoard', 'homeDir',
        'crewCoderLog', 'memoryGraph', 'getRoot', 'setRoot', 'listFiles', 'readFile', 'writeFile',
    ];

    // Dispatch an allow-listed call with positional args (used by server.php).
    public function call(string $name, array $args): mixed
    {
        if (!in_array($name, self::PUBLIC, true)) throw new \RuntimeException("unknown binding: $name");
        return $this->{$name}(...array_values($args));
    }

    public function listModels(): array
    {
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' models --json 2>/dev/null');
        $data = json_decode((string) $out, true);
        return is_array($data) ? $data : ['connected' => false, 'models' => []];
    }

    public function termCreate(string $id, string $model): bool
    {
        $this->pty->create($id, $model);
        $this->pty->start($id);
        return true;
    }
    public function termRead(string $id, int $offset = 0): array { return $this->pty->read($id, $offset); }
    public function termWrite(string $id, string $b64): bool { return $this->pty->write($id, $b64); }
    public function termKill(string $id): bool { $this->pty->delete($id); return true; }
    public function agentRun(string $id, string $prompt): bool { return $this->pty->agentRun($id, $prompt); }

    public function cliPath(): string { return $this->cli; }

    public function sttEnabled(): bool
    {
        return trim((string) @shell_exec(escapeshellarg($this->cli) . ' transcribe --enabled 2>/dev/null')) === '1';
    }
    public function sttTranscribe(string $b64, string $ext = 'webm'): string
    {
        $data = base64_decode($b64, true);
        if ($data === false || $data === '') return '';
        $tmp = sys_get_temp_dir() . '/odv_stt_' . getmypid() . '_' . substr(md5($b64), 0, 6) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext ?: 'webm');
        @file_put_contents($tmp, $data);
        $out = (string) @shell_exec(escapeshellarg($this->cli) . ' transcribe ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        return trim($out);
    }

    public function crewBoard(): array
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $f = $home . '/.ollamadev/crew/current.json';
        if (!is_file($f)) return [];
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    public function homeDir(): string { return getenv('HOME') ?: ''; }

    public function crewCoderLog(string $runId, int $n, int $offset = 0): array
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        if (!preg_match('/^crew_[0-9_]+$/', $runId) || $n < 1 || $n > 64) return ['data' => '', 'size' => 0];
        $f = $home . '/.ollamadev/crew/' . $runId . '/coder-' . $n . '.log';
        if (!is_file($f)) return ['data' => '', 'size' => 0];
        $size = (int) filesize($f);
        if ($offset >= $size) return ['data' => '', 'size' => $size];
        $fh = @fopen($f, 'rb');
        if (!$fh) return ['data' => '', 'size' => $size];
        if ($offset > 0) fseek($fh, $offset);
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        return ['data' => $data, 'size' => $size];
    }

    public function memoryGraph(): array
    {
        $root = $this->files->getRoot();
        $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($this->cli) . ' memory graph --json 2>/dev/null';
        $d = json_decode(trim((string) @shell_exec($cmd)), true);
        return is_array($d) && isset($d['nodes']) ? $d : ['nodes' => [], 'edges' => []];
    }

    public function getRoot(): string { return $this->files->getRoot(); }
    public function setRoot(string $path): array
    {
        $path = trim($path);
        if ($path !== '' && $path[0] === '~') $path = (getenv('HOME') ?: '') . substr($path, 1);
        $real = realpath($path);
        if ($real === false || !is_dir($real)) return ['error' => "Not a directory: $path"];
        $this->files->setRoot($real);
        return ['root' => $real];
    }
    public function listFiles(?string $path = null): array { return $this->files->listDir($path); }
    public function readFile(string $path): array { return $this->files->readFile($path); }
    public function writeFile(string $path, string $content): array { return $this->files->writeFile($path, $content); }
}
