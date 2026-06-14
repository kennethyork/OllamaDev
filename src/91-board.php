<?php
// ---------------------------------------------------------------------------
// Board — the unified pending-decisions queue.
//
// Three kinds of things land here:
//   permission   — a mutating tool wants to run; the agent blocks until you decide
//   crew_branch  — a coder's worktree is ready to merge; you accept, deny, or
//                  always-allow this branch for the repo
//   checkpoint   — the agent just took a checkpoint; accept to keep going, deny
//                  to roll back to the prior state
//
// All four UIs (desktop Board tab, desktop floating panel, CLI /board, web
// board.html) read the same $HOME/.ollamadev/board/ files, so the model is
// intentionally simple:
//
//   decisions.jsonl   append-only log of every enqueue + decide (audit trail)
//   current.json      { pending: [...], recent: [last 20] } — what pollers show
//   locks/<id>.lock   per-decision flock so the agent thread wakes exactly once
//
// The agent thread that called Board::enqueue() blocks in Board::waitFor() on
// the lock file. The deciding UI writes the verdict (atomically) then unlinks
// the lock. The waiter polls the lock's existence — when it disappears, it
// reads the verdict from current.json and returns. No sockets, no DB, no
// extensions. Vanilla PHP only.
//
// Permission integration (see 30-permission.php): when the Board is "attached"
// (started by the ADE, or the CLI --board flag), Permission::check() replaces
// its interactive fgets() prompt with a Board card. When unattached, the
// existing fgets() prompt runs unchanged — no behavior change for plain CLI.
// ---------------------------------------------------------------------------

class Board {
    public const KINDS = ['permission', 'crew_branch', 'checkpoint'];
    public const VERDICTS = ['accept', 'deny', 'always', 'skip'];

    // User has to opt in: ADE sets this on start, --board flag sets it on CLI.
    // Untouched = plain fgets() prompt as before.
    private static bool $attached = false;
    // Default = 0 (hard block forever). Set to N for N-second hard-block then
    // auto-deny (useful for unattended web sessions with a self-imposed limit).
    private static int $defaultTimeout = 0;
    // In-memory subscriber callbacks (SSE/poll fanout for the floating panel
    // and web board). Populated by Board::subscribe(); invoked on every state
    // change so UIs re-render in real time.
    private static array $subscribers = [];

    // ---- public: attach/detach ----------------------------------------------

    public static function attach(int $defaultTimeoutSec = 0): void {
        self::$attached = true;
        self::$defaultTimeout = $defaultTimeoutSec;
        @mkdir(self::dir(), 0755, true);
        @mkdir(self::dir() . '/locks', 0755, true);
    }
    public static function detach(): void { self::$attached = false; }
    public static function isAttached(): bool { return self::$attached; }
    public static function defaultTimeout(): int { return self::$defaultTimeout; }

    // ---- public: where things live ------------------------------------------

