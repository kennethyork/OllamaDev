class MCPClient {
    private ?string $command;
    private string $type;
    private string $url;
    private array $headers;

    public function __construct(array $config) {
        $this->command = $config['command'] ?? null;
        $this->type = $config['type'] ?? 'stdio';
        $this->url = $config['url'] ?? '';
        $this->headers = $config['headers'] ?? [];
    }

    public function listTools(): array {
        if ($this->type === 'sse' && !empty($this->url)) {
            $ch = curl_init($this->url . '/tools');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers)]);
            $resp = curl_exec($ch);
            curl_close($ch);
            return json_decode($resp, true) ?? [];
        }
        return [];
    }

    public function callTool(string $name, array $args): string {
        if ($this->type === 'sse' && !empty($this->url)) {
            $ch = curl_init($this->url . '/rpc');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['method' => 'tools/call', 'params' => ['name' => $name, 'input' => $args]]),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers)),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true) ?? [];
            if (isset($data['content'][0]['text'])) return $data['content'][0]['text'];
            return $resp;
        }
        return "MCP tool call not supported for type: {$this->type}";
    }
}