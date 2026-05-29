<?php

namespace OllamaDev;

// A small library of reusable agent prompts, stored as JSON next to the other
// OllamaDev data so the CLI could read it too.
class PromptStore
{
    private string $file;
    private array $prompts = [];

    public function __construct()
    {
        $home = getenv('HOME') ?: '/tmp';
        $dir = $home . '/.ollamadev';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->file = $dir . '/prompts.json';
        if (is_file($this->file)) {
            $this->prompts = json_decode((string)file_get_contents($this->file), true) ?: [];
        }
    }

    private function save(): void
    {
        file_put_contents($this->file, json_encode(array_values($this->prompts), JSON_PRETTY_PRINT));
    }

    public function list(): array
    {
        return array_values($this->prompts);
    }

    public function create(string $title, string $body): string
    {
        $id = 'p_' . substr(md5($title . microtime()), 0, 10);
        $this->prompts[] = ['id' => $id, 'title' => $title, 'body' => $body, 'created' => date('c')];
        $this->save();
        return $id;
    }

    public function delete(string $id): bool
    {
        $this->prompts = array_filter($this->prompts, fn($p) => ($p['id'] ?? '') !== $id);
        $this->save();
        return true;
    }
}
