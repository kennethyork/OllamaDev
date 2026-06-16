// VERIFY — make the agent test-aware. Detects the project's test command, runs
// it, and (with the fix loop) feeds failures back to the agent until the suite
// goes green. Turns "makes edits" into "makes edits that pass". Vanilla PHP.
class Verify {
    // Figure out how to run this project's tests. Returns ['cmd','label'] or null.
    // Override anything with config `test.command`.
    public static function detect(): ?array {
        $override = trim((string)Config::get('test.command', ''));
        if ($override !== '') return ['cmd' => $override, 'label' => 'config'];
        $cwd = getcwd();
        $has = fn($f) => file_exists($cwd . '/' . $f);
        // NOTE: no JS/node test runner — OllamaDev is node-free by design. Point a JS
        // project's tests at a non-node command via `config set test.command "<cmd>"`.
        if ($has('phpunit.xml') || $has('phpunit.xml.dist')) {
            return ['cmd' => $has('vendor/bin/phpunit') ? './vendor/bin/phpunit' : 'phpunit', 'label' => 'phpunit'];
        }
        if ($has('composer.json')) {
            $cj = json_decode((string)@file_get_contents($cwd . '/composer.json'), true);
            if (!empty($cj['scripts']['test'])) return ['cmd' => 'composer test', 'label' => 'composer'];
        }
        if ($has('go.mod')) return ['cmd' => 'go test ./...', 'label' => 'go'];
        if ($has('Cargo.toml')) return ['cmd' => 'cargo test', 'label' => 'cargo'];
        if ($has('pytest.ini') || $has('tox.ini') || $has('pyproject.toml') || $has('setup.cfg') || is_dir($cwd . '/tests')) {
            return ['cmd' => 'pytest -q', 'label' => 'pytest'];
        }
        if ($has('Makefile') || $has('makefile')) {
            $mk = (string)@file_get_contents($cwd . '/' . ($has('Makefile') ? 'Makefile' : 'makefile'));
            if (preg_match('/^test:/m', $mk)) return ['cmd' => 'make test', 'label' => 'make'];
        }
        return null;
    }

    // Run the command in the cwd; capture combined output + exit code.
    public static function run(array $det): array {
        $out = []; $code = 0;
        exec('( ' . $det['cmd'] . ' ) 2>&1', $out, $code);
        return ['cmd' => $det['cmd'], 'label' => $det['label'], 'exit' => (int)$code, 'output' => implode("\n", $out)];
    }

    // Run → if failing and allowed, ask the agent to fix → re-run, up to $max
    // rounds. Returns the process exit code (0 = green).
    public static function fixLoop(array $det, int $max): int {
        $g = "\033[32m"; $y = "\033[33m"; $r = "\033[0m"; $d = "\033[2m";
        $agent = new Agent();
        if (!$agent->checkConnection()) { echo "\033[31mCannot reach Ollama.\033[0m Start it with: ollama serve\n"; return 1; }
        $oldMode = Permission::getMode(); $oldInt = Permission::isInteractive();
        Permission::setMode('auto'); Permission::setInteractive(false);
        try {
            for ($i = 1; $i <= $max; $i++) {
                $res = self::run($det);
                if ($res['exit'] === 0) { echo "\n{$g}✓ tests pass" . ($i > 1 ? " after " . ($i - 1) . " fix attempt(s)" : "") . "{$r}  {$d}[{$det['cmd']}]{$r}\n"; return 0; }
                echo "\n{$y}✗ attempt {$i}/{$max}: failing{$r} {$d}[{$det['cmd']}]{$r}\n";
                if ($i === $max) break;
                $tail = implode("\n", array_slice(explode("\n", $res['output']), -120));
                echo "{$d}  asking the agent to fix…{$r} ";
                self::fixOnce($agent, $det['cmd'], $tail);
            }
            echo "\n{$y}Still failing after {$max} attempt(s).{$r} Review the changes before committing.\n";
            return 1;
        } finally {
            Permission::setMode($oldMode); Permission::setInteractive($oldInt);
        }
    }

    // One bounded fix turn: hand the agent the failure and let it edit until it
    // stops calling tools (capped). Uses the same tools as a normal session.
    private static function fixOnce(Agent $agent, string $cmd, string $failure): void {
        $sys = ['role' => 'system', 'content' =>
            "You are fixing FAILING TESTS. Read the failure output, find the root cause with your tools " .
            "(view, grep, code_search), and EDIT the code so `$cmd` passes. Make minimal, correct changes. " .
            "Do NOT weaken, skip, or delete tests just to make them pass. Actually call your tools to make the edits — " .
            "do not merely describe them. Stop when you believe the fix is complete."];
        $messages = [$sys, ['role' => 'user', 'content' => "Test command: $cmd\n\nFailure output (tail):\n$failure"]];
        for ($k = 0; $k < 12; $k++) {
            echo "\033[2m·\033[0m"; @flush();
            $turn = $agent->chatTurn($messages);
            $calls = $turn['calls'] ?? [];
            $messages[] = ['role' => 'assistant', 'content' => (string)($turn['content'] ?? '')];
            if (empty($calls)) break;
            foreach ($agent->executeCalls($calls) as $rr) {
                $messages[] = ['role' => 'tool', 'content' => (string)($rr['content'] ?? ''), 'tool_name' => $rr['name'] ?? 'tool'];
            }
        }
        echo "\n";
    }
}
