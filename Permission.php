class Permission {
    private static array $allowed = [];
    private static array $denied = [];
    private static bool $promptMode = false;

    public static function setPromptMode(bool $mode): void {
        self::$promptMode = $mode;
    }

    public static function autoAllow(): void {
        self::$promptMode = false;
    }

    public static function allow(string $tool): void {
        unset(self::$denied[$tool]);
        self::$allowed[$tool] = true;
    }

    public static function deny(string $tool): void {
        unset(self::$allowed[$tool]);
        self::$denied[$tool] = true;
    }

    public static function isAllowed(string $tool): bool {
        if (isset(self::$denied[$tool])) return false;
        if (isset(self::$allowed[$tool])) return true;
        if (!self::$promptMode) return true;
        return false;
    }

    public static function listAllowed(): array {
        return array_keys(self::$allowed);
    }

    public static function listDenied(): array {
        return array_keys(self::$denied);
    }

    public static function prompt(string $tool, string $command): bool {
        if (self::isAllowed($tool)) return true;
        echo "\n⚠️  Permission required for: $tool\n";
        echo "   Command: $command\n";
        echo "   Allow this? (yes/no/permanent): ";
        $input = trim(fgets(STDIN));
        if ($input === 'yes' || $input === 'y') {
            return true;
        } elseif ($input === 'permanent' || $input === 'p') {
            self::allow($tool);
            return true;
        }
        return false;
    }
}