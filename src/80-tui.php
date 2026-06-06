class TUI {
    const CLEAR = "\033[2J";
    const HOME = "\033[H";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";
    const DIM = "\033[2m";
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";

    private int $width = 80;
    private int $height = 24;

    public function __construct() {
        if (function_exists('exec')) {
            $size = [];
            exec('stty size 2>/dev/null', $size);
            if (count($size) === 2) {
                $this->height = (int)$size[0];
                $this->width = (int)$size[1];
            }
        }
    }

    public function clear(): void { echo self::CLEAR . self::HOME; }
    public function move(int $row, int $col): void { echo "\033[{$row};{$col}H"; }
    public function reset(): void { echo self::RESET; }

    public function write(string $text, ?string $color = null, bool $bold = false): void {
        if ($color) echo $color;
        if ($bold) echo self::BOLD;
        echo $text;
        if ($bold || $color) echo self::RESET;
    }

    public function writeAt(int $row, int $col, string $text, ?string $color = null): void {
        $this->move($row, $col);
        $this->write($text, $color);
    }

    public function clearLine(int $row): void {
        $this->move($row, 1);
        echo "\033[2K";
    }

    public function clearLines(int $start, int $end): void {
        for ($i = $start; $i <= $end; $i++) {
            $this->clearLine($i);
        }
    }

    public function box(int $top, int $left, int $height, int $width, ?string $title = null): void {
        $this->move($top, $left);
        echo '+' . str_repeat('─', $width - 2) . '+';
        for ($i = 1; $i < $height - 1; $i++) {
            $this->move($top + $i, $left);
            echo '│' . str_repeat(' ', $width - 2) . '│';
        }
        $this->move($top + $height - 1, $left);
        echo '+' . str_repeat('─', $width - 2) . '+';
        if ($title) {
            $this->move($top, $left + 2);
            echo " $title ";
        }
    }

    public function hline(int $row, int $left, int $width): void {
        $this->move($row, $left);
        echo str_repeat('─', $width);
    }

    public function statusBar(string $left, string $right, int $row = 0): void {
        $row = $row ?: $this->height;
        $this->move($row, 1);
        echo "\033[7m"; // reverse
        $padLen = $this->width - strlen($right);
        echo str_pad($left, $padLen);
        echo $right;
        echo self::RESET;
    }

    public function input(string $prompt, ?int $row = null): string {
        $row = $row ?: $this->height;
        $this->move($row, 1);
        echo "\033[7m$prompt\033[0m ";
        $input = '';
        while (true) {
            $c = $this->getChar();
            if ($c === "\n" || $c === "\r" || ord($c) === 13) {
                echo "\n";
                break;
            } elseif (ord($c) === 127 || ord($c) === 8) {
                if (strlen($input) > 0) {
                    $input = substr($input, 0, -1);
                    echo "\033[1D \033[1D";
                }
            } elseif (ord($c) >= 32) {
                $input .= $c;
                echo $c;
            }
        }
        return $input;
    }

    public function getChar(): string {
        $fp = fopen('/dev/tty', 'r');
        stream_set_blocking($fp, false);
        $c = fgetc($fp);
        fclose($fp);
        return $c ?? '';
    }

    public function keyPress(int $timeout = 0): ?string {
        if ($timeout > 0) {
            $fp = fopen('/dev/tty', 'r');
            stream_set_blocking($fp, false);
            usleep($timeout * 1000);
            $c = fgetc($fp);
            fclose($fp);
            return $c ?: null;
        }
        return $this->getChar();
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function renderMessages(array $messages, int $top = 2, int $bottom = 3): void {
        $maxLines = $this->height - $bottom - $top;
        $line = $top;

        foreach ($messages as $msg) {
            if ($line >= $this->height - $bottom) break;

            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            $icon = match($role) { 'user' => '👤', 'assistant' => '🤖', 'tool' => '🔧', default => '•' };
            $color = match($role) { 'user' => self::CYAN, 'assistant' => self::GREEN, 'tool' => self::YELLOW, default => self::DIM };

            $this->clearLine($line);
            $this->write(" $icon ", self::BOLD . $color);
            $this->write("[{$role}]", $color);
            $line++;

            foreach (explode("\n", $content) as $l) {
                if ($line >= $this->height - $bottom) break;
                $this->clearLine($line);
                $this->move($line++, 4);
                echo substr($l, 0, $this->width - 5);
            }
            if ($line < $this->height - $bottom) {
                $this->clearLine($line++);
            }
        }

        while ($line < $this->height - $bottom) {
            $this->clearLine($line++);
        }
    }

    public function renderModelList(array $models, string $current, int $top = 5, int $left = 10, int $height = 12, int $width = 50): void {
        $this->box($top, $left, $height, $width, "Models (Esc to close)");
        $row = $top + 2;
        foreach ($models as $i => $m) {
            $name = $m['name'] ?? 'unknown';
            $size = isset($m['size']) ? $this->formatBytes($m['size']) : '';
            $selected = $name === $current ? ' ◀' : '';
            $this->clearLine($row);
            $this->move($row++, $left + 3);
            $this->write(sprintf("%-25s %10s%s", $name, $size, $selected), $selected ? self::GREEN : self::DIM);
        }
    }

    public function renderSessionList(array $sessions, int $top = 5, int $left = 10, int $height = 12, int $width = 50): void {
        $this->box($top, $left, $height, $width, "Sessions (Enter to select, Esc close)");
        $row = $top + 2;
        foreach ($sessions as $s) {
            $title = substr($s['title'] ?? 'Untitled', 0, $width - 10);
            $this->clearLine($row);
            $this->move($row++, $left + 3);
            $this->write($title, self::DIM);
        }
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1024) . ' KB';
    }
}

