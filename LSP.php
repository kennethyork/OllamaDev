class LSP {
    private static array $clients = [];

    public static function load(array $config): void {
        $servers = $config['lsp'] ?? [];
        foreach ($servers as $name => $cfg) {
            if (($cfg['disabled'] ?? false) || empty($cfg['command'])) continue;
            self::$clients[$name] = new LSPClient($cfg['command'], $cfg['args'] ?? []);
        }
    }

    public static function diagnostics(string $filePath): array {
        foreach (self::$clients as $client) {
            $result = $client->diagnostics($filePath);
            if (!empty($result)) return $result;
        }
        return [];
    }

    public static function hover(string $filePath, int $line, int $col): ?string {
        foreach (self::$clients as $client) {
            $result = $client->hover($filePath, $line, $col);
            if ($result) return $result;
        }
        return null;
    }

    public static function gotoDefinition(string $filePath, int $line, int $col): ?array {
        foreach (self::$clients as $client) {
            $result = $client->gotoDefinition($filePath, $line, $col);
            if ($result) return $result;
        }
        return null;
    }

    public static function documentSymbols(string $filePath): array {
        foreach (self::$clients as $client) {
            $result = $client->documentSymbols($filePath);
            if (!empty($result)) return $result;
        }
        return [];
    }
}