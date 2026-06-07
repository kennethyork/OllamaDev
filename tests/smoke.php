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

// Provider-aware onboarding (preflight) needs a host() getter on every client and
// on the Agent facade so it can name the backend and print the right fix-up steps.
ok('OllamaClient exposes host()', strpos($src, 'function host()') !== false && strpos($src, 'class OllamaClient') !== false);
ok('LMStudioClient exposes host()', preg_match('/class LMStudioClient \{.*?function host\(\).*?\n\}/s', $src) === 1);
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
    eval(str_replace('private static function extractJson', 'public static function extractJson', $fm[0]));
    $j = Crew::extractJson('noise {"subtasks":[{"title":"a","prompt":"b"}]} tail');
    ok('Crew::extractJson pulls balanced JSON', is_array($j) && ($j['subtasks'][0]['title'] ?? '') === 'a');
    ok('Crew::extractJson returns null on none', Crew::extractJson('no json here') === null);
    ok('Crew::slug normalizes titles', Crew::slug('Add /Health Route!') === 'add-health-route');
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
shell_exec('rm -rf ' . escapeshellarg($shome));
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

echo "\n== Air-gap + attestation ==\n";
if (preg_match('/class Permission \{.*?\n\}/s', $src, $pm)) {
    if (!class_exists('Permission')) eval($pm[0]);
    Permission::setOffline(true);
    ok('offline blocks network tools', Permission::check('fetch') === false && Permission::check('git_push') === false);
    ok('offline keeps local readonly tools', Permission::check('view') === true);
    Permission::allow('fetch');
    ok('offline overrides an explicit allow()', Permission::check('fetch') === false);
    Permission::setOffline(false);
    ok('online lets network tools through (auto)', (function () { Permission::setMode('auto'); return Permission::check('fetch'); })() === true);
    Permission::setMode('ask');
} else { ok('Permission class extractable for air-gap', false); }
if (preg_match('/class Attest \{.*?\n\}/s', $src, $am)) {
    if (!class_exists('Attest')) eval($am[0]);
    ok('Attest detects loopback hosts', Attest::isLoopbackHost('http://localhost:11434') && Attest::isLoopbackHost('http://127.0.0.1:1234/v1') && Attest::isLoopbackHost('http://[::1]:11434'));
    ok('Attest rejects remote hosts', !Attest::isLoopbackHost('http://192.168.1.9:11434') && !Attest::isLoopbackHost('https://api.example.com'));
} else { ok('Attest class extractable', false); }
ok('attest command + offline flag wired', strpos($src, "=== 'attest'") !== false && strpos($src, 'Permission::setOffline(true)') !== false);
ok('update blocked when offline', strpos($src, 'offline mode is on') !== false);

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
ok('auto-provision is air-gap gated', strpos($src, 'Permission::isOffline()') !== false && strpos($src, 'OLLAMADEV_STT_DIR') !== false);
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
ok('web mode stays vanilla (no deps in web/)', !is_file($adeDir . '/web/package.json') && strpos($brg, 'import ') === false && strpos($brg, 'require(') === false);
ok('composer serve script wired', strpos((string)@file_get_contents($adeDir . '/composer.json'), '"serve"') !== false);
$adeCss = (string)@file_get_contents($adeDir . '/public/app.css');
ok('ADE app is responsive (mobile media query)', strpos($adeCss, '@media (max-width: 820px)') !== false && strpos($adeCss, 'nav-open') !== false);
ok('mobile sidebar drawer wired in JS', strpos($ade, 'initResponsive') !== false && strpos($ade, "matchMedia('(max-width: 820px)')") !== false);
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
ok('crew requires >1 host + >1 job to parallelize', strpos($src, 'count($jobs) > 1 && count($hosts) > 1') !== false);
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
// Free is the default: a fresh user (no stored pref) lands in Free; only an
// explicit 'tiled' choice opts back into the grid.
ok('free layout is the default (only explicit tiled opts out)',
    strpos($ajs, "localStorage.getItem('ade.termLayout') === 'tiled' ? 'tiled' : 'free'") !== false &&
    strpos($ajs, "termLayout: 'free'") !== false);
