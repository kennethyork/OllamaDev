// Thinking: stream a model's reasoning live in a small fixed-height "thinking
// box", then COLLAPSE the box into a one-line summary ("💭 thought for 4s") the
// moment the answer starts — so the reasoning is watchable (Ctrl-C if it's going
// wrong) but the finished transcript stays clean and the answer sits on its own
// line.
//
// Why a bounded box instead of streaming the whole chain-of-thought: a real
// terminal can only erase rows that are still on-screen — once reasoning scrolls
// past the top, cursor-up can't reach it, so a full-height block can't be folded
// away. A thinking model easily out-reasons the visible screen, so we instead
// keep only the last few wrapped lines pinned in place (older lines scroll out of
// the box). The box is always a handful of rows tall, so the collapse ALWAYS
// succeeds — in a real terminal and in the embedded ADE web terminal alike.
//
// The box is hard-wrapped at the terminal width so one emitted line is exactly
// one physical row in a real tty AND one logical <div> in the ADE line renderer;
// the cursor-up row count is then correct in both. (The ADE line renderer gained
// matching cursor-up + erase-to-end-of-display support; see app.js.)
//
// Pure vanilla PHP, ANSI escapes only.
class Thinking {
    /** @var callable(string):void where the bytes go (echo, or an $emit shim). */
    private $sink;
    private int $cols;            // hard-wrap width (one shy of the real width)
    private int $window;          // max rows the live box occupies
    private array $buf = [];      // completed wrapped lines (kept to the last $window)
    private string $cur = '';     // the in-progress (newest) line
    private int $col = 0;         // visible column of $cur
    private int $drawn = 0;       // rows currently painted on screen
    private bool $shown = false;  // any reasoning streamed yet?
    private bool $done = false;   // collapse already emitted? (idempotent)
    private float $t0;
    private bool $control;        // may we use cursor control (true tty/ADE)?
    private string $summaryPrefix;

    // $opt: sink-independent options.
    //   cols          override the wrap width (defaults to the terminal width - 1)
    //   window        rows the live thinking box may occupy (default 6)
    //   control       false ⇒ piped/non-tty: stream dimmed but never move the cursor
    //   summaryPrefix printed before "💭 thought for …" on the collapsed line
    public function __construct(callable $sink, array $opt = []) {
        $this->sink = $sink;
        $this->cols = isset($opt['cols']) && (int)$opt['cols'] > 0 ? (int)$opt['cols'] : self::width();
        $win = (int)($opt['window'] ?? 6);
        // Never let the box exceed the screen, or its own collapse couldn't reach
        // the top row. Clamp to a safe slice of the viewport.
        $this->window = max(1, min($win, self::height() - 2));
        $this->control = array_key_exists('control', $opt) ? (bool)$opt['control'] : true;
        $this->summaryPrefix = (string)($opt['summaryPrefix'] ?? '');
        $this->t0 = microtime(true);
    }

    // Terminal width, minus a 1-column margin so a wrapped line never brushes the
    // real terminal's own soft-wrap edge (which would split it into 2 physical
    // rows and throw the box's row count off by one).
    public static function width(): int {
        $c = (int)getenv('COLUMNS');
        if ($c <= 0) $c = (int)@exec('tput cols 2>/dev/null');
        if ($c <= 0) $c = 80;
        return max(20, $c - 1);
    }

    public static function height(): int {
        $r = (int)getenv('LINES');
        if ($r <= 0) $r = (int)@exec('tput lines 2>/dev/null');
        if ($r <= 0) $r = 24;
        return max(8, $r);
    }

