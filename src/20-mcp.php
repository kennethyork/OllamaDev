class MCPClient {
    private ?string $command;
    private array $cmdArgs;
    private string $type;
    private string $url;
    private array $headers;
    private array $env;
    private $proc = null;
    private array $pipes = [];
    private int $rpcId = 0;
    private bool $initialized = false;

    public function __construct(array $config) {
        $this->command = $config['command'] ?? null;
        $this->cmdArgs = $config['args'] ?? [];
        $this->type = $config['type'] ?? ($this->command ? 'stdio' : 'sse');
        $this->url = $config['url'] ?? '';
        $this->headers = $config['headers'] ?? [];
        $this->env = $config['env'] ?? [];
    }

    // ---- stdio JSON-RPC transport ----
    private function startProcess(): bool {
        if (is_resource($this->proc)) return true;
        if (empty($this->command)) return false;
        $cmd = $this->command;
        foreach ($this->cmdArgs as $a) $cmd .= ' ' . escapeshellarg((string)$a);
        $env = $this->env ? array_merge(getenv() ?: [], $this->env) : null;
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $this->proc = @proc_open($cmd, $descriptors, $this->pipes, null, $env);
        if (!is_resource($this->proc)) { $this->proc = null; return false; }
        stream_set_blocking($this->pipes[1], false);
        return true;
    }

    private function writeMessage(array $msg): void {
        $body = json_encode($msg);
        fwrite($this->pipes[0], "Content-Length: " . strlen($body) . "\r\n\r\n" . $body);
        fflush($this->pipes[0]);
    }

    // Read one Content-Length-framed JSON-RPC message.
    private function readMessage(int $timeout = 20): ?array {
        $stream = $this->pipes[1];
        $deadline = time() + $timeout;
        $headers = '';
        while (strpos($headers, "\r\n\r\n") === false) {
            if (time() > $deadline) return null;
            $c = fread($stream, 1);
            if ($c === '' || $c === false) { usleep(5000); continue; }
            $headers .= $c;
        }
        if (!preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) return null;
        $len = (int)$m[1];
        $body = '';
        while (strlen($body) < $len) {
            if (time() > $deadline) return null;
            $chunk = fread($stream, $len - strlen($body));
            if ($chunk === '' || $chunk === false) { usleep(5000); continue; }
            $body .= $chunk;
        }
        return json_decode($body, true);
    }

    private function rpc(string $method, array $params, bool $notification = false): ?array {
        if (!$this->startProcess()) return null;
        $msg = ['jsonrpc' => '2.0', 'method' => $method];
        if (!empty($params)) $msg['params'] = $params;
        if (!$notification) $msg['id'] = ++$this->rpcId;
        $this->writeMessage($msg);
        if ($notification) return null;
        $target = $this->rpcId;
        for ($i = 0; $i < 100; $i++) {
            $resp = $this->readMessage();
            if ($resp === null) return null;
            if (isset($resp['id']) && $resp['id'] == $target) return $resp; // skip server notifications
        }
        return null;
    }

    private function ensureInitialized(): bool {
        if ($this->initialized) return true;
        if ($this->type !== 'stdio') return true;
        $resp = $this->rpc('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => (object)[]],
            'clientInfo' => ['name' => 'ollamadev', 'version' => OLLAMADEV_VERSION],
        ]);
        if ($resp === null) return false;
        $this->rpc('notifications/initialized', [], true);
        $this->initialized = true;
        return true;
    }

    private function curlHeaders(): array {
        return array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), array_values($this->headers));
    }

    private function extractText($result): string {
        if (is_string($result)) return $result;
        if (isset($result['content']) && is_array($result['content'])) {
            $texts = [];
            foreach ($result['content'] as $c) {
                if (isset($c['text'])) $texts[] = $c['text'];
            }
            if (!empty($texts)) return implode("\n", $texts);
        }
        return json_encode($result);
    }

    public function listTools(): array {
        if ($this->type === 'stdio') {
            if (!$this->ensureInitialized()) return [];
            $resp = $this->rpc('tools/list', []);
            return $resp['result']['tools'] ?? [];
        }
        if (!empty($this->url)) {
            $ch = curl_init(rtrim($this->url, '/') . '/tools');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $this->curlHeaders()]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$resp, true);
            return $data['tools'] ?? (is_array($data) ? $data : []);
        }
        return [];
    }

    public function callTool(string $name, array $args): string {
        if ($this->type === 'stdio') {
            if (!$this->ensureInitialized()) return "MCP: failed to start server '{$this->command}'";
            $resp = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object)$args]);
            if ($resp === null) return "MCP: no response from server";
            if (isset($resp['error'])) return "MCP error: " . ($resp['error']['message'] ?? json_encode($resp['error']));
            return $this->extractText($resp['result'] ?? []);
        }
        if (!empty($this->url)) {
            $ch = curl_init(rtrim($this->url, '/') . '/rpc');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $this->curlHeaders()),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$resp, true) ?? [];
            return $this->extractText($data['result'] ?? $data);
        }
        return "MCP tool call not supported for type: {$this->type}";
    }

    public function __destruct() {
        if (is_resource($this->proc)) {
            foreach ($this->pipes as $p) { if (is_resource($p)) fclose($p); }
            @proc_terminate($this->proc);
            @proc_close($this->proc);
        }
    }
}

class MCP {
    private static array $servers = [];
    private static array $tools = [];

    public static function load(array $config): void {
        $servers = $config['mcpServers'] ?? [];
        foreach ($servers as $name => $cfg) {
            if (($cfg['disabled'] ?? false)) continue;
            $client = new MCPClient($cfg);
            self::$servers[$name] = $client;
            $tools = $client->listTools();
            foreach ($tools as $tool) {
                self::$tools[$name . '/' . ($tool['name'] ?? '')] = ['name' => $name, 'tool' => $tool['name'] ?? ''];
            }
        }
    }

    public static function listTools(): array {
        $result = [];
        foreach (self::$tools as $key => $info) {
            $result[] = $key;
        }
        return $result;
    }

    public static function call(string $name, array $args): string {
        if (!isset(self::$tools[$name])) return "Tool not found: $name";
        $info = self::$tools[$name];
        $server = self::$servers[$info['name']] ?? null;
        if (!$server) return "Server not found: {$info['name']}";
        return $server->callTool($info['tool'], $args);
    }
}