// Surface parity: every desktop binding (Bindings::PUBLIC) must be wrapped by the web
// bridge too, or that feature is dead in web mode (how crewSteer/skills* drifted).
$bridge = (string) @file_get_contents(dirname(__DIR__) . '/Desktop/ollamadev-ade/web/bridge.js');
if (preg_match('/PUBLIC\s*=\s*\[(.*?)\];/s', $bind, $pm)) {
    preg_match_all("/'([a-zA-Z][a-zA-Z0-9]*)'/", $pm[1], $pn);
    $missing = array_values(array_filter($pn[1], fn($x) => strpos($bridge, "'" . $x . "'") === false));
    ok('web bridge exposes every desktop binding (cli/desktop/web in sync)', empty($missing), 'missing in bridge.js: ' . implode(', ', $missing));
} else { ok('Bindings PUBLIC list parseable', false); }
ok('interactive crew loops Crew::run per prompt', strpos($src, "in_array(strtolower(\$line), ['exit', 'quit', 'q', ':q']") !== false);

echo "\n== LM Studio provider ==\n";
if (preg_match('/class ModelClient \{.*?\n\}/s', $src, $mc)) {
    eval($mc[0]);
    ok('ModelClient detects LM Studio /v1 + :1234 hosts', ModelClient::isOpenAiStyle('http://localhost:1234/v1') === true && ModelClient::isOpenAiStyle('http://x:1234') === true);
    ok('ModelClient treats a plain host as Ollama', ModelClient::isOpenAiStyle('http://localhost:11434') === false);
} else { ok('ModelClient extractable', false); }
ok('LMStudioClient speaks OpenAI /v1', strpos($src, 'class LMStudioClient') !== false && strpos($src, '/chat/completions') !== false && strpos($src, "response_format") !== false);
ok('LMStudioClient mirrors the client surface', preg_match('/class LMStudioClient \{.*?\n\}/s', $src, $lm) === 1
    && strpos($lm[0], 'function chatWithTools(') !== false && strpos($lm[0], 'function chatJson(') !== false
    && strpos($lm[0], 'function chatWithModel(') !== false && strpos($lm[0], 'function listModels(') !== false);
ok('Agent + Crew use the ModelClient factory', strpos($src, 'ModelClient::default()') !== false && strpos($src, 'ModelClient::for(') !== false);
ok('--lmstudio / --host flags set the override', strpos($src, "\$a === '--lmstudio'") !== false && strpos($src, "\$a === '--host'") !== false && strpos($src, 'ModelClient::$override') !== false);
ok('config has provider + lmstudio.host defaults', strpos($src, "'provider' =>") !== false && strpos($src, "'lmstudio' => ['host'") !== false);
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
ok('CLI has a direct `search` command honoring air-gap', strpos($mainSrc, "\$argv[1] === 'search'") !== false &&
    strpos($mainSrc, 'Permission::isOffline()') !== false && strpos($mainSrc, "Tools::run('search'") !== false);
ok('CLI has a `config get/set` command', strpos($mainSrc, "\$argv[1] === 'config'") !== false &&
    strpos($mainSrc, 'Config::persist(') !== false);
ok('Config::persist writes the user config file', strpos($cfgSrc, 'public static function persist(') !== false &&
    strpos($cfgSrc, '/.ollamadev/config.json') !== false && strpos($cfgSrc, 'JSON_PRETTY_PRINT') !== false);
ok('Bindings expose the web-access toggle', strpos($bindFull, "'webAccess', 'setWebAccess'") !== false &&
    strpos($bindFull, 'function webAccess(') !== false && strpos($bindFull, 'config set offline') !== false);
ok('Boson + web bridge expose the web toggle', strpos($idxPhp, "\$b->bind('webAccess'") !== false &&
    strpos($idxPhp, "\$b->bind('setWebAccess'") !== false && strpos($bridgeJs, "'webAccess', 'setWebAccess'") !== false);
ok('web toggle button + Net module in the UI', strpos($idxHtml2, 'id="webToggle"') !== false &&
    strpos($appjs, 'var Net = {') !== false && strpos($appjs, 'Net.bind(); Net.load();') !== false &&
    strpos($appjs, 'window.setWebAccess') !== false);
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
// Functional: the built binary round-trips config + refuses search when offline.
if (isset($bin) && is_file($bin)) {
    $hh = sys_get_temp_dir() . '/odv-cfgtest-' . getmypid();
    @mkdir($hh, 0755, true);
    $env = 'HOME=' . escapeshellarg($hh) . ' ';
    @shell_exec($env . 'php ' . escapeshellarg($bin) . ' config set offline true 2>/dev/null');
    $got = trim((string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' config get offline 2>/dev/null'));
    ok('config set/get round-trips through the config file', $got === 'true');
    $searchOut = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' search "anything" 2>&1');
    ok('search refuses in air-gapped mode', stripos($searchOut, 'offline') !== false || stripos($searchOut, 'air-gap') !== false);
    @shell_exec('rm -rf ' . escapeshellarg($hh));
}

