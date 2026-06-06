
// Runs commands inside an existing PTY terminal (the desktop's live shell) by
// appending to its pty-in queue and reading delimited output back from pty-out.
// Used so the agent's bash tool calls execute visibly in the user's terminal.
class PtyBridge {
    private string $dir;
    private string $inFile;
    private string $outFile;
    private int $offset;

    public function __construct(string $id) {
        $home = getenv('HOME') ?: '/tmp';
        $this->dir = "$home/.ollamadev/terminals/$id";
        $this->inFile = "$this->dir/pty-in";
        $this->outFile = "$this->dir/pty-out";
        // Begin reading from the current end so we only capture new output.
        $this->offset = is_file($this->outFile) ? (int)filesize($this->outFile) : 0;
    }

    public function available(): bool { return is_dir($this->dir); }

    // Send $cmd to the shell, wait for it to finish, return its output.
    public function run(string $cmd, int $timeout = 120): string {
        if (!is_dir($this->dir)) return "PTY terminal not available";
        // Capture only this command's output.
        clearstatcache(true, $this->outFile);
        $this->offset = is_file($this->outFile) ? (int)filesize($this->outFile) : 0;
        // Markers are split ("OD"."START") so the shell-echoed input line does
        // not itself contain the literal marker we scan the output for.
        $nonce = substr(md5(uniqid('', true)), 0, 10);
        $start = "OD_START_$nonce";
        $end = "OD_END_$nonce";
        $line = "printf '%s\\n' \"OD\"\"_START_$nonce\"; ( $cmd ); printf '%s%s\\n' \"OD\"\"_END_$nonce\" \"\$?\"\n";
        file_put_contents($this->inFile, $line, FILE_APPEND | LOCK_EX);

        $deadline = time() + $timeout;
        $buf = '';
        while (time() < $deadline) {
            clearstatcache(true, $this->outFile);
            $size = (int)@filesize($this->outFile);
            if ($size > $this->offset) {
                $fh = fopen($this->outFile, 'rb');
                fseek($fh, $this->offset);
                $buf .= (string)fread($fh, $size - $this->offset);
                fclose($fh);
                $this->offset = $size;
                if (strpos($buf, $end) !== false) break;
            }
            usleep(40000);
        }

        // Strip ANSI/control sequences, then slice between the markers.
        $clean = preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]|\x1b\][^\x07]*(?:\x07|\x1b\\\\)/', '', $buf);
        $clean = str_replace("\r", '', $clean);
        $s = strpos($clean, $start);
        $e = strpos($clean, $end);
        if ($s !== false && $e !== false && $e > $s) {
            $out = substr($clean, $s + strlen($start), $e - $s - strlen($start));
            $code = '';
            if (preg_match('/' . preg_quote($end, '/') . '(\d+)/', $clean, $m)) $code = $m[1];
            $out = trim($out, "\n");
            if ($out === '') $out = '(no output)';
            return $code !== '' && $code !== '0' ? $out . "\n[exit $code]" : $out;
        }
        return $e === false ? "(command still running or timed out)" : trim($clean);
    }
}
