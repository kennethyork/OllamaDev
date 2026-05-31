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
[$out] = run_bin([], "/model definitely-not-real-model-xyz\n/exit\n");
ok('/model rejects an uninstalled model', stripos($out, 'not installed') !== false);

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
// Source wiring: the four roles + worktree + json-mode are present
ok('crew has a Researcher role', strpos($src, 'Researcher worker') !== false || strpos($src, 'function research(') !== false);
ok('crew Director uses JSON mode', strpos($src, 'chatJson(') !== false);
ok('crew Coder uses git worktrees', strpos($src, 'git worktree add') !== false);
ok('crew Auditor + auto-merge wired', strpos($src, "git merge --no-ff") !== false && strpos($src, 'function audit(') !== false);

echo "\n== Skills ==\n";
if (preg_match('/class Skills \{.*?\n\}/s', $src, $sk)) {
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
    putenv("HOME=$oldHome"); chdir($oldCwd);
    @exec('rm -rf ' . escapeshellarg($tmpHome));
} else { ok('Skills class extractable', false); }
// Source wiring: skill tool registered, schema present, prompt injection, read-only
ok('skill tool registered', strpos($src, "Tools::register('skill'") !== false);
ok('skill tool has native schema', strpos($src, "\$fn('skill'") !== false);
ok('skills injected into system prompt', strpos($src, 'AVAILABLE SKILLS') !== false);
ok('skills install/export/remove wired (CLI)', strpos($src, "\$sub === 'install'") !== false && strpos($src, "\$sub === 'export'") !== false);
ok('skills install supports git + archive sources', strpos($src, 'git clone --depth 1') !== false && strpos($src, 'tgz|zip') !== false);

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
ok('crew supports interactive mode (no task → Director prompt)', strpos($src, 'Director ▸') !== false && strpos($src, 'posix_isatty(STDIN)') !== false);
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
    eval($cs[0]);
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
ok('crew computes team skills from focus', strpos($src, 'CrewSkills::forFocus($focus)') !== false);
ok('crew materializes skills into worktrees', strpos($src, 'CrewSkills::materialize($teamSkills, $wt)') !== false);
ok('crew --no-skills flag wired', strpos($src, "'--no-skills'") !== false);
// /crew slash command exposes per-role models + focus
ok('/crew parses per-role model flags', strpos($src, "--' . \$role . '-model") !== false);
ok('/crew parses --focus', strpos($src, "--focus\\s+\"") !== false || strpos($src, '--focus\s+"') !== false);
ok('crew prints per-role models when they differ', strpos($src, 'roles:') !== false);
// Self-modification safeguard: review forced on the OllamaDev source unless --auto-merge
ok('crew has a self-repo detector', strpos($src, 'function isSelfRepo()') !== false);
ok('crew forces review on self-repo', strpos($src, "self::isSelfRepo()") !== false && strpos($src, 'self-modification detected') !== false);
ok('crew --auto-merge override wired (CLI)', strpos($src, "'--auto-merge'") !== false);
ok('crew --auto-merge override wired (/crew)', strpos($src, '--auto-merge(\s|$)') !== false);

echo "\n========================\n";
echo "Results: $pass passed, $fail failed\n";
if ($fail > 0) { echo "FAILED: " . implode(', ', $fails) . "\n"; exit(1); }
echo "ALL SMOKE TESTS PASSED\n";
exit(0);