    // Visible width of $s (ANSI stripped; each codepoint 1 col, emoji 2).
    public static function visWidth(string $s): int {
        $s = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $s);
        $w = 0; $n = strlen($s);
        for ($i = 0; $i < $n; ) {
            $o = ord($s[$i]);
            if ($o < 0x80) { $len = 1; $cp = $o; }
            elseif ($o >= 0xF0) { $len = 4; $cp = $o & 0x07; }
            elseif ($o >= 0xE0) { $len = 3; $cp = $o & 0x0F; }
            elseif ($o >= 0xC0) { $len = 2; $cp = $o & 0x1F; }
            else { $len = 1; $cp = $o; }
            for ($k = 1; $k < $len && $i + $k < $n; $k++) $cp = ($cp << 6) | (ord($s[$i + $k]) & 0x3F);
            $i += $len;
            $w += ($cp >= 0x1100 && ($cp >= 0x1F000 || $cp >= 0x2190 && $cp <= 0x2bff || $cp >= 0x3000 && $cp <= 0x9fff)) ? 2 : 1;
        }
        return $w;
    }

    // Stream a chunk of reasoning into the box. Folds the text into the wrapped
    // line buffer, then repaints the (bounded) box in place.
    public function push(string $t): void {
        if ($t === '') return;
        $this->shown = true;
        if (!$this->control) { ($this->sink)("\033[2m" . $t . "\033[0m"); return; }
        $n = strlen($t);
        for ($i = 0; $i < $n; ) {
            $o = ord($t[$i]);
            $len = $o >= 0xF0 ? 4 : ($o >= 0xE0 ? 3 : ($o >= 0xC0 ? 2 : 1));
            $ch = substr($t, $i, $len); $i += $len;
            if ($ch === "\r") continue;                 // ignore stray carriage returns
            if ($ch === "\n") { $this->commit(); continue; }
            if ($ch === "\t") $ch = ' ';                // a tab counts as one column here
            if ($this->col >= $this->cols) $this->commit();   // hard wrap at the box width
            $this->cur .= $ch; $this->col++;
        }
        $this->repaint();
    }

    // Finish the current line and push it into the rolling buffer (oldest line
    // scrolls out of the box once we exceed $window).
    private function commit(): void {
        $this->buf[] = $this->cur;
        if (count($this->buf) > $this->window) array_shift($this->buf);
        $this->cur = ''; $this->col = 0;
    }

    // Repaint the box in place: climb back to its first row, erase it, and reprint
    // the last $window lines.
    private function repaint(): void {
        $vis = $this->buf;
        if ($this->cur !== '' || empty($vis)) $vis[] = $this->cur;   // include the in-progress line
        $vis = array_slice($vis, -$this->window);
        $seq = $this->rewind();
        $body = [];
        foreach ($vis as $l) $body[] = "\033[2m" . $l . "\033[0m";
        $seq .= implode("\n", $body);
        $this->drawn = count($vis);
        ($this->sink)($seq);
    }

    // Cursor sequence to return to the top-left of the currently-painted box and
    // erase it (everything from there down).
    private function rewind(): string {
        if ($this->drawn <= 0) return '';
        $up = $this->drawn - 1;
        return "\r" . ($up > 0 ? "\033[{$up}A" : '') . "\033[J";
    }

    // Collapse the box into the one-line summary. Idempotent: a second call (e.g. a
    // post-turn finalize after the answer path already collapsed) does nothing.
    // No-op when nothing was streamed — the caller handles that case.
    public function collapse(): void {
        if ($this->done || !$this->shown) { $this->done = true; return; }
        $this->done = true;
        $label = "\033[2m💭 thought for " . self::dur(microtime(true) - $this->t0) . "\033[0m";
        if (!$this->control) { ($this->sink)("\n"); return; }   // piped: just break to a new line
        // Erase the box and replace it with the summary. The next write lands on a
        // fresh line below it.
        ($this->sink)($this->rewind() . $this->summaryPrefix . $label . "\n");
    }

    public function shown(): bool { return $this->shown; }
    public function done(): bool { return $this->done; }

    public static function dur(float $s): string {
        if ($s < 1)  return round($s, 1) . 's';
        if ($s < 60) return round($s) . 's';
        $m = (int)floor($s / 60); $r = (int)round($s - $m * 60);
        return $m . 'm' . ($r ? " {$r}s" : '');
    }
}