    public static function dir(): string {
        $h = getenv('HOME') ?: sys_get_temp_dir();
        return $h . '/.ollamadev/board';
    }
    public static function logFile(): string { return self::dir() . '/decisions.jsonl'; }
    public static function indexFile(): string { return self::dir() . '/current.json'; }
    public static function lockFile(string $id): string { return self::dir() . '/locks/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $id) . '.lock'; }

    // ---- public: enqueue -----------------------------------------------------

    /**
     * Post a pending decision. $kind is one of self::KINDS. $summary is the
     * short headline shown in lists (e.g. "shell: rm -rf build/"). $detail is
     * the longer body (diff, command, audit verdict, …) the UI renders when
     * expanded. Returns the decision id (uuid-ish).
     *
     * $opts lets callers attach structured context (e.g. tool name, branch
     * name, run id) so the UI can show icons + route the right action.
     */
    public static function enqueue(string $kind, string $summary, string $detail = '', array $opts = []): string {
        if (!in_array($kind, self::KINDS, true)) $kind = 'permission';
        $id = self::newId($kind);
        $record = [
            'id' => $id,
            'kind' => $kind,
            'summary' => $summary,
            'detail' => $detail,
            'opts' => $opts,
            'status' => 'pending',
            'verdict' => null,
            'note' => null,
            'created_at' => date('c'),
            'decided_at' => null,
        ];
        // Append to the log FIRST (source of truth). Use LOCK_EX so two
        // enqueues from parallel coders can't truncate each other.
        $log = self::logFile();
        @mkdir(dirname($log), 0755, true);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $fh = @fopen($log, 'ab');
        if ($fh) { @flock($fh, LOCK_EX); @fwrite($fh, $line); @flock($fh, LOCK_UN); @fclose($fh); }
        // Create the lock file the waiter sleeps on.
        @mkdir(self::dir() . '/locks', 0755, true);
        @file_put_contents(self::lockFile($id), $id);
        self::rebuildIndex();
        self::fanout();
        return $id;
    }

    // ---- public: wait for a decision ----------------------------------------

    /**
     * Block until $id is decided or the timeout fires.
     *   return ['verdict' => 'accept'|'deny'|'always'|'skip'|'timeout',
     *           'note'   => string|null];
     * When the verdict is 'accept'/'always' the caller proceeds; 'deny'/'skip'
     * the caller aborts; 'timeout' the caller should treat like 'deny'.
     *
     * Implementation: poll the lock file every 250ms. Cheap (a stat() per
     * tick); no IPC sockets; works on every PHP build.
     */
    public static function waitFor(string $id, int $timeoutSec = 0): array {
        $lock = self::lockFile($id);
        $deadline = $timeoutSec > 0 ? (microtime(true) + $timeoutSec) : null;
        $pollUs = 250_000; // 250ms — responsive without busy-looping
        $lastNotify = 0;
        // Emit a one-shot "waiting" event so the UI can show the spinner.
        if ((microtime(true) - $lastNotify) > 0.5) { self::fanout(); }
        while (true) {
            if (!is_file($lock)) break; // decided (lock was unlinked by decide())
            if ($deadline !== null && microtime(true) >= $deadline) {
                return ['verdict' => 'timeout', 'note' => null];
            }
            usleep($pollUs);
        }
        // Lock is gone — find the verdict in the log.
        $rec = self::findRecord($id);
        if (!$rec) return ['verdict' => 'timeout', 'note' => null];
        return ['verdict' => $rec['verdict'] ?? 'deny', 'note' => $rec['note'] ?? null];
    }

    // ---- public: record a decision ------------------------------------------

    /**
     * Record a verdict. Called by every UI (desktop tab, floating panel, CLI,
     * web) — they all just need to know the id and the verdict. Optional
     * $note is shown in the audit trail (e.g. user typed a one-liner reason).
     *
     * Side effects:
     *   - append a 'decide' line to the log
     *   - unlink the lock file (this is what wakes the waiter)
     *   - rebuild the current.json index
     *   - fan out to subscribers
     */
    public static function decide(string $id, string $verdict, ?string $note = null): bool {
        $verdict = strtolower(trim($verdict));
        if (!in_array($verdict, self::VERDICTS, true)) $verdict = 'deny';
        $rec = self::findRecord($id);
        if (!$rec) return false;
        if (($rec['status'] ?? '') === 'decided') return false; // idempotent
        $event = [
            'id' => $id,
            'event' => 'decide',
            'verdict' => $verdict,
            'note' => $note,
            'decided_at' => date('c'),
        ];
        $log = self::logFile();
        $fh = @fopen($log, 'ab');
        if ($fh) { @flock($fh, LOCK_EX); @fwrite($fh, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n"); @flock($fh, LOCK_UN); @fclose($fh); }
        @unlink(self::lockFile($id));
        self::rebuildIndex();
        self::fanout();
        return true;
    }

    // ---- public: list / read for UIs ----------------------------------------

    /** @return array{pending: array, recent: array} */
    public static function list(): array {
        $idx = self::indexFile();
        if (!is_file($idx)) return ['pending' => [], 'recent' => []];
        $raw = (string) @file_get_contents($idx);
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['pending' => [], 'recent' => []];
        return [
            'pending' => is_array($data['pending'] ?? null) ? $data['pending'] : [],
            'recent' => is_array($data['recent'] ?? null) ? $data['recent'] : [],
        ];
    }

    /**
     * Subscribe to live updates. $cb is called with the full list() payload
     * after every enqueue/decide. Returns an opaque handle for unsubscribe.
     * Used by the desktop floating panel + the web SSE endpoint.
     */
    public static function subscribe(callable $cb): int {
        self::$subscribers[] = $cb;
        return array_key_last(self::$subscribers);
    }
    public static function unsubscribe(int $handle): void {
        if (isset(self::$subscribers[$handle])) unset(self::$subscribers[$handle]);
    }
    private static function fanout(): void {
        if (empty(self::$subscribers)) return;
        $payload = self::list();
        foreach (self::$subscribers as $cb) { try { $cb($payload); } catch (\Throwable $e) { /* never let a UI crash the engine */ } }
    }

    // ---- public: integration with Permission --------------------------------

    /**
     * The one-line call Permission::check() makes when Board is attached.
     * $tool/$params describe the mutating call; the user sees a card and the
     * agent blocks until they answer. Returns true if the tool may proceed.
     */
    public static function askPermission(string $tool, array $params): bool {
        if (!self::$attached) return true; // unattached = old behavior (caller decides)
        $summary = self::summarizeTool($tool, $params);
        $detail = self::detailTool($tool, $params);
        $id = self::enqueue('permission', $summary, $detail, ['tool' => $tool, 'params' => $params]);
        $result = self::waitFor($id, self::$defaultTimeout);
        $v = $result['verdict'];
        if ($v === 'accept') return true;
        if ($v === 'always' && class_exists('Permission')) { Permission::allow($tool); return true; }
        // deny / skip / timeout = block the call
        return false;
    }

    // ---- internal: helpers ---------------------------------------------------

    private static function newId(string $kind): string {
        // 8 chars of time (hex µs) + 6 of randomness = 14 chars, plenty unique
        // for a per-user pending queue; not a real uuid but cheap and sortable.
        return substr($kind, 0, 4) . '_' . sprintf('%08x', (int)(microtime(true) * 1000)) . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    private static function rebuildIndex(): void {
        $log = self::logFile();
        if (!is_file($log)) { atomicWrite(self::indexFile(), json_encode(['pending' => [], 'recent' => []])); return; }
        $pending = [];   // id => record (status=pending, no verdict)
        $recent = [];    // last 20 decided, newest first
        $fh = @fopen($log, 'rb');
        if (!$fh) return;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $obj = json_decode($line, true);
            if (!is_array($obj) || empty($obj['id'])) continue;
            if (($obj['event'] ?? '') === 'decide') {
                $id = $obj['id'];
                if (isset($pending[$id])) {
                    $pending[$id]['status'] = 'decided';
                    $pending[$id]['verdict'] = $obj['verdict'] ?? 'deny';
                    $pending[$id]['note'] = $obj['note'] ?? null;
                    $pending[$id]['decided_at'] = $obj['decided_at'] ?? null;
                    array_unshift($recent, $pending[$id]);
                    unset($pending[$id]);
                } else {
                    // Decision arrived for something we no longer have pending
                    // (e.g. log rotated). Still want it in the recent feed.
                    array_unshift($recent, [
                        'id' => $id, 'kind' => '?', 'summary' => '(unknown)', 'detail' => '',
                        'opts' => [], 'status' => 'decided', 'verdict' => $obj['verdict'] ?? 'deny',
                        'note' => $obj['note'] ?? null, 'created_at' => null, 'decided_at' => $obj['decided_at'] ?? null,
                    ]);
                }
            } else {
                $pending[$obj['id']] = $obj;
            }
        }
        @fclose($fh);
        if (count($recent) > 20) $recent = array_slice($recent, 0, 20);
        // Sort pending oldest-first (so the UI numbers them 1..N in arrival order).
        usort($pending, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
        $payload = ['pending' => array_values($pending), 'recent' => $recent, 'ts' => time()];
        atomicWrite(self::indexFile(), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function findRecord(string $id): ?array {
        $log = self::logFile();
        if (!is_file($log)) return null;
        $fh = @fopen($log, 'rb');
        if (!$fh) return null;
        $found = null;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $obj = json_decode($line, true);
            if (!is_array($obj) || ($obj['id'] ?? null) !== $id) continue;
            if (($obj['event'] ?? '') === 'decide') {
                if ($found) {
                    $found['status'] = 'decided';
                    $found['verdict'] = $obj['verdict'] ?? 'deny';
                    $found['note'] = $obj['note'] ?? null;
                    $found['decided_at'] = $obj['decided_at'] ?? null;
                }
            } else { $found = $obj; }
        }
        @fclose($fh);
        return $found;
    }

    private static function summarizeTool(string $tool, array $params): string {
        if ($tool === 'shell' || $tool === 'bash' || $tool === 'exec') {
            $cmd = (string)($params['command'] ?? '');
            return $tool . ': ' . ($cmd !== '' ? $cmd : '(empty)');
        }
        if ($tool === 'write' || $tool === 'edit' || $tool === 'create') {
            $p = (string)($params['file_path'] ?? $params['path'] ?? '');
            return $tool . ': ' . ($p !== '' ? $p : '(no path)');
        }
        if ($tool === 'web_search' || $tool === 'web_fetch' || $tool === 'search' || $tool === 'fetch') {
            $q = (string)($params['query'] ?? $params['url'] ?? '');
            return $tool . ': ' . ($q !== '' ? $q : '(no query)');
        }
        if ($tool === 'git_push' || $tool === 'git_pull' || $tool === 'git_clone' || $tool === 'git_fetch') {
            $r = (string)($params['remote'] ?? '');
            return $tool . ($r !== '' ? ' ' . $r : '');
        }
        return $tool;
    }

    private static function detailTool(string $tool, array $params): string {
        // Pretty JSON so the UI can both <pre>-show it and pick out fields.
        return json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
