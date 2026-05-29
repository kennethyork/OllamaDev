// Helper for the /retry (/regenerate) command. Pure, side-effect-free message
// surgery so it can be unit-tested offline. Given the full message array, it
// rewinds to just before the last assistant turn: it finds the last 'user'
// message and discards everything after it (the previous assistant reply and
// any tool result messages it produced). The returned array ends with that
// last user message, ready to feed back into the agentic loop for a fresh run.
class Regenerate {
    // Returns null when there is no user message to regenerate from. Otherwise
    // returns the message array truncated to end at (and including) the last
    // user message.
    public static function rewind(array $messages): ?array {
        $lastUser = -1;
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? '') === 'user') $lastUser = $i;
        }
        if ($lastUser < 0) return null;
        return array_slice($messages, 0, $lastUser + 1);
    }
}
