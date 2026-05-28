<?php

namespace OllamaDev;

class SessionManager
{
    private PtyManager $pty;

    public function __construct()
    {
        $this->pty = new PtyManager();
    }

    public function listTerminals(): array
    {
        $terminals = $this->pty->list();
        return array_map(fn($t) => [
            'id' => $t['id'],
            'model' => $t['model'],
            'cwd' => $t['cwd'],
            'status' => $t['status'],
            'created' => $t['created'],
        ], $terminals);
    }

    public function createTerminal(string $model = 'llama3.2:latest'): string
    {
        $id = 'term_' . time() . '_' . substr(md5(mt_rand()), 0, 8);
        $this->pty->create($id, $model);
        $this->pty->start($id);
        return $id;
    }

    public function getTerminal(string $id): ?array
    {
        return $this->pty->loadTerminal($id) ?? null;
    }

    public function killTerminal(string $id): bool
    {
        $this->pty->stop($id);
        $this->pty->delete($id);
        return true;
    }

    public function writeToTerminal(string $id, string $input): bool
    {
        return $this->pty->write($id, $input);
    }

    public function getTerminalOutput(string $id, int $lines = 100): string
    {
        return $this->pty->getOutput($id, $lines);
    }

    public function resizeTerminal(string $id, int $cols, int $rows): bool
    {
        return $this->pty->resize($id, $cols, $rows);
    }
}