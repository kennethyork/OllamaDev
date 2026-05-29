// SUBAGENT / TASK DELEGATION
// Runs a focused sub-task in a fresh nested Agent with its own short message
// list, the parent session's model, full tool access, and bounded iterations.
// Reuses Agent::chatTurn()/executeCalls() (the real agentic loop) and guards
// against runaway recursion with a depth counter.
class SubAgent {
    public static int $depth = 0;        // current nesting level
    public static int $maxDepth = 2;     // hard recursion cap

    // Run one delegated task. Returns a concise result string for the caller.
    public static function run(array $p): string {
        $prompt = trim((string)($p['prompt'] ?? $p['task'] ?? ''));
        if ($prompt === '') return "missing prompt (need 'prompt' or 'task' parameter)";

        if (self::$depth >= self::$maxDepth) {
            return "Sub-agent depth limit reached (max " . self::$maxDepth . "); refusing to nest further. Complete this task directly.";
        }

        $context = trim((string)($p['context'] ?? ''));
        $maxIterations = (int)($p['max_iterations'] ?? $p['iterations'] ?? 6);
        if ($maxIterations < 1) $maxIterations = 1;
        if ($maxIterations > 12) $maxIterations = 12;

        $model = $GLOBALS['currentSessionModel'] ?? Config::get('ollama.defaultModel', 'llama3.2:latest');

        $sub = new Agent();
        if (!empty($model)) {
            $resolved = $sub->resolveModel($model);
            $sub->setModel($resolved ?: $model);
        }

        // Permission policy: a sub-agent runs read-only BY DEFAULT so an
        // auto-mode parent can't let it silently write files or run shell. The
        // caller can opt in to mutations (allow_writes / permission:auto), but a
        // sub-agent is never MORE permissive than its parent.
        $parentMode = Permission::getMode();
        $parentInteractive = Permission::isInteractive();
        $subMode = Config::get('agents.subagentPermission', 'readonly');
        $req = strtolower(trim((string)($p['permission'] ?? '')));
        if (!empty($p['allow_writes']) || $req === 'auto') $subMode = 'auto';
        elseif (in_array($req, ['ask', 'readonly', 'auto'], true)) $subMode = $req;
        if ($parentMode === 'readonly') $subMode = 'readonly';            // can't escalate past parent
        if ($parentMode === 'ask' && $subMode === 'auto') $subMode = 'ask';

        $userContent = "Task: $prompt";
        if ($context !== '') $userContent .= "\n\nContext:\n$context";
        $userContent .= "\n\nWork through this using tools as needed, then give a concise final answer in plain text.";
        $messages = [['role' => 'user', 'content' => $userContent]];

        $lastText = '';
        $toolTail = [];

        self::$depth++;
        // Sub-agents can't field approval prompts, so run non-interactively under
        // the chosen (cautious) mode, then restore the parent's policy.
        Permission::setMode($subMode);
        Permission::setInteractive(false);
        try {
            for ($i = 0; $i < $maxIterations; $i++) {
                $turn = $sub->chatTurn($messages);
                $content = (string)($turn['content'] ?? '');
                $calls = $turn['calls'] ?? [];

                $assistant = ['role' => 'assistant', 'content' => $content];
                $messages[] = $assistant;

                if (empty($calls)) {
                    $clean = $sub->stripToolMarkup($content);
                    if ($clean !== '') $lastText = $clean;
                    break; // no more actions: the sub-agent is done
                }

                $results = $sub->executeCalls($calls);
                foreach ($results as $r) {
                    $messages[] = $r;
                    $toolTail[] = '[' . ($r['name'] ?? 'tool') . '] ' . substr((string)($r['content'] ?? ''), 0, 200);
                }
            }
        } catch (\Throwable $e) {
            return 'Sub-agent error: ' . $e->getMessage();
        } finally {
            self::$depth--;
            Permission::setMode($parentMode);          // restore parent policy
            Permission::setInteractive($parentInteractive);
        }

        if ($lastText !== '') return $lastText;
        if (!empty($toolTail)) {
            return "Sub-agent did not produce a final summary. Tool activity:\n" . implode("\n", array_slice($toolTail, -8));
        }
        return 'Sub-agent produced no output.';
    }
}

Tools::register('task', function($p) { return SubAgent::run(is_array($p) ? $p : ['prompt' => (string)$p]); });
Tools::register('subagent', function($p) { return SubAgent::run(is_array($p) ? $p : ['prompt' => (string)$p]); });
Tools::register('delegate', function($p) { return SubAgent::run(is_array($p) ? $p : ['prompt' => (string)$p]); });
