// SECSCAN — a dependency-free secret + unsafe-pattern scanner. Catches hardcoded
// credentials (API keys, tokens, private keys, passwords) and a few high-confidence
// dangerous sinks BEFORE they land — in a commit, or in a crew coder's branch. Pure
// vanilla PHP (regex only, no services). The crew Auditor uses it as a hard gate so
// secret-bearing branches never auto-merge; the CLI `scan` command runs it on demand.
class SecScan {
    // Each rule: id, human label, severity (high|med), and a precise regex. Kept tight
    // to favor precision (few false positives) over recall — a noisy scanner gets ignored.
    public static function rules(): array {
        return [
            ['aws-akid',     'AWS access key id',        'high', '/\bAKIA[0-9A-Z]{16}\b/'],
            ['aws-secret',   'AWS secret access key',    'high', '/\baws_secret_access_key\b\s*[=:]\s*[\'"][A-Za-z0-9\/+]{40}[\'"]/i'],
            ['gcp-key',      'Google API key',           'high', '/\bAIza[0-9A-Za-z\-_]{35}\b/'],
            ['github-token', 'GitHub token',             'high', '/\b(ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36}\b|\bgithub_pat_[A-Za-z0-9_]{82}\b/'],
            ['slack-token',  'Slack token',              'high', '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/'],
            ['stripe-key',   'Stripe secret key',        'high', '/\bsk_live_[A-Za-z0-9]{16,}\b/'],
            ['openai-key',   'OpenAI API key',           'high', '/\bsk-[A-Za-z0-9]{20}T3BlbkFJ[A-Za-z0-9]{20}\b|\bsk-proj-[A-Za-z0-9_-]{20,}\b/'],
            ['private-key',  'Private key block',        'high', '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/'],
            ['jwt',          'JSON Web Token',           'med',  '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/'],
            ['basic-auth',   'Credentials in a URL',     'high', '/\b[a-z][a-z0-9+.\-]*:\/\/[^\/\s:@]+:[^\/\s:@]+@/i'],
            ['generic-secret','Hardcoded secret/password','med', '/\b(?:api[_-]?key|secret|token|passwd|password|access[_-]?key|private[_-]?key)\b\s*[=:]\s*[\'"][^\'"\s]{8,}[\'"]/i'],
            ['php-eval',     'Dynamic eval of a variable','med', '/\beval\s*\(\s*\$/'],
            ['shell-var',    'Shell exec of a variable', 'med',  '/\b(?:shell_exec|exec|system|passthru|popen|proc_open)\s*\(\s*[^\'")]*\$/'],
        ];
    }

    // Lines that are clearly placeholders/examples — never a real leak. Avoids flagging
    // docs, env templates, and obvious dummies, which would train users to ignore findings.
    private static function isPlaceholder(string $line): bool {
        return (bool)preg_match('/\b(example|placeholder|dummy|your[_-]?(key|token|secret)|xxx+|<[^>]+>|changeme|redacted|\*{4,}|\.\.\.\.)/i', $line);
    }

    // Redact a matched secret so the scanner never echoes the full credential.
    private static function redact(string $s): string {
        $s = trim($s);
        if (strlen($s) <= 10) return str_repeat('•', strlen($s));
        return substr($s, 0, 4) . str_repeat('•', 6) . substr($s, -4);
    }

    // Scan raw text. $file is for reporting only. Returns a list of findings
    // ['file','line','id','label','severity','match'(redacted)].
    public static function scanText(string $text, string $file = ''): array {
        $rules = self::rules();
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $i => $line) {
            if ($line === '' || self::isPlaceholder($line)) continue;
            foreach ($rules as $r) {
                if (preg_match($r[3], $line, $m)) {
                    $out[] = ['file' => $file, 'line' => $i + 1, 'id' => $r[0],
                        'label' => $r[1], 'severity' => $r[2], 'match' => self::redact($m[0])];
                }
            }
        }
        return $out;
    }

    // Scan a unified diff: only ADDED lines (start with a single '+'), so we flag what a
    // change INTRODUCES, not pre-existing code. Tracks the +++ file headers for reporting.
    public static function scanDiff(string $diff): array {
        $out = []; $file = ''; $ln = 0;
        foreach (preg_split('/\r\n|\r|\n/', $diff) as $line) {
            if (strncmp($line, '+++ ', 4) === 0) { $file = preg_replace('#^\+\+\+ (b/)?#', '', $line); continue; }
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $m)) { $ln = (int)$m[1] - 1; continue; }
            if ($line !== '' && $line[0] === '+' && strncmp($line, '+++', 3) !== 0) {
                $ln++;
                foreach (self::scanText(substr($line, 1), $file) as $f) { $f['line'] = $ln; $out[] = $f; }
            } elseif ($line === '' || $line[0] === ' ') { $ln++; }
        }
        return $out;
    }

    // Scan specific files on disk (skips binaries + very large files).
    public static function scanFiles(array $paths): array {
        $out = [];
        foreach ($paths as $p) {
            if (!is_file($p) || filesize($p) > 2_000_000) continue;
            $data = (string)@file_get_contents($p);
            if ($data === '' || strpos(substr($data, 0, 8000), "\0") !== false) continue; // skip binary
            foreach (self::scanText($data, $p) as $f) $out[] = $f;
        }
        return $out;
    }

    // Convenience for the crew/commit gate: scan a repo's pending changes (staged+unstaged
    // diff against HEAD), or a given ref-range diff. Returns findings.
    public static function scanGit(string $root = '', string $range = ''): array {
        $g = 'git' . ($root !== '' ? ' -C ' . escapeshellarg($root) : '') . ' ';
        $diff = (string)@shell_exec($g . 'diff ' . ($range !== '' ? escapeshellarg($range) . ' ' : 'HEAD ') . '2>/dev/null');
        return self::scanDiff($diff);
    }
}
