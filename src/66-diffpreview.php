// DiffView: unified diff computation + colorized rendering, and an apply-with-preview
// gate used by the write/edit tools. Vanilla PHP only (no libraries).
class DiffView {
    // Produce a unified-diff string (old vs new). $label is shown in the @@ header.
    public static function unified(string $old, string $new, string $label = ''): string {
        if ($old === $new) return '';
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        $n = count($a); $m = count($b);
        // LCS table.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = ($a[$i] === $b[$j])
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        // Walk to build an edit script of [op, line] where op is ' ', '-', '+'.
        $ops = [];
        $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $ops[] = [' ', $a[$i]]; $i++; $j++; }
            elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) { $ops[] = ['-', $a[$i]]; $i++; }
            else { $ops[] = ['+', $b[$j]]; $j++; }
        }
        while ($i < $n) { $ops[] = ['-', $a[$i]]; $i++; }
        while ($j < $m) { $ops[] = ['+', $b[$j]]; $j++; }

        $head = '--- a' . ($label !== '' ? '/' . $label : '') . "\n";
        $head .= '+++ b' . ($label !== '' ? '/' . $label : '') . "\n";
        $head .= '@@ -1,' . $n . ' +1,' . $m . " @@\n";
        $body = '';
        foreach ($ops as [$op, $line]) {
            $body .= ($op === ' ' ? ' ' : $op) . $line . "\n";
        }
        return $head . $body;
    }

    // Colorize a unified-diff string for terminal output.
    public static function colorize(string $diff): string {
        if ($diff === '') return '';
        $out = '';
        foreach (explode("\n", $diff) as $line) {
            if ($line === '' ) { $out .= "\n"; continue; }
            $c = $line[0];
            if (str_starts_with($line, '@@')) $out .= "\033[36m$line\033[0m\n";
            elseif (str_starts_with($line, '+++') || str_starts_with($line, '---')) $out .= "\033[1m$line\033[0m\n";
            elseif ($c === '+') $out .= "\033[32m$line\033[0m\n";
            elseif ($c === '-') $out .= "\033[31m$line\033[0m\n";
            else $out .= "$line\n";
        }
        return rtrim($out, "\n") . "\n";
    }

    // Show the diff, then decide whether to apply. Returns true if the write should proceed.
    // - Prints the colorized diff in every mode (when there is a change).
    // - Prompts for confirmation ONLY when interactive AND mode is not 'auto'.
    // - Never prompts in non-interactive/daemon runs (Permission::isInteractive() false).
    public static function confirm(string $path, string $old, string $new): bool {
        $diff = self::unified($old, $new, $path);
        if ($diff === '') return true; // no change; nothing to preview, allow no-op write
        echo "\n\033[1mDiff preview for " . $path . "\033[0m\n";
        echo self::colorize($diff);
        $interactive = method_exists('Permission', 'isInteractive') ? Permission::isInteractive() : false;
        $mode = method_exists('Permission', 'getMode') ? Permission::getMode() : 'auto';
        if (!$interactive || $mode === 'auto') return true;
        echo "\n   Apply this change? [y]es / [n]o: ";
        $input = strtolower(trim((string)fgets(STDIN)));
        if ($input === 'y' || $input === 'yes' || $input === '') return true;
        echo "   \033[31m\u{2717} change discarded\033[0m\n";
        return false;
    }
}
