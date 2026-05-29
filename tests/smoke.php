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

// Run the binary with optional piped stdin. Returns [stdout, stderr, exitcode].
function run_bin(array $args, string $stdin = '', array $env = []): array {
    global $BIN;
    $cmd = 'php ' . escapeshellarg($BIN);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $fullEnv = array_merge(getenv() ?: [], $env);
    $proc = proc_open($cmd, $descriptors, $pipes, sys_get_temp_dir(), $fullEnv);
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

[$out, $err] = run_bin(['-m', 'definitely-not-installed:999b'], "/exit\n");
ok('-m warns on missing model', stripos($err, 'not installed') !== false, trim($err));

echo "\n== Internal units (extracted classes) ==\n";

// Load just the Agent's tool-call parsers by extracting the methods into a
// throwaway class - keeps the test independent of the full CLI bootstrap.
$src = file_get_contents($BIN);

// extractJsonToolCalls + parseToolCalls live in the Agent class; pull the two
// static/instance methods we want to exercise.
if (preg_match('/public static function extractJsonToolCalls\(string \$content\): array \{.*?\n    \}/s', $src, $mE)) {
    eval('class _P { ' . $mE[0] . ' }');
    $calls = _P::extractJsonToolCalls('blah <tool_code>{"name":"bash","arguments":{"command":"echo hi"}}');
    ok('extractJsonToolCalls handles missing close tag', count($calls) === 1 && $calls[0]['name'] === 'bash');
    $calls2 = _P::extractJsonToolCalls('{"name":"write","arguments":{"file_path":"a.txt","content":"x"}}');
    ok('extractJsonToolCalls parses nested args', count($calls2) === 1 && ($calls2[0]['params']['file_path'] ?? '') === 'a.txt');
    $calls3 = _P::extractJsonToolCalls('no tool calls here at all');
    ok('extractJsonToolCalls returns none for plain text', count($calls3) === 0);
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
    eval('class _PC { ' . $e1[0] . "\n" . $e2[0] . ' }');
    $pc = new _PC();
    $a = $pc->parseToolCalls('<tool_code>{"name":"ls","arguments":{"path":"."}}</tool_code>');
    ok('parses a wrapped JSON tool call', count($a) === 1 && $a[0]['name'] === 'ls');
    $b = $pc->parseToolCalls("name: write params: file_path=a.txt");
    ok('parses the text name/params format', count($b) === 1 && $b[0]['name'] === 'write' && ($b[0]['params']['file_path'] ?? '') === 'a.txt');
    $c = $pc->parseToolCalls("Sure! I'll find the file and list the diff for you.");
    ok('plain prose does NOT trigger false tool calls', count($c) === 0);
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

echo "\n========================\n";
echo "Results: $pass passed, $fail failed\n";
if ($fail > 0) { echo "FAILED: " . implode(', ', $fails) . "\n"; exit(1); }
echo "ALL SMOKE TESTS PASSED\n";
exit(0);
