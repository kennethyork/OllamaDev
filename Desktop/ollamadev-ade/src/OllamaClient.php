<?php

declare(strict_types=1);

namespace OllamaDev;

class OllamaClient
{
    private string $host;
    private int $timeout = 120;

    public function __construct(?string $host = null)
    {
        $this->host = $host ?? Config::get('ollama.host', 'http://localhost:11434');
    }

    public function checkConnection(): bool
    {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response) {
            $data = json_decode($response, true);
            return $code === 200 && isset($data['models']);
        }
        return false;
    }

    public function listModelsDetailed(): array
    {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['models'])) {
                return $data['models'];
            }
        }
        return [];
    }

    public function listModels(): array
    {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['models'])) {
                return array_map(fn($m) => $m['name'], $data['models']);
            }
        }
        return [];
    }

    public function chat(array $messages, callable $handler = null): string
    {
        $model = Config::get('ollama.defaultModel', 'llama3.2:latest');
        return $this->chatWithModel($model, $messages, $handler);
    }

    public function chatWithModel(string $model, array $messages, callable $handler = null): string
    {
        $params = ['model' => $model, 'messages' => $messages, 'stream' => false];
        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $j = json_decode($resp, true);
            if ($j && isset($j['message'])) {
                $content = $j['message']['content'] ?? '';
                $thinking = $j['message']['thinking'] ?? '';
                if (empty($content) && !empty($thinking)) {
                    $content = $thinking;
                }
                if (!empty($content) && $handler) {
                    $handler($content);
                }
            }
        }
        return '';
    }
}