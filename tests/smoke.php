<?php
// Smoke tests that exercise the REAL ollamadev binary as a subprocess, plus
// fast unit checks on internal parsing/transport classes. No Ollama model is
// required - these are deterministic and offline. Run: php tests/smoke.php
//
// (Optional) set SMOKE_MODEL=<installed model> to also run a couple of
// model-dependent agent-loop checks.

$BIN = realpath(__DIR__ . '/../ollamadev');
if (!$BIN) { fwrite(STDERR, "Cannot find ollamadev binary\n"); exit(2); }

$pass = 0; $fail = 0; $fails = [];
function ok(string $name, bool $cond, string $detail = '') {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  \033[32m✓\033[0m $name\n"; }
    else { $fail++; $fails[] = $name; echo "  \033[31m✗\033[0m $name" . ($detail ? " — $detail" : '') . "\n"; }
}

// A couple of checks exercise "model not installed" warnings, which only fire
// when a model backend is actually reachable (otherwise the agent can't verify
// and trusts the name). Detect Ollama so those run locally but are skipped in
// CI / offline — keeping the suite deterministic everywhere.
function ollama_reachable(): bool {
    $ch = curl_init('http://localhost:11434/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $r !== false && $code === 200;
}
$ollamaUp = ollama_reachable();

// Run the binary with optional piped stdin. Returns [stdout, stderr, exitcode].
function run_bin(array $args, string $stdin = '', array $env = [], ?string $cwd = null): array {
    global $BIN;
    $cmd = 'php ' . escapeshellarg($BIN);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $fullEnv = array_merge(getenv() ?: [], $env);
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd ?? sys_get_temp_dir(), $fullEnv);
    if (!is_resource($proc)) return ['', 'proc_open failed', 1];
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$out, $err, $code];
}

echo "\n== CLI behavior (real binary) ==\n";

[$out, $err, $code] = run_bin(['help']);
ok('help exits 0', $code === 0, "exit=$code");
ok('help lists topics', stripos($out, 'help topics') !== false || stripos($out, 'usage') !== false);

[$out] = run_bin(['--version']);
ok('--version prints a version', (bool)preg_match('/v?\d+\.\d+\.\d+/', $out), trim($out));

[$out] = run_bin([], "/tools\n/exit\n");
ok('/tools lists tools', stripos($out, 'Available tools') !== false && strpos($out, 'write') !== false);

[$out] = run_bin([], "/permission\n/exit\n");
ok('/permission shows default mode ask', stripos($out, 'mode: ask') !== false);

[$out] = run_bin([], "/permission mode readonly\n/permission\n/exit\n");
ok('/permission mode switch works', stripos($out, 'mode: readonly') !== false);

[$out] = run_bin([], "/bogus\n/exit\n");
ok('unknown command is reported (not sent to model)', stripos($out, 'Unknown command') !== false && stripos($out, '🤖') === false);

[$out] = run_bin([], "/cd /tmp\n/pwd\n/exit\n");
ok('/cd then /pwd reflects new dir', strpos($out, '/tmp') !== false);

if ($ollamaUp) {
    [$out, $err] = run_bin(['-m', 'definitely-not-installed:999b'], "/exit\n");
    ok('-m warns on missing model', stripos($err, 'not installed') !== false, trim($err));
} else {
    echo "  \033[2m· skipped: -m warns on missing model (no model backend reachable)\033[0m\n";
}

echo "\n== Internal units (extracted classes) ==\n";

// Load just the Agent's tool-call parsers by extracting the methods into a
// throwaway class - keeps the test independent of the full CLI bootstrap.
$src = file_get_contents($BIN);
// Several extracted classes (Checkpoints, Config, …) call the global atomicWrite()
// helper — define it in this test process before any of them are eval'd.
if (!function_exists('atomicWrite') && preg_match('/function atomicWrite\(.*?\n\}/s', $src, $awm)) eval($awm[0]);

// extractJsonToolCalls + parseToolCalls live in the Agent class; pull the two
// static/instance methods we want to exercise.
if (preg_match('/public static function extractJsonToolCalls\(string \$content\): array \{.*?\n    \}/s', $src, $mE)
    && preg_match('/public static function repairJson\(string \$s\): \?string \{.*?\n    \}/s', $src, $mR)) {
    eval('class _P { ' . $mE[0] . "\n" . $mR[0] . ' }');
    $calls = _P::extractJsonToolCalls('blah <tool_code>{"name":"bash","arguments":{"command":"echo hi"}}');
    ok('extractJsonToolCalls handles missing close tag', count($calls) === 1 && $calls[0]['name'] === 'bash');
    $calls2 = _P::extractJsonToolCalls('{"name":"write","arguments":{"file_path":"a.txt","content":"x"}}');
    ok('extractJsonToolCalls parses nested args', count($calls2) === 1 && ($calls2[0]['params']['file_path'] ?? '') === 'a.txt');
    $calls3 = _P::extractJsonToolCalls('no tool calls here at all');
    ok('extractJsonToolCalls returns none for plain text', count($calls3) === 0);
    // Small-model reliability: recover ALMOST-valid JSON tool calls instead of
    // dropping them (which reads as "the model described it but did nothing").
    $r1 = _P::extractJsonToolCalls('{"name":"write","arguments":{"file_path":"a.txt","done":True,}}');  // trailing comma + Python True
    ok('recovers a tool call with a trailing comma + Python True', count($r1) === 1 && $r1[0]['name'] === 'write' && ($r1[0]['params']['file_path'] ?? '') === 'a.txt');
    $r2 = _P::extractJsonToolCalls("{name: 'ls', params: {path: '/tmp'}}");                              // unquoted keys + single quotes
    ok('recovers a tool call with unquoted keys + single quotes', count($r2) === 1 && $r2[0]['name'] === 'ls' && ($r2[0]['params']['path'] ?? '') === '/tmp');
    ok('repairJson returns null for genuinely-not-JSON', _P::repairJson('this is just prose') === null);
    // A VALID tool call is never sent through repair (repair only fires on parse failure).
    ok('valid JSON tool call parses without repair', count(_P::extractJsonToolCalls('{"name":"bash","arguments":{"command":"ls"}}')) === 1);
} else {
    ok('extractJsonToolCalls extractable', false, 'method not found in binary');
}

// MCPClient stdio round-trip against the bundled mock server.
if (preg_match('/\/\/ Model Context Protocol client.*?\n\}\n/s', $src, $mM)) {
    if (!defined('OLLAMADEV_VERSION')) define('OLLAMADEV_VERSION', 'test');
    eval($mM[0]);
    $server = __DIR__ . '/fixtures/mcp_server.php';
    if (is_file($server)) {
        $c = new MCPClient(['command' => 'php', 'args' => [$server], 'type' => 'stdio']);
        $tools = $c->listTools();
        ok('MCP stdio lists tools', is_array($tools) && ($tools[0]['name'] ?? '') === 'greet');
        ok('MCP stdio tools/call works', stripos($c->callTool('greet', ['who' => 'Test']), 'Hello, Test') !== false);
    } else {
        ok('MCP mock server present', false, "missing $server");
    }
} else {
    ok('MCPClient extractable', false, 'class not found in binary');
}

echo "\n== Tool-call parser (regression) ==\n";
// Extract both parser methods into a throwaway class and exercise them.
if (preg_match('/public static function extractJsonToolCalls\(string \$content\): array \{.*?\n    \}/s', $src, $e1)
 && preg_match('/public function parseToolCalls\(string \$content\): array \{.*?\n    \}/s', $src, $e2)) {
    // parseToolCalls now also falls back to function-call syntax; pull those
    // helpers in too (they no-op when Tools isn't present in the throwaway class).
    preg_match('/public static function extractCallSyntax\(string \$content\): array \{.*?\n    \}/s', $src, $e3);
    preg_match('/private static function parseCallArgs\(string \$inner, string \$tool\): \?array \{.*?\n    \}/s', $src, $e4);
    preg_match('/private static function firstToolParam\(string \$tool\): string \{.*?\n    \}/s', $src, $e5);
    eval('class _PC { ' . $e1[0] . "\n" . $e2[0] . "\n" . ($e3[0] ?? '') . "\n" . ($e4[0] ?? '') . "\n" . ($e5[0] ?? '') . ' }');
    $pc = new _PC();
    $a = $pc->parseToolCalls('<tool_code>{"name":"ls","arguments":{"path":"."}}</tool_code>');
    ok('parses a wrapped JSON tool call', count($a) === 1 && $a[0]['name'] === 'ls');
    $b = $pc->parseToolCalls("name: write params: file_path=a.txt");
    ok('parses the text name/params format', count($b) === 1 && $b[0]['name'] === 'write' && ($b[0]['params']['file_path'] ?? '') === 'a.txt');
    $c = $pc->parseToolCalls("Sure! I'll find the file and list the diff for you.");
    ok('plain prose does NOT trigger false tool calls', count($c) === 0);

    // Function-call syntax fallback (mistral-style `view(...)`). extractCallSyntax
    // needs a Tools class for the known-name allowlist + first-param lookup; stub a
    // minimal one so prose like view(file_path="x") resolves to a real call while
    // ordinary parentheses don't.
    if (!class_exists('Tools')) {
        eval('class Tools {
            public static function all() { return ["view","write","bash"]; }
            public static function schemas() { return [
                ["function"=>["name"=>"view","parameters"=>["properties"=>["file_path"=>["type"=>"string"]]]]],
                ["function"=>["name"=>"bash","parameters"=>["properties"=>["command"=>["type"=>"string"]]]]],
            ]; }
        }');
    }
    if (class_exists('Tools')) {
        $d = $pc->parseToolCalls('To read it I will use view(file_path="build.txt") now.');
        ok('parses toolname(arg="val") function-call syntax',
            count($d) === 1 && $d[0]['name'] === 'view' && ($d[0]['params']['file_path'] ?? '') === 'build.txt');
        $e = $pc->parseToolCalls('view("notes.md")');
        ok('maps a single positional arg to the first schema param',
            count($e) === 1 && ($e[0]['params']['file_path'] ?? '') === 'notes.md');
        $f = $pc->parseToolCalls("This function helps you understand the code better.");
        ok('prose with unknown name( does NOT false-fire', count($f) === 0);
    }
} else {
    ok('parser methods extractable', false, 'methods not found');
}

echo "\n== Permission enforcement ==\n";
if (preg_match('/class Permission \{.*?\n\}/s', $src, $pm)) {
    eval($pm[0]);
    Permission::setMode('auto');
    ok('auto mode allows mutating tools', Permission::check('write', ['file_path' => 'x']) === true);
    Permission::setMode('readonly');
    ok('readonly blocks mutating tools', Permission::check('write', ['file_path' => 'x']) === false);
    ok('readonly still allows read-only tools', Permission::check('ls', []) === true);
    Permission::setMode('ask');
    Permission::setInteractive(false);
    ok('ask (non-interactive) allows read-only', Permission::check('grep', []) === true);
    Permission::allow('bash');
    ok('explicitly-allowed tool passes in any mode', Permission::check('bash', []) === true);
} else {
    ok('Permission class extractable', false, 'class not found');
}

echo "\n== Web search tool ==\n";
[$out] = run_bin([], "/tools\n/exit\n");
ok('search tool registered', strpos($out, 'search') !== false);
// Live check only when explicitly enabled (needs internet).
if (getenv('SMOKE_NET')) {
    [$out] = run_bin([], "/exit\n"); // warm
    $res = run_bin(['-p', 'use the search tool to search for: PHP manual']);
    ok('live web search returns results', stripos($res[0], 'http') !== false);
} else {
    echo "  \033[2m· skipped live search (set SMOKE_NET=1 to enable)\033[0m\n";
}

echo "\n== Agent loop (end-to-end) ==\n";
// Real model run, gated behind SMOKE_MODEL (set to an installed tool-capable
// model, e.g. SMOKE_MODEL=mistral:latest). Verifies prompt -> tool -> result.
$smokeModel = getenv('SMOKE_MODEL');
if ($smokeModel) {
    $target = sys_get_temp_dir() . '/smoke_agent_' . getmypid() . '.txt';
    $ok = false;
    // Retry: small/local models are probabilistic, so allow a couple of tries.
    for ($attempt = 0; $attempt < 2 && !$ok; $attempt++) {
        @unlink($target);
        run_bin(['-m', $smokeModel, '--auto', '-p',
            "Use the write tool now to create the file $target with the exact content: OK"]);
        $ok = is_file($target) && stripos((string)@file_get_contents($target), 'OK') !== false;
    }
    ok("agent creates a file via the write tool ($smokeModel)", $ok);
    @unlink($target);

    // --- End-to-end checks for the new features (real model, in a temp cwd) ---
    $wd = sys_get_temp_dir() . '/smoke_e2e_' . getmypid();
    @mkdir($wd, 0755, true);

    // Checkpoint + diff pipeline: an edit via the model snapshots the prior file.
    $ef = $wd . '/edit_target.txt';
    file_put_contents($ef, "ALPHA\n");
    $done = false;
    for ($attempt = 0; $attempt < 2 && !$done; $attempt++) {
        run_bin(['-m', $smokeModel, '--auto', '-p',
            "Use the edit tool to replace the text ALPHA with OMEGA in the file edit_target.txt"], '', [], $wd);
        $done = stripos((string)@file_get_contents($ef), 'OMEGA') !== false;
    }
    $ckpts = glob($wd . '/.ollamadev/checkpoints/ckpt_*.json') ?: [];
    ok("edit tool writes the change ($smokeModel)", $done);
    ok('edit via model created a checkpoint', count($ckpts) >= 1, count($ckpts) . ' checkpoints');

    // @file mention: the model can answer about an inlined file it never tool-read.
    $secret = 'ZEBRA' . substr(md5((string)getmypid()), 0, 4);
    file_put_contents($wd . '/fact.txt', "The magic word is $secret.\n");
    [$mout] = run_bin(['-m', $smokeModel, '--auto', '-p',
        "What is the magic word? It is stated in @$wd/fact.txt — answer with just the word."], '', [], $wd);
    ok('@file mention reaches the model', stripos($mout, $secret) !== false);

    // Token meter: a real turn records non-zero usage shown by /status.
    [$sout] = run_bin(['-m', $smokeModel], "hi\n/status\n/exit\n");
    ok('/status reports real token usage after a turn',
        (bool)preg_match('/last turn:\s*\d+\s*in/i', $sout), trim(substr($sout, -160)));

    shell_exec('rm -rf ' . escapeshellarg($wd));
} else {
    echo "  \033[2m· skipped (set SMOKE_MODEL=<installed model> to run)\033[0m\n";
}

echo "\n== Terminal daemon lifecycle ==\n";
$tid = 'smoke-' . getmypid();
$tdir = (getenv('HOME') ?: '/tmp') . '/.ollamadev/terminals/' . $tid;
@mkdir($tdir, 0755, true);
$cmd = 'php ' . escapeshellarg($BIN) . ' __terminal-daemon__ ' . escapeshellarg($tid) . ' >/dev/null 2>&1 & echo $!';
$dpid = (int)trim(shell_exec($cmd));
usleep(700000);
$alive = $dpid > 0 && posix_kill($dpid, 0);
ok('daemon starts on __terminal-daemon__', $alive, "pid=$dpid");
$log = is_file("$tdir/session.log") ? file_get_contents("$tdir/session.log") : '';
ok('daemon writes ready marker', stripos($log, 'ready') !== false);
file_put_contents("$tdir/input.txt", '__STOP__');
usleep(700000);
ok('daemon exits on __STOP__', !($dpid > 0 && posix_kill($dpid, 0)));
if ($dpid > 0) @posix_kill($dpid, 9);
shell_exec('rm -rf ' . escapeshellarg($tdir));

echo "\n== New features (harness) ==\n";

// chatOptions must set num_ctx well above Ollama's silent 2048 default, or the
// agent's system prompt + tool history get truncated mid-task.
if (preg_match('/class Config \{.*?\n\}/s', $src, $cfg) && preg_match('/class OllamaClient \{.*?\n\}/s', $src, $oc)) {
    if (!class_exists('Config')) eval($cfg[0]);
    if (!class_exists('OllamaClient')) eval($oc[0]);
    $opts = OllamaClient::chatOptions(); // no model → baseline, no network
    ok('chatOptions sets num_ctx > 2048', ($opts['num_ctx'] ?? 0) > 2048, 'num_ctx=' . ($opts['num_ctx'] ?? 'unset'));
    ok('chatOptions sets a temperature', isset($opts['temperature']));
    // lowering the cap below the baseline must pull num_ctx down (weak hardware)
    Config::set('ollama.maxContextWindow', 4096);
    ok('maxContextWindow can lower the window', (OllamaClient::chatOptions()['num_ctx'] ?? 0) === 4096);
    Config::set('ollama.maxContextWindow', 32768);
    // manual pin: autoContext off uses contextWindow exactly
    Config::set('ollama.autoContext', false); Config::set('ollama.contextWindow', 6000);
    ok('autoContext off pins contextWindow', (OllamaClient::chatOptions()['num_ctx'] ?? 0) === 6000);
    Config::set('ollama.autoContext', true); Config::set('ollama.contextWindow', 16384);
} else {
    ok('chatOptions extractable', false, 'Config or OllamaClient not found');
}

// Env vars are documented overrides — a config file must NOT silently win over
// OLLAMA_HOST/OLLAMA_MODEL. Guard the merge order so the latent "built but never
// applied" $envOverrides bug can't come back.
ok('Config applies env overrides over the config file',
   preg_match('/array_replace_recursive\(\s*\$defaults\s*,\s*\$json\s*,\s*\$envOverrides\s*\)/', $src) === 1,
   'env overrides not merged last in Config::load');

// Onboarding (preflight) needs a host() getter on the client and on the Agent
// facade so it can print the right fix-up steps when Ollama isn't reachable.
ok('OllamaClient exposes host()', strpos($src, 'function host()') !== false && strpos($src, 'class OllamaClient') !== false);
ok('Agent forwards host() to its client', strpos($src, "method_exists(\$this->client, 'host')") !== false);
ok('start() runs a preflight backend check', strpos($src, '$ready = $this->preflight();') !== false);
ok('preflight surfaces the no-models case', strpos($src, 'no models installed') !== false);
ok('first run hints recovery (/undo + /checkpoints)',
   strpos($src, '/undo') !== false && strpos($src, '/checkpoints') !== false);

// Tab-completion's common-prefix helper underpins completion behaviour.
if (preg_match('/private static function commonPrefix\(array \$strs\): string \{.*?\n    \}/s', $src, $cp2)
 && preg_match('/private static function split\(string \$s\): array \{.*?\n    \}/s', $src, $sp)) {
    $body = str_replace('private static function', 'public static function', $cp2[0] . "\n" . $sp[0]);
    eval('class _LE { ' . $body . ' }');
    ok('commonPrefix finds shared prefix', _LE::commonPrefix(['/model', '/models']) === '/model');
    ok('commonPrefix empty when none shared', _LE::commonPrefix(['abc', 'xyz']) === '');
} else {
    ok('commonPrefix extractable', false, 'methods not found');
}

// relativeTime formats session ages for the resume picker.
if (preg_match('/private static function relativeTime\(string \$iso\): string \{.*?\n    \}/s', $src, $rt)) {
    eval('class _RT { ' . str_replace('private static function', 'public static function', $rt[0]) . ' }');
    ok('relativeTime: recent reads "just now"', _RT::relativeTime(date('c')) === 'just now');
    ok('relativeTime: hours ago', _RT::relativeTime(date('c', time() - 7200)) === '2h ago');
    ok('relativeTime: empty is unknown', _RT::relativeTime('') === 'unknown');
} else {
    ok('relativeTime extractable', false, 'method not found');
}

// `resume` with no sessions (run in a clean empty dir) reports nothing to resume.
$tmpHome = sys_get_temp_dir() . '/ollamadev_smoke_resume_' . getmypid();
@mkdir($tmpHome, 0755, true);
[$out] = run_bin(['resume'], "\n", [], $tmpHome);
ok('resume picker handles no sessions', stripos($out, 'No previous sessions') !== false, trim($out));
shell_exec('rm -rf ' . escapeshellarg($tmpHome));

// /model with an uninstalled name reports it instead of silently switching.
// (Only meaningful when a backend is reachable to verify against.)
if ($ollamaUp) {
    [$out] = run_bin([], "/model definitely-not-real-model-xyz\n/exit\n");
    ok('/model rejects an uninstalled model', stripos($out, 'not installed') !== false);
} else {
    echo "  \033[2m· skipped: /model rejects an uninstalled model (no model backend reachable)\033[0m\n";
}

echo "\n== Workflow features (12) ==\n";

// Pure-helper unit checks: extract each standalone class from the binary source
// and exercise it in-process. (Classes with only filesystem/self deps.)
$extract = function(string $cls) use ($src) {
    return preg_match('/class ' . $cls . ' \{.*?\n\}/s', $src, $m) ? $m[0] : null;
};

// 1. diff preview — unified() emits +/- lines.
if ($c = $extract('DiffView')) { eval($c);
    $d = DiffView::unified("a\nb\nc\n", "a\nX\nc\n");
    ok('DiffView shows +/- changes', strpos($d, '-') !== false && strpos($d, '+') !== false);
} else ok('DiffView extractable', false);

// 2. Thinking — reasoning collapser: hard-wraps to make rows == newlines, then
// climbs the block and erases it, leaving a one-line summary. Deterministic with
// an explicit wrap width (cols) and a tall viewport (LINES) so the height guard
// never trips here.
putenv('LINES=50');
if ($c = $extract('Thinking')) { eval($c);
    ok('Thinking::visWidth strips ANSI and counts emoji as 2 cols',
        Thinking::visWidth("\033[2mab\033[0m") === 2 && Thinking::visWidth("💭") === 2);

    // 25 chars at width 10 ⇒ wraps after col 10 and 20 ⇒ a 3-row block.
    $buf = '';
    $t = new Thinking(function ($b) use (&$buf) { $buf .= $b; }, ['cols' => 10, 'control' => true]);
    $t->push(str_repeat('y', 25));
    ok('Thinking streams reasoning dimmed', strpos($buf, "\033[2m") !== false);
    ok('Thinking hard-wraps long reasoning into rows', substr_count($buf, "\n") === 2, "newlines=" . substr_count($buf, "\n"));
    $t->collapse();
    ok('Thinking collapse climbs (rows-1) then erases to end of display',
        preg_match('/\r\033\[2A\033\[J/', $buf) === 1, $buf);
    ok('Thinking collapse leaves a one-line summary', strpos($buf, 'thought for') !== false);
    ok('Thinking collapse ends on a fresh line for the answer', substr($buf, -1) === "\n");
    $snapshot = $buf; $t->collapse();
    ok('Thinking collapse is idempotent', $buf === $snapshot);

    // Bounded box: with a 3-row window, reasoning longer than the box keeps only
    // the last few lines pinned, so neither a repaint nor the collapse ever climbs
    // more than window-1 rows (the guarantee that makes the fold always succeed,
    // even when a model out-reasons the visible screen).
    $bb = '';
    $tb = new Thinking(function ($b) use (&$bb) { $bb .= $b; }, ['cols' => 8, 'window' => 3, 'control' => true]);
    $tb->push(str_repeat('a', 8) . str_repeat('b', 8) . str_repeat('c', 8) . str_repeat('d', 8) . 'ee');   // 5 rows of content
    $tb->collapse();
    preg_match_all('/\033\[(\d+)A/', $bb, $mUp);
    $maxUp = $mUp[1] ? max(array_map('intval', $mUp[1])) : 0;
    ok('Thinking box never climbs past window-1 rows', $maxUp <= 2, "maxUp=$maxUp");

    // Nothing streamed ⇒ collapse is silent (the caller draws the no-reasoning case).
    $b2 = ''; (new Thinking(function ($b) use (&$b2) { $b2 .= $b; }, ['control' => true]))->collapse();
    ok('Thinking collapse is a no-op when no reasoning streamed', $b2 === '');

    // Piped / non-tty (control=false): stream dimmed but never move the cursor;
    // separate the answer with a plain newline.
    $b3 = ''; $t3 = new Thinking(function ($b) use (&$b3) { $b3 .= $b; }, ['control' => false]);
    $t3->push('reasoning'); $t3->collapse();
    ok('Thinking without cursor control never emits cursor moves', preg_match('/\033\[\d*[AJ]/', $b3) === 0, $b3);
    ok('Thinking without cursor control still separates with a newline', substr($b3, -1) === "\n");
} else ok('Thinking extractable', false);

// 3. interrupt — trip/aborted/reset state machine.
if ($c = $extract('Interrupt')) { eval($c);
    Interrupt::reset();
    ok('Interrupt starts not-aborted', Interrupt::aborted() === false);
    Interrupt::trip();
    ok('Interrupt trips', Interrupt::aborted() === true);
    Interrupt::reset();
    ok('Interrupt resets', Interrupt::aborted() === false);
} else ok('Interrupt extractable', false);

// 4. @file mentions — inline a real file's contents.
if ($c = $extract('Mentions')) { eval($c);
    $tmpf = sys_get_temp_dir() . '/mention_' . getmypid() . '.txt';
    file_put_contents($tmpf, 'HELLO_MENTION_BODY');
    $exp = Mentions::expand("see @$tmpf please");
    ok('Mentions inlines file contents', strpos($exp, 'HELLO_MENTION_BODY') !== false);
    @unlink($tmpf);
} else ok('Mentions extractable', false);

// 6. vision — extract() flags an image path and strips it from text.
if ($c = $extract('Vision')) { eval($c);
    $img = sys_get_temp_dir() . '/v_' . getmypid() . '.png';
    file_put_contents($img, "\x89PNG\r\n\x1a\n");
    $v = Vision::extract("look at @$img");
    ok('Vision captures image attachments', !empty($v['images']) && is_array($v['images']));
    // Tilde in an image path expands to the home dir so '@~/pic.png' resolves.
    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
    $himg = rtrim($home, '/\\') . '/odv_smoke_' . getmypid() . '.png';
    file_put_contents($himg, "\x89PNG\r\n\x1a\n");
    $vt = Vision::extract('see @~/' . basename($himg));
    ok('Vision expands ~ in image paths', !empty($vt['images']));
    @unlink($himg);
    @unlink($img);
} else ok('Vision extractable', false);

// 10. regenerate — rewind() drops the last assistant turn.
if ($c = $extract('Regenerate')) { eval($c);
    $msgs = [
        ['role' => 'user', 'content' => 'hi'],
        ['role' => 'assistant', 'content' => 'hello'],
    ];
    $rw = Regenerate::rewind($msgs);
    ok('Regenerate rewinds to last user msg', is_array($rw) && end($rw)['role'] === 'user');
    ok('Regenerate returns null when nothing to redo', Regenerate::rewind([]) === null);
} else ok('Regenerate extractable', false);

// 5. markdown render — via the real binary (deterministic, offline).
[$out] = run_bin(['render'], "# Heading\n\n- item\n");
ok('render styles markdown (ANSI)', strpos($out, "\033[") !== false && stripos($out, 'Heading') !== false);

// 7. token meter — Usage records real counts and renders a context meter.
if (!class_exists('Config')) { require_once __DIR__ . '/../src/10-config.php'; }
if ($c = $extract('Usage')) { eval($c);
    Usage::record(['prompt_eval_count' => 100, 'eval_count' => 25, 'done' => true]);
    ok('Usage captures real token counts', Usage::haveReal() && Usage::lastPrompt() === 100 && Usage::lastEval() === 25);
    ok('Usage renders a context meter', is_string(Usage::contextMeter(500)) && Usage::contextMeter(500) !== '');
} else ok('Usage extractable', false);

// 2. checkpoint/undo — save snapshot then undoLast restores (real Config, temp cwd).
$ckRoot = sys_get_temp_dir() . '/ollamadev_ck_' . getmypid();
@mkdir($ckRoot, 0755, true);
$prevCwd = getcwd(); chdir($ckRoot);
if ($c = $extract('Checkpoints')) { eval($c);
    $tf = $ckRoot . '/target.txt';
    file_put_contents($tf, 'V1');
    Checkpoints::save($tf);
    file_put_contents($tf, 'V2');
    $msg = Checkpoints::undoLast();
    ok('Checkpoints undo restores prior content', file_get_contents($tf) === 'V1', $msg);
} else ok('Checkpoints extractable', false);
chdir($prevCwd);
shell_exec('rm -rf ' . escapeshellarg($ckRoot));

// 11. subagent delegation — wiring present in the built source.
ok('task tool registered', strpos($src, "Tools::register('task'") !== false);
ok('task schema exposed to model', strpos($src, "\$fn('task'") !== false);
ok('subagent has a recursion depth guard', stripos($src, 'depth') !== false && strpos($src, 'SubAgent') !== false);
ok('subagent defaults to read-only', strpos($src, "agents.subagentPermission") !== false && strpos($src, "'readonly'") !== false);
ok('subagent restores parent permission mode', strpos($src, 'Permission::setMode($parentMode)') !== false);
ok('subagent cannot escalate past parent', strpos($src, "\$parentMode === 'readonly') \$subMode = 'readonly'") !== false);

// 8/9/12. command wiring present in the built source.
ok('init command wired', strpos($src, 'ProjectInit::run') !== false);
ok('custom-command fallthrough wired', strpos($src, 'UserCmds::exists') !== false);
ok('pull command wired', strpos($src, 'Puller::pull') !== false);
ok('hooks fire in the loop', strpos($src, "Hooks::run('beforePrompt')") !== false);

// Discoverability: the new commands appear in /help, top-level help, and completion.
[$out] = run_bin([], "/help\n/exit\n");
$inChat = stripos($out, '/undo') !== false && stripos($out, '/pull') !== false
       && stripos($out, '/init') !== false && stripos($out, '/retry') !== false;
ok('/help lists the new slash commands', $inChat);

[$out] = run_bin(['help', 'commands']);
ok('help commands lists pull/init/resume',
    stripos($out, 'pull') !== false && stripos($out, 'init') !== false && stripos($out, 'resume') !== false);

[$out] = run_bin(['completion', 'bash']);
ok('bash completion includes new commands',
    strpos($out, 'pull') !== false && strpos($out, 'init') !== false && strpos($out, 'resume') !== false);

echo "\n== Crew (bench) ==\n";
// Offline guards
[$out] = run_bin(['crew']);
ok('crew with no task prints usage', stripos($out, 'Usage: ollamadev crew') !== false);
$ngdir = sys_get_temp_dir() . '/crew_nogit_' . getmypid(); @mkdir($ngdir, 0755, true);
[$out] = run_bin(['crew', 'do a thing'], '', [], $ngdir);
ok('crew outside a git repo errors', stripos($out, 'needs a git repository') !== false, trim($out));
shell_exec('rm -rf ' . escapeshellarg($ngdir));
// Unit: JSON extraction + slug (extract the Crew class from the binary)
if (preg_match('/class Crew \{.*?\n\}/s', $src, $fm)) {
    eval(str_replace(
        ['private static function extractJson', 'private static function parsePlan'],
        ['public static function extractJson', 'public static function parsePlan'],
        $fm[0]));
    $j = Crew::extractJson('noise {"subtasks":[{"title":"a","prompt":"b"}]} tail');
    ok('Crew::extractJson pulls balanced JSON', is_array($j) && ($j['subtasks'][0]['title'] ?? '') === 'a');
    ok('Crew::extractJson returns null on none', Crew::extractJson('no json here') === null);
    ok('Crew::slug normalizes titles', Crew::slug('Add /Health Route!') === 'add-health-route');
    // Director plan parsing must tolerate the shape variations real models (esp. cloud)
    // emit, instead of collapsing to one subtask whenever the JSON shape drifts.
    if (!class_exists('CrewRoles')) { eval('class CrewRoles { public static function normName(string $n): string { return strtolower(trim($n)); } }'); }
    $roles = ['coder', 'tester', 'docs'];
    ok('parsePlan reads the canonical {"subtasks":[…]} shape',
        count(Crew::parsePlan(['subtasks' => [['title' => 'a', 'role' => 'coder', 'prompt' => 'do a'], ['prompt' => 'do b']]], $roles)) === 2);
    ok('parsePlan accepts the {"tasks":[…]} alias',
        count(Crew::parsePlan(['tasks' => [['prompt' => 'x'], ['prompt' => 'y']]], $roles)) === 2);
    ok('parsePlan accepts a bare top-level array',
        count(Crew::parsePlan([['prompt' => 'x'], ['task' => 'y'], ['instruction' => 'z']], $roles)) === 3);
    ok('parsePlan accepts a single subtask object',
        count(Crew::parsePlan(['title' => 'solo', 'prompt' => 'just this'], $roles)) === 1);
    ok('parsePlan accepts bare string steps',
        count(Crew::parsePlan(['steps' => ['first thing', 'second thing']], $roles)) === 2);
    $pp = Crew::parsePlan(['subtasks' => [['prompt' => '   '], ['prompt' => 'real'], 'bare']], $roles);
    ok('parsePlan drops empty prompts but keeps real ones', count($pp) === 2);
    ok('parsePlan synthesizes a title when none is given', ($pp[0]['title'] ?? '') !== '');
    ok('parsePlan returns [] on truly unusable JSON', Crew::parsePlan(['unrelated' => 'value'], $roles) === [] && Crew::parsePlan('nope', $roles) === []);
} else { ok('Crew class extractable', false); }
// "Clear the board" — explicit-only, idle-only, across CLI + agent tool + desktop.
$chome = sys_get_temp_dir() . '/crew_clear_' . getmypid(); @mkdir($chome . '/.ollamadev/crew', 0777, true);
$cboard = $chome . '/.ollamadev/crew/current.json';
file_put_contents($cboard, json_encode(['active' => true, 'subtasks' => [['n' => 1, 'title' => 't', 'state' => 'doing']]]));
[$out, , $code] = run_bin(['crew', 'clear'], '', ['HOME' => $chome]);
$still = json_decode((string) @file_get_contents($cboard), true);
ok('crew clear refuses while a run is active', $code === 1 && count($still['subtasks'] ?? []) === 1 && stripos($out, 'active') !== false, trim($out));
file_put_contents($cboard, json_encode(['active' => false, 'subtasks' => [['n' => 1, 'title' => 't', 'state' => 'held']], 'ideas' => ['x']]));
[$out, , $code] = run_bin(['crew', 'clear'], '', ['HOME' => $chome]);
$after = json_decode((string) @file_get_contents($cboard), true);
ok('crew clear wipes the board + writes a cleared sentinel when idle', $code === 0 && empty($after['subtasks']) && empty($after['ideas']) && !empty($after['cleared']), trim($out));
shell_exec('rm -rf ' . escapeshellarg($chome));
// Agent tool: explicit-only (needs confirm=true) so the model can't clear unprompted.
ok('clear_board tool requires confirm + refuses without it', strpos($src, "Tools::register('clear_board'") !== false &&
    strpos($src, 'FILTER_VALIDATE_BOOLEAN') !== false && strpos($src, 'Board NOT cleared') !== false);
ok('clear_board schema marks it explicit-only with required confirm', strpos($src, 'ONLY call this when the user EXPLICITLY') !== false &&
    strpos($src, "'confirm'") !== false);
// Interactive Director: "clear board" is handled directly (not spent on a crew run).
ok('interactive Director clears the board directly on "clear board"',
    preg_match('/\^\(clear\|reset\|wipe\|empty\).*board\$/', $src) === 1 && strpos($src, 'Crew::clearBoard()') !== false);
// Separate-Director steering: `crew steer <#> "..."` / desktop box → run's steer.jsonl,
// injected into the targeted coder between iterations (works sequential + forked).
ok('crew has a separate-Director steer channel (command + injection)',
    strpos($src, 'function steer(') !== false && strpos($src, 'injectSteerFor') !== false &&
    strpos($src, 'steer.jsonl') !== false && strpos($src, "\$arg1 === 'steer'") !== false);
// Functional: steer refuses with no active run, and queues to steer.jsonl when active.
$shome = sys_get_temp_dir() . '/crew_steer_' . getmypid(); @mkdir($shome . '/.ollamadev/crew/crew_x/', 0777, true);
[$o1, , $c1] = run_bin(['crew', 'steer', '2', 'focus on tests'], '', ['HOME' => $shome]);
ok('crew steer refuses when no run is active', $c1 === 1 && stripos($o1, 'no active crew run') !== false, trim($o1));
file_put_contents($shome . '/.ollamadev/crew/current.json', json_encode(['active' => true, 'runId' => 'crew_x', 'subtasks' => []]));
[$o2, , $c2] = run_bin(['crew', 'steer', '2', 'focus on tests'], '', ['HOME' => $shome]);
$sj = (string) @file_get_contents($shome . '/.ollamadev/crew/crew_x/steer.jsonl');
ok('crew steer queues a targeted message to steer.jsonl', $c2 === 0 && strpos($sj, '"target":2') !== false && strpos($sj, 'focus on tests') !== false, trim($o2 . ' | ' . $sj));
// Live model swap via the Director: "<#>: model <name>" is sent through the same
// steer channel and hot-swaps that coder's model mid-run (same worktree + history).
[$o3, , $c3] = run_bin(['crew', 'steer', '2', 'model llama3.2:latest'], '', ['HOME' => $shome]);
$sj2 = (string) @file_get_contents($shome . '/.ollamadev/crew/crew_x/steer.jsonl');
ok('Director can send a live model-swap through steer', $c3 === 0 && strpos($sj2, 'model llama3.2:latest') !== false);
ok('coder recognizes a "model <name>" directive + hot-swaps its model', strpos($src, "model\\s+(\\S+)") !== false &&
    strpos($src, 'injectSteerFor($messages, $steerFile, $steerN, $agent)') !== false &&
    strpos($src, '$agent->setModel($resolved)') !== false && strpos($src, "coder {\$n} model →") !== false);
ok('live model swap is discoverable (console + desktop box)',
    strpos($src, "Swap a coder's model live") !== false &&
    strpos((string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/index.html'), '2: model llama3.3:70b') !== false);
shell_exec('rm -rf ' . escapeshellarg($shome));
// Resume an interrupted run on a DIFFERENT model: --coder-model wins over saved.
ok('crew resume accepts model overrides (flags win over saved)',
    strpos($src, 'function resume(string $runId = \'\', array $overrides = [])') !== false &&
    strpos($src, "exit(Crew::resume(\$positional[2] ?? '', \$ov))") !== false &&
    strpos($src, 'function saveRunOpts(') !== false);
// Functional: fabricate an interrupted run, resume with a new coder model, assert
// it merges + persists (recorded before the Ollama check, so it works offline).
$rh = sys_get_temp_dir() . '/odv_resume_' . getmypid();
$rrepo = $rh . '/repo'; @mkdir($rrepo, 0777, true);
shell_exec('cd ' . escapeshellarg($rrepo) . ' && git init -q && git config user.email t@t && git config user.name t && echo x > a.txt && git add -A && git commit -qm init 2>/dev/null');
$commit = trim((string) shell_exec('cd ' . escapeshellarg($rrepo) . ' && git rev-parse HEAD 2>/dev/null'));
@mkdir($rh . '/.ollamadev/crew/crew_r', 0777, true);
$runjson = json_encode(['runId' => 'crew_r', 'base' => 'main', 'baseCommit' => $commit, 'task' => 'demo',
    'status' => 'running', 'opts' => ['coderModel' => 'qwen2.5-coder:7b', 'audit' => false, 'research' => false], 'subtasks' => []]);
file_put_contents($rh . '/.ollamadev/crew/crew_r/run.json', $runjson);
file_put_contents($rh . '/.ollamadev/crew/current.json', $runjson);
run_bin(['crew', 'resume', 'crew_r', '--coder-model', 'llama3.3:70b'], '', ['HOME' => $rh], $rrepo);
$rafter = (string) @file_get_contents($rh . '/.ollamadev/crew/crew_r/run.json');
ok('crew resume --coder-model persists the new model to the run', strpos($rafter, 'llama3.3:70b') !== false &&
    strpos($rafter, 'qwen2.5-coder:7b') === false, substr($rafter, 0, 200));
shell_exec('rm -rf ' . escapeshellarg($rh));
$bind = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/src/Bindings.php');
ok('desktop exposes crewSteer for the Director box', strpos($bind, 'function crewSteer') !== false && strpos($bind, "'crewSteer'") !== false);
// The Director in its own terminal: live auto-refreshing console + whole-crew broadcast.
ok('crew director is a live steering console (auto-refresh + "all" broadcast)', strpos($src, "\$arg1 === 'director'") !== false &&
    strpos($src, 'Director console') !== false && strpos($src, '$ts !== $lastTs') !== false &&
    strpos($src, "'all', '*', 'everyone'") !== false && strpos($src, '0 = broadcast to the whole crew') !== false);
$ajs = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/app.js');
ok('desktop opens a dedicated Director terminal on crew launch', strpos($ajs, 'openDirectorTerminal') !== false && strpos($ajs, 'crew director') !== false);
// Per-terminal working folder: each desktop terminal can run in its own directory.
ok('desktop terminals support a per-terminal working folder', strpos($bind, 'termCreate(string $id, string $model, string $cwd') !== false &&
    strpos($ajs, 'changeTermFolder') !== false && strpos($ajs, "class=\"term-cd\"") !== false &&
    strpos($ajs, 'cdPrefix: function (cwd)') !== false && strpos($ajs, 'expandHome') !== false);

// ---- Crew cockpit: live topology, voice control, activity feed, model defaults ----
// (1) Engine enriches the live board with per-role models, branch + files + audit
//     verdict — ADDITIVE display fields; orchestration is untouched.
$ihtml_c = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/index.html');
$brjs = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/web/bridge.js');
ok('crew board carries per-role models + branch for the topology view',
    strpos($src, "'models' => ['director' => \$mDirector") !== false &&
    strpos($src, "'branch' => self::branchFor(\$runId, \$i + 1") !== false);
ok('crew board gains a setMeta channel for files + audit verdict (additive)',
    strpos($src, '$setMeta = function (int $n, array $kv)') !== false &&
    strpos($src, "'audit' => \$av, 'issues' =>") !== false &&
    strpos($src, '?callable $setMeta = null') !== false);
// (2) Per-role model defaults persist via the shared bridge (crew.*Model in config).
ok('desktop/web can save per-role crew models as defaults',
    strpos($bind, 'function setCrewModels') !== false && strpos($bind, "'setCrewModels'") !== false &&
    strpos($brjs, "'setCrewModels'") !== false &&
    strpos($ajs, 'saveCrewModels: function') !== false && strpos($ihtml_c, 'id="crewModelsDefault"') !== false);
// (3) Live topology window — a read-only map over the (now enriched) crew board.
ok('crew topology is a canvas window wired to the live board',
    strpos($ajs, 'var Topology = {') !== false && strpos($ajs, "topology: '#topologyView'") !== false &&
    strpos($ajs, "'topology'") !== false && strpos($ihtml_c, 'id="topologyView"') !== false &&
    strpos($ihtml_c, 'data-add="topology"') !== false);
// (4) Real-time activity feed parsed from each coder's log tail.
ok('crew panes parse live per-coder activity (editing/reading/running)',
    strpos($ajs, 'parseActivity: function') !== false && strpos($ajs, 'self.activity[n] = self.parseActivity') !== false);
// Roles / Skills / Hooks are directly openable from the ＋ Add / right-click menu
// (previously only reachable through the Crew window). addPane already routes them.
ok('Roles, Skills, Hooks are direct ＋ Add menu entries',
    strpos($ihtml_c, 'data-add="skills"') !== false && strpos($ihtml_c, 'data-add="roles"') !== false &&
    strpos($ihtml_c, 'data-add="hooks"') !== false &&
    strpos($ajs, 'skills: function () { SkillMgr.open(); }') !== false);
// (5) Voice drives the EXISTING crew (start + steer) — no new orchestration path.
ok('voice can start + steer the crew via runCrew / crewSteer',
    strpos($ajs, 'voiceStartCrew: function') !== false && strpos($ajs, 'steerCrew: function') !== false &&
    strpos($ajs, 'tell|steer|have|ask)\\s+coder') !== false);
// Desktop Skills manager: list / view / create-edit / remove, backed by the CLI.
$shk = sys_get_temp_dir() . '/skills_mgr_' . getmypid(); @mkdir($shk, 0777, true);
[$so, , $sc] = run_bin(['skills', 'save', 'Demo Skill'], '{"description":"d","body":"# Demo\nBody."}', ['HOME' => $shk]);
[$sl] = run_bin(['skills', 'list', '--json'], '', ['HOME' => $shk]);
[$sg] = run_bin(['skills', 'show', 'Demo Skill', '--json'], '', ['HOME' => $shk]);
ok('skills save/list/show --json round-trip (CLI surface for the desktop)', $sc === 0 &&
    strpos($sl, '"name":"Demo Skill"') !== false && strpos($sg, '"body":"# Demo') !== false);
shell_exec('rm -rf ' . escapeshellarg($shk));
ok('desktop exposes skill bindings + a Skills manager UI', strpos($bind, 'function skillsList') !== false &&
    strpos($bind, 'function skillsSave') !== false && strpos($bind, "'skillsList'") !== false &&
    strpos($ajs, 'var SkillMgr') !== false && strpos($ajs, 'SkillMgr.bind()') !== false);
// Full skills CRUD UX: a New button, create-vs-edit mode (locks the name when
// editing so an update doesn't silently fork a new skill), and a one-click
// "add crew-template skills" that copies the template-injected built-ins to disk.
ok('Skills window has full CRUD UX (new / edit-mode / add-template-skills)',
    strpos($ajs, 'newSkill: function') !== false && strpos($ajs, 'setMode: function') !== false &&
    strpos($ajs, 'addTemplateSkills: function') !== false &&
    strpos($ihtml_c, 'id="skillNew"') !== false && strpos($ihtml_c, 'id="skillAddTemplates"') !== false &&
    strpos($ihtml_c, 'id="skillFormStatus"') !== false);
// Every crew TEAM has a matching, listed + readable + editable skill (teamLibrary),
// surfaced in the manager via allBuiltins() — without changing crew focus-matching.
ok('each crew team has a matching skill in the manager (teamLibrary)',
    strpos($src, 'function teamLibrary') !== false && strpos($src, 'function allBuiltins') !== false &&
    strpos($src, "'ecommerce', 'E-commerce") !== false && strpos($src, 'CrewSkills::allBuiltins()') !== false);
// Functional: the binary lists the per-team skills as built-ins (31 capability + 34 team).
$tsl = json_decode((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/ollamadev') . ' skills list --json 2>/dev/null'), true);
$tsBuilt = is_array($tsl) ? array_filter($tsl['skills'] ?? [], fn($s) => $s['builtin'] ?? false) : [];
$tsNames = array_map(fn($s) => $s['name'], $tsBuilt);
ok('crew-team skills are listed by the engine (website/ecommerce/saas present, 60+ built-ins)',
    count($tsBuilt) >= 60 && in_array('website', $tsNames, true) && in_array('ecommerce', $tsNames, true) && in_array('saas', $tsNames, true),
    'built-in count: ' . count($tsBuilt));
// Picking a by-project-type team auto-loads its team skill: the engine can force a
// team skill by name (byNames → allBuiltins), and the UI maps each team → its slug.
ok('a crew team auto-loads its matching skill (engine forces it by name + UI maps it)',
    strpos($src, '$lib = self::allBuiltins();   // capability + per-team skills') !== false &&
    strpos($ajs, 'TEAM_SKILL: {') !== false && strpos($ajs, 'teamSkillFor: function') !== false &&
    strpos($ajs, "this.crewTeamSkill = (p.group === 'domain')") !== false &&
    strpos($ajs, 'self.crewTeamSkill ? [self.crewTeamSkill]') !== false);
// Desktop Hooks panel: bindings + bridge + UI, backed by `ollamadev hooks --json`.
$ihtml_h = (string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/index.html');
ok('desktop exposes hooks bindings + a Hooks panel UI', strpos($bind, 'function hooksList') !== false &&
    strpos($bind, 'function hooksAdd') !== false && strpos($bind, 'function hooksRemove') !== false &&
    strpos($bind, "'hooksList'") !== false &&
    strpos($ajs, 'var HookMgr') !== false && strpos($ajs, 'HookMgr.bind()') !== false &&
    strpos($ihtml_h, 'id="hooksOverlay"') !== false && strpos($ihtml_h, 'id="manageHooks"') !== false);
ok('hooks JSON surface for the panel', strpos($src, "\$argv[1] === 'hooks'") !== false &&
    strpos($src, 'Hooks::configuredData()') !== false);
// Functional: the JSON surface the panel reads round-trips through the binary.
if (isset($BIN) && is_file($BIN)) {
    $hj = sys_get_temp_dir() . '/odv_hooksjson_' . getmypid(); @mkdir($hj . '/.ollamadev', 0777, true);
    run_bin(['hooks', 'add', 'PostToolUse', 'echo hi'], '', ['HOME' => $hj]);
    [$hjson] = run_bin(['hooks', 'list', '--json'], '', ['HOME' => $hj]);
    $hd = json_decode(trim($hjson), true);
    ok('hooks list --json feeds the panel', is_array($hd) && isset($hd['hooks'][0]['event']) &&
        $hd['hooks'][0]['event'] === 'PostToolUse' && !empty($hd['events']));
    @exec('rm -rf ' . escapeshellarg($hj));
}
// Plain shell as the top entry of the model dropdown (no ollamadev when chosen).
ok('desktop offers a plain shell via the model list', strpos($ajs, 'value="shell"') !== false &&
    strpos($ajs, "isShell = (model === 'shell')") !== false && strpos($ajs, 'if (!isShell) self.launchCli') !== false &&
    strpos($ajs, 'realModel: function') !== false && strpos($ajs, 'kind: t.kind') !== false);
// Desktop: a cleared sentinel wipes the localStorage-only manual cards (once, watermarked).
$appjs = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/app.js');
ok('desktop applies the cleared sentinel to manual cards', strpos($appjs, 'ade.boardCleared') !== false &&
    strpos($appjs, "removeItem('ade.tasks')") !== false && strpos($appjs, 'b.cleared') !== false);
// Source wiring: the four roles + worktree + json-mode are present
ok('crew has a Researcher role', strpos($src, 'Researcher worker') !== false || strpos($src, 'function research(') !== false);
ok('crew Director uses JSON mode', strpos($src, 'chatJson(') !== false);
ok('crew Coder uses git worktrees', strpos($src, 'git worktree add') !== false);
ok('crew Auditor + auto-merge wired', strpos($src, "git merge --no-ff") !== false && strpos($src, 'function audit(') !== false);
// Amplify: self-consistency planning + an adversarial multi-pass audit panel.
ok('crew amplify: plan self-consistency', strpos($src, 'function planOnce(') !== false && strpos($src, 'array_count_values(array_map(\'count\'') !== false);
ok('crew amplify: adversarial audit panel', strpos($src, 'function auditOnce(') !== false && strpos($src, '$clean > $passes / 2') !== false);
ok('crew amplify: skeptic reviewer stance', strpos($src, 'SKEPTICAL adversarial reviewer') !== false);
ok('--amplify flag wired to crew opts', strpos($src, "\$flagOpts['amplify']") !== false);
// Resume-from-disk: persist the plan, detect interrupted runs, re-enter the pipeline.
ok('crew persists a resumable plan (run.json + prompts)', strpos($src, "self::saveRun(") !== false && strpos($src, "'prompt' => \$st['prompt']") !== false);
ok('crew resume + findResumable exist', strpos($src, 'function resume(') !== false && strpos($src, 'function findResumable(') !== false);
ok('crew run marks status done', strpos($src, "self::setRunStatus(\$runId, 'done')") !== false);
ok('explicit -m overrides a resumed session model (desktop model change works)', strpos($src, 'function useModel(') !== false && strpos($src, '$session->useModel($flags[') !== false);
ok('audit+land shared by run and resume', strpos($src, 'function auditAndLand(') !== false);
ok('auditor → coder fix-back (one bounded pass, not a chat)', strpos($src, 'Auditor → coder fix-back') !== false && strpos($src, "Config::get('crew.repairRounds', 1)") !== false && strpos($src, 'FLAGGED it. Fix ONLY these problems') !== false);
ok('crew resume subcommand wired', strpos($src, "\$arg1 === 'resume'") !== false && strpos($src, 'Crew::resume(') !== false);
ok('interactive crew offers to resume', strpos($src, 'Crew::findResumable()') !== false && strpos($src, 'Resume it?') !== false);
// Auto-ideas: every crew run surfaces ranked next-step suggestions (not implemented).
ok('crew auto-suggests next-step ideas', strpos($src, 'function offerIdeas(') !== false && strpos($src, 'function suggestNext(') !== false);
ok('crew run + resume both call offerIdeas', substr_count($src, 'self::offerIdeas(') >= 2);
ok('ideas are informational, not auto-applied', strpos($src, 'not applied') !== false);
ok('--no-ideas flag wired', strpos($src, "\$flagOpts['ideas'] = false") !== false);
$ideaJs = (string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/app.js');
ok('desktop board renders idea cards', strpos($ideaJs, 'run-idea') !== false && strpos($ideaJs, 'crewBoard.ideas') !== false);

echo "\n== Ollama cloud models ==\n";
if (preg_match('/class Models \{.*?\n\}/s', $src, $cm)) {
    if (!class_exists('Models')) eval($cm[0]);
    ok('Models::isCloud detects -cloud and :cloud tags',
        Models::isCloud('qwen3-coder:480b-cloud') === true && Models::isCloud('glm-4.6:cloud') === true
        && Models::isCloud('qwen2.5-coder:7b') === false && Models::isCloud('mistral:latest') === false);
    ok('Models::isCloudEntry trusts the /api/tags remote host',
        Models::isCloudEntry(['name' => 'x:latest', 'remote_host' => 'https://ollama.com:443']) === true
        && Models::isCloudEntry(['name' => 'glm-4.6:cloud']) === true
        && Models::isCloudEntry(['name' => 'qwen2.5-coder:7b']) === false);
    ok('cloud catalog is curated, tool-capable, and all :cloud/-cloud tags',
        count(Models::cloudPresets()) >= 4
        && array_reduce(Models::cloudPresets(), fn($a, $p) => $a && Models::isCloud($p['tag']) && !empty($p['tools']), true));
    ok('cloud aliases resolve to their tag', Models::resolveTag('gpt-oss-cloud') === 'gpt-oss:120b-cloud');
    ok('cloud models are kept OUT of the local fallback chain',
        array_reduce(Models::defaultChain(), fn($a, $t) => $a && !Models::isCloud($t), true));
} else { ok('Models class extractable', false); }
// chatJson/chatStructured must survive a model that fences its JSON in ```json … ```
// (real cloud behavior even with format:json) — the root cause of the crew falling
// back to a single subtask. decodeLoose strips fences / extracts the JSON block.
if (preg_match('/class OllamaClient \{.*?\n\}/s', $src, $ocd)) {
    $cls = str_replace(
        ['class OllamaClient ', 'private static function decodeLoose', 'private static function extractBalanced'],
        ['class OllamaClientDL ', 'public static function decodeLoose', 'public static function extractBalanced'],
        $ocd[0]);
    if (!class_exists('OllamaClientDL')) eval($cls);
    ok('decodeLoose unwraps a ```json fenced object',
        (OllamaClientDL::decodeLoose("```json\n{\"subtasks\":[{\"prompt\":\"a\"}]}\n```")['subtasks'][0]['prompt'] ?? '') === 'a');
    ok('decodeLoose still reads bare JSON', (OllamaClientDL::decodeLoose('{"x":1}')['x'] ?? null) === 1);
    ok('decodeLoose extracts JSON embedded in prose', (OllamaClientDL::decodeLoose('Sure: {"y":2} done.')['y'] ?? null) === 2);
    ok('decodeLoose ignores braces inside string values',
        (OllamaClientDL::decodeLoose('{"p":"has } a brace"}')['p'] ?? '') === 'has } a brace');
    ok('decodeLoose returns null when there is no JSON', OllamaClientDL::decodeLoose('no json at all') === null);
} else { ok('OllamaClient class extractable for decodeLoose', false); }
ok('CLI surfaces cloud models (models cloud command + signin guidance + badge)',
    strpos($src, "\$sub === 'cloud'") !== false && strpos($src, 'ollama signin') !== false
    && strpos($src, 'Models::isCloud(') !== false && strpos($src, '☁') !== false);
// Cloud models get their FULL context window — the local VRAM cap is bypassed —
// while local models stay clamped to maxContextWindow. Unreachable host ⇒
// modelContextLength returns 0 ⇒ deterministic fallbacks.
if (!class_exists('Config')) { require_once __DIR__ . '/../src/10-config.php'; }
if (!class_exists('Models') && preg_match('/class Models \{.*?\n\}/s', $src, $mm)) eval($mm[0]);
if (!class_exists('OllamaClient') && preg_match('/class OllamaClient \{.*?\n\}/s', $src, $ocm)) eval($ocm[0]);
if (class_exists('OllamaClient') && class_exists('Models')) {
    $NULLHOST = 'http://127.0.0.1:1';
    $cloudCtx = OllamaClient::chatOptions('glm-4.6:cloud', $NULLHOST)['num_ctx'];
    $localCtx = OllamaClient::chatOptions('qwen2.5-coder:7b', $NULLHOST)['num_ctx'];
    ok('cloud model bypasses the VRAM cap (full-context fallback when length unknown)', $cloudCtx === 131072, "got=$cloudCtx");
    ok('local model stays clamped to maxContextWindow', $localCtx === 16384, "got=$localCtx");
} else { ok('OllamaClient + Models available for chatOptions test', false); }

// Edit tool robustness: when an exact old_string match fails, a whitespace-tolerant
// fallback rescues a slightly-off old_string (the #1 weak-model edit failure) — but
// ONLY on an unambiguous single match; it never guesses between candidate sites.
if (preg_match('/function editFuzzyFind\(.*?\n\}/s', $src, $efm)) {
    eval($efm[0]);
    $doc = "<?php\nfunction f() {\n        return 1\n}\n";   // 8-space indent in the file
    $r1 = editFuzzyFind($doc, "return 1");                    // model omitted the indent
    $r2 = editFuzzyFind($doc, "return  1");                   // model used 2 spaces
    $amb = editFuzzyFind("a=1\nb=1\n", "= 1");                // two candidate sites
    ok('edit fuzzy-match rescues off-whitespace old_string', is_array($r1) && $r1[1] === 'return 1' && is_array($r2));
    ok('edit fuzzy-match refuses ambiguous (multi-site) matches', $amb === null);
} else { ok('editFuzzyFind extractable', false); }
ok('edit + multi_edit fall back to editFuzzyFind on exact-match miss', substr_count($src, 'editFuzzyFind(') >= 3);
ok('edit requires a UNIQUE old_string (no silent first-of-many match)',
    strpos($src, 'substr_count($content, $oldStr)') !== false && strpos($src, 'old_string appears') !== false);
ok('edit accepts a literal "0" old_string (=== "" not empty())',
    strpos($src, "if (\$oldStr === '') return \"missing old_string\"") !== false);

// --- audit fixes: summarize, cloud-auth, web-serve guard, per-coder models, expanded eval ---
$serverSrc = (string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/web/server.php');
ok('summarize is a real model summary (placeholder gone)',
    strpos($src, 'Summary placeholder') === false && strpos($src, "Tools::register('summarize'") !== false && strpos($src, 'Summarize the following') !== false);
ok('cloud-auth: ollama signin guidance wired into preflight + chat startup',
    strpos($src, 'cloudAuthError') !== false && substr_count($src, 'cloudAuthError(') >= 3 && strpos($src, 'ollama signin') !== false);
ok('web server is loopback-only without a token (no LAN exposure by default)',
    strpos($serverSrc, '$isLoopback') !== false && strpos($serverSrc, "\$token === '' && !\$isLoopback") !== false);
ok('crew supports per-coder models (--coder-models, round-robin)',
    strpos($src, '--coder-models') !== false && strpos($src, 'coderModelFor') !== false && strpos($src, 'function modelList(') !== false);
ok('eval suite expanded with harder tasks (algorithms/class/multi-file/multi-bug)',
    strpos($src, "'binary-search'") !== false && strpos($src, "'bank-class'") !== false
    && strpos($src, "'refactor-extract'") !== false && strpos($src, "'fix-two-bugs'") !== false);

// --- robustness hardening: bad-UTF8 payloads, binary/huge files, huge diffs ---
if (preg_match('/public static function jenc\(.*?\n    \}/s', $src, $jm)) {
    eval('class _JENC { ' . $jm[0] . ' }');
    $enc = _JENC::jenc(['content' => "bad \x80\xFF bytes"]);   // invalid UTF-8
    ok('jenc survives invalid UTF-8 (no false/empty request body)', is_string($enc) && $enc !== '{}' && strpos($enc, 'bad') !== false);
} else { ok('jenc extractable', false); }
ok('chat/generate payloads go through jenc (UTF-8-safe)', substr_count($src, 'self::jenc($params)') >= 5);
ok('view tool guards binary files + caps lines (streamed slice, not whole file)',
    strpos($src, 'Binary file') !== false && strpos($src, "strpos(\$head, \"\\0\")") !== false
    && strpos($src, 'fgets($fh)') !== false && strpos($src, ': 2000;') !== false);
if (class_exists('DiffView')) {
    $big = str_repeat("x\n", 30000);
    $t0 = microtime(true); $d = DiffView::unified($big, $big . 'y'); $ms = (microtime(true) - $t0) * 1000;
    ok('DiffView guards huge content (no O(n*m) hang)', strpos($d, 'large change') !== false && $ms < 1000, 'ms=' . round($ms));
} else { ok('DiffView available for huge-content guard test', false); }

// bash/grep: hard timeout + output cap so a hanging or runaway command can't wedge
// the agent or flood memory.
if (preg_match('/function runShell\(.*?\n\}/s', $src, $rsm)) {
    eval($rsm[0]);
    $t0 = microtime(true); $r = runShell('sleep 5', 1); $el = microtime(true) - $t0;
    ok('runShell kills a hanging command at the timeout', $el < 3.5 && strpos($r, 'killed') !== false, 'el=' . round($el, 1) . 's');
    $r2 = runShell('yes ABCDEFGHIJ 2>/dev/null | head -c 500000', 10, 10000);
    ok('runShell caps runaway output', strlen($r2) < 30000 && strpos($r2, 'truncated') !== false, 'len=' . strlen($r2));
} else { ok('runShell extractable', false); }
ok('bash + grep route through runShell (timeout + output cap)', substr_count($src, 'runShell(') >= 4);
ok('grep skips binary files (-rIn) and bounds output', strpos($src, 'grep -rIn') !== false);
ok('glob caps its result list', strpos($src, 'array_slice($files, 0, 500)') !== false);
ok('crew holds branches on a dirty tree / detached HEAD (no false merge)',
    strpos($src, 'uncommitted changes in the working tree') !== false
    && strpos($src, 'detached HEAD') !== false
    && strpos($src, 'status --porcelain --untracked-files=no') !== false);

// Network-flake resilience: transient failures (dropped conn / 5xx) retry with
// backoff; a clean 4xx doesn't (it won't fix itself).
if (preg_match('/public static function isTransient\(.*?\n    \}/s', $src, $itm)) {
    eval('class _ISTRAN { ' . $itm[0] . ' }');
    ok('isTransient retries dropped-conn + 5xx, never a clean 4xx/200',
        _ISTRAN::isTransient(56, 0) && _ISTRAN::isTransient(7, 0) && _ISTRAN::isTransient(0, 503)
        && !_ISTRAN::isTransient(0, 404) && !_ISTRAN::isTransient(0, 401) && !_ISTRAN::isTransient(0, 200));
} else { ok('isTransient extractable', false); }
ok('non-streaming chat (chatJson/chatStructured) retries transient failures',
    strpos($src, 'function postJsonRetry(') !== false && strpos($src, 'self::isTransient($errno, $code)') !== false
    && substr_count($src, 'self::retryWait(') >= 3);
ok('streaming retry only fires before any token streamed (no duplication)',
    strpos($src, "\$content === '' && !\$aborted && \$attempt < self::\$maxAttempts") !== false);

// Ecosystem / onboarding / measurable quality: built-in crew packs, hardware-aware
// setup, eval --compare across models.
if (preg_match('/class CrewPacks \{.*?\n\}/s', $src, $cpm)) {
    if (!class_exists('CrewPacks')) eval($cpm[0]);
    $b = CrewPacks::builtins();
    ok('crew ships built-in packs (web-app, rest-api, bugfix, …)',
        count($b) >= 6 && isset($b['bugfix']) && isset($b['web-app']) && isset($b['rest-api']));
    $bp = CrewPacks::load('bugfix');
    ok('crew pack load falls back to a built-in (focus + amplify)',
        is_array($bp) && !empty($bp['focus']) && (int)($bp['amplify'] ?? 0) === 3);
} else { ok('CrewPacks extractable', false); }
$mainSrc0 = (string)@file_get_contents(dirname(__DIR__) . '/src/99-main.php');
ok('eval --compare runs the suite across models', strpos($mainSrc0, "'--compare'") !== false
    && strpos($mainSrc0, "json_encode(['compare'") !== false);
ok('setup: hardware-aware onboarding (detect → recommend → pull → set default)',
    strpos($mainSrc0, "\$argv[1] === 'setup'") !== false && strpos($mainSrc0, 'nvidia-smi') !== false
    && strpos($mainSrc0, 'Recommended:') !== false && strpos($mainSrc0, "Config::persist('ollama.defaultModel'") !== false);

// Crash-safe state writes: temp + atomic rename, no half-written session/config/board.
ok('atomicWrite is crash-safe (temp + rename, no leftover .tmp)', (function () {
    if (!function_exists('atomicWrite')) return false;
    $d = sys_get_temp_dir() . '/awt_' . getmypid(); @mkdir($d, 0755, true);
    atomicWrite("$d/s.json", '{"x":1}');
    $ok = is_file("$d/s.json") && file_get_contents("$d/s.json") === '{"x":1}' && count(glob("$d/*.tmp*")) === 0;
    foreach (glob("$d/*") ?: [] as $f) @unlink($f); @rmdir($d);
    return $ok;
})());
ok('session/config/board/checkpoints/memory write atomically', substr_count($src, 'atomicWrite(') >= 6);

// Continue-where-left-off + symlink/permission + partial-pull resume.
ok('reopening shows the last exchange (visible resume, not a blank prompt)',
    strpos($src, 'continuing where you left off') !== false && strpos($src, '$lastUser') !== false
    && strpos($src, '$lastAsst') !== false);
ok('find/tree skip unreadable dirs instead of aborting (CATCH_GET_CHILD)',
    substr_count($src, 'CATCH_GET_CHILD') >= 2);
ok('model pull auto-resumes a transient network drop (Ollama resumes from cache)',
    strpos($src, 'resuming pull') !== false && strpos($src, 'OllamaClient::isTransient($errno, $code)') !== false);
ok('doctor health-check command (Ollama/model/GPU/disk/git, with fixes + --json)',
    strpos($mainSrc0, "\$argv[1] === 'doctor'") !== false && strpos($mainSrc0, '🩺 OllamaDev doctor') !== false
    && strpos($mainSrc0, 'Ollama reachable') !== false && strpos($mainSrc0, 'disk headroom') !== false);
ok('--careful self-review pass (re-check + fix own work — lifts weak models on hard tasks)',
    strpos($mainSrc0, "'--careful'") !== false && strpos($src, "Config::get('agents.selfReview'") !== false
    && strpos($src, 'check them against the ORIGINAL task') !== false && strpos($src, '🔎 self-review') !== false);
ok('--light / keepAlive resource controls (small KV cache, unload idle, leave CPU, no crew parallel)',
    strpos($mainSrc0, "'--light'") !== false && strpos($src, 'ollama.lowResource') !== false
    && strpos($src, 'function keepAlive(') !== false && strpos($src, "'keep_alive' => \$ka") !== false
    && strpos($src, 'ollama.lowResourceCtx') !== false);
ok('--light throttles only LOCAL crew coders — cloud coders still run in parallel',
    strpos($src, '$anyLocalCoder') !== false && strpos($src, '$lowResLocal') !== false
    && strpos($src, 'Models::isCloud((string)$cm)') !== false);

echo "\n== Air-gap attestation removed; web-access toggle kept ==\n";
ok('no Attest class / attest command / air-gap naming remains',
    strpos($src, 'class Attest') === false && strpos($src, "=== 'attest'") === false
    && strpos($src, 'setOffline') === false && strpos($src, 'isOffline') === false
    && strpos($src, "'--offline'") === false && strpos($src, '--air-gapped') === false
    && strpos($src, 'OLLAMADEV_OFFLINE') === false && stripos($src, 'air-gap') === false);
if (preg_match('/class Permission \{.*?\n\}/s', $src, $pm)) {
    if (!class_exists('Permission')) eval($pm[0]);
    Permission::setMode('auto');
    ok('web access ON lets network tools through', Permission::check('fetch') === true && Permission::check('git_push') === true);
    Permission::setWebAccess(false);
    ok('web access OFF blocks network tools (but not local readonly)',
        Permission::check('fetch') === false && Permission::check('git_push') === false && Permission::check('view') === true);
    Permission::allow('fetch');
    ok('web access OFF overrides an explicit allow()', Permission::check('fetch') === false);
    Permission::setWebAccess(true);
    Permission::setMode('ask');
} else { ok('Permission class extractable', false); }
ok('CLI wires the --no-web flag + web.enabled config', strpos($src, "'--no-web'") !== false &&
    strpos($src, "Config::get('web.enabled', true)") !== false && strpos($src, 'Permission::setWebAccess(false)') !== false);

echo "\n== Skill registry + crew packs ==\n";
ok('skills search/browse/add wired', strpos($src, "\$sub === 'browse'") !== false && strpos($src, 'Skills::addFromRegistry(') !== false);
ok('Skills has registry methods', strpos($src, 'function browse(') !== false && strpos($src, 'function search(') !== false && strpos($src, 'function registries(') !== false);
if (preg_match('/class CrewPacks \{.*?\n\}/s', $src, $cpm)) {
    if (!class_exists('CrewPacks')) eval($cpm[0]);
    $packName = 'smoke_pack_' . getmypid();
    $path = CrewPacks::save($packName, ['focus' => 'Test stack', 'coderModel' => 'codestral', 'amplify' => 3, 'max' => 2, 'runId' => 'should-not-persist']);
    ok('CrewPacks::save writes a pack', is_file($path));
    $loaded = CrewPacks::load($packName);
    ok('CrewPacks::load round-trips team keys', is_array($loaded) && ($loaded['focus'] ?? '') === 'Test stack' && (int)($loaded['amplify'] ?? 0) === 3);
    ok('CrewPacks drops one-off keys (runId)', is_array($loaded) && !isset($loaded['runId']));
    ok('CrewPacks::all lists the pack', array_key_exists($packName, CrewPacks::all()));
    ok('CrewPacks::remove deletes it', CrewPacks::remove($packName) && CrewPacks::load($packName) === null);
} else { ok('CrewPacks class extractable', false); }
ok('crew --pack flag + pack subcommands wired', strpos($src, "\$arg1 === 'pack'") !== false && strpos($src, 'CrewPacks::load($flags[') !== false);

echo "\n== Watch (background agent) ==\n";
[$wout] = run_bin(['watch']);
ok('watch with no task prints usage', stripos($wout, 'Usage: ollamadev watch') !== false, trim($wout));
if (preg_match('/class Watcher \{.*?\n\}/s', $src, $wm)) {
    if (!class_exists('Watcher')) eval($wm[0]);
    $snap = new ReflectionMethod('Watcher', 'snapshot');  $snap->setAccessible(true);
    $diff = new ReflectionMethod('Watcher', 'diff');      $diff->setAccessible(true);
    $wd = sys_get_temp_dir() . '/odv_watch_' . getmypid(); @mkdir($wd, 0755, true);
    file_put_contents("$wd/a.php", "<?php // 1");
    @mkdir("$wd/node_modules", 0755, true); file_put_contents("$wd/node_modules/skip.js", "x"); // must be ignored
    file_put_contents("$wd/pic.png", "binary");  // wrong extension — ignored
    $s1 = $snap->invoke(null, [$wd]);
    ok('watch snapshot picks source files', isset($s1["$wd/a.php"]));
    ok('watch snapshot skips node_modules + non-source', !isset($s1["$wd/node_modules/skip.js"]) && !isset($s1["$wd/pic.png"]));
    clearstatcache();
    touch("$wd/a.php", time() + 5); file_put_contents("$wd/b.php", "<?php // new");
    $s2 = $snap->invoke(null, [$wd]);
    $changed = $diff->invoke(null, $s1, $s2);
    ok('watch diff detects modified + new files', in_array("$wd/a.php", $changed, true) && in_array("$wd/b.php", $changed, true));
    @exec('rm -rf ' . escapeshellarg($wd));
} else { ok('Watcher class extractable', false); }
ok('watch command + flags wired', strpos($src, "cmd === 'watch'") !== false && strpos($src, "Watcher::run(") !== false);

echo "\n== Per-repo session resume ==\n";
ok('session save records cwd', strpos($src, "'cwd' => \$this->cwd") !== false);
ok('Session::latestForCwd exists', strpos($src, 'function latestForCwd(') !== false);
ok('bare run resumes this repo (autoResume)', strpos($src, 'Session::latestForCwd($config, getcwd()') !== false && strpos($src, "Config::get('session.autoResume'") !== false);
ok('--new forces a fresh session', strpos($src, "\$flags['new']") !== false);

echo "\n== Terminal multiplexer ==\n";
if (preg_match('/class TerminalManager.*?\n\}/s', $src, $tmm)) {
    if (!class_exists('TerminalManager')) eval($tmm[0]);
    $thome = sys_get_temp_dir() . '/odv_term_' . getmypid();
    @mkdir($thome . '/.ollamadev/terminals/term_desktop_x', 0755, true);
    // A desktop-app record: keyed by 'id', with no 'name'/'status' (the schema
    // that used to make `terminal list` warn).
    file_put_contents($thome . '/.ollamadev/terminals/term_desktop_x/session.json',
        json_encode(['id' => 'term_desktop_x', 'model' => 'wizardlm', 'cwd' => '/x', 'pid' => '99']));
    $oldHome = getenv('HOME'); putenv("HOME=$thome");
    $tm = new TerminalManager();
    $rec = $tm->loadTerminal('term_desktop_x');
    ok('terminal loadTerminal backfills name/status from desktop schema',
       is_array($rec) && ($rec['name'] ?? '') === 'term_desktop_x' && isset($rec['status']));
    ok('terminal list tolerates desktop-schema records', count($tm->list()) === 1);
    putenv($oldHome !== false ? "HOME=$oldHome" : 'HOME');
    @exec('rm -rf ' . escapeshellarg($thome));
} else { ok('TerminalManager extractable', false); }

echo "\n== Speech-to-text (local, engine-agnostic) ==\n";
if (preg_match('/class SttClient \{.*?\n\}/s', $src, $stm)) {
    if (!class_exists('SttClient')) eval($stm[0]);
    Config::set('stt.host', ''); Config::set('stt.command', '');
    ok('STT disabled when unconfigured', SttClient::enabled() === false);
    Config::set('stt.command', 'cat'); // fake engine: echoes the "audio" file's text back
    ok('STT enabled with a command engine', SttClient::enabled() === true);
    $sf = sys_get_temp_dir() . '/odv_stt_' . getmypid() . '.txt'; file_put_contents($sf, 'spoken words here');
    ok('STT command-mode transcribes via the local engine', SttClient::transcribe($sf) === 'spoken words here');
    @unlink($sf);
    ok('STT returns empty for a missing file', SttClient::transcribe('/no/such/file.wav') === '');
    Config::set('stt.command', '');
} else { ok('SttClient extractable', false); }
ok('transcribe command + --enabled wired', strpos($src, "=== 'transcribe'") !== false && strpos($src, "=== '--enabled'") !== false);
// Voice (/voice): zero-config Whisper auto-detect, mic recording, CPU-only.
// (Asserted against $src — the full concatenated source — since per-file
// $repoRoot loads aren't available this early in the script.)
ok('STT auto-detects an open-source Whisper engine (zero config)',
    strpos($src, 'function available(') !== false && strpos($src, 'function detectedEngine(') !== false &&
    strpos($src, 'function viaAuto(') !== false);
ok('STT auto path is CPU-only', strpos($src, '--device cpu') !== false && strpos($src, '--fp16 False') !== false);
ok('STT can record the mic (arecord/ffmpeg/parecord)',
    strpos($src, 'function startRecording(') !== false && strpos($src, 'function stopRecording(') !== false &&
    strpos($src, 'arecord') !== false);
ok('/voice (and /listen) wired into the session as a command',
    strpos($src, "'voice', 'listen'") !== false && strpos($src, 'function voiceInput(') !== false);
ok('transcribe + mic availability gate on available() not just explicit config',
    strpos($src, 'SttClient::available()') !== false);
ok('STT model is selectable (stt.model getter/setter + sizes)',
    strpos($src, 'function model(') !== false && strpos($src, 'function setModel(') !== false &&
    strpos($src, 'function modelSizes(') !== false);
ok('/voice model and /voice status subcommands wired',
    strpos($src, "\$sub === 'model'") !== false && strpos($src, "\$sub === 'status'") !== false);
// Voice history round-trips through SttClient (log → read → clear).
if (class_exists('SttClient') && method_exists('SttClient', 'logHistory')) {
    $hf = SttClient::historyFile();
    $bak = is_file($hf) ? file_get_contents($hf) : null;   // preserve any real history
    SttClient::clearHistory();
    SttClient::logHistory('first voice note', 'base', 'openai-whisper', 1700000000);
    SttClient::logHistory('second voice note', 'small', 'openai-whisper', 1700000060);
    $h = SttClient::history(10);
    ok('Voice history logs + reads back oldest→newest',
        count($h) === 2 && ($h[0]['text'] ?? '') === 'first voice note' && ($h[1]['model'] ?? '') === 'small');
    ok('Voice history empty entries are skipped', (SttClient::logHistory('   ', 'base', 'x', 1) === null) && count(SttClient::history(10)) === 2);
    SttClient::clearHistory();
    ok('Voice history clears', SttClient::history(10) === []);
    if ($bak !== null) @file_put_contents($hf, $bak); // restore
} else {
    ok('Voice history logs + reads back oldest→newest', false);
    ok('Voice history empty entries are skipped', false);
    ok('Voice history clears', false);
}
ok('/voice history subcommand wired', strpos($src, "\$sub === 'history'") !== false);

// Voice "bake-in": auto-provisioned/bundled whisper.cpp engine.
ok('SttClient provisions a self-contained engine (download + sttDir + platformTarget)',
    strpos($src, 'function provision(') !== false && strpos($src, 'function download(') !== false &&
    strpos($src, 'function platformTarget(') !== false && strpos($src, 'function whisperCppBin(') !== false);
ok('engine fetched from OllamaDev releases + model from Hugging Face',
    strpos($src, 'releases/latest/download/') !== false && strpos($src, 'huggingface.co/ggerganov/whisper.cpp') !== false);
ok('CLI exposes `voice install` + /voice offers a one-time download',
    strpos($src, "\$sub === 'install'") !== false && strpos($src, 'function provisionVoice(') !== false);
// Behavioral: a bundled engine dir (OLLAMADEV_STT_DIR) is detected, no PATH/network.
if (class_exists('SttClient') && method_exists('SttClient', 'platformTarget')) {
    $td = sys_get_temp_dir() . '/odv-stt-' . getmypid(); @mkdir($td, 0755, true);
    $tgt = SttClient::platformTarget();
    @file_put_contents("$td/$tgt", "#!/bin/sh\n"); @chmod("$td/$tgt", 0755);
    @file_put_contents("$td/" . SttClient::ggmlModelName('base'), 'x');
    $prev = getenv('OLLAMADEV_STT_DIR'); putenv("OLLAMADEV_STT_DIR=$td");
    ok('bundled engine dir is detected via OLLAMADEV_STT_DIR',
        SttClient::whisperCppBin() === "$td/$tgt" && SttClient::detectedEngine() === 'whisper.cpp' && SttClient::hasBundledEngine());
    $prev === false ? putenv('OLLAMADEV_STT_DIR') : putenv("OLLAMADEV_STT_DIR=$prev");
    @unlink("$td/$tgt"); @unlink("$td/" . SttClient::ggmlModelName('base')); @rmdir($td);
} else { ok('bundled engine dir is detected via OLLAMADEV_STT_DIR', false); }
// Build + CI + bundling for the engine. ($repoRoot is defined later; use a local.)
$rr = dirname(__DIR__);
ok('whisper.cpp build script exists', is_file($rr . '/scripts/build-whisper.sh'));
$rel = (string)@file_get_contents($rr . '/.github/workflows/release.yml');
ok('release CI builds + publishes whisper binaries', strpos($rel, 'whisper-linux-x64') !== false &&
    strpos($rel, 'build-whisper.sh') !== false && strpos($rel, 'whisper-windows') !== false);
$appimg = (string)@file_get_contents($rr . '/scripts/build-appimage.sh');
ok('AppImage bundles the engine + sets OLLAMADEV_STT_DIR',
    strpos($appimg, 'build-whisper.sh') !== false && strpos($appimg, 'OLLAMADEV_STT_DIR') !== false);
$winps = (string)@file_get_contents($rr . '/scripts/windows/build-installer.ps1');
ok('Windows installer bundles the engine + sets OLLAMADEV_STT_DIR',
    strpos($winps, 'whisper-windows-x64.exe') !== false && strpos($winps, 'OLLAMADEV_STT_DIR') !== false);
$ade = (string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/app.js');
ok('desktop has a local voice dictation module', strpos($ade, 'var Voice') !== false && strpos($ade, 'sttTranscribe') !== false && strpos($ade, 'MediaRecorder') !== false);
ok('crew terminal uses a real model, not the literal "crew" (resume relaunch bug)', strpos($ade, "new Terminal(id, 'crew')") === false);
ok('desktop binds sttEnabled + sttTranscribe to the CLI', strpos((string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/index.php'), 'sttTranscribe') !== false);

echo "\n== General chat (ollama-only, no tools) ==\n";
// CLI: a plain, tool-free conversational command that shares the one engine.
ok('CLI has a `chat` command (general, tool-free)',
    strpos($src, "=== 'chat'") !== false && strpos($src, 'OllamaDev chat') !== false &&
    strpos($src, 'no tools, no file edits') !== false);
ok('chat shares the engine (model/host config via ModelClient)',
    strpos($src, 'ModelClient::default()') !== false && strpos($src, "Config::get('ollama.defaultModel'") !== false &&
    strpos($src, '$client->chatWithModel(') !== false);
ok('chat --json one-shot returns {reply}/{error}',
    strpos($src, "json_encode(['reply'") !== false && strpos($src, "'no messages'") !== false);
// Functional: the error path needs no Ollama running.
[$cout] = run_bin(['chat', '--json'], '{}');
ok('`echo {} | ollamadev chat --json` → no-messages error', strpos($cout, '"error":"no messages"') !== false, trim($cout));

// Desktop/web: a DEDICATED 💬 Chat window (its own canvas pane) with an in-window model
// picker that runs vanilla `ollama run <model>` — NOT the coding agent.
$ihtmlChat = (string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/index.html');
$acssChat  = (string)@file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/app.css');
// Chat is a MULTI-INSTANCE window — a 'chat' terminal kind, not a singleton pane — so you
// can open as many independent chats as you want (each its own model + session).
ok('Chat is a multi-instance window (terminal kind, NOT a singleton pane)',
    strpos($ade, 'spawnChatWindow: function') !== false && strpos($ade, "if (kind === 'chat') { this._spawnGeom") !== false &&
    strpos($ade, "'browser', 'topology', 'chat'") === false && strpos($ade, "chat: '#chatView'") === false &&
    strpos($ihtmlChat, 'id="chatView"') === false && strpos($ihtmlChat, 'data-add="chat"') !== false);
ok('each Chat window runs its own `ollamadev chat --session <id> -m <model>`',
    strpos($ade, "' chat --session ' + session + ' -m ' + model") !== false &&
    strpos($ade, "t.kind = 'chat'; t.chatSession = session") !== false);
ok('Chat window header has a toolbar (model picker + 📎/🧠/⬇)',
    strpos($ade, '_wireChatBar = function') !== false && strpos($ade, 'class="chat-model"') !== false &&
    strpos($ade, 'class="chat-iconbtn chat-img"') !== false && strpos($ade, 'class="chat-iconbtn chat-persona"') !== false &&
    strpos($ade, 'class="chat-iconbtn chat-export"') !== false && strpos($acssChat, '.chat-iconbtn') !== false);
ok('Chat per-window actions: model switch (/model), image, persona, export',
    strpos($ade, 'chatSetModel = function') !== false && strpos($ade, 'chatImage = function') !== false &&
    strpos($ade, 'chatPersona = function') !== false && strpos($ade, 'chatExport = function') !== false);
ok('Chat windows resume their session on reopen (kind chat + saved session)',
    strpos($ade, "ti.kind === 'chat') self.spawnChatWindow(ti.model, ti.session)") !== false &&
    strpos($ade, 'session: t.chatSession') !== false);
ok('the ollamadev agent terminals are untouched (still launchCli)',
    strpos($ade, 'launchCli: function') !== false && strpos($ade, 'if (!isShell) self.launchCli') !== false);
ok('desktop remembers your last real model (chat default)',
    strpos($ade, 'lastModel') !== false && strpos($ade, "localStorage.setItem('ade.lastModel'") !== false);

// Behavioral (hermetic): `ollamadev chat` must show a reasoning model's ANSWER, not its
// chain-of-thought. A tiny fake Ollama server (no GPU/model) emits a `thinking` field;
// the chat must drop it. Guards the OllamaClient $includeThinking fix in BOTH modes.
$fakeSrv  = __DIR__ . '/fake-ollama.php';
$fakePort = 41700 + (getmypid() % 180);
$fakeHost = 'http://127.0.0.1:' . $fakePort;
$chatHome = sys_get_temp_dir() . '/odv_chat_test_' . getmypid();
@mkdir($chatHome, 0755, true);
$fproc = @proc_open(escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $fakePort . ' ' . escapeshellarg($fakeSrv),
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $fpipes);
if (is_resource($fproc)) {
    usleep(500000);   // let the server bind
    $cenv = ['HOME' => $chatHome];   // clean config → engine defaults (streaming on, etc.)
    // Non-stream path (chat --json): the reply is the answer only.
    [$jout] = run_bin(['chat', '--json', '--host', $fakeHost], '{"messages":[{"role":"user","content":"hi"}]}', $cenv);
    $jr = json_decode(trim($jout), true);
    ok('chat --json returns the answer, NOT the chain-of-thought',
        is_array($jr) && ($jr['reply'] ?? '') === 'Hello there, world.' && strpos($jout, 'REASONING_LEAK_SENTINEL') === false, trim($jout));
    // Streaming REPL path — the exact shape that leaked (a thinking-only chunk first).
    // Reasoning is now SHOWN dimmed in the live output (so you can watch it and Ctrl-C),
    // but it must stay OUT of the saved answer / conversation history. Guard the saved
    // assistant turn is answer-only.
    [$rout] = run_bin(['chat', '--session', 'smoke_think', '-m', 'fake-reasoner:latest', '--host', $fakeHost], "hi\n/exit\n", $cenv);
    $rclean = preg_replace('/\x1b\[[0-9;]*m/', '', $rout);
    $tf = $chatHome . '/.ollamadev/chats/smoke_think.json';
    $td = is_file($tf) ? json_decode((string) @file_get_contents($tf), true) : null;
    $asst = '';
    if (is_array($td)) foreach (($td['messages'] ?? []) as $mm) { if (($mm['role'] ?? '') === 'assistant') $asst = (string)($mm['content'] ?? ''); }
    ok('chat REPL shows the answer and keeps reasoning out of the saved reply',
        strpos($rclean, 'Hello there, world.') !== false && $asst === 'Hello there, world.' && strpos($asst, 'REASONING_LEAK_SENTINEL') === false, trim($asst));
    run_bin(['chat', 'delete', 'smoke_think'], '', $cenv);
    // Threads/history: a --session run persists the conversation; list + delete work.
    run_bin(['chat', '--session', 'smoke_thread', '-m', 'fake-reasoner:latest', '--host', $fakeHost], "capital of France?\n/exit\n", $cenv);
    $sessFile = $chatHome . '/.ollamadev/chats/smoke_thread.json';
    $sd = is_file($sessFile) ? json_decode((string) @file_get_contents($sessFile), true) : null;
    ok('chat --session persists the conversation (title + messages)',
        is_array($sd) && ($sd['title'] ?? '') === 'capital of France?' && count($sd['messages'] ?? []) === 2 && (($sd['messages'][0]['role'] ?? '') === 'user'), json_encode($sd['title'] ?? null));
    [$lout] = run_bin(['chat', 'list', '--json'], '', $cenv);
    $ld = json_decode(trim($lout), true);
    ok('chat list --json returns the saved thread',
        is_array($ld) && isset($ld['chats']) && count(array_filter($ld['chats'], fn($x) => ($x['id'] ?? '') === 'smoke_thread')) === 1, trim($lout));
    // Resuming the same session replays its prior turns.
    [$r2] = run_bin(['chat', '--session', 'smoke_thread', '-m', 'fake-reasoner:latest', '--host', $fakeHost], "/exit\n", $cenv);
    ok('chat --session resumes (replays the prior conversation)', strpos($r2, 'resumed') !== false && strpos($r2, 'capital of France?') !== false, trim($r2));
    run_bin(['chat', 'delete', 'smoke_thread'], '', $cenv);
    ok('chat delete removes the thread', !is_file($sessFile));
    // Custom persona (/system) persists in the session.
    run_bin(['chat', '--session', 'smoke_persona', '-m', 'fake-reasoner:latest', '--host', $fakeHost], "/system You are a pirate.\nhi\n/exit\n", $cenv);
    $pf = json_decode((string) @file_get_contents($chatHome . '/.ollamadev/chats/smoke_persona.json'), true);
    ok('chat /system persists a custom persona in the session', is_array($pf) && ($pf['system'] ?? '') === 'You are a pirate.', json_encode($pf['system'] ?? null));
    // Export renders Markdown.
    [$xo] = run_bin(['chat', 'export', 'smoke_persona', '--json'], '', $cenv);
    $xd = json_decode(trim($xo), true);
    ok('chat export <id> --json returns Markdown', is_array($xd) && strpos((string)($xd['markdown'] ?? ''), '**You:**') !== false && strpos((string)($xd['markdown'] ?? ''), '# ') === 0, trim($xo));
    // Images via the SHARED Vision helper — inline "/image <path> msg" and "@<path>"
    // (the fake server flags any message that carried an image).
    $imgPath = sys_get_temp_dir() . '/odv_smoke_img_' . getmypid() . '.png';
    @file_put_contents($imgPath, "\x89PNG\r\n\x1a\nFAKE-IMAGE-BYTES");
    [$io] = run_bin(['chat', '--session', 'smoke_img', '-m', 'fake-reasoner:latest', '--host', $fakeHost], "/image $imgPath what is this?\n/exit\n", $cenv);
    $iclean = preg_replace('/\x1b\[[0-9;]*m/', '', $io);
    ok('chat /image (inline, via Vision::extract) attaches an image', strpos($iclean, 'I see your image.') !== false && strpos($iclean, 'attached 1 image') !== false, trim($iclean));
    [$io2] = run_bin(['chat', '--session', 'smoke_img2', '-m', 'fake-reasoner:latest', '--host', $fakeHost], "look at @$imgPath please\n/exit\n", $cenv);
    ok('chat @<path> mention attaches an image (same Vision path as the agent)', strpos(preg_replace('/\x1b\[[0-9;]*m/', '', (string) $io2), 'I see your image.') !== false);
    @unlink($imgPath);
    foreach ($fpipes as $p) { if (is_resource($p)) fclose($p); }
    @proc_terminate($fproc); @proc_close($fproc);
} else {
    ok('fake Ollama server spawns (chat chain-of-thought test)', false, 'could not start php -S');
}
@exec('rm -rf ' . escapeshellarg($chatHome) . ' 2>/dev/null');
// The chat session bindings back the CLI features (list/delete/export), desktop + web.
$bindChat = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/src/Bindings.php');
ok('chat session bindings exist (list / delete / export, CLI-backed)',
    strpos($bindChat, 'function chatList') !== false && strpos($bindChat, 'function chatDelete') !== false &&
    strpos($bindChat, 'function chatExport') !== false);
ok('chat reuses the shared Vision helper (no duplicate image logic)',
    strpos($src, 'Vision::extract($line)') !== false);

echo "\n== Auto-remember (self-populating memory) ==\n";
ok('Memory::autoRemember exists + dedupes', strpos($src, 'function autoRemember(') !== false && strpos($src, 'title dedupe') !== false);
ok('crew auto-remembers after a run (run + resume)', strpos($src, 'function rememberFacts(') !== false && substr_count($src, 'self::rememberFacts(') >= 2);
ok('interactive session auto-remembers on exit when it did work', strpos($src, '$this->didEdit') !== false && strpos($src, 'Memory::autoRemember($ctx') !== false);
ok('--no-memory flag + config default wired', strpos($src, "\$flagOpts['memory'] = false") !== false && strpos($src, "'autoRemember' => true") !== false);

echo "\n== Context tuning + smarter compaction ==\n";
if (preg_match('/class ContextTuner \{.*?\n\}/s', $src, $ctm)) {
    if (!class_exists('OllamaClient')) { if (preg_match('/class OllamaClient \{.*?\n\}/s', $src, $oc2)) eval($oc2[0]); }
    if (!class_exists('ContextTuner')) eval($ctm[0]);
    ok('ContextTuner reads system RAM', ContextTuner::ramBytes() > 0);
    $p = ContextTuner::probe();
    ok('ContextTuner::probe returns a suggestion', is_array($p) && ($p['suggested'] ?? 0) >= 4096 && ($p['suggested'] % 4096) === 0);
} else { ok('ContextTuner extractable', false); }
ok('context command + --num-ctx wired', strpos($src, "=== 'context'") !== false && strpos($src, "\$flags['numCtx']") !== false && strpos($src, "Config::set('ollama.autoContext', false)") !== false);
ok('compaction preserves referenced tool output', strpos($src, 'still in use') !== false && strpos($src, '$preserved[$ref]') !== false);
ok('compaction keeps recent window after preserving', strpos($src, 'Preserved tool output the recent steps still reference') !== false);
ok('auto-compaction also triggers on real context fill (not just message count)',
    strpos($src, 'agents.compactContextPct') !== false && strpos($src, 'Usage::contextWindow()') !== false &&
    strpos($src, 'Usage::haveReal()') !== false && strpos($src, '$overCtx') !== false);

echo "\n== Browser/server mode (shared bindings) ==\n";
$adeDir = dirname(__DIR__) . '/Desktop/ollamadev-ade';
ok('shared Bindings class exists', is_file($adeDir . '/src/Bindings.php'));
ok('web server + browser shim live in web/', is_file($adeDir . '/web/server.php') && is_file($adeDir . '/web/bridge.js'));
$bind = (string)@file_get_contents($adeDir . '/src/Bindings.php');
ok('Bindings allow-lists calls + dispatches', strpos($bind, 'const PUBLIC') !== false && strpos($bind, 'function call(') !== false);
ok('desktop delegates bindings to the shared class', strpos((string)@file_get_contents($adeDir . '/index.php'), 'new Bindings(') !== false);
$brg = (string)@file_get_contents($adeDir . '/web/bridge.js');
ok('browser shim maps window.<binding> to /api', strpos($brg, "'/api/'") !== false && strpos($brg, 'listModels') !== false && strpos($brg, 'sttTranscribe') !== false);
$srv = (string)@file_get_contents($adeDir . '/web/server.php');
ok('server is localhost-shaped with optional token', strpos($srv, 'OLLAMADEV_SERVE_TOKEN') !== false && strpos($srv, "str_starts_with(\$uri, '/api/')") !== false);
// Web polish: SSE terminal streaming, safe-by-design (503 without workers → client
// falls back to polling) so it never blocks the single-threaded built-in server.
ok('web streams terminal output over SSE (with safe fallback)',
    strpos($srv, "str_starts_with(\$uri, '/api/stream')") !== false &&
    strpos($srv, "text/event-stream") !== false &&
    strpos($srv, "(int) getenv('PHP_CLI_SERVER_WORKERS') < 2") !== false &&   // 503-guards when it can't stream
    strpos($brg, 'window.__odvOpenStream') !== false &&
    strpos($ajs, 'window.__odvOpenStream') !== false && strpos($ajs, '_pollLoop') !== false);
ok('serve script enables workers so SSE streams by default',
    strpos((string)@file_get_contents($adeDir . '/composer.json'), 'PHP_CLI_SERVER_WORKERS=4') !== false);
// Functional: the SSE endpoint 503s without workers (proving the safe fallback path).
$sp = 41977; $sproc = @proc_open('php -S localhost:' . $sp . ' ' . escapeshellarg($adeDir . '/web/server.php'),
    [['pipe','r'],['pipe','w'],['pipe','w']], $spipes, $adeDir);
if (is_resource($sproc)) {
    usleep(700000);
    $code = (int)(@shell_exec('curl -s -o /dev/null -w "%{http_code}" http://localhost:' . $sp . '/api/stream?term=x 2>/dev/null') ?: 0);
    @proc_terminate($sproc); @proc_close($sproc);
    ok('SSE endpoint 503s without workers (client then polls)', $code === 503, "got HTTP $code");
}
// Desktop+web polish: full ANSI color/attributes (bg, 256-color, truecolor, italic/
// underline/dim/reverse) — was foreground+bold only.
ok('terminal renders full SGR (bg/256/truecolor + attributes)',
    strpos($ajs, 'function xterm256(') !== false && strpos($ajs, 'var ANSI16') !== false &&
    strpos($ajs, 'function parseSgr(st, p)') !== false &&
    strpos($ajs, 'st.reverse') !== false && strpos($ajs, 'st.underline') !== false &&
    strpos($ajs, "st[target] = xterm256(") !== false &&
    strpos($ajs, 'c >= 40 && c <= 47') !== false);
// Desktop+web polish: real alt-screen grid emulator for full-screen TUIs (vim/htop),
// with pty-resize so the program's layout matches what we render.
ok('alt-screen TUI grid emulator present', strpos($ajs, 'function TermGrid(') !== false &&
    strpos($ajs, 'TermGrid.prototype.csi') !== false && strpos($ajs, 'TermGrid.prototype.renderHtml') !== false &&
    strpos($ajs, "/^\\?(1049|47|1047)\$/.test(pp)") !== false &&     // alt-screen toggle detection
    strpos($ajs, 'this.enterAlt()') !== false && strpos($ajs, 'this.grid.csi(pp, fin)') !== false);
ok('terminal sizes the pty to the screen (termResize)',
    strpos($ajs, 'window.termResize(this.id, cols, rows)') !== false &&
    strpos($ajs, 'Terminal.prototype.fit') !== false && strpos($ajs, 'Terminal.prototype.measure') !== false &&
    strpos($bind, 'function termResize(') !== false && strpos($brg, 'termResize') !== false);
// Opt-in GPU-composited canvas terminal renderer (DOM stays the default).
ok('opt-in canvas (GPU) terminal renderer present', strpos($ajs, 'function CanvasRenderer(') !== false &&
    strpos($ajs, 'getContext(\'2d\'') !== false && strpos($ajs, 'CanvasRenderer.prototype.selectionText') !== false &&
    strpos($ajs, 'CanvasRenderer.prototype._paintNow') !== false);
ok('canvas renderer is opt-in; DOM is the default', strpos($ajs, "App.canvasTerm)") !== false &&
    strpos($ajs, 'if (this.canvasR) { this.canvasR.write(text); return; }') !== false &&
    strpos($ajs, "localStorage.getItem('ade.canvasTerm') === 'on'") !== false &&
    strpos($ajs, 'setCanvasTerm: function') !== false);
ok('canvas renderer toggle in the UI', strpos((string)@file_get_contents($adeDir . '/public/index.html'), 'id="termCanvas"') !== false);
// Canvas matches the DOM terminal: pull colors/font from the theme CSS, not hardcoded.
ok('canvas renderer reads theme colors/font (matches DOM terminal)',
    strpos($ajs, 'CanvasRenderer.prototype.readTheme') !== false &&
    strpos($ajs, 'cs.color') !== false && strpos($ajs, 'parseFloat(cs.fontSize)') !== false &&
    strpos($ajs, 'cs.fontFamily') !== false && strpos($ajs, "screenEl.style.padding = '0'") !== false);
// The pty daemon honors resize by setting the pts window size → SIGWINCH (backend).
ok('pty daemon applies resize via stty on the pts', strpos($src, "stty -F ' . escapeshellarg(\$pts)") !== false &&
    strpos($src, 'pty-size') !== false);
ok('web mode stays vanilla (no deps in web/)', !is_file($adeDir . '/web/package.json') && strpos($brg, 'import ') === false && strpos($brg, 'require(') === false);
ok('composer serve script wired', strpos((string)@file_get_contents($adeDir . '/composer.json'), '"serve"') !== false);
$adeCss = (string)@file_get_contents($adeDir . '/public/app.css');
ok('ADE app is responsive (mobile media query)', strpos($adeCss, '@media (max-width: 820px)') !== false && strpos($adeCss, 'nav-open') !== false);
ok('sidebar/projects drawer (☰) wired in JS for the full-canvas layout', strpos($ade, 'initResponsive') !== false && strpos($ade, "nav-open") !== false && strpos($ade, "'#navToggle'") !== false);
ok('terminal has a touch input + key bar', strpos($ade, 'term-touch') !== false && strpos($ade, 'term-input') !== false && strpos($ade, 'var KEYS = {') !== false && strpos($ade, 'data-k="cc"') !== false);
ok('touch input bar hidden on desktop, shown on mobile', strpos($adeCss, '.term-touch { display: none;') !== false && strpos($adeCss, '.term-touch { display: flex; }') !== false);

// Vanilla guard — enforces the no-frameworks/no-deps rule on OLLAMADEV'S OWN code
// only (CLI src/, the website docs/, the desktop front-end public/). It does NOT
// constrain other projects the agent works on — those can use any deps they need.
// Exempt: vscode-extension/ (a Node VS Code extension by nature) and vendor/.
echo "\n== Vanilla constraint (OllamaDev's own code) ==\n";
$vroot = dirname(__DIR__);
$pkgs = array_filter(['/package.json', '/src/package.json', '/docs/package.json', '/Desktop/ollamadev-ade/public/package.json'], fn($p) => is_file($vroot . $p));
ok('no package.json in CLI / site / desktop-public', empty($pkgs), implode(',', $pkgs));
ok('no node_modules in the vanilla dirs', !is_dir($vroot . '/src/node_modules') && !is_dir($vroot . '/docs/node_modules') && !is_dir($vroot . '/Desktop/ollamadev-ade/public/node_modules'));
$extHtml = '';
foreach (array_merge(glob($vroot . '/docs/*.html') ?: [], glob($vroot . '/Desktop/ollamadev-ade/public/*.html') ?: []) as $h) {
    if (preg_match('/<script[^>]*src="(https?:|\/\/)|<link[^>]*href="https?:[^"]*\.css/i', (string)@file_get_contents($h))) { $extHtml = basename($h); break; }
}
ok('site/desktop HTML pulls no external scripts or styles (no CDN/framework)', $extHtml === '', $extHtml);
$cliDep = '';
foreach (glob($vroot . '/src/*.php') ?: [] as $f) if (strpos((string)@file_get_contents($f), 'vendor/autoload') !== false) { $cliDep = basename($f); break; }
ok('CLI source loads no composer/vendor autoload (pure vanilla PHP)', $cliDep === '', $cliDep);
$dc = json_decode((string)@file_get_contents($vroot . '/Desktop/ollamadev-ade/composer.json'), true);
$extraDeps = array_values(array_diff(is_array($dc['require'] ?? null) ? array_keys($dc['require']) : [], ['php', 'boson-php/runtime']));
ok('desktop dependencies limited to PHP + Boson runtime', empty($extraDeps), 'unexpected: ' . implode(',', $extraDeps));

echo "\n== Distribution (binaries) ==\n";
$repoRoot = dirname(__DIR__);
ok('install.sh present + executable', is_file($repoRoot . '/install.sh') && is_executable($repoRoot . '/install.sh'));
ok('build-binary.sh present + executable', is_file($repoRoot . '/scripts/build-binary.sh') && is_executable($repoRoot . '/scripts/build-binary.sh'));
ok('release workflow present', is_file($repoRoot . '/.github/workflows/release.yml'));
ok('build-desktop.sh present + executable', is_file($repoRoot . '/scripts/build-desktop.sh') && is_executable($repoRoot . '/scripts/build-desktop.sh'));
$vps = (string)@file_get_contents($repoRoot . '/scripts/vps-setup.sh');
ok('vps-setup.sh present + executable', is_file($repoRoot . '/scripts/vps-setup.sh') && is_executable($repoRoot . '/scripts/vps-setup.sh'));
ok('vps-setup installs Ollama + ADE, stays localhost', strpos($vps, 'ollama.com/install.sh') !== false && strpos($vps, 'OllamaDev-ADE-linux-') !== false && strpos($vps, '0.0.0.0') === false);
ok('vps-setup generates + wires an auth token', strpos($vps, 'gen_token()') !== false && strpos($vps, 'Environment=OLLAMADEV_SERVE_TOKEN=$TOKEN') !== false);
// Default web port is the uncommon 41434 (not 8080) everywhere it's set.
ok('web default port is 41434 (vps-setup)', strpos($vps, 'PORT:-41434') !== false && strpos($vps, ':-8080') === false);
ok('web default port is 41434 (desktop launcher)', strpos((string)@file_get_contents($repoRoot . '/scripts/build-desktop.sh'), 'OLLAMADEV_SERVE_PORT:-41434') !== false);
ok('web default port is 41434 (composer serve)', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/composer.json'), 'localhost:41434') !== false);
ok('docs document OLLAMADEV_SERVE_PORT default 41434', (function() use ($repoRoot) { $d = (string)@file_get_contents($repoRoot . '/docs/docs.html'); return strpos($d, 'OLLAMADEV_SERVE_PORT') !== false && strpos($d, '41434') !== false; })());
ok('vps-setup firewall allows SSH before any enable (no lockout)', strpos($vps, 'ufw allow OpenSSH') !== false && strpos($vps, 'ufw --force enable') !== false && strpos($vps, 'WANT_FIREWALL') !== false);
ok('desktop archive bundles the CLI', strpos((string)@file_get_contents($repoRoot . '/scripts/build-desktop.sh'), 'OLLAMADEV_BINARY') !== false && strpos((string)@file_get_contents($repoRoot . '/scripts/build-desktop.sh'), '/bin/ollamadev') !== false);
$dlPage = (string)@file_get_contents($repoRoot . '/docs/downloads.html');
ok('downloads page points desktop at archives', strpos($dlPage, 'OllamaDev-ADE-linux-x64.tar.gz') !== false && strpos($dlPage, 'OllamaDev-ADE-windows-x64.zip') !== false);
// Self-contained Linux AppImage: bundles a PHP runtime; archives (mac/win/linux) stay.
$appImg = (string)@file_get_contents($repoRoot . '/scripts/build-appimage.sh');
ok('build-appimage.sh bundles PHP + Boson into a self-contained AppImage', is_file($repoRoot . '/scripts/build-appimage.sh') &&
    is_executable($repoRoot . '/scripts/build-appimage.sh') && strpos($appImg, 'extension=$(basename') !== false &&
    strpos($appImg, 'appimagetool') !== false && strpos($appImg, 'usr/bin/php') !== false);
ok('AppImage build is arch-aware (x86_64 + aarch64)', strpos($appImg, 'AIARCH=x86_64') !== false &&
    strpos($appImg, 'AIARCH=aarch64') !== false && strpos($appImg, 'OllamaDev-ADE-$AIARCH.AppImage') !== false);
// v4.8.32: must NOT bundle libnghttp2 — Boson's WebView loads the host's libcurl-gnutls,
// and a bundled (build-runner) nghttp2 shadows the host's matched copy → "undefined symbol".
ok('AppImage does not bundle libnghttp2 (host WebView owns curl+nghttp2)', strpos($appImg, "SHARED='libnghttp2'") !== false &&
    strpos($appImg, '"$CORE|$SHARED"') !== false);
// AppRun runs `php -n` (no ini), so shared posix/pcntl must be bundled AND loaded
// explicitly — else the engine's posix_isatty / PtyManager's posix_kill are undefined
// (the "Call to undefined function posix_kill()" desktop crash).
ok('AppImage bundles posix/pcntl and AppRun loads every bundled extension', strpos($appImg, 'for ext in ffi curl posix pcntl') !== false &&
    strpos($appImg, 'basename "$so" .so') !== false && strpos($appImg, '-d extension=$(basename') !== false);
$pty = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/src/PtyManager.php');
ok('PtyManager guards posix_kill (graceful kill fallback when posix is absent)',
    strpos($pty, "function_exists('posix_kill')") !== false && strpos($pty, 'kill -TERM') !== false);
$relYml = (string)@file_get_contents($repoRoot . '/.github/workflows/release.yml');
ok('release builds x86_64 + arm64 AppImages + keeps all desktop archives', strpos($relYml, 'build-appimage.sh') !== false &&
    strpos($relYml, 'extensions: ffi, curl') !== false && strpos($relYml, 'ubuntu-22.04-arm') !== false &&
    strpos($relYml, 'OllamaDev-ADE-aarch64.AppImage') !== false);
ok('downloads page offers both Linux AppImages (x64 + arm64, no-install)', strpos($dlPage, 'OllamaDev-ADE-x86_64.AppImage') !== false &&
    strpos($dlPage, 'OllamaDev-ADE-aarch64.AppImage') !== false);
// Windows installer: self-contained .exe (Inno Setup) bundling PHP + Boson.
$iss = (string)@file_get_contents($repoRoot . '/scripts/windows/ollamadev-ade.iss');
$ps1 = (string)@file_get_contents($repoRoot . '/scripts/windows/build-installer.ps1');
ok('Windows installer scripts present (Inno .iss + builder .ps1)', $iss !== '' && $ps1 !== '' &&
    strpos($iss, 'OutputBaseFilename=OllamaDev-ADE-Setup') !== false && strpos($ps1, 'php-win.exe') !== false);
ok('Windows installer bundles PHP + writes absolute extension_dir', strpos($iss, "extension_dir=' + ExpandConstant('{app}\\php\\ext')") !== false &&
    strpos($ps1, 'vcruntime140.dll') !== false);
ok('release builds the Windows installer on a windows runner', strpos($relYml, 'desktop-windows') !== false &&
    strpos($relYml, 'windows-latest') !== false && strpos($relYml, 'OllamaDev-ADE-Setup.exe') !== false);
ok('downloads page offers the Windows installer', strpos($dlPage, 'OllamaDev-ADE-Setup.exe') !== false);
ok('desktop composer build no longer calls boson compile', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/composer.json'), 'boson compile') === false);
$inst = (string)@file_get_contents($repoRoot . '/install.sh');
ok('installer detects os/arch → asset name', strpos($inst, 'ollamadev-${pos}-${parch}') !== false);
ok('update picks per-OS release asset', strpos($src, '"ollamadev-$pos-$parch"') !== false);
ok('Makefile has binary targets', strpos((string)@file_get_contents($repoRoot . '/Makefile'), 'binary:') !== false);
$dl = (string)@file_get_contents($repoRoot . '/docs/downloads.html');
ok('downloads page lists CLI + desktop assets', strpos($dl, 'ollamadev-linux-x64') !== false && strpos($dl, 'OllamaDev-ADE-mac-arm64') !== false);
ok('no compile-time deprecations (implicit-nullable params)',
   preg_match('/[(,] *(callable|string|int|float|bool|array|object|iterable) \$[a-zA-Z0-9_]+ = null/', $src) === 0);
ok('no deprecated ${} interpolation outside nowdocs',
   preg_match('/return "[^"]*\$\{[a-zA-Z_]/', $src) === 0);

echo "\n== Skills ==\n";
if (preg_match('/class Skills \{.*?\n\}/s', $src, $sk)) {
    if (!class_exists('CrewSkills') && preg_match('/class CrewSkills \{.*?\n\}/s', $src, $cs)) eval($cs[0]);   // Skills::builtins/get fall back to it
    eval($sk[0]);
    $tmpHome = sys_get_temp_dir() . '/odv_skills_' . getmypid();
    @mkdir($tmpHome . '/.ollamadev/skills/git-pro', 0755, true);
    file_put_contents($tmpHome . '/.ollamadev/skills/git-pro/SKILL.md',
        "---\nname: git-pro\ndescription: Craft clean commits and rebases.\n---\n\n# git-pro\n\nWrite atomic commits.\n");
    $oldHome = getenv('HOME'); $oldCwd = getcwd();
    putenv("HOME=$tmpHome"); chdir($tmpHome);
    $all = Skills::all();
    ok('Skills::all discovers a SKILL.md', isset($all['git-pro']));
    ok('Skills parses frontmatter description', ($all['git-pro']['description'] ?? '') === 'Craft clean commits and rebases.');
    ok('Skills::catalog lists name: description', strpos(Skills::catalog(), 'git-pro: Craft clean') !== false);
    $g = Skills::get('git-pro');
    ok('Skills::get returns full body', $g !== null && strpos($g['body'], 'Write atomic commits.') !== false);
    ok('Skills::get is case-insensitive', Skills::get('GIT-PRO') !== null);
    ok('Skills::get returns null for unknown', Skills::get('nope') === null);
    $md = Skills::scaffold('new-thing');
    ok('Skills::scaffold writes a SKILL.md', is_file($md) && strpos(file_get_contents($md), 'name: new-thing') !== false);
    // install from a local directory of skill folders (sharing path)
    $share = $tmpHome . '/share/shared-skill';
    @mkdir($share, 0755, true);
    file_put_contents($share . '/SKILL.md', "---\nname: shared-skill\ndescription: Came from elsewhere.\n---\n\n# shared-skill\nbody\n");
    file_put_contents($share . '/helper.txt', "data");
    $res = Skills::install($tmpHome . '/share');
    ok('Skills::install installs from a directory', in_array('shared-skill', $res['installed'], true) && Skills::get('shared-skill') !== null);
    ok('Skills::install copies helper files', is_file($tmpHome . '/.ollamadev/skills/shared-skill/helper.txt'));
    $res2 = Skills::install($tmpHome . '/share'); // already exists, no --force
    ok('Skills::install skips existing without --force', empty($res2['installed']));
    ok('Skills::install --force overwrites', in_array('shared-skill', Skills::install($tmpHome . '/share', true)['installed'], true));
    $bad = Skills::install('/nonexistent/path/xyz');
    ok('Skills::install rejects an unknown source', empty($bad['installed']) && !empty($bad['messages']));
    // export → tarball
    $exp = Skills::export('shared-skill', $tmpHome . '/out.tar.gz');
    ok('Skills::export writes a tarball', $exp !== null && is_file($exp) && filesize($exp) > 0);
    ok('Skills::export returns null for unknown', Skills::export('nope') === null);
    // remove
    ok('Skills::remove deletes a skill', Skills::remove('shared-skill') === true && Skills::get('shared-skill') === null);
    ok('Skills::remove returns false for unknown', Skills::remove('nope') === false);
    // Built-in team-skills are browsable through the manager surface, and get()
    // falls back to them so the desktop can view a starter's full body.
    if (class_exists('CrewSkills')) {
        $built = Skills::builtins();
        ok('Skills::builtins surfaces the team-skill library', count($built) >= 20 &&
            in_array('testing-discipline', array_column($built, 'name'), true));
        $mgr = Skills::listForManager();
        $names = array_column($mgr, 'name');
        ok('listForManager merges disk skills + built-ins', in_array('git-pro', $names, true) &&
            in_array('refactor-safety', $names, true));
        $tb = Skills::get('refactor-safety');
        ok('Skills::get falls back to a built-in body', $tb !== null && !empty($tb['builtin']) &&
            strpos($tb['body'], 'refactor-safety') !== false);
        // A disk skill of the same name overrides (and drops out of builtins()).
        Skills::save('testing-discipline', 'mine', "# mine\nlocal override\n");
        ok('a user skill overrides a built-in of the same name',
            !in_array('testing-discipline', array_column(Skills::builtins(), 'name'), true) &&
            empty(Skills::get('testing-discipline')['builtin']));
        Skills::remove('testing-discipline');
        // resolve(): forced-by-name skills are always kept; focus adds more, deduped.
        $r = CrewSkills::resolve('a docs site', ['security-hardening'], 5);
        $rn = array_column($r, 'name');
        ok('CrewSkills::resolve keeps forced skills + adds focus matches',
            in_array('security-hardening', $rn, true) && in_array('docs-writing', $rn, true));
        ok('CrewSkills::byNames ignores unknown names',
            count(CrewSkills::byNames(['testing-discipline', 'not-a-real-skill'])) === 1);
    }
    putenv("HOME=$oldHome"); chdir($oldCwd);
    @exec('rm -rf ' . escapeshellarg($tmpHome));
} else { ok('Skills class extractable', false); }
// Source wiring: skill tool registered, schema present, prompt injection, read-only
ok('skill tool registered', strpos($src, "Tools::register('skill'") !== false);
ok('skill tool has native schema', strpos($src, "\$fn('skill'") !== false);
ok('skills injected into system prompt', strpos($src, 'AVAILABLE SKILLS') !== false);
ok('skills install/export/remove wired (CLI)', strpos($src, "\$sub === 'install'") !== false && strpos($src, "\$sub === 'export'") !== false);
ok('skills install supports git + archive sources', strpos($src, 'git clone --depth 1') !== false && strpos($src, 'tgz|zip') !== false);
// Built-in team-skills surface in the manager (list/show JSON carry builtin flag).
ok('skills list/show JSON expose built-ins', strpos($src, 'Skills::listForManager()') !== false &&
    strpos($src, "'builtin' => !empty(\$s['builtin'])") !== false);
// crew --skill <name> forces a built-in team-skill into the run (template wiring).
ok('crew --skill flag forces a team-skill in', strpos($src, "=== '--skill'") !== false &&
    strpos($src, "'forceSkills'") !== false && strpos($src, 'CrewSkills::resolve(') !== false);
// Desktop: each crew template carries its matching skill, passed via --skill.
ok('desktop crew templates carry skills wired to --skill',
    strpos($ajs, "skills: ['testing-discipline']") !== false &&
    strpos($ajs, "skills: ['refactor-safety']") !== false &&
    strpos($ajs, "rmf('--skill', s)") !== false);
// Desktop Skills manager renders the built-in section (badge + customize-to-override).
ok('desktop Skills manager shows built-ins (badge + override)',
    strpos($ajs, 'Built-in team-skills') !== false && strpos($ajs, 's.builtin') !== false);

echo "\n== Graph memory ==\n";
if (preg_match('/class Memory \{.*?\n\}/s', $src, $mm)) {
    eval($mm[0]);
    $tmp = sys_get_temp_dir() . '/odv_mem_' . getmypid();
    @mkdir($tmp, 0755, true);
    $oldCwd2 = getcwd(); $oldHome3 = getenv('HOME');
    chdir($tmp); putenv('HOME=' . $tmp . '/home');
    $s1 = Memory::save('Auth uses JWT', 'JWT in HttpOnly cookies. See [[session-handling]].', ['auth', 'security']);
    $s2 = Memory::save('Session handling', 'Rotates every 15m. Related to [[auth-uses-jwt]].', ['auth']);
    ok('Memory::save slugifies the title', $s1 === 'auth-uses-jwt' && $s2 === 'session-handling');
    $all = Memory::all();
    ok('Memory::all discovers saved notes', isset($all['auth-uses-jwt']) && isset($all['session-handling']));
    ok('Memory parses tags', $all['auth-uses-jwt']['tags'] === ['auth', 'security']);
    ok('Memory extracts [[wiki-links]]', in_array('session-handling', $all['auth-uses-jwt']['links'], true));
    ok('Memory::get resolves by slug', (Memory::get('auth-uses-jwt')['title'] ?? '') === 'Auth uses JWT');
    ok('Memory::get resolves by title', (Memory::get('Session handling')['slug'] ?? '') === 'session-handling');
    ok('Memory::search matches body/tags', isset(Memory::search('httponly')['auth-uses-jwt']) && count(Memory::search('auth')) === 2);
    $g = Memory::graph();
    ok('Memory::graph builds nodes', count($g['nodes']) === 2);
    ok('Memory::graph resolves edges both ways', count($g['edges']) === 2);
    ok('Memory::graph computes degree', ($g['nodes'][0]['degree'] ?? 0) === 2);
    ok('Memory::remove deletes a note', Memory::remove('session-handling') === true && Memory::get('session-handling') === null);
    chdir($oldCwd2); putenv($oldHome3 !== false ? "HOME=$oldHome3" : 'HOME');
    @exec('rm -rf ' . escapeshellarg($tmp));
} else { ok('Memory class extractable', false); }
ok('recall tool registered + read-only', strpos($src, "Tools::register('recall'") !== false && strpos($src, "'skill', 'recall'") !== false);
ok('remember tool registered', strpos($src, "Tools::register('remember'") !== false);
ok('memory injected into system prompt', strpos($src, 'PROJECT MEMORY') !== false);
ok('recall/remember have native schemas', strpos($src, "\$fn('recall'") !== false && strpos($src, "\$fn('remember'") !== false);

echo "\n== Crew multi-host parallel ==\n";
ok('Agent::setHost added', strpos($src, 'function setHost(string $host)') !== false);
ok('crew builds a host pool', strpos($src, '$baseHost') !== false && strpos($src, "Config::get('ollama.hosts'") !== false);
ok('crew parallelizes with pcntl when >1 host', strpos($src, 'pcntl_fork()') !== false && strpos($src, "function_exists('pcntl_fork')") !== false);
ok('crew parallelizes on multi-host automatically, single-box only when opted in', strpos($src, '$wantParallel = $multiHost || (') !== false &&
    strpos($src, '$parallel = count($jobs) > 1 && $canFork && $wantParallel') !== false);
// Single-box parallel coders: opt-in (--parallel [N] / crew.parallel), bounded pool
// so 6 coders don't open 6 inference streams against one GPU; default stays sequential.
ok('crew supports opt-in single-box parallel coders (bounded pool)',
    strpos($src, "Config::get('crew.parallel'") !== false && strpos($src, 'array_chunk($jobs, $maxPar)') !== false &&
    strpos($src, "Config::get('crew.parallelMax'") !== false &&
    strpos((string)@file_get_contents(dirname(__DIR__) . '/src/99-main.php'), "\$a === '--parallel'") !== false);
ok('crew runCoder accepts a host param', strpos($src, "string \$focus = '', string \$host = ''") !== false);
ok('crew falls back to sequential (inline) on fork failure', strpos($src, 'fork failed: run inline') !== false);
ok('crew --hosts flag wired (CLI)', strpos($src, "\$a === '--hosts'") !== false);
ok('config has ollama.hosts default', strpos($src, "'hosts' => []") !== false);
ok('bash honors auto mode for full shell', strpos($src, "Permission::getMode() === 'auto'") !== false && strpos($src, 'readonly only, or switch to auto mode') !== false);
ok('crew exposes a per-run logDir on the board', strpos($src, "'logDir' => \$logDir") !== false);
ok('crew tees each coder to a log file', strpos($src, "/coder-' . \$n . '.log'") !== false);
ok('runCoder accepts a logFile param', strpos($src, "string \$host = '', string \$logFile = ''") !== false);
ok('crew --panes flag parsed', strpos($src, "\$a === '--panes'") !== false);
ok('crew --run-id parsed + honored', strpos($src, "\$a === '--run-id'") !== false && strpos($src, "\$opts['runId']") !== false);
ok('crew-watch subcommand dispatches', strpos($src, "\$cmd === 'crew-watch'") !== false);
ok('Crew::watchPanes splits a tmux pane per coder', strpos($src, 'function watchPanes(') !== false && strpos($src, 'tmux split-window') !== false);
ok('--panes degrades gracefully without tmux', strpos($src, '--panes needs tmux') !== false);
ok('--panes uses tail -f on coder logs', strpos($src, "'tail -n +1 -f '") !== false);
ok('crew supports interactive mode (no task → Director prompt)', strpos($src, 'Type a task for the Director') !== false && strpos($src, 'posix_isatty(STDIN)') !== false);
// Director answer mode: "ask" answers questions read-only instead of tasking.
ok('Director has an answer mode (read-only Q&A, no tasking)', strpos($src, 'public static function answer(') !== false &&
    strpos($src, "Permission::setMode('readonly')") !== false && strpos($src, '$answerMode') !== false &&
    strpos($src, 'Crew::answer(') !== false);
// Desktop restore brings crew + director terminals back as themselves (not -m label).
ok('desktop restores crew/director terminals to their real command', strpos($ajs, 'spawnCmd: function') !== false &&
    strpos($ajs, "ti.kind === 'director'") !== false && strpos($ajs, "cli + ' crew director'") !== false &&
    strpos($ajs, "cli + ' crew'") !== false);
// Free-floating window layout: tiled↔free toggle, drag/resize, geometry persisted.
$ihtml = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/public/index.html');
ok('desktop has a free-floating (drag/resize) terminal layout', strpos($ajs, 'renderFree: function') !== false &&
    strpos($ajs, 'setTermLayout: function') !== false && strpos($ajs, 'wireFree: function') !== false &&
    strpos($ihtml, 'id="termArrange"') !== false);
// Layout mode is a global preference → the app reopens in whichever mode you last used.
ok('desktop reopens in the last-used layout mode (free/tiled)', strpos($ajs, "localStorage.setItem('ade.termLayout'") !== false &&
    strpos($ajs, "localStorage.getItem('ade.termLayout')") !== false);
// Focus/zoom (full-screen one terminal) works in FREE mode too, not just tiled.
ok('free layout honors focus/zoom (full-screen a pane)',
    strpos($ajs, 'Focus also works in free mode') !== false &&
    strpos($ajs, "wrap.className = 'zoomed';") !== false &&            // free-mode zoom branch fills the area
    strpos($ajs, "if (this.termLayout === 'free') this.zoomed = null;") === false);   // no longer cleared on switch
// Free is the default: a fresh user (no stored pref) lands in Free; only an
// explicit 'tiled' choice opts back into the grid.
ok('free layout is the default (only explicit tiled opts out)',
    strpos($ajs, "localStorage.getItem('ade.termLayout') === 'tiled' ? 'tiled' : 'free'") !== false &&
    strpos($ajs, "termLayout: 'free'") !== false);
// Surface parity: every desktop binding (Bindings::PUBLIC) must be wrapped by the web
// bridge too, or that feature is dead in web mode (how crewSteer/skills* drifted).
$bridge = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/web/bridge.js');
$idxBind = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/index.php');
if (preg_match('/PUBLIC\s*=\s*\[(.*?)\];/s', $bind, $pm)) {
    preg_match_all("/'([a-zA-Z][a-zA-Z0-9]*)'/", $pm[1], $pn);
    $missing = array_values(array_filter($pn[1], fn($x) => strpos($bridge, "'" . $x . "'") === false));
    ok('web bridge exposes every desktop binding (cli/desktop/web in sync)', empty($missing), 'missing in bridge.js: ' . implode(', ', $missing));
    // AND the Boson desktop must register every PUBLIC binding too — otherwise window.<name>
    // is undefined there and an id-named DOM element can shadow it (how chatList broke).
    $missingIdx = array_values(array_filter($pn[1], fn($x) => strpos($idxBind, "\$b->bind('" . $x . "'") === false));
    ok('desktop index.php registers every Bindings::PUBLIC method (Boson in sync)', empty($missingIdx), 'missing in index.php: ' . implode(', ', $missingIdx));
} else { ok('Bindings PUBLIC list parseable', false); }
ok('interactive crew loops Crew::run per prompt', strpos($src, "in_array(strtolower(\$line), ['exit', 'quit', 'q', ':q']") !== false);

echo "\n== Model client (Ollama-only) ==\n";
ok('ModelClient is the sole factory — no LM Studio / provider switching left',
    strpos($src, 'class LMStudioClient') === false && strpos($src, 'isOpenAiStyle') === false
    && strpos($src, "Config::get('provider'") === false && strpos($src, "'lmstudio'") === false
    && strpos($src, "--lmstudio") === false && strpos($src, 'OLLAMADEV_PROVIDER') === false);
ok('Agent + Crew use the ModelClient factory', strpos($src, 'ModelClient::default()') !== false && strpos($src, 'ModelClient::for(') !== false);
ok('ModelClient::default returns an OllamaClient', preg_match('/class ModelClient \{.*?\n\}/s', $src, $mc) === 1
    && strpos($mc[0], 'new OllamaClient(') !== false && strpos($mc[0], 'LMStudio') === false);
ok('--host flag sets the session override', strpos($src, "\$a === '--host'") !== false && strpos($src, 'ModelClient::$override') !== false);
ok('skill tool is read-only', strpos($src, "'summarize', 'skill'") !== false);

echo "\n== Crew team skills ==\n";
if (preg_match('/class CrewSkills \{.*?\n\}/s', $src, $cs)) {
    if (!class_exists('CrewSkills')) eval($cs[0]);
    $ecom = CrewSkills::forFocus('An e-commerce site — catalog, cart, checkout, payments, orders.');
    $enames = array_map(fn($s) => $s['name'], $ecom);
    ok('forFocus matches e-commerce → payments-money', in_array('payments-money', $enames, true));
    ok('forFocus is capped at 5', count($ecom) <= 5);
    $api = array_map(fn($s) => $s['name'], CrewSkills::forFocus('A REST API / backend service. Prioritize routing, validation, auth, and tests.'));
    ok('forFocus matches REST API → rest-api-design', in_array('rest-api-design', $api, true));
    ok('forFocus matches "and tests" → testing-discipline', in_array('testing-discipline', $api, true));
    ok('forFocus on empty focus returns nothing', CrewSkills::forFocus('') === []);
    $site = array_map(fn($s) => $s['name'], CrewSkills::forFocus('A website (static / marketing). Prioritize semantic markup, responsive design, SEO, and accessibility.'));
    ok('forFocus matches website → responsive-design', in_array('responsive-design', $site, true));
    // materialize writes SKILL.md into <base>/.ollamadev/skills/<name>/
    $tmpWt = sys_get_temp_dir() . '/odv_teamskill_' . getmypid();
    @mkdir($tmpWt, 0755, true);
    $oldHome2 = getenv('HOME'); putenv('HOME=' . $tmpWt . '/home'); // isolate the "don't clobber global" check
    $written = CrewSkills::materialize($ecom, $tmpWt);
    $md = $tmpWt . '/.ollamadev/skills/payments-money/SKILL.md';
    ok('materialize writes a team SKILL.md', is_file($md) && strpos(file_get_contents($md), 'name: payments-money') !== false);
    ok('materialize reports written names', in_array('payments-money', $written, true));
    putenv($oldHome2 !== false ? "HOME=$oldHome2" : 'HOME');
    @exec('rm -rf ' . escapeshellarg($tmpWt));
} else { ok('CrewSkills class extractable', false); }
// Crew wiring: focus → team skills → materialized into worktrees
ok('crew computes team skills from focus', strpos($src, 'CrewSkills::resolve($focus, $opts[\'forceSkills\'] ?? [])') !== false);
ok('crew materializes skills into worktrees', strpos($src, 'CrewSkills::materialize($teamSkills, $wt)') !== false);
ok('crew --no-skills flag wired', strpos($src, "'--no-skills'") !== false);
// Skill-awareness: the Director, Researcher, and Auditor all receive the loaded
// team-skills (so the planner routes them, the researcher flags them, the auditor
// holds work to their standards) — not just the coders.
ok('Director/Researcher/Auditor are skill-aware', strpos($src, 'function skillsBrief(') !== false &&
    strpos($src, 'self::plan($agent, $task, $maxCoders, $research, $mDirector, $focus, $amplify, self::skillsBrief($teamSkills))') !== false &&
    strpos($src, 'self::skillsBrief($teamSkills))') !== false &&   // research call
    strpos($src, 'self::audit($agent, $res[\'title\'], $res["diff"], $task, $mAuditor, $amplify, $auditBrief)') !== false);
ok('Director names the skill in each subtask prompt', strpos($src, 'NAME that skill in its prompt') !== false);
// /crew slash command exposes per-role models + focus
ok('/crew parses per-role model flags', strpos($src, "--' . \$role . '-model") !== false);
ok('/crew parses --focus', strpos($src, "--focus\\s+\"") !== false || strpos($src, '--focus\s+"') !== false);
ok('crew prints per-role models when they differ', strpos($src, 'roles:') !== false);
// Self-modification safeguard: review forced on the OllamaDev source unless --auto-merge
ok('crew has a self-repo detector', strpos($src, 'function isSelfRepo()') !== false);
ok('crew forces review on self-repo', strpos($src, "self::isSelfRepo()") !== false && strpos($src, 'self-modification detected') !== false);
ok('crew --auto-merge override wired (CLI)', strpos($src, "'--auto-merge'") !== false);
ok('crew --auto-merge override wired (/crew)', strpos($src, '--auto-merge(\s|$)') !== false);

// ===== Workspaces (named projects, shared CLI ↔ desktop/web) =====
echo "\n== Workspaces ==\n";
ok('Workspaces class in binary', strpos($src, 'class Workspaces') !== false);
ok('CLI workspace/ws command dispatched', strpos($src, "\$argv[1] === 'workspace'") !== false && strpos($src, "\$argv[1] === 'ws'") !== false);
ok('workspace store is global ($HOME/.ollamadev)', strpos($src, "'/.ollamadev/workspaces.json'") !== false);
// Functional round-trip against a throwaway HOME so we don't touch the real store.
$tmpHome = sys_get_temp_dir() . '/odv_ws_' . getmypid();
@mkdir($tmpHome, 0755, true);
$wsEnv = ['HOME' => $tmpHome];
[$o1] = run_bin(['workspace', 'add', '/tmp', 'smoke-ws'], '', $wsEnv);
ok('workspace add reports the entry', strpos($o1, 'smoke-ws') !== false && strpos($o1, '/tmp') !== false, trim($o1));
[$o2] = run_bin(['workspace', 'list'], '', $wsEnv);
ok('workspace list shows it active', strpos($o2, 'smoke-ws') !== false && strpos($o2, '* ') !== false, trim($o2));
[$o3] = run_bin(['workspace', 'open', 'smoke-ws'], '', $wsEnv);
ok('workspace open prints the path (for cd $(...))', trim($o3) === '/tmp', trim($o3));
[$o4] = run_bin(['ws', 'remove', 'smoke-ws'], '', $wsEnv);   // `ws` alias
ok('ws remove (alias) works', strpos($o4, 'Removed') !== false, trim($o4));
[$o5] = run_bin(['workspace', 'list'], '', $wsEnv);
ok('list is empty after remove', strpos($o5, 'No workspaces') !== false, trim($o5));
@unlink($tmpHome . '/.ollamadev/workspaces.json'); @rmdir($tmpHome . '/.ollamadev'); @rmdir($tmpHome);
// Desktop/web wiring shares one store + one Bindings surface.
$bind = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/src/Bindings.php');
ok('Bindings exposes ws methods', strpos($bind, "'wsList', 'wsAdd', 'wsRemove', 'wsSetActive', 'wsSaveState'") !== false && strpos($bind, 'function wsList') !== false);
ok('desktop Workspaces mirror reads the same file', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/src/Workspaces.php'), "'/.ollamadev/workspaces.json'") !== false);
ok('Boson binds ws* methods', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/index.php'), "\$b->bind('wsList'") !== false);
ok('web bridge exposes ws* over HTTP', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/web/bridge.js'), "'wsList', 'wsAdd', 'wsRemove', 'wsSetActive', 'wsSaveState'") !== false);
// Frontend: switcher + full per-workspace state capture/restore (terminals reattach).
$appjs = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/app.js');
ok('app.js has a Workspaces tabs module', strpos($appjs, 'var Workspaces = {') !== false && strpos($appjs, 'switchTo:') !== false);
ok('app.js captures + restores window state', strpos($appjs, 'captureState:') !== false && strpos($appjs, 'restoreState:') !== false);
ok('terminals detach (pty kept alive) for re-attach on switch', strpos($appjs, 'Terminal.prototype.detach') !== false && strpos($appjs, 'attachTerminal:') !== false);
$idxhtml = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/index.html');
ok('project tab list present in the UI', strpos($idxhtml, 'id="wsBar"') !== false && strpos($idxhtml, 'id="wsStrip"') !== false);
ok('project tabs render in the strip', strpos($appjs, "\$('#wsStrip')") !== false && strpos($appjs, 'ws-tab-item') !== false);
// v4.2.1 — project list lives in the LEFT pane (inside #sidebar), vertical.
ok('project list is in the left sidebar', strpos($idxhtml, 'id="sidebar"') !== false && strpos($idxhtml, 'id="sidebar"') < strpos($idxhtml, 'id="wsBar"'));
ok('project strip is vertical (column)', (bool)preg_match('/\.ws-strip\s*\{[^}]*flex-direction:\s*column/', (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/app.css')));
// v4.2.1 fixes — ＋ opens a blank "new project" modal (no prefill no-op).
ok('＋ opens a blank new-project modal', strpos($appjs, 'openFolderModal(false, true)') !== false && strpos($appjs, 'blank ? \'\'') !== false);
// v4.2.1 fixes — open is best-effort + wsSaveState marshals as a JSON string (Boson-safe).
ok('openFolder is best-effort (folder opens even if bookkeeping fails)', strpos($appjs, 'finish(null)') !== false);
ok('wsSaveState sends a JSON string from the front-end', strpos($appjs, 'JSON.stringify(App.captureState())') !== false);
ok('project tab clicks use delegated handling (survive re-renders)',
    strpos($appjs, "strip.addEventListener('click'") !== false && strpos($appjs, "closest('.ws-tab-item')") !== false);
ok('switch is not gated on the leaving-save (fixes "switches only once")',
    strpos($appjs, 'do NOT gate the switch on it') !== false);
ok('active workspace autosaves so the app resumes on reopen',
    strpos($appjs, 'startAutosave:') !== false && strpos($appjs, "addEventListener('beforeunload'") !== false &&
    strpos($appjs, '!self.terminals.length') !== false);
ok('Bindings::wsSaveState accepts a JSON string + decodes', strpos($bind, 'function wsSaveState(string $id, string $state)') !== false && strpos($bind, 'json_decode($state') !== false);
ok('Boson binds wsSaveState as a string', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/index.php'), "fn(string \$id, string \$state): bool => \$bx->wsSaveState") !== false);

// ---- v4.3.0 — Crew roles: a per-subtask role catalog the Director assigns. ----
$rolesSrc = (string)@file_get_contents($repoRoot . '/src/83a-crew-roles.php');
$crewSrc = (string)@file_get_contents($repoRoot . '/src/83-crew.php');
$mainSrc = (string)@file_get_contents($repoRoot . '/src/99-main.php');
ok('CrewRoles class exists', strpos($rolesSrc, 'class CrewRoles') !== false);
ok('built-in roles present (coder/tester/docs/refactor/security)',
    strpos($rolesSrc, "'coder' =>") !== false && strpos($rolesSrc, "'tester' =>") !== false &&
    strpos($rolesSrc, "'docs' =>") !== false && strpos($rolesSrc, "'refactor' =>") !== false &&
    strpos($rolesSrc, "'security' =>") !== false);
ok('roles stored as JSON in ~/.ollamadev/crew-roles', strpos($rolesSrc, "/.ollamadev/crew-roles") !== false);
ok('Director plan lists the role catalog + asks for a role per subtask',
    strpos($crewSrc, 'CrewRoles::catalog()') !== false && strpos($crewSrc, '"role":"coder"') !== false);
ok('unknown Director-assigned roles fall back to coder', strpos($crewSrc, "\$role = 'coder';") !== false);
ok('coder runs with its role persona/model/permission',
    strpos($crewSrc, "CrewRoles::get((string)(\$st['role']") !== false &&
    strpos($crewSrc, '$persona') !== false && strpos($crewSrc, '$roleMode') !== false);
ok('role persisted in the resumable plan + live board',
    strpos($crewSrc, "'role' => \$st['role'] ?? 'coder'") !== false);
ok('CLI exposes crew role list/add/show/remove', strpos($mainSrc, "\$arg1 === 'role'") !== false &&
    strpos($mainSrc, 'CrewRoles::add(') !== false && strpos($mainSrc, 'CrewRoles::remove(') !== false);
ok('crew role add takes the persona positionally (no --prompt collision)',
    strpos($mainSrc, "\$positional[4]") !== false);
// Desktop/web (shared app.js + css) surface the role on each crew card.
ok('crew board card shows the role badge', strpos($appjs, "s.role && s.role !== 'coder'") !== false &&
    strpos($appjs, '<span class="role">') !== false);
ok('role badge has a CSS style', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/app.css'), '.card.crew .title .role') !== false);
// Functional: the built binary lists built-ins and round-trips a custom role
// (isolated HOME so it never touches the real ~/.ollamadev).
$bin = $repoRoot . '/ollamadev';
if (is_file($bin)) {
    $tmpHome = sys_get_temp_dir() . '/odv-roletest-' . getmypid();
    @mkdir($tmpHome, 0755, true);
    $env = 'HOME=' . escapeshellarg($tmpHome) . ' ';
    $list = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' crew role list 2>&1');
    ok('crew role list shows built-ins', strpos($list, 'coder') !== false && strpos($list, 'tester') !== false && strpos($list, '(built-in)') !== false);
    @shell_exec($env . 'php ' . escapeshellarg($bin) . ' crew role add qa "You are a QA agent." --desc "quality" 2>&1');
    $after = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' crew role list 2>&1');
    ok('crew role add registers a custom role', strpos($after, 'qa') !== false);
    $rm = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' crew role remove qa 2>&1');
    ok('crew role remove deletes a custom role', strpos($rm, 'Removed role: qa') !== false);
    $rmBuiltin = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' crew role remove coder 2>&1');
    ok('built-in roles cannot be removed', strpos($rmBuiltin, "can't be removed") !== false);
    @shell_exec('rm -rf ' . escapeshellarg($tmpHome));
}

// ---- v4.4.0 — manage Crew roles from the desktop & web (shared Bindings). ----
$bindFull = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/src/Bindings.php');
$idxPhp = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/index.php');
$bridgeJs = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/web/bridge.js');
$idxHtml2 = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/index.html');
ok('crew role list has a --json mode for the UI', strpos($mainSrc, "in_array('--json', \$argv, true)") !== false && strpos($mainSrc, "'builtin' => empty(\$def['custom'])") !== false);
ok('Bindings exposes crewRoleList/Add/Remove', strpos($bindFull, "'crewRoleList', 'crewRoleAdd', 'crewRoleRemove'") !== false &&
    strpos($bindFull, 'function crewRoleList(') !== false && strpos($bindFull, 'function crewRoleAdd(') !== false && strpos($bindFull, 'function crewRoleRemove(') !== false);
ok('Bindings role methods shell out to the one CLI catalog', strpos($bindFull, "crew role list --json") !== false && strpos($bindFull, "crew role add ") !== false && strpos($bindFull, "crew role remove ") !== false);
ok('Boson binds the role methods (desktop)', strpos($idxPhp, "\$b->bind('crewRoleList'") !== false && strpos($idxPhp, "\$b->bind('crewRoleAdd'") !== false && strpos($idxPhp, "\$b->bind('crewRoleRemove'") !== false);
ok('web bridge exposes the role methods', strpos($bridgeJs, "'crewRoleList', 'crewRoleAdd', 'crewRoleRemove'") !== false);
ok('app.js has a Roles manager module', strpos($appjs, 'var Roles = {') !== false && strpos($appjs, 'Roles.bind()') !== false &&
    strpos($appjs, 'window.crewRoleAdd') !== false && strpos($appjs, 'window.crewRoleRemove') !== false);
ok('roles modal + entry point in the UI', strpos($idxHtml2, 'id="rolesOverlay"') !== false && strpos($idxHtml2, 'id="crewManageRoles"') !== false &&
    strpos($idxHtml2, 'id="rolePersona"') !== false && strpos($idxHtml2, 'id="roleReadonly"') !== false);
ok('roles manager has CSS', strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/app.css'), '.roles-list') !== false && strpos((string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/app.css'), '.role-row') !== false);
// Functional: the binary's --json catalog parses and carries the builtin flag.
if (isset($bin) && is_file($bin)) {
    $tmpHome2 = sys_get_temp_dir() . '/odv-rolejson-' . getmypid();
    @mkdir($tmpHome2, 0755, true);
    $j = json_decode((string)@shell_exec('HOME=' . escapeshellarg($tmpHome2) . ' php ' . escapeshellarg($bin) . ' crew role list --json 2>/dev/null'), true);
    ok('crew role list --json returns the catalog with builtin flags',
        is_array($j) && isset($j['roles']) && count($j['roles']) === 5 && ($j['roles'][0]['builtin'] ?? null) === true);
    @shell_exec('rm -rf ' . escapeshellarg($tmpHome2));
}

// ---- v4.4.1 — desktop/web prefer the repo's freshly built CLI in a source checkout. ----
$ptySrc = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/src/PtyManager.php');
ok('dev checkout prefers the repo-built CLI over PATH', strpos($ptySrc, "is_file(\$repoRoot . '/build.sh')") !== false &&
    strpos($ptySrc, "command -v ollamadev") !== false);
ok('explicit OLLAMADEV_BINARY still wins (shipped archive)', strpos($ptySrc, "if (defined('OLLAMADEV_BINARY') && OLLAMADEV_BINARY) return OLLAMADEV_BINARY") !== false &&
    strpos($ptySrc, 'getenv(\'OLLAMADEV_BINARY\')') !== false);

// ---- v4.5.0 — web search: direct CLI command, pluggable backends, GUI toggle. ----
$toolsSrc = (string)@file_get_contents($repoRoot . '/src/65-tools-register.php');
$cfgSrc = (string)@file_get_contents($repoRoot . '/src/10-config.php');
ok('search tool has pluggable backends (duckduckgo/searxng/brave)',
    strpos($toolsSrc, "Config::get('search.provider'") !== false && strpos($toolsSrc, "'searxng'") !== false &&
    strpos($toolsSrc, 'api.search.brave.com') !== false && strpos($toolsSrc, '/search?') !== false);
ok('CLI has a direct `search` command', strpos($mainSrc, "\$argv[1] === 'search'") !== false &&
    strpos($mainSrc, "Tools::run('search'") !== false);
ok('CLI has a `config get/set` command', strpos($mainSrc, "\$argv[1] === 'config'") !== false &&
    strpos($mainSrc, 'Config::persist(') !== false);
ok('Config::persist writes the user config file', strpos($cfgSrc, 'public static function persist(') !== false &&
    strpos($cfgSrc, '/.ollamadev/config.json') !== false && strpos($cfgSrc, 'JSON_PRETTY_PRINT') !== false);
ok('desktop exposes the web-access toggle (bindings + UI), governing web.enabled',
    strpos($bindFull, 'function webAccess(') !== false && strpos($bindFull, 'config set web.enabled') !== false &&
    strpos($idxPhp, "\$b->bind('webAccess'") !== false && strpos($bridgeJs, "'webAccess', 'setWebAccess'") !== false &&
    strpos($idxHtml2, 'id="webToggle"') !== false && strpos($appjs, 'window.setWebAccess') !== false);
ok('web-access toggle carries no "air-gap"/"offline" wording', stripos($appjs, 'air-gap') === false &&
    stripos($bindFull, 'air-gap') === false && strpos($appjs, "config get offline") === false);
// Voice (STT) model + history exposed to desktop AND web, backed by the one CLI.
ok('CLI exposes a voice command (model/history/status) for the UI bindings',
    strpos($mainSrc, "\$argv[1] === 'voice'") !== false && strpos($mainSrc, "voice history") !== false &&
    strpos($mainSrc, 'SttClient::setModel(') !== false);
ok('Bindings expose STT model + history methods', strpos($bindFull, "'sttModel', 'setSttModel', 'sttHistory', 'sttClearHistory'") !== false &&
    strpos($bindFull, 'function sttModel(') !== false && strpos($bindFull, 'function sttHistory(') !== false);
ok('Boson + web bridge expose the STT model/history bindings',
    strpos($idxPhp, "\$b->bind('sttModel'") !== false && strpos($idxPhp, "\$b->bind('sttHistory'") !== false &&
    strpos($bridgeJs, "'sttModel', 'setSttModel', 'sttHistory', 'sttClearHistory'") !== false);
ok('voice model dropdown + history panel in the UI (Stt module, additive)',
    strpos($idxHtml2, 'id="sttSelect"') !== false && strpos($idxHtml2, 'id="voiceOverlay"') !== false &&
    strpos($appjs, 'var Stt = {') !== false && strpos($appjs, 'Stt.bind();') !== false);
// Functional: the built binary round-trips a config value through the file.
if (isset($bin) && is_file($bin)) {
    $hh = sys_get_temp_dir() . '/odv-cfgtest-' . getmypid();
    @mkdir($hh, 0755, true);
    $env = 'HOME=' . escapeshellarg($hh) . ' ';
    @shell_exec($env . 'php ' . escapeshellarg($bin) . ' config set search.provider brave 2>/dev/null');
    $got = trim((string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' config get search.provider 2>/dev/null'), " \n\"");
    ok('config set/get round-trips through the config file', $got === 'brave');
    @shell_exec('rm -rf ' . escapeshellarg($hh));
}

// ---- web-search kill switch (search.enabled). ----
ok('search tool honors search.enabled', strpos($toolsSrc, "Config::get('search.enabled', true)") !== false);
ok('CLI search command checks search.enabled', strpos($mainSrc, "!Config::get('search.enabled', true)") !== false);
ok('Bindings expose searchEnabled/setSearchEnabled', strpos($bindFull, "'searchEnabled', 'setSearchEnabled'") !== false &&
    strpos($bindFull, 'function searchEnabled(') !== false && strpos($bindFull, 'config set search.enabled') !== false);
ok('Boson + web bridge expose the search switch', strpos($idxPhp, "\$b->bind('searchEnabled'") !== false &&
    strpos($bridgeJs, "'searchEnabled', 'setSearchEnabled'") !== false);
ok('search toggle button + handler in the UI', strpos($idxHtml2, 'id="searchToggle"') !== false &&
    strpos($appjs, 'toggleSearch:') !== false && strpos($appjs, 'window.setSearchEnabled') !== false);
// Functional: search.enabled=false turns off web search.
if (isset($bin) && is_file($bin)) {
    $h3 = sys_get_temp_dir() . '/odv-setest-' . getmypid();
    @mkdir($h3, 0755, true);
    $env = 'HOME=' . escapeshellarg($h3) . ' ';
    @shell_exec($env . 'php ' . escapeshellarg($bin) . ' config set search.enabled false 2>/dev/null');
    $so = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' search "x" 2>&1');
    ok('disabling search turns web search off', stripos($so, 'search is turned off') !== false);
    @shell_exec('rm -rf ' . escapeshellarg($h3));
}

// ---- v4.5.2 — fix: terminal render() crashed when a terminal was zoomed. ----
// A bare .filter callback read `this.zoomed` with no thisArg → this===undefined
// under 'use strict'. render() now hoists zoomed into a local `z`.
ok('render() hoists zoomed into a local (no this in callbacks)', strpos($appjs, 'var z = this.zoomed;') !== false);
ok('render() filter callback does not read this.zoomed', strpos($appjs, 'filter(function (t) { return t.id === this.zoomed; })') === false);
// Guard the whole class: no array-iteration callback may reference `this.` unless
// it passes a thisArg. Scan render() specifically for the fixed shape.
ok('no unbound this.zoomed left in terminal render', !preg_match('/\.(filter|map|forEach|find|findIndex)\(function\s*\([^)]*\)\s*\{[^}]*this\.zoomed[^}]*\}\)/', $appjs));
// v4.5.3 — focusing a terminal pops it OUT to fill the whole code view.
$appcss = (string)@file_get_contents($repoRoot . '/Desktop/ollamadev-ade/public/app.css');
ok('focus toggles a pop-out class on the code view', strpos($appjs, "cv.classList.toggle('term-zoom', !!z)") !== false);
ok('pop-out CSS fills the code view (absolute, editor hidden)',
    strpos($appcss, '#codeView.term-zoom #terminals') !== false && strpos($appcss, 'position: absolute') !== false &&
    strpos($appcss, '#codeView.term-zoom #editorPane') !== false);

// ---- v4.6.0 — semantic code index, verify loop, git/PR workflow. ----
$ci = (string)@file_get_contents($repoRoot . '/src/56-codeindex.php');
$vf = (string)@file_get_contents($repoRoot . '/src/57-verify.php');
$gf = (string)@file_get_contents($repoRoot . '/src/58-gitflow.php');
// 1) Semantic code index (local embeddings).
ok('CodeIndex uses local Ollama embeddings', strpos($ci, 'class CodeIndex') !== false &&
    strpos($ci, '/api/embeddings') !== false && strpos($ci, 'nomic-embed-text') !== false && strpos($ci, 'cosine') !== false);
ok('code_search is an agent tool + has a schema', strpos($toolsSrc, "Tools::register('code_search'") !== false &&
    strpos((string)@file_get_contents($repoRoot . '/src/60-tools.php'), "\$fn('code_search'") !== false);
ok('CLI exposes index + code-search', strpos($mainSrc, "\$argv[1] === 'index'") !== false && strpos($mainSrc, "\$argv[1] === 'code-search'") !== false);
ok('index ignores build/dep dirs', strpos($ci, "'.build'") !== false && strpos($ci, "'node_modules'") !== false && strpos($ci, "'vendor'") !== false);
// 2) Verify / test-aware agent.
ok('Verify detects common test runners', strpos($vf, 'class Verify') !== false &&
    strpos($vf, 'phpunit') !== false && strpos($vf, 'go test') !== false && strpos($vf, 'pytest') !== false && strpos($vf, 'npm test') !== false);
ok('run_tests is an agent tool + verify has a fix loop', strpos($toolsSrc, "Tools::register('run_tests'") !== false && strpos($vf, 'function fixLoop') !== false);
ok('CLI exposes test + verify', strpos($mainSrc, "\$argv[1] === 'test'") !== false && strpos($mainSrc, "\$argv[1] === 'verify'") !== false);
// 3) Git/PR workflow.
ok('GitFlow generates commit messages + PR text + review', strpos($gf, 'class GitFlow') !== false &&
    strpos($gf, 'function message') !== false && strpos($gf, 'function prText') !== false && strpos($gf, 'function review') !== false);
ok('CLI exposes commit + pr create/review', strpos($mainSrc, "\$argv[1] === 'commit'") !== false &&
    strpos($mainSrc, "\$argv[1] === 'pr'") !== false && strpos($mainSrc, "'create'") !== false && strpos($mainSrc, "'review'") !== false);
// Functional: detection + commit-message round-trips (no model needed for detect).
if (isset($bin) && is_file($bin)) {
    $td = sys_get_temp_dir() . '/odv-feat-' . getmypid();
    @mkdir($td, 0755, true);
    @file_put_contents($td . '/package.json', '{"scripts":{"test":"exit 0"}}');
    $t = (string)@shell_exec('cd ' . escapeshellarg($td) . ' && php ' . escapeshellarg($bin) . ' test 2>&1');
    ok('verify detects npm + runs the suite', stripos($t, 'npm test') !== false && stripos($t, 'passed') !== false);
    @shell_exec('rm -rf ' . escapeshellarg($td));
}

// ---- v4.7.0 — code search in the desktop/web UI + landing cards. ----
ok('code-search/index expose --json for the UI', strpos($mainSrc, 'CodeIndex::search($query, $limit)) . "\n"; exit(0)') !== false || strpos($mainSrc, 'json_encode(CodeIndex::search') !== false);
ok('Bindings expose codeSearch/codeIndexStatus/codeIndexBuild', strpos($bindFull, "'codeSearch', 'codeIndexStatus', 'codeIndexBuild'") !== false &&
    strpos($bindFull, 'function codeSearch(') !== false && strpos($bindFull, 'function codeIndexBuild(') !== false);
ok('code search bindings run in the open project root', strpos($bindFull, 'private function inRoot(') !== false && strpos($bindFull, '$this->files->getRoot()') !== false);
ok('Boson + web bridge expose the code-search bindings', strpos($idxPhp, "\$b->bind('codeSearch'") !== false &&
    strpos($bridgeJs, "'codeSearch', 'codeIndexStatus', 'codeIndexBuild'") !== false);
ok('search panel + rail button in the UI', strpos($idxHtml2, 'id="searchPanel"') !== false &&
    strpos($idxHtml2, 'data-panel="search"') !== false && strpos($idxHtml2, 'id="codeSearchInput"') !== false);
ok('app.js has a CodeSearch module wired for the canvas search window', strpos($appjs, 'var CodeSearch = {') !== false &&
    strpos($appjs, 'CodeSearch.bind()') !== false && strpos($appjs, "view === 'search' && window.CodeSearch && CodeSearch.onShow") !== false);
// Landing cards repurposed (no new cards added) to surface the new features.
$land = (string)@file_get_contents($repoRoot . '/docs/index.html');
ok('landing card surfaces local semantic search', strpos($land, 'local semantic code search') !== false || strpos($land, 'semantic code search') !== false);
ok('landing card surfaces the verify loop', strpos($land, 'ollamadev verify') !== false);

// ---- v4.8.0 — eval harness, model presets + fallback chain, diff-review UI. ----
$evalSrc = (string)@file_get_contents($repoRoot . '/src/59-eval.php');
$modelsSrc = (string)@file_get_contents($repoRoot . '/src/51-models.php');
$agentSrc = (string)@file_get_contents($repoRoot . '/src/75-agent.php');
$sessSrc = (string)@file_get_contents($repoRoot . '/src/90-session.php');

// 1) Eval harness.
ok('Evals class has a built-in suite + isolated runner', strpos($evalSrc, 'class Evals') !== false &&
    strpos($evalSrc, 'function builtins(') !== false && strpos($evalSrc, 'function runOne(') !== false);
ok('Eval checks are deterministic (file_contains / command / file_exists)', strpos($evalSrc, "'file_contains'") !== false &&
    strpos($evalSrc, "'command'") !== false && strpos($evalSrc, "'file_exists'") !== false);
ok('Eval loads user task JSON from ./evals and ~/.ollamadev/evals', strpos($evalSrc, 'userDirs(') !== false && strpos($evalSrc, "/evals'") !== false);
ok('CLI exposes eval (suite + pass rate)', strpos($mainSrc, "\$argv[1] === 'eval'") !== false &&
    strpos($mainSrc, 'Pass rate:') !== false && strpos($mainSrc, 'Evals::suite(') !== false);

// ── Auto-escalate on failure: a weak model hands off to a bigger installed one ──
if (preg_match('/class Models \{.*?\n\}/s', $src, $mEsc)) {
    if (!class_exists('Config')) { if (preg_match('/class Config \{.*?\n\}/s', $src, $cfgE)) eval($cfgE[0]); }
    if (!class_exists('Models')) eval($mEsc[0]);
    ok('Models::paramSize parses the tag size (not the family version)',
        Models::paramSize('qwen2.5-coder:14b') === 14.0 && Models::paramSize('llama3.2:3b') === 3.0 &&
        Models::paramSize('qwen3.6:latest') === null);
    $inst = ['qwen2.5-coder:7b', 'qwen2.5-coder:14b', 'qwen2.5-coder:32b'];
    ok('Models::escalate climbs to the next-bigger installed model',
        Models::escalate('qwen2.5-coder:7b', $inst) === 'qwen2.5-coder:14b' &&
        Models::escalate('qwen2.5-coder:32b', $inst) === null);   // nothing bigger
    ok('Models::escalate honors a configured ladder', (function () {
        Config::set('models.escalation', ['a:1b', 'b:2b', 'c:3b']);
        $r = Models::escalate('a:1b', ['a:1b', 'c:3b']);   // b not installed → skip to c
        Config::set('models.escalation', null);
        return $r === 'c:3b';
    })());
}
// Crew auto-escalates a coder on an empty/failed retry; opt out with --no-escalate.
ok('crew auto-escalates a stalled coder (climbing across retries)', strpos($src, 'Models::escalate($climb, $installedModels)') !== false &&
    strpos($src, '$climb = $bigger') !== false &&
    strpos($src, "Config::get('crew.escalate', true)") !== false && strpos($src, "'--no-escalate'") !== false);
// eval --escalate retries a failed task on a bigger model; --min gates the exit code.
ok('eval supports --escalate and --min threshold', strpos($mainSrc, "in_array('--escalate', \$args, true)") !== false &&
    strpos($mainSrc, "\$a === '--min'") !== false && strpos($mainSrc, '$min >= 0 ? ($rate >= $min)') !== false &&
    strpos($mainSrc, 'Models::escalate($cur, $client->listModels())') !== false);
// CI: the scheduled model-backed eval gates on the --min threshold.
$evalYml = (string)@file_get_contents(dirname(__DIR__) . '/.github/workflows/eval.yml');
ok('CI eval workflow gates on --min', strpos($evalYml, 'eval --model qwen2.5-coder --min 25') !== false);

// 2) Model presets + graceful fallback chain.
ok('Models class has presets + a fallback chain', strpos($modelsSrc, 'class Models') !== false &&
    strpos($modelsSrc, 'function presets(') !== false && strpos($modelsSrc, 'function chain(') !== false &&
    strpos($modelsSrc, 'function toolFallback(') !== false);
ok('presets recommend qwen2.5-coder for tool use', strpos($modelsSrc, 'qwen2.5-coder') !== false && strpos($modelsSrc, "'tools' => true") !== false);
ok('CLI models exposes presets / pull / chain', strpos($mainSrc, "\$sub === 'presets'") !== false &&
    strpos($mainSrc, "\$sub === 'pull'") !== false && strpos($mainSrc, "\$sub === 'chain'") !== false);
ok('agent falls back to a tool-capable model when native tools unsupported', strpos($agentSrc, 'Models::toolFallback(') !== false &&
    strpos($agentSrc, 'triedFallback') !== false && strpos($agentSrc, 'model.autoFallback') !== false);
ok('agent default-selects the best installed chain model', strpos($agentSrc, 'Models::bestInstalled(') !== false);
ok('Models can classify tool support + find any installed tool-capable model',
    strpos($modelsSrc, 'function toolsSupported(') !== false && strpos($modelsSrc, 'function anyToolCapable(') !== false);
ok('agent auto-pick prefers a tool-capable model over an arbitrary first-listed one',
    strpos($agentSrc, 'Models::anyToolCapable(') !== false);
ok('session nudges off a weak model toward a better installed one (gated, non-overriding)',
    strpos($sessSrc, 'modelIsWeakForTools(') !== false && strpos($sessSrc, "model.nagWeakModel") !== false &&
    strpos($sessSrc, 'Models::bestInstalled(') !== false);
ok('weak-model nudge is silenceable via config', strpos($sessSrc, "Config::get('model.nagWeakModel', true)") !== false);

// 2b) Vision: discoverable vision presets + capability-based "no vision" warning.
$ollSrc = (string)@file_get_contents($repoRoot . '/src/50-ollama-client.php');
ok('Models catalog includes vision presets (llava/llama3.2-vision/moondream)',
    strpos($modelsSrc, "'vision' => true") !== false && strpos($modelsSrc, 'function visionPresets(') !== false &&
    strpos($modelsSrc, 'function installedVision(') !== false);
ok('OllamaClient detects model capabilities + vision via /api/show',
    strpos($ollSrc, 'function modelCapabilities(') !== false &&
    strpos($ollSrc, 'function modelSupportsVision(') !== false && strpos($ollSrc, "'capabilities'") !== false);
ok('session warns when an image is attached to a non-vision model',
    strpos($sessSrc, 'function warnIfNoVision(') !== false && strpos($sessSrc, 'modelCapabilities') !== false);

// 3) Diff-review UI (read-only; git stays agent-driven).
ok('GitFlow exposes a read-only working-tree diff', strpos($gf, 'function workingDiff(') !== false);
ok('CLI exposes diff (--json)', strpos($mainSrc, "\$argv[1] === 'diff'") !== false && strpos($mainSrc, 'GitFlow::workingDiff()') !== false);
ok('Bindings expose reviewDiff over the local CLI', strpos($bindFull, "'reviewDiff'") !== false && strpos($bindFull, 'function reviewDiff(') !== false &&
    strpos($bindFull, "inRoot('diff --json')") !== false);
ok('Boson + web bridge expose reviewDiff', strpos($idxPhp, "\$b->bind('reviewDiff'") !== false && strpos($bridgeJs, "'reviewDiff'") !== false);
ok('Review button + diff overlay in the UI', strpos($idxHtml2, 'id="diffBtn"') !== false && strpos($idxHtml2, 'id="diffOverlay"') !== false);
ok('app.js has a Diff module wired in, rendering a colorized diff', strpos($appjs, 'var Diff = {') !== false &&
    strpos($appjs, 'Diff.bind()') !== false && strpos($appjs, "'d-add'") !== false);
ok('diff review styles present', strpos($appcss, '.diff-code') !== false && strpos($appcss, '.d-add') !== false);

// Comparison page.
$cmpPage = (string)@file_get_contents($repoRoot . '/docs/compare.html');
ok('compare.html leads with privacy/cost + evals',
    (strpos($cmpPage, 'privacy') !== false || strpos($cmpPage, 'private') !== false) && strpos($cmpPage, 'eval') !== false);
ok('compare page is honest about where hosted leads', strpos($cmpPage, 'Where hosted tools still lead') !== false);
ok('nav links to the compare page', strpos($land, 'compare.html') !== false);

// Functional: enumerate tasks + presets offline (no model needed).
if (isset($bin) && is_file($bin)) {
    $el = (string)@shell_exec('php ' . escapeshellarg($bin) . ' eval list 2>&1');
    ok('eval list enumerates the built-in tasks', stripos($el, 'create-file') !== false && stripos($el, 'fix-bug') !== false);
    // App-shaped suite: ~20 tasks spanning algorithms, fixes, multi-file, parsing.
    ok('eval suite is broad (algorithms/fixes/multi-file/parsing)', stripos($el, 'fizzbuzz') !== false &&
        stripos($el, 'stack-class') !== false && stripos($el, 'module') !== false && stripos($el, 'fix-syntax') !== false);
    $ec = (int)@shell_exec('php ' . escapeshellarg($bin) . ' eval list --json 2>/dev/null | php -r \'echo count(json_decode(stream_get_contents(STDIN),true) ?: []);\'');
    ok('eval suite has >=20 tasks', $ec >= 20, "count=$ec");
    $mp = (string)@shell_exec('php ' . escapeshellarg($bin) . ' models presets --json 2>&1');
    ok('models presets emits JSON with installed flags', strpos($mp, '"alias"') !== false && strpos($mp, '"installed"') !== false);
    // A global flag BEFORE the subcommand must not hide the command (argv re-root).
    $lead = (string)@shell_exec('php ' . escapeshellarg($bin) . ' --auto eval list 2>&1');
    ok('global flag before subcommand still routes (--auto eval list)', stripos($lead, 'create-file') !== false && stripos($lead, 'Unknown command') === false);
    $leadM = (string)@shell_exec('php ' . escapeshellarg($bin) . ' -m foo eval list 2>&1');
    ok('-m before subcommand still routes', stripos($leadM, 'create-file') !== false);
}
ok('argv re-roots at the first positional (leading global flags)', strpos($mainSrc, '$cmdIdx') !== false && strpos($mainSrc, 'array_slice($argv, $cmdIdx)') !== false);

// Critical: tool schemas must encode empty `properties` as a JSON object ({}),
// not an array ([]) — Ollama >=0.23 returns HTTP 400 on [] and silently kills
// native tool-calling for every model (the run_tests regression).
$toolsDef = (string)@file_get_contents($repoRoot . '/src/60-tools.php');
ok('tool schema properties cast to object (empty -> {} not [])', strpos($toolsDef, "'properties' => (object)\$props") !== false);
// Eval --model override must reach the Agent (which reads static Config, not the array).
ok('eval --model override applied via Config::set', strpos($mainSrc, "Config::set('ollama.defaultModel'") !== false);

// Text-protocol tool-calling: own the protocol instead of relying on Ollama native.
ok('tools.mode text path short-circuits native in chatTurn', strpos($agentSrc, "Config::get('tools.mode'") !== false &&
    strpos($agentSrc, "\$toolMode === 'text'") !== false);
ok('text mode injects a tool catalog + explicit JSON format', strpos($agentSrc, 'Tools::textCatalog()') !== false &&
    strpos($agentSrc, 'TOOL PROTOCOL') !== false);
// Structured mode: schema-constrained decoding for reliable tool calls.
$ollClient = (string)@file_get_contents($repoRoot . '/src/50-ollama-client.php');
ok('structured mode uses schema-constrained decoding in chatTurn', strpos($agentSrc, "\$toolMode === 'structured'") !== false &&
    strpos($agentSrc, 'chatStructured(') !== false && strpos($agentSrc, 'toolCallSchema()') !== false);
ok('toolCallSchema constrains tool names to real tools (enum)', strpos($agentSrc, 'function toolCallSchema(') !== false &&
    strpos($agentSrc, "'enum'") !== false && strpos($agentSrc, "'tool_calls'") !== false);
ok('Ollama client supports chatStructured (schema-constrained format)',
    strpos($ollClient, 'function chatStructured(') !== false && strpos($ollClient, "'format' => \$schema") !== false);
ok('auto resolves to structured when the backend supports it (native is opt-in)',
    strpos($agentSrc, 'function effectiveToolMode(') !== false &&
    strpos($agentSrc, "method_exists(\$this->client, 'chatStructured') ? 'structured' : 'text'") !== false);
ok('structured has a self-consistent envelope fallback', strpos($agentSrc, 'function interpretStructuredText(') !== false &&
    strpos($agentSrc, 'function mapStructured(') !== false && strpos($agentSrc, 'structuredCap') !== false);
ok('parser accepts both name and tool keys', strpos($agentSrc, "\$json['tool']") !== false);
// Reliability: strip stray markdown fences from written files; nudge the model to act via tools.
ok('write/notebook strip a stray enclosing code fence (unfence)', strpos((string)@file_get_contents($repoRoot . '/src/00-header.php'), 'function unfence(') !== false &&
    strpos((string)@file_get_contents($repoRoot . '/src/65-tools-register.php'), 'unfence($content)') !== false);
ok('prompt nudges the model to write via tools, not prose/fences', strpos($agentSrc, '$actNudge') !== false &&
    strpos($agentSrc, 'do NOT wrap file contents') !== false);
// Default temperature lowered to 0.3 (better tool-calling) + a UI dropdown to change it.
$cfgDefaults = (string)@file_get_contents($repoRoot . '/src/10-config.php');
ok('default temperature is 0.3 (agentic-friendly)', strpos($cfgDefaults, "'temperature' => 0.3") !== false &&
    strpos($ollClient, "Config::get('ollama.temperature', 0.3)") !== false);
ok('temperature dropdown wired across UI (binding + select + module)',
    strpos($bindFull, 'function setTemperature(') !== false && strpos($idxPhp, "\$b->bind('setTemperature'") !== false &&
    strpos($idxHtml2, 'id="tempSelect"') !== false && strpos($appjs, 'var Temp = {') !== false);
// Default multi-model crew: per-role models fall back to crew.<role>Model config.
ok('crew per-role models read crew.<role>Model config when no flag', substr_count($crewSrc, "Config::get('crew.' . \$key, '')") >= 2);
// Team skills: templates' focus auto-matches a domain-skill library, seeded into worktrees.
$teamSkillsSrc = (string)@file_get_contents($repoRoot . '/src/86-team-skills.php');
ok('crew matches team skills by focus + seeds them into worktrees', strpos($crewSrc, 'CrewSkills::resolve(') !== false &&
    strpos($crewSrc, 'CrewSkills::materialize(') !== false);
ok('Director plan de-duplicates overlapping subtasks', strpos($crewSrc, 'function dedupeSubtasks(') !== false &&
    strpos($crewSrc, 'dedupeSubtasks(self::planOnce(') !== false);
ok('team-skill library covers PWA + observability (focus-matched)', strpos($teamSkillsSrc, "'pwa' =>") !== false &&
    strpos($teamSkillsSrc, "'observability' =>") !== false && substr_count($teamSkillsSrc, "'triggers' =>") >= 31);
ok('Tools::textCatalog renders signatures from the schemas', strpos($toolsDef, 'function textCatalog(') !== false);
// The text catalog must advertise the full tool set (files + code-intel + git),
// not just the 15 native-schema tools, so the model can call them by name.
ok('text catalog includes git/tree/diagnostics tools', strpos($toolsDef, 'function extraTextTools(') !== false &&
    strpos($toolsDef, "'git_commit'") !== false && strpos($toolsDef, "'tree'") !== false &&
    strpos($toolsDef, "'diagnostics'") !== false && strpos($toolsDef, "'mkdir'") !== false);
ok('textCatalog merges schemas + extraTextTools', strpos($toolsDef, 'self::extraTextTools()') !== false);

// Claude Code tool parity: multi_edit, todo_write, notebook_edit.
$toolsReg = (string)@file_get_contents($repoRoot . '/src/65-tools-register.php');
ok('Claude Code parity tools registered (multi_edit/todo_write/notebook_edit)',
    strpos($toolsReg, "Tools::register('multi_edit'") !== false &&
    strpos($toolsReg, "Tools::register('todo_write'") !== false &&
    strpos($toolsReg, "Tools::register('notebook_edit'") !== false);
ok('multi_edit is atomic (no edits applied on a miss)', strpos($toolsReg, 'no edits applied') !== false);
ok('parity tools advertised (schemas + catalog)', strpos($toolsDef, "\$fn('multi_edit'") !== false &&
    strpos($toolsDef, "\$fn('todo_write'") !== false && strpos($toolsDef, "'notebook_edit'") !== false);
// Background-shell trio (BashOutput/KillShell parity) + ask_user (AskUserQuestion).
ok('tracked background shells: bg + bash_output + kill_bash', strpos($toolsReg, "Tools::register('bash_output'") !== false &&
    strpos($toolsReg, "Tools::register('kill_bash'") !== false && strpos($toolsReg, 'ollamadevBgDir(') !== false);
ok('ask_user tool exists and is interactive-gated', strpos($toolsReg, "Tools::register('ask_user'") !== false &&
    strpos($toolsReg, 'non-interactive run') !== false);
ok('background + ask_user advertised in catalog', strpos($toolsDef, "'bash_output'") !== false &&
    strpos($toolsDef, "'kill_bash'") !== false && strpos($toolsDef, "'ask_user'") !== false);
// CI gate exists: smoke runs on every push/PR, and a model-backed eval gates regressions.
$ciYml = (string)@file_get_contents($repoRoot . '/.github/workflows/ci.yml');
$evalYml = (string)@file_get_contents($repoRoot . '/.github/workflows/eval.yml');
ok('CI runs smoke on push/PR and checks the binary is rebuilt', strpos($ciYml, 'tests/smoke.php') !== false &&
    strpos($ciYml, 'pull_request') !== false && strpos($ciYml, 'git diff --exit-code ollamadev') !== false);
ok('eval workflow gates catastrophic regressions', strpos($evalYml, 'ollamadev eval') !== false &&
    strpos($evalYml, 'ollama pull') !== false && strpos($evalYml, '--min 25') !== false);
// Direct tool-invoke command + the tool-layer functional test wired into CI.
ok('tool invoke command exists (ollamadev tool <name>)', strpos($mainSrc, "\$argv[1] === 'tool'") !== false &&
    strpos($mainSrc, 'Tools::run($name, $params)') !== false);
ok('tool-layer functional test runs in CI', is_file($repoRoot . '/tests/tools.sh') && strpos($ciYml, 'tests/tools.sh') !== false);
// find tool: glob->regex must translate the ESCAPED wildcards (\* \?), else
// every wildcard search silently returns nothing (regression guard).
ok('find translates escaped glob wildcards (no broken regex)',
    strpos((string)@file_get_contents($repoRoot . '/src/00-header.php'), "str_replace(['\\*', '\\?'], ['.*', '.']") !== false);
// Expanded tool tests assert side effects + atomicity + error paths, not just "no crash".
$toolsSh = (string)@file_get_contents($repoRoot . '/tests/tools.sh');
ok('tool tests assert side effects + atomicity', strpos($toolsSh, 'ATOMIC') !== false &&
    strpos($toolsSh, 'eff(') !== false && strpos($toolsSh, 'git_commit created the commit') !== false);
if (isset($bin) && is_file($bin)) {
    $tc = (string)@shell_exec('php ' . escapeshellarg($bin) . " config set tools.mode text 2>&1");
    ok('tools.mode is a settable config key', stripos($tc, 'tools.mode') !== false);
}

// ── Help docs can't drift from the registry (guard test) ─────────────────────
// The 66-vs-95 drift happened because the tool count and category list in help
// were hand-maintained. They're now generated from Tools::all(); these assertions
// fail the build if that generation ever stops covering the real registry, or if
// a new crew subcommand lands without being advertised in the usage block.
[$toolJson] = run_bin(['tool', 'list', '--json']);
$registered = json_decode(trim($toolJson), true);
ok('tool list --json returns the registry', is_array($registered) && count($registered) > 0);
if (is_array($registered) && $registered) {
    [$helpTopics] = run_bin(['help']);
    ok('help advertises the live tool count (no hardcoded drift)',
        strpos($helpTopics, '(' . count($registered) . ' total)') !== false,
        'expected (' . count($registered) . ' total) in help topics');
    [$helpTools] = run_bin(['help', 'tools']);
    // Word-boundary match so e.g. "git" can't false-pass on "git_status".
    $missing = array_values(array_filter($registered,
        fn($t) => !preg_match('/(?<![\w])' . preg_quote($t, '/') . '(?![\w])/', $helpTools)));
    ok('help tools lists every registered tool (Other catch-all)',
        count($missing) === 0, 'missing from help tools: ' . implode(', ', array_slice($missing, 0, 10)));
}
// crew usage (non-TTY) must advertise its subcommands so they're discoverable.
[$crewUsage] = run_bin(['crew']);
ok('crew usage lists steer/director/clear subcommands',
    strpos($crewUsage, 'crew steer') !== false && strpos($crewUsage, 'crew director') !== false
    && strpos($crewUsage, 'crew clear') !== false, 'crew usage missing a subcommand');

// ===== Claude-Code-parity harness: plan mode, hooks, styles, statusline, agents, MCP server =====
echo "\n== Harness parity (plan/hooks/styles/statusline/agents/mcp) ==\n";
// 1. Plan mode — Permission gates mutations; exit_plan_mode restores the prior mode.
if (preg_match('/class Permission \{.*?\n\}/s', $src, $pmh)) {
    if (!class_exists('Permission')) eval($pmh[0]);
    Permission::setMode('auto'); Permission::setMode('plan');
    ok('plan mode blocks mutating tools', Permission::check('write', []) === false && Permission::check('bash', []) === false);
    ok('plan mode allows read-only + exit_plan_mode', Permission::check('view', []) === true && Permission::check('exit_plan_mode', []) === true);
    ok('exitPlan restores the prior mode', Permission::exitPlan() === 'auto' && Permission::check('write', []) === true);
}
ok('exit_plan_mode tool + schema registered', strpos($src, "Tools::register('exit_plan_mode'") !== false && strpos($src, "\$fn('exit_plan_mode'") !== false);
ok('--plan flag sets plan mode (not readonly)', strpos($src, "\$a === '--plan') { \$flags['permission'] = 'plan'") !== false);
ok('/plan command wired', strpos($src, "'plan' => \$this->togglePlan(") !== false);
ok('agent system prompt honors plan mode', strpos($src, 'Permission::inPlanMode()') !== false && strpos($src, 'PLAN MODE:') !== false);

// 2. Hooks — full event set + PreToolUse blocking, fired from Tools::run.
ok('Hooks support PreToolUse blocking + PostToolUse', strpos($src, 'function preToolUse(') !== false &&
    strpos($src, 'function postToolUse(') !== false && strpos($src, 'Blocked by PreToolUse hook') !== false);
ok('Hooks fire from Tools::run', strpos($src, 'Hooks::preToolUse($name, $params)') !== false && strpos($src, 'Hooks::postToolUse($name, $params') !== false);
ok('lifecycle events wired (SessionStart/UserPromptSubmit/Stop/PreCompact/SubagentStop)',
    strpos($src, "Hooks::event('SessionStart'") !== false && strpos($src, "Hooks::event('UserPromptSubmit'") !== false &&
    strpos($src, "Hooks::event('Stop'") !== false && strpos($src, "Hooks::event('PreCompact'") !== false &&
    strpos($src, "Hooks::event('SubagentStop'") !== false);
ok('hooks support per-tool matchers + list form', strpos($src, "\$h['matcher']") !== false && strpos($src, 'function forEvent(') !== false);

// 3 & 4. Output styles + status line classes present and wired.
ok('OutputStyles class with named presets', strpos($src, 'class OutputStyles') !== false && strpos($src, "'concise'") !== false && strpos($src, 'function promptSuffix(') !== false);
ok('output style appended to system prompt', strpos($src, 'OutputStyles::promptSuffix()') !== false);
ok('/output-style command wired', strpos($src, "=> \$this->setOutputStyle(") !== false);
ok('StatusLine class + tokens + render in REPL', strpos($src, 'class StatusLine') !== false && strpos($src, '{branch}') !== false && strpos($src, 'StatusLine::render(') !== false);
ok('/statusline command wired', strpos($src, "'statusline' => \$this->setStatusLine(") !== false);

// 5. Custom agent types.
ok('AgentDefs class reads .ollamadev/agents/*.md', strpos($src, 'class AgentDefs') !== false && strpos($src, '/.ollamadev/agents') !== false);
ok('SubAgent honors agent_type (model/permission/persona)', strpos($src, 'AgentDefs::get($at)') !== false &&
    strpos($src, "\$p['agent_type']") !== false);
ok('agents CLI command + --json', strpos($src, "\$argv[1] === 'agents'") !== false && strpos($src, 'AgentDefs::all()') !== false);
// 5b. The agent's tools: list is a HARD gate (not just a prompt-level nudge).
if (preg_match('/class Permission \{.*?\n\}/s', $src, $pah)) {
    if (!class_exists('Permission')) eval($pah[0]);
    Permission::setMode('auto'); Permission::setToolAllowlist(['view', 'grep']);
    ok('tool allowlist hard-blocks unlisted tools', Permission::check('view', []) === true &&
        Permission::check('bash', []) === false && Permission::check('write', []) === false);
    Permission::clearToolAllowlist();
    ok('clearing the allowlist restores access', Permission::check('bash', []) === true);
}
ok('SubAgent enforces + restores the tool allowlist', strpos($src, 'Permission::setToolAllowlist($def[\'tools\'])') !== false &&
    strpos($src, 'Permission::setToolAllowlist($parentAllow)') !== false);

// 5c. /hooks + `ollamadev hooks` editor (add/list/remove, persisted as JSON).
ok('Hooks editor methods present', strpos($src, 'function editorCommand(') !== false &&
    strpos($src, 'function add(string $event') !== false && strpos($src, 'function removeAt(') !== false);
ok('/hooks + hooks CLI wired', strpos($src, "'hooks' => Hooks::editorCommand(") !== false &&
    strpos($src, "\$argv[1] === 'hooks'") !== false);
if (isset($BIN) && is_file($BIN)) {
    $hh = sys_get_temp_dir() . '/odv_hooks_' . getmypid(); @mkdir($hh . '/.ollamadev', 0777, true);
    run_bin(['hooks', 'add', 'PreToolUse', 'echo nope; exit 1', '--match', 'bash'], '', ['HOME' => $hh]);
    [$hl] = run_bin(['hooks', 'list'], '', ['HOME' => $hh]);
    ok('hooks add persists with matcher', strpos($hl, 'PreToolUse') !== false && strpos($hl, 'match: bash') !== false);
    [$hb] = run_bin(['tool', 'bash', '{"command":"echo hi"}'], '', ['HOME' => $hh]);
    ok('an added PreToolUse hook actually blocks', strpos($hb, 'Blocked by PreToolUse hook') !== false);
    run_bin(['hooks', 'remove', 'PreToolUse', '0'], '', ['HOME' => $hh]);
    [$hb2] = run_bin(['tool', 'bash', '{"command":"echo back"}'], '', ['HOME' => $hh]);
    ok('hooks remove un-blocks the tool', strpos($hb2, 'Blocked') === false);
    @exec('rm -rf ' . escapeshellarg($hh));
}

// 6. MCP server.
ok('McpServer speaks JSON-RPC over stdio', strpos($src, 'class McpServer') !== false &&
    strpos($src, "'tools/list'") !== false && strpos($src, "'tools/call'") !== false && strpos($src, "'initialize'") !== false);
ok('mcp serve dispatched (read-only by default, --allow-writes to opt in)',
    strpos($src, "=== 'serve') { exit(McpServer::serve(in_array('--allow-writes'") !== false &&
    strpos($src, "Permission::setMode(\$allowWrites ? 'auto' : 'readonly')") !== false);
// Functional: drive the server end-to-end through the real binary.
if (isset($BIN) && is_file($BIN)) {
    $rpc = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' . "\n" . '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' . "\n";
    [$mo] = run_bin(['mcp', 'serve'], $rpc);
    ok('mcp serve: initialize + tools/list round-trip', strpos($mo, '"serverInfo"') !== false && strpos($mo, '"name":"view"') !== false);
    [$mc] = run_bin(['mcp', 'serve'], '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"ping"}}}' . "\n");
    ok('mcp serve: tools/call returns content', strpos($mc, '"content"') !== false && strpos($mc, 'ping') !== false);
}

// ── Security-review regressions (found by adversarial review, now guarded) ──────
// 1. The `agent` tool must route nested calls through Tools::run (gate/hooks/plan),
//    not invoke the closure directly (which bypassed every guard).
ok('agent tool routes nested calls through the permission gate',
    strpos($src, '$result = $tool ? Tools::run($toolName, $params)') !== false &&
    strpos($src, '$result = $tool($params);') === false);
// 2. Plan mode can only be left via an EXPLICIT interactive yes — no self-exit in
//    non-interactive runs, and a bare Enter/EOF is not approval.
ok('exit_plan_mode cannot self-approve non-interactively',
    strpos($src, 'if (!Permission::isInteractive()) {') !== false &&
    strpos($src, "Leaving plan mode needs explicit user approval") !== false &&
    strpos($src, "\$ans === 'y' || \$ans === 'yes') {") !== false &&   // no "|| $ans === ''"
    strpos($src, "\$ans === 'y' || \$ans === 'yes' || \$ans === ''") === false);
// 3. Hooks fail CLOSED: a PreToolUse hook that can't start (proc_open fails) blocks
//    the tool (non-zero), and an INVALID matcher regex applies the hook (not skip).
ok('PreToolUse hooks fail closed (proc fail → block; bad matcher → apply)',
    strpos($src, "if (!is_resource(\$proc)) return ['', 127];") !== false &&
    strpos($src, '$m === 0) continue;') !== false &&
    strpos($src, 'OLLAMADEV_HOOK_DEPTH') !== false);
// 4. A live model swap only targets a CONFIRMED-installed model (no dead-model swap
//    when Ollama is unreachable).
ok('crew model hot-swap verifies the model is installed',
    strpos($src, "in_array(\$resolved, \$installed, true)") !== false &&
    strpos($src, 'model switch skipped (unverified') !== false);
// 5. A fresh coder attempt clears its steering watermark so retries see the Director.
// Watermark PERSISTS across retries (the earlier reset clobbered auto-escalation by
// re-applying a stale model-swap; only post-attempt steering reaches a new attempt).
ok('steering watermark persists across retries (no stale re-apply)',
    strpos($src, 'unset(self::$steerSeen[$steerN]);') === false &&
    strpos($src, 'the watermark deliberately PERSISTS across retry') !== false);
// 6. MoE tags size correctly (experts × per-expert), and resume whitelists override keys.
ok('MoE param sizes + resume key whitelist', strpos($src, ')\s*x\s*(') !== false &&
    strpos($src, 'array_intersect_key($overrides, $allowed)') !== false);

// ── Ultrareview regressions (found by the multi-agent cloud review, now guarded) ──
// R1. Executable config (statusline/hooks) reads ONLY trusted home/global config —
//     a cloned repo's project-local .ollamadev.json can't run shell commands (RCE).
ok('statusline + hooks read trusted (home-only) config',
    strpos($src, 'function trustedGet(') !== false &&
    strpos($src, "Config::trustedGet('statusline'") !== false &&
    strpos($src, "Config::trustedGet('hooks.' . \$event") !== false);
if (preg_match('/class Config \{.*?\n\}/s', $src, $cfgT)) {
    if (!class_exists('Config')) eval($cfgT[0]);
    $th = sys_get_temp_dir() . '/odv_trust_' . getmypid(); @mkdir($th . '/proj', 0777, true);
    file_put_contents($th . '/proj/.ollamadev.json', '{"statusline":"echo PWNED"}');
    $oldHome = getenv('HOME'); $oldCwd = getcwd();
    putenv("HOME=$th"); chdir($th . '/proj');   // home has NO config; only the project file
    $r = Config::trustedGet('statusline', '');
    putenv($oldHome !== false ? "HOME=$oldHome" : 'HOME'); chdir($oldCwd);
    @exec('rm -rf ' . escapeshellarg($th));
    ok('project-local config cannot supply an executable statusline', $r === '');
}
// R2. Tool allowlist confines only MUTATING tools (read-only + exit_plan_mode pass).
ok('tool allowlist exempts read-only + control tools',
    strpos($src, '!in_array($tool, self::$toolAllow, true) && !self::isReadonly($tool)) return false') !== false);
// R3. Plan-mode prompt re-syncs when the mode flips mid-session (no stale PLAN MODE).
ok('agent re-syncs the system prompt on plan-mode change',
    strpos($src, '$this->builtPlanMode') !== false &&
    strpos($src, 'Permission::inPlanMode() !== $this->builtPlanMode) $this->systemPrompt = $this->buildSystemPrompt()') !== false);
// R4. /plan off restores the prior mode (not a hardcoded 'ask').
ok('/plan off restores the pre-plan mode', strpos($src, 'elseif ($wasPlan) Permission::exitPlan();') !== false &&
    strpos($src, "\$a === 'off' ? 'ask'") === false);
// R5. Escalation ladder selects the next rung alias-aware (base-name ladders work).
ok('escalation ladder is alias-aware on both ends', strpos($src, '$hit = self::match($ladder[$i], $installed)') !== false);
// R6. paramSize infers :latest/untagged sizes from the preset catalog.
ok('paramSize falls back to preset sizes for :latest tags', strpos($src, "explode(':', (string)(\$p['tag'] ?? ''))[0] === \$base") !== false);
// R7. MCP server defaults read-only; mutations need --allow-writes / mcp.allowWrites.
ok('mcp serve is read-only by default', strpos($src, "Permission::setMode(\$allowWrites ? 'auto' : 'readonly')") !== false &&
    strpos($src, "Config::get('mcp.allowWrites', false)") !== false);
// R8. MCP tools/call marks failures isError:true.
ok('mcp tools/call surfaces tool errors', strpos($src, 'CmdError::isError($text)') !== false &&
    strpos($src, 'function isError(string $result)') !== false);
// R9. Plan mode propagates into delegated subagents (they can't mutate either).
ok('plan mode propagates into subagents', strpos($src, "\$parentMode === 'plan') \$subMode = 'readonly'") !== false);

// Small-model reliability: "described but didn't call a tool" nudge in the agent loop.
ok('agent loop nudges a described-but-unacted edit once', strpos($src, '$nudgedAct') !== false &&
    strpos($src, 'self::looksLikeUnactedEdit($response)') !== false &&
    strpos($src, 'You described the change but did NOT call a tool') !== false);
if (preg_match('/private static function looksLikeUnactedEdit\(string \$response\): bool \{.*?\n    \}/s', $src, $mL)) {
    eval('class _LU { ' . str_replace('private static', 'public static', $mL[0]) . ' }');
    ok('looksLikeUnactedEdit flags a fenced code block', _LU::looksLikeUnactedEdit("Sure! ```php\n<?php function f(){ return 1; }\n```") === true);
    ok('looksLikeUnactedEdit flags bare source', _LU::looksLikeUnactedEdit("<?php echo 1;") === true);
    ok('looksLikeUnactedEdit ignores a plain answer', _LU::looksLikeUnactedEdit("The capital of France is Paris.") === false);
}

echo "\n========================\n";
echo "Results: $pass passed, $fail failed\n";
if ($fail > 0) { echo "FAILED: " . implode(', ', $fails) . "\n"; exit(1); }
echo "ALL SMOKE TESTS PASSED\n";
exit(0);
