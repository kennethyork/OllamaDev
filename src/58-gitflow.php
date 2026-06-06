// GITFLOW — close the loop from "wrote code" to "shipped it": AI commit
// messages, opening a PR, and reviewing a PR with the local model. Local git is
// never gated; the PR commands reach GitHub (via `gh`) and are blocked in
// air-gap mode. Vanilla PHP — shells out to git/gh.
class GitFlow {
    public static function sh(string $cmd): string { return trim((string)@shell_exec($cmd)); }
    public static function isRepo(): bool { return self::sh('git rev-parse --is-inside-work-tree 2>/dev/null') === 'true'; }
    public static function gh(): string { return self::sh('command -v gh 2>/dev/null'); }

    private static function model(): string { return (new Agent())->getModel(); }

    // The full working-tree diff for review: tracked changes vs HEAD plus
    // untracked files rendered as all-addition diffs (so new work shows too).
    // Raw unified diff text — the UI parses and colorizes it. Read-only.
    public static function workingDiff(): string {
        if (!self::isRepo()) return '';
        $head = self::sh('git rev-parse --verify HEAD 2>/dev/null');
        $diff = (string)@shell_exec($head !== '' ? 'git --no-pager diff HEAD 2>/dev/null' : 'git --no-pager diff 2>/dev/null');
        $untracked = trim((string)@shell_exec('git ls-files --others --exclude-standard 2>/dev/null'));
        if ($untracked !== '') {
            foreach (explode("\n", $untracked) as $f) {
                $f = trim($f); if ($f === '') continue;
                $diff .= (string)@shell_exec('git --no-pager diff --no-index -- /dev/null ' . escapeshellarg($f) . ' 2>/dev/null');
            }
        }
        return $diff;
    }

    // A Conventional Commit message for a staged diff.
    public static function message(string $diff): string {
        $sys = ['role' => 'system', 'content' =>
            'Write ONE Conventional Commit message for this staged diff. Output ONLY JSON: ' .
            '{"message":"type(scope): summary\n\n- optional bullet\n- optional bullet"}. ' .
            'Subject line <=72 chars, imperative mood, no trailing period. Types: feat, fix, docs, refactor, test, chore, perf, build, ci. Bullets only if they add information.'];
        $j = ModelClient::default()->chatJson(self::model(), [$sys, ['role' => 'user', 'content' => "Diff:\n" . substr($diff, 0, 12000)]]);
        return is_array($j) ? trim((string)($j['message'] ?? '')) : '';
    }

    // A PR title + body from the branch's commits and diff.
    public static function prText(string $commits, string $diff): array {
        $sys = ['role' => 'system', 'content' =>
            'Write a pull-request title and body. Output ONLY JSON: {"title":"...","body":"..."}. ' .
            'Title is a concise imperative summary (<=72 chars). Body is short markdown: a one-line summary, then a "## Changes" bullet list, then "## Testing" if evident. No fluff.'];
        $user = ['role' => 'user', 'content' => "Commits:\n$commits\n\nDiff:\n" . substr($diff, 0, 12000)];
        $j = ModelClient::default()->chatJson(self::model(), [$sys, $user]);
        $title = is_array($j) ? trim((string)($j['title'] ?? '')) : '';
        $body = is_array($j) ? trim((string)($j['body'] ?? '')) : '';
        return [$title ?: 'Update', $body ?: $commits];
    }

    // Review a diff for correctness/security/scope; returns a human-readable report.
    public static function review(string $diff): string {
        $sys = ['role' => 'system', 'content' =>
            'You are a meticulous code reviewer. Review the diff for correctness, security (injection, ' .
            'unsafe shell, secrets), and whether it stays in scope. Output ONLY JSON: ' .
            '{"verdict":"approve|comment|request_changes","summary":"one line","findings":["file:line — issue", ...]}. ' .
            'Be concrete; no nitpicks unless they matter.'];
        $j = ModelClient::default()->chatJson(self::model(), [$sys, ['role' => 'user', 'content' => "Diff:\n" . substr($diff, 0, 14000)]]);
        if (!is_array($j)) return "Review unavailable (could not parse the model response).";
        $verdict = (string)($j['verdict'] ?? 'comment');
        $summary = (string)($j['summary'] ?? '');
        $findings = is_array($j['findings'] ?? null) ? $j['findings'] : [];
        $out = "Verdict: " . strtoupper($verdict) . ($summary !== '' ? " — $summary" : '') . "\n";
        if ($findings) { $out .= "\nFindings:\n"; foreach ($findings as $f) $out .= "  - " . (string)$f . "\n"; }
        else $out .= "\nNo blocking findings.\n";
        return rtrim($out);
    }
}
