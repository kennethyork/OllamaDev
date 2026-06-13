// MODEL CLIENT — factory for the Ollama backend. OllamaDev talks to Ollama only
// (local, no cloud); this centralises host selection so a `--host <url>` override
// (e.g. a remote Ollama, or one on another port) is honoured everywhere without
// each call site re-reading config.
class ModelClient {
    public static string $override = ''; // set by --host for the session

    // A client for an explicit host, or the configured/default Ollama host.
    public static function for(string $host): object {
        return new OllamaClient($host !== '' ? $host : Config::get('ollama.host', 'http://localhost:11434'));
    }

    public static function default(): object {
        return self::for(self::$override);
    }

    // Human-readable label for the active backend (shown in onboarding/errors).
    public static function activeLabel(): string {
        return self::$override !== '' ? self::$override : 'Ollama';
    }
}
