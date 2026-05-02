<?php

class OllamaClient {
    private string $host;
    private int $timeout;

    public function __construct(?string $host = null) {
        $this->host = $host ?? Config::get('ollama.host', 'http://localhost:11434');
        $this->timeout = 120;
    }

    public function checkConnection(): bool {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $code === 200 && isset($data['models']);
        }
        return false;
    }

    public function listModels(): array {
        $ch = curl_init($this->host . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
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

    public function chat(array $messages, callable $handler = null): string {
        $model = Config::get('ollama.defaultModel', 'codellama');
        $params = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true
        ];

        $ch = curl_init($this->host . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => function($curl, $data) use ($handler) {
                $lines = explode("\n", trim($data));
                foreach ($lines as $line) {
                    if (empty($line)) continue;
                    $resp = json_decode($line, true);
                    if ($resp && isset($resp['message']['content'])) {
                        $content = $resp['message']['content'];
                        if ($handler) {
                            $handler($content);
                        }
                    }
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        curl_exec($ch);
        curl_close($ch);

        return '';
    }

    public function generate(string $prompt, callable $handler = null): string {
        $model = Config::get('ollama.defaultModel', 'codellama');
        $params = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => true
        ];

        $ch = curl_init($this->host . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => function($curl, $data) use ($handler) {
                $lines = explode("\n", trim($data));
                foreach ($lines as $line) {
                    if (empty($line)) continue;
                    $resp = json_decode($line, true);
                    if ($resp && isset($resp['response'])) {
                        $content = $resp['response'];
                        if ($handler) {
                            $handler($content);
                        }
                    }
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout
        ]);

        curl_exec($ch);
        curl_close($ch);

        return '';
    }
}