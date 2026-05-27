class Tools {
    private static array $tools = [];

    public static function register(string $name, callable $fn): void { self::$tools[$name] = $fn; }
    public static function find(string $name): ?callable { return self::$tools[$name] ?? null; }
    public static function run(string $name, array $params): string {
        $fn = self::find($name);
        if (!$fn) return "Error: tool '$name' not found";
        if (!Permission::isAllowed($name)) return "PERMISSION_DENIED:$name";
        try { return $fn($params); } catch (Exception $e) { return "Error: " . $e->getMessage(); }
    }
    public static function all(): array { return array_keys(self::$tools); }
}