// ---- v4.5.1 — search-only kill switch (search.enabled), separate from air-gap. ----
ok('search tool honors search.enabled', strpos($toolsSrc, "Config::get('search.enabled', true)") !== false);
ok('CLI search command checks search.enabled', strpos($mainSrc, "!Config::get('search.enabled', true)") !== false);
ok('Bindings expose searchEnabled/setSearchEnabled', strpos($bindFull, "'searchEnabled', 'setSearchEnabled'") !== false &&
    strpos($bindFull, 'function searchEnabled(') !== false && strpos($bindFull, 'config set search.enabled') !== false);
ok('Boson + web bridge expose the search switch', strpos($idxPhp, "\$b->bind('searchEnabled'") !== false &&
    strpos($bridgeJs, "'searchEnabled', 'setSearchEnabled'") !== false);
ok('search toggle button + handler in the UI', strpos($idxHtml2, 'id="searchToggle"') !== false &&
    strpos($appjs, 'toggleSearch:') !== false && strpos($appjs, 'window.setSearchEnabled') !== false);
// Functional: search.enabled gates ONLY search; air-gap stays independent.
if (isset($bin) && is_file($bin)) {
    $h3 = sys_get_temp_dir() . '/odv-setest-' . getmypid();
    @mkdir($h3, 0755, true);
    $env = 'HOME=' . escapeshellarg($h3) . ' ';
    @shell_exec($env . 'php ' . escapeshellarg($bin) . ' config set search.enabled false 2>/dev/null');
    $so = (string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' search "x" 2>&1');
    $off = trim((string)@shell_exec($env . 'php ' . escapeshellarg($bin) . ' config get offline 2>/dev/null'));
    ok('disabling search blocks search but not the air-gap flag', stripos($so, 'search is turned off') !== false && $off !== 'true');
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
ok('PR commands are air-gap gated; commit is local', strpos($mainSrc, "Offline mode — PR commands") !== false);
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
ok('app.js has a CodeSearch module wired into setPanel', strpos($appjs, 'var CodeSearch = {') !== false &&
    strpos($appjs, 'CodeSearch.bind()') !== false && strpos($appjs, "if (p === 'search') CodeSearch.onShow()") !== false);
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
ok('models pull stays air-gap gated', strpos($mainSrc, 'offline mode is on — pulling a model') !== false);

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
ok('compare.html leads with privacy/cost/air-gap + evals', strpos($cmpPage, 'air-gap') !== false &&
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
    $lead = (string)@shell_exec('php ' . escapeshellarg($bin) . ' --offline eval list 2>&1');
    ok('global flag before subcommand still routes (--offline eval list)', stripos($lead, 'create-file') !== false && stripos($lead, 'Unknown command') === false);
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
$lmClient = (string)@file_get_contents($repoRoot . '/src/52-lmstudio-client.php');
ok('structured mode uses schema-constrained decoding in chatTurn', strpos($agentSrc, "\$toolMode === 'structured'") !== false &&
    strpos($agentSrc, 'chatStructured(') !== false && strpos($agentSrc, 'toolCallSchema()') !== false);
ok('toolCallSchema constrains tool names to real tools (enum)', strpos($agentSrc, 'function toolCallSchema(') !== false &&
    strpos($agentSrc, "'enum'") !== false && strpos($agentSrc, "'tool_calls'") !== false);
ok('both backends support chatStructured (Ollama format + LM Studio json_schema)',
    strpos($ollClient, 'function chatStructured(') !== false && strpos($ollClient, "'format' => \$schema") !== false &&
    strpos($lmClient, 'function chatStructured(') !== false && strpos($lmClient, 'json_schema') !== false);
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
    strpos($evalYml, 'ollama pull') !== false && strpos($evalYml, '-lt 2') !== false);
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

echo "\n========================\n";
echo "Results: $pass passed, $fail failed\n";
if ($fail > 0) { echo "FAILED: " . implode(', ', $fails) . "\n"; exit(1); }
echo "ALL SMOKE TESTS PASSED\n";
exit(0);
