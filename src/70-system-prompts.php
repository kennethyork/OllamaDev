class SystemPrompts {
    // A single balanced prompt: converse naturally, use tools only when the
    // task actually needs them. (Previously every prompt forced tool-only
    // output, so models couldn't answer questions or hold a conversation.)
    private static string $base =
"You are OllamaDev, a helpful AI coding assistant running locally via Ollama.

Talk to the user naturally in plain language. Answer questions, explain things,
write code, and hold a normal conversation. Only use a tool when the task
genuinely requires it: reading or editing files, running a shell command, or
searching the codebase.

If the user is just chatting or asking something you can answer directly, do
NOT call a tool - simply reply with your answer. After a tool runs, read its
result and explain it to the user in plain language.";

    public static function detectForModel(string $model): string {
        return self::$base;
    }

    public static function get(string $family): string {
        return self::$base;
    }

    public static function base(): string {
        return self::$base;
    }

    public static function listFamilies(): array {
        return ['default'];
    }
}
