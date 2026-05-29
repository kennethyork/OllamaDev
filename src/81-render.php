// Render: a tiny, dependency-free Markdown -> ANSI renderer for model output.
// ANSI escapes only; vanilla PHP. Designed to be SAFE on arbitrary text: any
// line that is not recognized markdown is emitted verbatim, so plain prose and
// code that happens to contain markdown-ish characters is never corrupted.
class Render {
    private const R   = "\033[0m";   // reset
    private const B   = "\033[1m";   // bold
    private const DIM = "\033[2m";   // dim
    private const ITAL= "\033[3m";   // italic
    private const CY  = "\033[36m";  // cyan (headings/markers)
    private const REV = "\033[7m";   // reverse (inline code)
    private const GRN = "\033[32m";  // green (code keywords)

    // Whether styled output should be produced. Off when piped/non-TTY, when
    // NO_COLOR is set, or when disabled via config (ui.markdown=false).
    public static function enabled(): bool {
        if (!Config::get('ui.markdown', true)) return false;
        if (getenv('NO_COLOR') !== false) return false;
        if (function_exists('posix_isatty')) return @posix_isatty(STDOUT);
        return function_exists('stream_isatty') ? @stream_isatty(STDOUT) : false;
    }

    // Render markdown text to an ANSI-styled string. No trailing newline added.
    // Returns the input unchanged on any internal error.
    public static function md(string $text): string {
        try { return self::renderBlocks($text); }
        catch (\Throwable $e) { return $text; }
    }

    private static function renderBlocks(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $out = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];

            // Fenced code block: ``` or ~~~ optionally followed by a language.
            if (preg_match('/^\s*(```|~~~)(.*)$/', $line, $m)) {
                $fence = $m[1];
                $code = [];
                $closed = false;
                for ($j = $i + 1; $j < $n; $j++) {
                    if (preg_match('/^\s*' . preg_quote($fence, '/') . '\s*$/', $lines[$j])) {
                        $closed = true; $i = $j; break;
                    }
                    $code[] = $lines[$j];
                }
                if (!$closed) { $i = $n; } // unterminated: consume rest as code
                $out[] = self::renderCode($code);
                continue;
            }

            // ATX heading: # .. ######
            if (preg_match('/^\s*(#{1,6})\s+(.*)$/', $line, $m)) {
                $out[] = self::CY . self::B . self::inline(rtrim($m[2])) . self::R;
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $out[] = self::DIM . str_repeat('-', 40) . self::R;
                continue;
            }

            // Bullet list: -, *, + then space.
            if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $m)) {
                $out[] = $m[1] . self::CY . "\xe2\x80\xa2 " . self::R . self::inline($m[2]);
                continue;
            }

            // Numbered list: 1. or 2)
            if (preg_match('/^(\s*)(\d+)[.)]\s+(.*)$/', $line, $m)) {
                $out[] = $m[1] . self::CY . $m[2] . '. ' . self::R . self::inline($m[3]);
                continue;
            }

            // Blockquote.
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $out[] = self::DIM . "\xe2\x94\x82 " . self::R . self::inline($m[1]);
                continue;
            }

            // Plain line: inline styling only.
            $out[] = self::inline($line);
        }
        return implode("\n", $out);
    }

    // Fenced code block: dim left bar + very light keyword tint.
    private static function renderCode(array $code): string {
        $bar = self::DIM . "\xe2\x94\x82 " . self::R;
        $kw = '/\b(function|class|return|if|else|elseif|for|foreach|while|public|private|protected|static|const|var|let|def|import|from|new|use|namespace|echo|print|true|false|null|void|int|string|bool|float|array)\b/';
        $lines = [];
        foreach ($code as $c) {
            $hi = preg_replace_callback($kw, fn($m) => self::GRN . $m[1] . self::DIM, $c);
            $lines[] = $bar . self::DIM . $hi . self::R;
        }
        return implode("\n", $lines);
    }

    // Inline spans: `code`, **bold**, __bold__, *italic*, _italic_.
    // Inline code is extracted first and protected so emphasis markers inside
    // it are not reinterpreted. Unmatched markers are left untouched.
    private static function inline(string $s): string {
        $codes = [];
        $s = preg_replace_callback('/`([^`]+)`/', function($m) use (&$codes) {
            $token = "\x00C" . count($codes) . "\x00";
            $codes[$token] = self::REV . ' ' . $m[1] . ' ' . self::R;
            return $token;
        }, $s);

        $s = preg_replace('/\*\*([^*\n]+?)\*\*/', self::B . '$1' . self::R, $s);
        $s = preg_replace('/__([^_\n]+?)__/', self::B . '$1' . self::R, $s);
        $s = preg_replace('/(?<![\*\w])\*([^*\n]+?)\*(?![\*\w])/', self::ITAL . '$1' . self::R, $s);
        $s = preg_replace('/(?<![_\w])_([^_\n]+?)_(?![_\w])/', self::ITAL . '$1' . self::R, $s);

        if (!empty($codes)) $s = strtr($s, $codes);
        return $s;
    }
}
