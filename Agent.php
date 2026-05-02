<?php
require_once __DIR__ . '/Tools.php';

class Agent {
    private OllamaClient $client;
    private array $config;
    private string $model;
    private array $systemPrompt;

    public function __construct() {
        $this->config = Config::load();
        $this->client = new OllamaClient();
        $this->model = Config::get('ollama.defaultModel', 'codellama');
        $this->systemPrompt = $this->loadSystemPrompt();
    }

    private function loadSystemPrompt(): array {
        return [
            'role' => 'system',
            'content' => 'You are OllamaDev, an AI coding assistant running locally via Ollama.

You have access to tools to help with coding tasks:
- view: Read file contents with line numbers
- write: Create or overwrite files  
- edit: Replace text in files (old_string/new_string)
- glob: Find files matching patterns
- grep: Search file contents with regex
- ls: List directory contents
- bash: Execute shell commands (read-only allowed freely)

Guidelines:
- Be helpful and precise
- Use tools when needed to accomplish tasks
- Ask for confirmation before destructive actions
- Prefer showing code over explaining'
        ];
    }

    public function setModel(string $model): void {
        $this->model = $model;
    }

    public function getModel(): string {
        return $this->model;
    }

    public function listModels(): array {
        return $this->client->listModels();
    }

    public function checkConnection(): bool {
        return $this->client->checkConnection();
    }

    public function run(array $messages, callable $handler = null): string {
        $allMessages = array_merge([$this->systemPrompt], $messages);

        $response = '';
        $this->client->chat($allMessages, function($chunk) use (&$response, $handler) {
            $response .= $chunk;
            if ($handler) {
                $handler($chunk);
            }
        });

        return $response;
    }

    public function parseAndExecuteTools(string $content): array {
        $results = [];
        $toolCalls = $this->parseToolCalls($content);

        foreach ($toolCalls as $call) {
            $tool = Tools::find($call['name']);
            if (!$tool) {
                $results[] = [
                    'role' => 'tool',
                    'content' => "Error: tool '{$call['name']}' not found"
                ];
                continue;
            }

            $params = $call['params'] ?? [];
            $result = Tools::run($call['name'], $params);
            $results[] = [
                'role' => 'tool',
                'content' => $result
            ];
        }

        return $results;
    }

    private function parseToolCalls(string $content): array {
        $calls = [];
        $lines = explode("\n", $content);
        $inCall = false;
        $current = null;
        $args = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '<tool_call>') || str_starts_with($trimmed, '```tool_call')) {
                $inCall = true;
                $current = ['name' => '', 'params' => []];
                continue;
            }

            if (str_ends_with($trimmed, '</tool_call>') || str_starts_with($trimmed, '```')) {
                if ($inCall && !empty($current['name'])) {
                    $current['params'] = $this->parseParams($args);
                    $calls[] = $current;
                }
                $inCall = false;
                $current = null;
                $args = '';
                continue;
            }

            if ($inCall && $current !== null) {
                if (str_starts_with($trimmed, 'name:')) {
                    $current['name'] = trim(substr($trimmed, 5));
                } elseif (str_starts_with($trimmed, 'params:') || str_starts_with($trimmed, 'args:')) {
                    $args = trim(substr($trimmed, strlen(str_starts_with($trimmed, 'params:') ? 'params:' : 'args:')));
                } else {
                    $args .= ' ' . $trimmed;
                }
            }
        }

        return $calls;
    }

    private function parseParams(string $argsStr): array {
        $argsStr = trim($argsStr);
        if (empty($argsStr)) {
            return [];
        }

        if (str_starts_with($argsStr, '{') && str_ends_with($argsStr, '}')) {
            return json_decode($argsStr, true) ?? [];
        }

        $params = [];
        $pairs = explode(',', $argsStr);
        foreach ($pairs as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $key = trim($kv[0]);
                $val = trim($kv[1], "\"' ");
                $params[$key] = $val;
            }
        }
        return $params;
    }
}