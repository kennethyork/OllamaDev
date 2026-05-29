<?php

namespace OllamaDev;

// Reads/writes the shared ~/.ollamadev/config.json that the CLI also uses, so
// agent settings (model, system prompt, temperature, permission mode) stay in
// one place across the desktop app and the ollamadev CLI.
class SettingsStore
{
    private string $file;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/tmp';
        $dir = $home . '/.ollamadev';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->file = $dir . '/config.json';
    }

    private function read(): array
    {
        if (!is_file($this->file)) return [];
        return json_decode((string)file_get_contents($this->file), true) ?: [];
    }

    public function get(): array
    {
        $c = $this->read();
        return [
            'model' => $c['ollama']['defaultModel'] ?? 'llama3.2:latest',
            'host' => $c['ollama']['host'] ?? 'http://localhost:11434',
            'systemPrompt' => $c['agents']['systemPrompt'] ?? '',
            'temperature' => $c['agents']['coder']['temperature'] ?? 0.7,
            'permissionMode' => $c['permissions']['mode'] ?? 'ask',
        ];
    }

    public function set(array $s): bool
    {
        $c = $this->read();
        if (isset($s['model'])) $c['ollama']['defaultModel'] = (string)$s['model'];
        if (isset($s['host'])) $c['ollama']['host'] = (string)$s['host'];
        if (isset($s['systemPrompt'])) $c['agents']['systemPrompt'] = (string)$s['systemPrompt'];
        if (isset($s['temperature'])) $c['agents']['coder']['temperature'] = (float)$s['temperature'];
        if (isset($s['permissionMode'])) $c['permissions']['mode'] = (string)$s['permissionMode'];
        return file_put_contents($this->file, json_encode($c, JSON_PRETTY_PRINT)) !== false;
    }
}
