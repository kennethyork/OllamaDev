// @FILE MENTIONS: expand @path tokens in a user message by inlining the
// referenced file's contents. Pure/offline; safe to call on every input.
class Mentions {
    // Max bytes of any single file to inline before truncating.
    const MAX_BYTES = 20000;

    // Returns $text unchanged when it contains no @mentions; otherwise returns
    // the original text followed by a block per mentioned file.
    public static function expand(string $text): string {
        $paths = self::extract($text);
        if (empty($paths)) return $text;

        $blocks = '';
        $seen = [];
        foreach ($paths as $path) {
            if (isset($seen[$path])) continue; // de-dupe repeated mentions
            $seen[$path] = true;
            $blocks .= self::renderFile($path);
        }
        if ($blocks === '') return $text;
        return rtrim($text) . "\n" . $blocks;
    }

    // Find @tokens that look like file paths. Accepts letters, digits and the
    // usual path punctuation; stops at whitespace. Skips bare "@" and emails
    // (a token immediately preceded by a word char, e.g. user@host).
    public static function extract(string $text): array {
        $out = [];
        if (!preg_match_all('/(?<![\w@])@([^\s@]+)/u', $text, $m)) return $out;
        foreach ($m[1] as $tok) {
            // Strip trailing punctuation that usually isn't part of a path.
            $tok = rtrim($tok, '.,;:!?)\'"');
            if ($tok === '') continue;
            $out[] = $tok;
        }
        return $out;
    }

    private static function renderFile(string $path): string {
        if (is_dir($path)) {
            return "\n[@$path is a directory — not inlined]\n";
        }
        if (!is_file($path) || !is_readable($path)) {
            return "\n[@$path not found]\n";
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return "\n[@$path could not be read]\n";
        }
        $note = '';
        if (strlen($data) > self::MAX_BYTES) {
            $cut = strlen($data) - self::MAX_BYTES;
            $data = substr($data, 0, self::MAX_BYTES);
            $note = "\n…[truncated $cut bytes]";
        }
        return "\n--- contents of $path ---\n" . $data . $note . "\n--- end of $path ---\n";
    }
}
