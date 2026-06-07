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

// MCP SERVER — expose THIS CLI's tool registry to any MCP client over stdio.
// Speaks newline-delimited JSON-RPC 2.0 (the MCP stdio transport): initialize,
// tools/list, tools/call, ping. Lets editors/agents that speak MCP drive
// OllamaDev's tools. `ollamadev mcp serve`. Vanilla PHP, no dependencies.
class McpServer {
    public static function serve(bool $allowWrites = false): int {
        // SECURITY: an MCP client is a remote caller. Default to READ-ONLY so a
        // connected client can't run bash/write/rm on the user's machine; mutations
        // require an explicit opt-in (`mcp serve --allow-writes` or mcp.allowWrites).
        // Non-interactive either way (calls can't block on an approval prompt); the
        // air-gap (offline) flag still hard-blocks network tools.
        $allowWrites = $allowWrites || (Config::get('mcp.allowWrites', false) === true);
        Permission::setMode($allowWrites ? 'auto' : 'readonly');
        Permission::setInteractive(false);
        if (Config::get('network.offline', false)) Permission::setOffline(true);
        $in = fopen('php://stdin', 'r');
        if (!$in) return 1;
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $msg = json_decode($line, true);
            if (!is_array($msg)) continue;
            $resp = self::handle($msg);
            if ($resp !== null) { echo json_encode($resp) . "\n"; @flush(); }
        }
        return 0;
    }

    private static function handle(array $msg): ?array {
        $id = $msg['id'] ?? null;
        $method = (string)($msg['method'] ?? '');
        if ($id === null && str_starts_with($method, 'notifications/')) return null; // fire-and-forget
        switch ($method) {
            case 'initialize':
                return self::ok($id, [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'ollamadev', 'version' => defined('OLLAMADEV_VERSION') ? OLLAMADEV_VERSION : '0'],
                ]);
            case 'ping':
                return self::ok($id, new stdClass());
            case 'tools/list':
                return self::ok($id, ['tools' => self::toolList()]);
            case 'tools/call':
                $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
                $name = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                if (!Tools::find($name)) return self::err($id, -32602, "Unknown tool: $name");
                // Tools may echo (diff previews etc.) — capture so stdout stays pure JSON-RPC.
                ob_start();
                $result = Tools::run($name, $args);
                $echoed = trim((string) ob_get_clean());
                $text = trim((string) $result);
                if ($echoed !== '') $text = trim($echoed . ($text !== '' ? "\n" . $text : ''));
                // Surface tool failures as isError:true so a client can tell a blocked/
                // failed tool from a real answer (permission denied, tool failed, etc.).
                $isErr = class_exists('CmdError') && CmdError::isError($text);
                return self::ok($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isErr]);
            default:
                return $id !== null ? self::err($id, -32601, "Method not found: $method") : null;
        }
    }

    // Native function-call schemas → MCP tool descriptors (name/description/inputSchema).
    private static function toolList(): array {
        $out = [];
        foreach (Tools::schemas() as $s) {
            $f = $s['function'] ?? null;
            if (!is_array($f) || empty($f['name'])) continue;
            $out[] = ['name' => $f['name'], 'description' => (string)($f['description'] ?? ''),
                'inputSchema' => $f['parameters'] ?? ['type' => 'object', 'properties' => new stdClass()]];
        }
        return $out;
    }

    private static function ok($id, $result): array { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; }
    private static function err($id, int $code, string $message): array { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]; }
}

