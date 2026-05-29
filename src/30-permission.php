class Permission {
    private static array $allowed = [];   // tools approved for this session
    private static array $denied = [];    // tools blocked for this session
    private static string $mode = 'ask';  // auto | ask | readonly
    private static bool $interactive = false;

    // Tools that only read state - always safe to run without approval.
    private static array $readonly = [
        'view', 'read', 'cat', 'head', 'tail', 'ls', 'list_directory', 'list_files',
        'glob', 'grep', 'find', 'tree', 'stat', 'wc', 'sort', 'uniq', 'diff', 'pwd',
        'changes', 'watch', 'symbols', 'hover', 'goto', 'goto_definition', 'definition',
        'refs', 'find_refs', 'diagnostics', 'git_status', 'git_diff', 'git_log',
        'git_branch', 'git_show', 'git_remote', 'git_fetch', 'mcp_servers', 'calc',
        'print', 'say', 'echo', 'reply', 'ok', 'OK', 'error', 'summarize',
    ];

    public static function setMode(string $mode): void {
        if (in_array($mode, ['auto', 'ask', 'readonly'], true)) self::$mode = $mode;
    }
    public static function getMode(): string { return self::$mode; }
    public static function setInteractive(bool $v): void { self::$interactive = $v; }
    public static function isInteractive(): bool { return self::$interactive; }

    // Legacy compatibility shims.
    public static function autoAllow(): void { self::$mode = 'auto'; }
    public static function setPromptMode(bool $mode): void { self::$mode = $mode ? 'ask' : 'auto'; }

    public static function allow(string $tool): void { unset(self::$denied[$tool]); self::$allowed[$tool] = true; }
    public static function deny(string $tool): void { unset(self::$allowed[$tool]); self::$denied[$tool] = true; }
    public static function isReadonly(string $tool): bool { return in_array($tool, self::$readonly, true); }
    public static function listAllowed(): array { return array_keys(self::$allowed); }
    public static function listDenied(): array { return array_keys(self::$denied); }
    public static function listReadonly(): array { return self::$readonly; }

    // The gate enforced by Tools::run. Returns true if the tool may run.
    public static function check(string $tool, array $params = []): bool {
        if (isset(self::$denied[$tool])) return false;
        if (isset(self::$allowed[$tool])) return true;
        if (self::$mode === 'auto') return true;
        if (self::isReadonly($tool)) return true;     // read-only is always fine
        if (self::$mode === 'readonly') return false;  // block all mutating tools
        // file edits show their own diff-based confirmation (DiffView::confirm),
        // so don't also fire the generic prompt here — that would double-ask.
        if ($tool === 'write' || $tool === 'edit') return true;
        // mode 'ask': prompt when interactive, otherwise allow (one-shot = user-driven).
        if (!self::$interactive) return true;
        return self::prompt($tool, $params);
    }

    private static function prompt(string $tool, array $params): bool {
        $desc = '';
        if (isset($params['command'])) $desc = $params['command'];
        elseif (isset($params['file_path'])) $desc = $params['file_path'];
        elseif (!empty($params)) $desc = json_encode($params);
        echo "\n⚠️  Allow mutating tool '$tool'?";
        if ($desc !== '') echo "\n   → " . substr($desc, 0, 200);
        echo "\n   [y]es once / [a]lways / [n]o: ";
        $input = strtolower(trim((string)fgets(STDIN)));
        if ($input === 'y' || $input === 'yes') return true;
        if ($input === 'a' || $input === 'always') { self::allow($tool); return true; }
        echo "   ✗ denied\n";
        return false;
    }
}

