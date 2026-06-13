// Checkpoint / undo of file mutations. Before a write/edit changes a file we
// snapshot the prior contents into Config::checkpointsDir() as a small JSON
// record. /undo restores the most recent snapshot; /checkpoints lists them.
// Vanilla PHP only (file_get_contents/file_put_contents/unlink).
class Checkpoints {
    private static function dir(): string {
        $dir = Config::checkpointsDir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    // Snapshot the current state of $path before it is mutated. Records whether
    // the file already existed so undo can delete a newly-created file.
    public static function save(string $path): void {
        if ($path === '') return;
        $existed = is_file($path);
        $prior = $existed ? (string)@file_get_contents($path) : '';
        $record = [
            'path' => $path,
            'existed' => $existed,
            'content' => $prior,
            'created_at' => date('c'),
        ];
        $name = 'ckpt_' . sprintf('%012d', (int)(microtime(true) * 1000)) . '_' . substr(md5($path . mt_rand()), 0, 6) . '.json';
        atomicWrite(self::dir() . '/' . $name, json_encode($record, JSON_PRETTY_PRINT));
    }

    // Newest-first list of checkpoint files with decoded metadata.
    public static function list(): array {
        $dir = self::dir();
        $files = glob($dir . '/ckpt_*.json') ?: [];
        rsort($files); // names are zero-padded ms timestamps, so lexical desc == newest first
        $out = [];
        foreach ($files as $f) {
            $data = json_decode((string)@file_get_contents($f), true);
            if (!is_array($data)) continue;
            $out[] = [
                'file' => $f,
                'path' => $data['path'] ?? '?',
                'existed' => (bool)($data['existed'] ?? true),
                'created_at' => $data['created_at'] ?? '',
            ];
        }
        return $out;
    }

    // Restore the most recent checkpoint and remove it, so repeated calls walk
    // back through edit history. Returns a human-readable status string.
    public static function undoLast(): string {
        $list = self::list();
        if (empty($list)) return "Nothing to undo.\n";
        $top = $list[0];
        $record = json_decode((string)@file_get_contents($top['file']), true);
        if (!is_array($record)) { @unlink($top['file']); return "Skipped a corrupt checkpoint.\n"; }
        $path = $record['path'] ?? '';
        if ($path === '') { @unlink($top['file']); return "Skipped an empty checkpoint.\n"; }
        if (!empty($record['existed'])) {
            $ok = @file_put_contents($path, (string)($record['content'] ?? '')) !== false;
            @unlink($top['file']);
            return $ok ? "Reverted: $path\n" : "Failed to revert: $path\n";
        }
        // File did not exist before the mutation; undo means delete it.
        if (is_file($path)) @unlink($path);
        @unlink($top['file']);
        return "Removed (was newly created): $path\n";
    }
}
