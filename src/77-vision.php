// Vision input helper: parse image attachments out of a user prompt and
// base64-encode them for Ollama's multimodal /api/chat message shape
// (the 'images' array on a user message). Degrades gracefully: on a
// non-vision model Ollama just ignores the images, and the prompt text
// (with the @path left as a readable filename) still goes through.
class Vision {
    // Extensions we treat as attachable images.
    private static array $exts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];

    // Does this path look like a readable image file? Checks extension and,
    // when possible, the leading magic bytes so a mislabelled file is skipped.
    public static function isImage(string $path): bool {
        if ($path === '' || !is_file($path) || !is_readable($path)) return false;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::$exts, true)) return false;
        $fh = @fopen($path, 'rb');
        if ($fh === false) return false;
        $head = (string)fread($fh, 12);
        fclose($fh);
        if ($head === '') return false;
        // Magic-byte sniff for the common formats; fall back to trusting the ext.
        if (str_starts_with($head, "\x89PNG")) return true;            // PNG
        if (str_starts_with($head, "\xFF\xD8\xFF")) return true;        // JPEG
        if (str_starts_with($head, 'GIF8')) return true;                  // GIF
        if (str_starts_with($head, 'BM')) return true;                    // BMP
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') return true; // WEBP
        return true; // recognised extension, unrecognised header: still attach
    }

    // Base64-encode an image file, or '' on failure.
    public static function encode(string $path): string {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') return '';
        return base64_encode($data);
    }

    // Parse a raw user prompt for image attachments. Recognises:
    //   - a leading '/image <path>' command (the rest of the line is the prompt)
    //   - one or more '@<path>' tokens anywhere in the text
    // Returns ['text' => cleaned prompt, 'images' => [base64, ...], 'paths' => [...]].
    // The '@<path>' token is replaced in the visible text by the bare filename
    // so the prompt still reads naturally if the model can't see the image.
    public static function extract(string $input): array {
        $images = [];
        $paths = [];
        $text = $input;

        // '/image <path> [rest]' form.
        if (preg_match('/^\/image\s+(\S+)\s*(.*)$/s', trim($input), $m)) {
            $p = self::resolvePath($m[1]);
            if (self::isImage($p)) {
                $b64 = self::encode($p);
                if ($b64 !== '') { $images[] = $b64; $paths[] = $p; }
            }
            $text = trim($m[2]);
            if ($text === '') $text = 'Describe this image.';
            return ['text' => $text, 'images' => $images, 'paths' => $paths];
        }

        // '@<path>' tokens. Capture a non-space run after @.
        $text = preg_replace_callback('/(?<![\w@])@(\S+)/', function($mm) use (&$images, &$paths) {
            $p = self::resolvePath($mm[1]);
            if (self::isImage($p)) {
                $b64 = self::encode($p);
                if ($b64 !== '') { $images[] = $b64; $paths[] = $p; }
                return basename($p);
            }
            return $mm[0]; // not an image: leave the token untouched
        }, $input);

        return ['text' => trim($text), 'images' => $images, 'paths' => $paths];
    }

    private static function unquote(string $s): string {
        $s = trim($s);
        if (strlen($s) >= 2) {
            $f = $s[0]; $l = $s[strlen($s) - 1];
            if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) $s = substr($s, 1, -1);
        }
        return $s;
    }

    // Strip quotes, then expand a leading ~ to the home dir so '/image ~/pic.png'
    // and '@~/pic.png' resolve. Relative paths are left for is_file() to resolve
    // against the current working directory.
    private static function resolvePath(string $s): string {
        $p = self::unquote($s);
        if ($p !== '' && $p[0] === '~') {
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
            if ($home !== '') $p = rtrim($home, '/\\') . substr($p, 1);
        }
        return $p;
    }
}