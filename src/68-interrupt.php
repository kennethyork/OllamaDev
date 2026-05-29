// Cooperative Ctrl-C interruption of an in-flight model response / tool loop.
//
// pcntl is optional (not present on all PHP builds, e.g. some Windows ones), so
// every pcntl call is guarded with function_exists(). When pcntl is missing this
// whole class is a no-op: armed() stays false, the streaming/agentic checks below
// never fire, and Ctrl-C keeps its old behaviour (handled by the line editor /
// default signal disposition). This keeps the build dependency-free and vanilla.
class Interrupt {
    private static bool $aborted = false;   // set by the SIGINT handler
    private static bool $armed = false;     // true while a handler is installed
    private static int $depth = 0;          // nesting guard for begin()/end()
    private static $prev = null;            // previous SIGINT handler, to restore

    private static function hasPcntl(): bool {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }

    // Install the SIGINT handler and clear the abort flag. Safe to nest: only the
    // outermost begin() touches the signal disposition. No-op without pcntl.
    public static function begin(): void {
        if (!self::hasPcntl()) return;
        if (self::$depth++ > 0) return;
        self::$aborted = false;
        self::$armed = true;
        // Deliver signals between PHP VM ticks without needing declare(ticks=1).
        @pcntl_async_signals(true);
        // Remember whatever was installed before so we can put it back exactly.
        self::$prev = function_exists('pcntl_signal_get_handler')
            ? @pcntl_signal_get_handler(SIGINT) : null;
        @pcntl_signal(SIGINT, function () { Interrupt::trip(); });
    }

    // Restore the previous handler (or the default) and clear armed state. Safe to
    // call unbalanced. No-op without pcntl.
    public static function end(): void {
        if (!self::hasPcntl()) return;
        if (self::$depth > 0) self::$depth--;
        if (self::$depth > 0) return;
        self::$armed = false;
        $prev = self::$prev;
        self::$prev = null;
        if ($prev !== null && $prev !== 0 && (is_string($prev) ? is_callable($prev) : true)) {
            @pcntl_signal(SIGINT, $prev);
        } else {
            @pcntl_signal(SIGINT, SIG_DFL);
        }
    }

    // The signal handler body: just flip the flag. The streaming transfer and the
    // agentic loop poll aborted() at safe points and unwind cooperatively.
    public static function trip(): void { self::$aborted = true; }

    // True once Ctrl-C has been pressed within the current begin()/end() scope.
    public static function aborted(): bool { return self::$aborted; }

    // True while a handler is installed (i.e. interruption is possible at all).
    public static function armed(): bool { return self::$armed; }

    // Clear the flag (e.g. after reporting the interruption to the user).
    public static function reset(): void { self::$aborted = false; }
}
