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