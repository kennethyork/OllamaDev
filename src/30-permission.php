class Permission {
    private static array $allowed = [];   // tools approved for this session
    private static array $denied = [];    // tools blocked for this session
    private static string $mode = 'ask';  // auto | ask | readonly | plan
    private static string $planReturn = 'ask'; // mode restored when plan mode is approved
    private static array $toolAllow = []; // when non-empty, ONLY these tools may run (hard gate)
    private static bool $interactive = false;
    private static bool $offline = false; // air-gapped: hard-block all network tools

    // Tools that reach the network. Blocked outright in offline mode, regardless
    // of permission mode — the air-gap guarantee cannot be waived by 'auto' or by
    // a prior allow(). (git_fetch is read-only but still hits the network.)
    private static array $network = [
        'fetch', 'search', 'web_search', 'web_fetch',
        'git_fetch', 'git_push', 'git_pull', 'git_clone',
    ];

    // Tools that only read state - always safe to run without approval.
    private static array $readonly = [
        'view', 'read', 'cat', 'head', 'tail', 'ls', 'list_directory', 'list_files',
        'glob', 'grep', 'find', 'tree', 'stat', 'wc', 'sort', 'uniq', 'diff', 'pwd',
        'changes', 'watch', 'symbols', 'hover', 'goto', 'goto_definition', 'definition',
        'refs', 'find_refs', 'diagnostics', 'git_status', 'git_diff', 'git_log',
        'git_branch', 'git_show', 'git_remote', 'git_fetch', 'mcp_servers', 'calc',
        'print', 'say', 'echo', 'reply', 'ok', 'OK', 'error', 'summarize', 'skill', 'recall',
        'exit_plan_mode',
    ];

    public static function setMode(string $mode): void {
        if (in_array($mode, ['auto', 'ask', 'readonly', 'plan'], true)) {
            // Entering plan mode remembers the mode to come back to on approval.
            if ($mode === 'plan' && self::$mode !== 'plan') self::$planReturn = self::$mode;
            self::$mode = $mode;
        }
    }
    public static function getMode(): string { return self::$mode; }
    // Hard tool allowlist (a custom agent type's `tools:` field). When set, any tool
    // outside the list is blocked at the gate — not just discouraged in the prompt.
    public static function setToolAllowlist(array $tools): void { self::$toolAllow = array_values(array_filter(array_map('strval', $tools), fn($t) => $t !== '')); }
    public static function toolAllowlist(): array { return self::$toolAllow; }
    public static function clearToolAllowlist(): void { self::$toolAllow = []; }
    public static function inPlanMode(): bool { return self::$mode === 'plan'; }
    // Approve the plan: leave plan mode, restoring whatever mode preceded it.
    public static function exitPlan(): string { self::$mode = self::$planReturn; return self::$mode; }
    public static function setInteractive(bool $v): void { self::$interactive = $v; }
    public static function isInteractive(): bool { return self::$interactive; }
    public static function setOffline(bool $v): void { self::$offline = $v; }
    public static function isOffline(): bool { return self::$offline; }
    public static function isNetwork(string $tool): bool { return in_array($tool, self::$network, true); }
    public static function listNetwork(): array { return self::$network; }

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
        if (self::$offline && self::isNetwork($tool)) return false; // air-gap: nothing leaves the machine
        // Hard allowlist (custom agent's tools:): a MUTATING tool off the list is
        // blocked. Always-safe read-only tools (view/grep/ls…) and control tools
        // (exit_plan_mode) stay available — confinement limits what the agent can
        // CHANGE, not what it can read, so a `tools: edit` agent can still inspect
        // files and leave plan mode.
        if (!empty(self::$toolAllow) && !in_array($tool, self::$toolAllow, true) && !self::isReadonly($tool)) return false;
        // Plan mode: research only. Read-only tools run; everything that mutates is
        // blocked (even a prior allow()) until the user approves via exit_plan_mode.
        if (self::$mode === 'plan') return $tool === 'exit_plan_mode' || self::isReadonly($tool);
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
        if (class_exists('Hooks')) Hooks::event('Notification', ['_subject' => $tool, 'tool' => $tool, 'message' => "approval needed for '$tool'"]);
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

