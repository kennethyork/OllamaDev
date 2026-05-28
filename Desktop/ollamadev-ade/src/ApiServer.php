<?php

declare(strict_types=1);

namespace OllamaDev;

class ApiServer
{
    private string $host;
    private int $port;
    private string $baseDir;

    public function __construct(string $host = '127.0.0.1', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
        $this->baseDir = dirname(__DIR__);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function start(): void
    {
        $router = new ApiRouter();
        $this->setupRoutes($router);

        $serverUrl = sprintf('http://%s:%d', $this->host, $this->port);

        $server = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if (!$server) {
            if ($errno === 98) {
                return;
            }
            throw new \RuntimeException("Failed to create server: $errstr ($errno)");
        }

        stream_set_timeout($server, 1);

        while (true) {
            $client = @stream_socket_accept($server, 5);
            if ($client) {
                $this->handleRequest($client, $router);
            }
        }
    }

    private function handleRequest($client, ApiRouter $router): void
    {
        $request = '';
        $headers = [];

        while (($line = fgets($client)) !== false) {
            $request .= $line;
            if ($line === "\r\n") {
                break;
            }
        }

        if (preg_match('/^(GET|POST|PUT|DELETE)\s+(\/\S*)\s+HTTP/', $request, $matches)) {
            $method = $matches[1];
            $path = $matches[2];
        } else {
            fclose($client);
            return;
        }

        $body = '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            if (preg_match('/Content-Length:\s*(\d+)/i', $request, $matches)) {
                $length = (int)$matches[1];
                $body = fread($client, $length);
            }
        }

        ob_start();
        try {
            $response = $router->dispatch($method, $path, $body);
        } catch (\Throwable $e) {
            $response = [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['error' => $e->getMessage()]),
            ];
        }
        $output = ob_get_clean();

        if (!is_array($response)) {
            $response = [
                'status' => 200,
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => (string)$response,
            ];
        }

        $statusText = match ((int)($response['status'] ?? 200)) {
            200 => '200 OK',
            201 => '201 Created',
            204 => '204 No Content',
            400 => '400 Bad Request',
            404 => '404 Not Found',
            405 => '405 Method Not Allowed',
            500 => '500 Internal Server Error',
            default => '200 OK',
        };

        $headersStr = "HTTP/1.1 $statusText\r\n";
        foreach ($response['headers'] ?? [] as $name => $value) {
            $headersStr .= "$name: $value\r\n";
        }
        $headersStr .= "Content-Length: " . strlen($response['body'] ?? '') . "\r\n";
        $headersStr .= "Connection: close\r\n";
        $headersStr .= "\r\n";

        fwrite($client, $headersStr . ($response['body'] ?? ''));
        fclose($client);
    }

    public function stop(): void
    {
    }

    private function setupRoutes(ApiRouter $router): void
    {
        $ollama = new OllamaClient();
        $sessions = new SessionManager();
        $memory = new MemoryStore();
        $tasks = new TaskStore();
        $files = new FileBrowser();

        $router->get('/api/ollama/status', function () use ($ollama) {
            $connected = $ollama->checkConnection();
            $models = $connected ? $ollama->listModelsDetailed() : [];
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['connected' => $connected, 'models' => $models]),
            ];
        });

        $router->get('/api/tasks', function () use ($tasks) {
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($tasks->all()),
            ];
        });

        $router->post('/api/tasks', function ($body) use ($tasks) {
            $data = json_decode($body, true) ?? [];
            $id = $tasks->create($data);
            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['id' => $id]),
            ];
        });

        $router->put('/api/tasks/:id', function ($params, $body) use ($tasks) {
            $data = json_decode($body, true) ?? [];
            $tasks->update($params['id'], $data);
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];
        });

        $router->delete('/api/tasks/:id', function ($params) use ($tasks) {
            $tasks->delete($params['id']);
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];
        });

        $router->get('/api/memory', function () use ($memory) {
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($memory->listNotes()),
            ];
        });

        $router->post('/api/memory', function ($body) use ($memory) {
            $data = json_decode($body, true) ?? [];
            $id = $memory->create($data['title'] ?? 'Untitled', $data['content'] ?? '');
            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['id' => $id]),
            ];
        });

        $router->get('/api/memory/:id', function ($params) use ($memory) {
            $note = $memory->get($params['id']);
            if (!$note) {
                return ['status' => 404, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"error":"Not found"}'];
            }
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($note),
            ];
        });

        $router->put('/api/memory/:id', function ($params, $body) use ($memory) {
            $data = json_decode($body, true) ?? [];
            $memory->update($params['id'], $data);
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];
        });

        $router->get('/api/sessions', function () use ($sessions) {
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($sessions->listTerminals()),
            ];
        });

        $router->post('/api/sessions', function ($body) use ($sessions) {
            $data = json_decode($body, true) ?? [];
            $id = $sessions->createTerminal($data['model'] ?? 'llama3.2:latest');
            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['id' => $id]),
            ];
        });

        $router->get('/api/sessions/:id/output', function ($params) use ($sessions) {
            $output = $sessions->getTerminalOutput($params['id']);
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => $output,
            ];
        });

        $router->post('/api/sessions/:id/input', function ($params, $body) use ($sessions) {
            $data = json_decode($body, true) ?? [];
            $sessions->writeToTerminal($params['id'], $data['input'] ?? '');
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];
        });

        $router->delete('/api/sessions/:id', function ($params) use ($sessions) {
            $sessions->killTerminal($params['id']);
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}'];
        });

        $router->get('/api/files', function () use ($files) {
            $path = isset($_GET['path']) ? $_GET['path'] : null;
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($files->listDir($path)),
            ];
        });

        $router->get('/api/files/search', function () use ($files) {
            $q = $_GET['q'] ?? '';
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($files->search($q)),
            ];
        });
    }
}
