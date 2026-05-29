
// A small raw-mode line editor that renders a Claude-Code-style bordered input
// box you type *inside*, with the session status (model · mode · cwd) embedded
// in the top border. Pure vanilla PHP: it drives the terminal with `stty` and
// ANSI escapes, no extensions beyond what the CLI already uses.
//
// Supports: printable input (UTF-8), left/right/home/end cursor movement,
// backspace/delete, up/down history, Ctrl-C (cancel line), Ctrl-D (EOF on an
// empty line). Long input scrolls horizontally so the box stays exactly three
// terminal rows and never wraps. Falls back gracefully (caller checks
// supported()) for pipes, daemons, and non-TTY contexts.
class LineEditor {
    public static function supported(): bool {
        // Embedded/simple terminals (e.g. the desktop ADE) can't render raw-mode
        // cursor control cleanly; OLLAMADEV_SIMPLE_INPUT forces plain line input
        // (the host pty echoes keystrokes itself).
        if (getenv('OLLAMADEV_SIMPLE_INPUT')) return false;
        if (stripos(PHP_OS, 'WIN') === 0) return false;
        if (!function_exists('stream_isatty') || !@stream_isatty(STDIN)) return false;
        static $stty = null;
        if ($stty === null) $stty = (bool)@exec('command -v stty 2>/dev/null');
        return $stty;
    }

    private static function termWidth(): int {
        $cols = (int)getenv('COLUMNS');
        if ($cols <= 0) $cols = (int)@exec('tput cols 2>/dev/null');
        if ($cols <= 0) $cols = 80;
        return $cols;
    }

    // Read one logical keypress from raw STDIN. Returns ['type'=>..., 'val'=>...]
    // where type is one of: char, enter, backspace, delete, left, right, home,
    // end, up, down, ctrl-c, eof, ignore.
    private static function readKey(): array {
        $b = fread(STDIN, 1);
        if ($b === '' || $b === false) return ['type' => 'eof'];
        $o = ord($b);
        if ($o === 13 || $o === 10) return ['type' => 'enter'];
        if ($o === 127 || $o === 8) return ['type' => 'backspace'];
        if ($o === 3) return ['type' => 'ctrl-c'];
        if ($o === 4) return ['type' => 'eof'];
        if ($o === 1) return ['type' => 'home'];
        if ($o === 5) return ['type' => 'end'];
        if ($o === 9) return ['type' => 'tab'];
        if ($o === 27) { // escape sequence
            $n = fread(STDIN, 1);
            if ($n === '[' || $n === 'O') {
                $seq = '';
                while (true) {
                    $x = fread(STDIN, 1);
                    if ($x === '' || $x === false) break;
                    $seq .= $x;
                    $c = ord($x);
                    if ($c >= 0x40 && $c <= 0x7e) break; // final byte
                }
                switch ($seq) {
                    case 'A': return ['type' => 'up'];
                    case 'B': return ['type' => 'down'];
                    case 'C': return ['type' => 'right'];
                    case 'D': return ['type' => 'left'];
                    case 'H': case '1~': case '7~': return ['type' => 'home'];
                    case 'F': case '4~': case '8~': return ['type' => 'end'];
                    case '3~': return ['type' => 'delete'];
                }
            }
            return ['type' => 'ignore'];
        }
        if ($o < 32) return ['type' => 'ignore'];
        // UTF-8: pull continuation bytes for a complete character.
        $extra = $o >= 0xF0 ? 3 : ($o >= 0xE0 ? 2 : ($o >= 0xC0 ? 1 : 0));
        $ch = $b;
        for ($i = 0; $i < $extra; $i++) { $x = fread(STDIN, 1); if ($x === '' || $x === false) break; $ch .= $x; }
        return ['type' => 'char', 'val' => $ch];
    }

    // Render a simple single-line prompt ("> <text>"), redrawing in place. Long
    // input scrolls horizontally so it stays on one line and the caret is always
    // visible.
    private static function render(array $glyphs, int $cursor): void {
        $promptLen = 2; // "> "
        $w = max(20, self::termWidth() - $promptLen - 1);
        static $start = 0;
        if ($cursor < $start) $start = $cursor;
        if ($cursor > $start + $w) $start = $cursor - $w;
        if ($start < 0) $start = 0;
        $visible = implode('', array_slice($glyphs, $start, $w));
        echo "\r\033[K\033[36m> \033[0m" . $visible;
        $col = $promptLen + ($cursor - $start);
        echo "\r";
        if ($col > 0) echo "\033[{$col}C";
    }

    // Common leading run of glyphs shared by all candidate strings.
    private static function commonPrefix(array $strs): string {
        if (!$strs) return '';
        $first = self::split($strs[0]);
        $len = count($first);
        foreach ($strs as $s) {
            $g = self::split($s);
            $len = min($len, count($g));
            for ($i = 0; $i < $len; $i++) { if ($g[$i] !== $first[$i]) { $len = $i; break; } }
        }
        return implode('', array_slice($first, 0, $len));
    }

    // Read one line of input inside the box. Returns the string, or null on EOF
    // (Ctrl-D on an empty line). $history is read for up/down recall. $complete,
    // if given, is called as $complete(string $line, int $cursor) and returns
    // ['start' => glyphIndex, 'candidates' => string[]] for Tab-completion.
    public static function readLine(string $model, string $mode, string $cwd, array $history, ?callable $complete = null): ?string {
        $saved = trim((string)@exec('stty -g 2>/dev/null'));
        @exec('stty raw -echo 2>/dev/null');
        $glyphs = [];
        $cursor = 0;
        $hidx = count($history);
        $stash = '';
        $result = null;

        try {
            self::render($glyphs, $cursor);
            while (true) {
                $key = self::readKey();
                switch ($key['type']) {
                    case 'enter':
                        $result = implode('', $glyphs);
                        break 2;
                    case 'eof':
                        if (empty($glyphs)) { $result = null; break 2; }
                        break; // Ctrl-D mid-line: ignore
                    case 'ctrl-c':
                        $result = ''; // cancel this line
                        break 2;
                    case 'char':
                        array_splice($glyphs, $cursor, 0, [$key['val']]);
                        $cursor++;
                        break;
                    case 'backspace':
                        if ($cursor > 0) { array_splice($glyphs, $cursor - 1, 1); $cursor--; }
                        break;
                    case 'delete':
                        if ($cursor < count($glyphs)) array_splice($glyphs, $cursor, 1);
                        break;
                    case 'left':  if ($cursor > 0) $cursor--; break;
                    case 'right': if ($cursor < count($glyphs)) $cursor++; break;
                    case 'home':  $cursor = 0; break;
                    case 'end':   $cursor = count($glyphs); break;
                    case 'tab':
                        if ($complete) {
                            $res = $complete(implode('', $glyphs), $cursor);
                            $cands = $res['candidates'] ?? [];
                            $tstart = max(0, min((int)($res['start'] ?? $cursor), $cursor));
                            $token = implode('', array_slice($glyphs, $tstart, $cursor - $tstart));
                            if (count($cands) === 1) {
                                array_splice($glyphs, $tstart, $cursor - $tstart, self::split($cands[0]));
                                $cursor = $tstart + count(self::split($cands[0]));
                            } elseif (count($cands) > 1) {
                                $common = self::commonPrefix($cands);
                                if (mb_strlen($common) > mb_strlen($token)) {
                                    array_splice($glyphs, $tstart, $cursor - $tstart, self::split($common));
                                    $cursor = $tstart + count(self::split($common));
                                } else {
                                    // No further shared prefix: list the candidates
                                    // on a new line, then redraw the prompt below.
                                    echo "\r\n";
                                    echo "\033[2m" . wordwrap(implode('   ', $cands), max(20, self::termWidth() - 2), "\n", true) . "\033[0m\n";
                                }
                            }
                        }
                        break;
                    case 'up':
                        if ($hidx > 0) {
                            if ($hidx === count($history)) $stash = implode('', $glyphs);
                            $hidx--;
                            $glyphs = self::split($history[$hidx]);
                            $cursor = count($glyphs);
                        }
                        break;
                    case 'down':
                        if ($hidx < count($history)) {
                            $hidx++;
                            $line = $hidx === count($history) ? $stash : $history[$hidx];
                            $glyphs = self::split($line);
                            $cursor = count($glyphs);
                        }
                        break;
                    default: break; // ignore
                }
                self::render($glyphs, $cursor);
            }
        } finally {
            // Move to the next line and restore the terminal.
            echo "\r\n";
            if ($saved !== '') @exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
            else @exec('stty sane 2>/dev/null');
        }
        return $result;
    }

    // Split a string into an array of UTF-8 glyphs.
    private static function split(string $s): array {
        return $s === '' ? [] : preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    }
}
