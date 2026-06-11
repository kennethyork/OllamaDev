<?php

declare(strict_types=1);

namespace OllamaDev;

// Shared implementation of every frontend↔backend call, so the SAME logic backs
// both runtimes: the Boson desktop (native bindings) and the browser server mode
// (HTTP /api/<name>). One source of truth — add a method here and both get it.
// All model/agent/crew work still runs locally through the ollamadev CLI; the
// browser is just another front-end over the same local engine.
final class Bindings
{
    public function __construct(
        private PtyManager $pty,
        private FileBrowser $files,
        private string $cli,
    ) {}

    // Names callable over HTTP (and the arg order the web shim sends).
    public const PUBLIC = [
        'listModels', 'termCreate', 'termRead', 'termWrite', 'termKill', 'termResize', 'agentRun',
        'cliPath', 'sttEnabled', 'sttTranscribe', 'crewBoard', 'homeDir',
        'crewCoderLog', 'memoryGraph', 'getRoot', 'setRoot', 'listFiles', 'readFile', 'writeFile',
        'wsList', 'wsAdd', 'wsRemove', 'wsSetActive', 'wsSaveState',
        'crewRoleList', 'crewRoleAdd', 'crewRoleRemove',
        'webAccess', 'setWebAccess', 'searchEnabled', 'setSearchEnabled',
        'codeSearch', 'codeIndexStatus', 'codeIndexBuild',
        'reviewDiff', 'temperature', 'setTemperature',
        'sttModel', 'setSttModel', 'sttHistory', 'sttClearHistory',
        'openExternal', 'proxyFetch', 'crewModels', 'setCrewModels', 'crewSteer',
        'skillsList', 'skillsGet', 'skillsSave', 'skillsRemove',
        'hooksList', 'hooksAdd', 'hooksRemove',
        'chatList', 'chatDelete', 'chatExport',
    ];

    // Dispatch an allow-listed call with positional args (used by server.php).
    public function call(string $name, array $args): mixed
    {
        if (!in_array($name, self::PUBLIC, true)) throw new \RuntimeException("unknown binding: $name");
        return $this->{$name}(...array_values($args));
    }

    public function listModels(): array
    {
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' models --json 2>/dev/null');
        $data = json_decode((string) $out, true);
        return is_array($data) ? $data : ['connected' => false, 'models' => []];
    }

    public function termCreate(string $id, string $model, string $cwd = ''): bool
    {
        // Per-terminal working folder: each terminal can run in its own directory
        // (defaults to the pty's cwd when blank). Expand a leading ~ and only accept
        // a real directory.
        if ($cwd !== '' && ($cwd === '~' || strncmp($cwd, '~/', 2) === 0)) {
            $h = getenv('HOME') ?: '';
            if ($h !== '') $cwd = $h . substr($cwd, 1);
        }
        $dir = ($cwd !== '' && is_dir($cwd)) ? $cwd : null;
        $this->pty->create($id, $model, $dir);
        $this->pty->start($id);
        return true;
    }
    public function termRead(string $id, int $offset = 0): array { return $this->pty->read($id, $offset); }
    public function termWrite(string $id, string $b64): bool { return $this->pty->write($id, $b64); }
    public function termKill(string $id): bool { $this->pty->delete($id); return true; }
    public function termResize(string $id, int $cols, int $rows): bool { return $this->pty->resize($id, $cols, $rows); }
    public function agentRun(string $id, string $prompt): bool { return $this->pty->agentRun($id, $prompt); }

    public function cliPath(): string { return $this->cli; }

    public function sttEnabled(): bool
    {
        return trim((string) @shell_exec(escapeshellarg($this->cli) . ' transcribe --enabled 2>/dev/null')) === '1';
    }
    public function sttTranscribe(string $b64, string $ext = 'webm'): string
    {
        $data = base64_decode($b64, true);
        if ($data === false || $data === '') return '';
        $tmp = sys_get_temp_dir() . '/odv_stt_' . getmypid() . '_' . substr(md5($b64), 0, 6) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext ?: 'webm');
        @file_put_contents($tmp, $data);
        $out = (string) @shell_exec(escapeshellarg($this->cli) . ' transcribe ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        return trim($out);
    }

    public function crewBoard(): array
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $f = $home . '/.ollamadev/crew/current.json';
        if (!is_file($f)) return [];
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    // Separate Director: redirect a running coder. Writes the active run's steer.jsonl
    // directly (the CLI engine's Crew class isn't loaded here) — coders read it between
    // steps. Mirrors Crew::steer so CLI + desktop hit the same channel.
    public function crewSteer(int $coder, string $msg): array
    {
        $msg = trim($msg);
        if ($coder < 0) return ['error' => 'coder number must be 0 (all) or higher'];
        if ($msg === '') return ['error' => 'nothing to say'];
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $board = json_decode((string) @file_get_contents($home . '/.ollamadev/crew/current.json'), true);
        if (!is_array($board) || empty($board['active']) || empty($board['runId']))
            return ['error' => 'no active crew run to steer'];
        $steerFile = $home . '/.ollamadev/crew/' . $board['runId'] . '/steer.jsonl';
        @mkdir(dirname($steerFile), 0755, true);
        $entry = ['target' => $coder, 'msg' => $msg, 'ts' => microtime(true)];
        $ok = @file_put_contents($steerFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX) !== false;
        return $ok ? ['ok' => true] : ['error' => 'could not write to the steer inbox'];
    }

    // ---- Skills manager: list / view / create-or-edit / remove. Shells out to the
    // CLI engine (the one source of truth, shared with terminal + web). ----
    public function skillsList(): array
    {
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' skills list --json 2>/dev/null');
        $d = json_decode((string) $out, true);
        return is_array($d) && isset($d['skills']) ? $d : ['skills' => []];
    }
    public function skillsGet(string $name): array
    {
        if (trim($name) === '') return ['error' => 'no name'];
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' skills show ' . escapeshellarg($name) . ' --json 2>/dev/null');
        $d = json_decode((string) $out, true);
        return is_array($d) ? $d : ['error' => 'not found'];
    }
    public function skillsSave(string $name, string $description, string $body): array
    {
        if (trim($name) === '') return ['error' => 'a skill needs a name'];
        // Multiline body → pipe a JSON payload on stdin (no shell-escaping nightmares).
        $cmd = 'php ' . escapeshellarg($this->cli) . ' skills save ' . escapeshellarg($name) . ' 2>/dev/null';
        $proc = @proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return ['error' => 'could not run skills save'];
        fwrite($pipes[0], (string) json_encode(['description' => $description, 'body' => $body]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) return ['error' => trim((string) $out) ?: 'save failed'];
        return ['ok' => true] + $this->skillsList();
    }
    public function skillsRemove(string $name): array
    {
        if (trim($name) === '') return ['error' => 'no name'];
        @shell_exec('php ' . escapeshellarg($this->cli) . ' skills remove ' . escapeshellarg($name) . ' 2>/dev/null');
        return $this->skillsList();
    }

    // Hooks panel — view/add/remove shell hooks via the CLI (shared config.json).
    public function hooksList(): array
    {
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' hooks list --json 2>/dev/null');
        $d = json_decode((string) $out, true);
        return is_array($d) && isset($d['hooks']) ? $d : ['hooks' => [], 'events' => []];
    }
    public function hooksAdd(string $event, string $command, string $matcher = ''): array
    {
        if (trim($event) === '' || trim($command) === '') return ['error' => 'event and command required'] + $this->hooksList();
        $cmd = 'php ' . escapeshellarg($this->cli) . ' hooks add ' . escapeshellarg($event) . ' ' . escapeshellarg($command);
        if (trim($matcher) !== '') $cmd .= ' --match ' . escapeshellarg($matcher);
        @shell_exec($cmd . ' 2>/dev/null');
        return $this->hooksList();
    }
    public function hooksRemove(string $event, $index): array
    {
        if (trim($event) === '') return ['error' => 'no event'] + $this->hooksList();
        @shell_exec('php ' . escapeshellarg($this->cli) . ' hooks remove ' . escapeshellarg($event) . ' ' . escapeshellarg((string) (int) $index) . ' 2>/dev/null');
        return $this->hooksList();
    }

    // Configured per-role crew models, so the desktop Crew modal can default to them
    // (instead of a hardcoded list) — change crew.coderModel in config and it sticks.
    public function crewModels(): array
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $c = json_decode((string) @file_get_contents($home . '/.ollamadev/config.json'), true);
        $crew = (is_array($c) && is_array($c['crew'] ?? null)) ? $c['crew'] : [];
        $base = (is_array($c) && is_array($c['ollama'] ?? null)) ? (string)($c['ollama']['defaultModel'] ?? '') : '';
        return [
            'directorModel'   => (string)($crew['directorModel'] ?? $base),
            'coderModel'      => (string)($crew['coderModel'] ?? $base),
            'auditorModel'    => (string)($crew['auditorModel'] ?? $base),
            'researcherModel' => (string)($crew['researcherModel'] ?? $base),
        ];
    }

    // Persist the per-role crew models as the new defaults (crew.*Model in
    // config.json) so the Crew modal's "Save as default" sticks. Additive — the
    // crew engine already reads these keys (crew.coderModel etc.); this only writes
    // them. Read-modify-write preserves every other config key. Returns the set.
    public function setCrewModels(array $models): array
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $f = $home . '/.ollamadev/config.json';
        $c = json_decode((string) @file_get_contents($f), true);
        if (!is_array($c)) $c = [];
        if (!is_array($c['crew'] ?? null)) $c['crew'] = [];
        foreach (['directorModel', 'coderModel', 'auditorModel', 'researcherModel'] as $k) {
            $v = trim((string) ($models[$k] ?? ''));
            if ($v !== '') $c['crew'][$k] = $v;
        }
        @mkdir(dirname($f), 0755, true);
        @file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $this->crewModels();
    }

    // --- Chat threads/history — the 💬 Chat window's sidebar. Backed by the CLI's
    // `chat list` / `chat delete`, so saved conversations (~/.ollamadev/chats) are the
    // single source of truth, shared with the terminal. Starting/resuming a thread is
    // done by the terminal itself (`ollamadev chat --session <id>`), not a binding. ----
    public function chatList(): array
    {
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' chat list --json 2>/dev/null');
        $d = json_decode((string) $out, true);
        return is_array($d) && isset($d['chats']) ? $d : ['chats' => []];
    }
    public function chatDelete(string $id): array
    {
        if (trim($id) !== '') @shell_exec('php ' . escapeshellarg($this->cli) . ' chat delete ' . escapeshellarg($id) . ' 2>/dev/null');
        return $this->chatList();
    }
    // Render a saved conversation as Markdown (the Chat window's ⎘ export button copies +
    // downloads it). Backed by the CLI's `chat export <id> --json`.
    public function chatExport(string $id): array
    {
        if (trim($id) === '') return ['error' => 'no id'];
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' chat export ' . escapeshellarg($id) . ' --json 2>/dev/null');
        $d = json_decode((string) $out, true);
        return is_array($d) ? $d : ['error' => 'not found'];
    }

    public function homeDir(): string { return getenv('HOME') ?: ''; }

    // Open a URL in the user's REAL browser (the Browser pane's ↗ button). Only
    // http(s) — never shell-interpret arbitrary input. Used by desktop where an
    // in-app window.open isn't a real browser; web mode falls back to window.open.
    public function openExternal(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) return false;
        $u = escapeshellarg($url);
        if (stripos(PHP_OS, 'WIN') === 0) { @pclose(@popen('start "" ' . $u, 'r')); return true; }
        $opener = (PHP_OS === 'Darwin') ? 'open' : 'xdg-open';
        @shell_exec($opener . ' ' . $u . ' >/dev/null 2>&1 &');
        return true;
    }

    // Fetch a page server-side so the Browser pane can show sites that refuse to
    // be <iframe>'d (X-Frame-Options / CSP frame-ancestors). We curl the HTML,
    // strip its frame-blocking <meta>, inject a <base> so its assets/links resolve
    // against the real origin, and hand it back to be shown via iframe.srcdoc —
    // inline content isn't subject to X-Frame-Options at all. Best-effort: a
    // page's own XHR/API calls are cross-origin from the frame and may fail
    // (so logged-in SPAs break), but content/doc sites render. Works in BOTH
    // surfaces because it rides the same binding bridge as everything else.
    public function proxyFetch(string $url): array
    {
        if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'only http(s) URLs'];
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl unavailable'];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_ENCODING => '',                       // accept + auto-decode gzip/br
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $err = curl_error($ch); curl_close($ch); return ['ok' => false, 'error' => $err ?: 'fetch failed']; }
        $final = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $ct    = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $isHtml = stripos($ct, 'html') !== false || ($ct === '' && stripos((string) $body, '<html') !== false);
        // Non-HTML (images, PDFs, JSON…) aren't framed, so they have no XFO problem
        // — let the frame load them straight from the origin.
        if (!$isHtml) return ['ok' => true, 'direct' => true, 'url' => $final, 'contentType' => $ct, 'code' => $code];
        return ['ok' => true, 'direct' => false, 'url' => $final, 'code' => $code, 'html' => $this->rewriteForEmbed((string) $body, $final)];
    }

    // Make a fetched page safe + functional to show inline: drop frame-blocking
    // CSP meta + any existing <base>, then inject our own <base> (assets/links
    // resolve to the real site) and a tiny click-catcher that bubbles in-page
    // link navigations back up to the pane so they re-proxy instead of dead-ending
    // on the next site's X-Frame-Options.
    private function rewriteForEmbed(string $html, string $base): string
    {
        // Strip CSP <meta http-equiv> (it can otherwise block our injected script / assets).
        $html = preg_replace('#<meta[^>]+http-equiv\s*=\s*["\']?content-security-policy["\']?[^>]*>#i', '', $html) ?? $html;
        // Strip any existing <base> — ours must win.
        $html = preg_replace('#<base[^>]*>#i', '', $html) ?? $html;
        $inject = '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '">'
            . '<script>(function(){document.addEventListener("click",function(e){'
            . 'var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;if(!a)return;'
            . 'var h=a.href;if(!/^https?:/i.test(h))return;if(a.target==="_blank"){e.preventDefault();parent.postMessage({__odvNav:h},"*");return;}'
            . 'e.preventDefault();parent.postMessage({__odvNav:h},"*");},true);})();</script>';
        // Put it right after <head> if present, else at the very top.
        if (preg_match('#<head[^>]*>#i', $html)) {
            $html = preg_replace('#(<head[^>]*>)#i', '$1' . $inject, $html, 1) ?? $html;
        } else {
            $html = $inject . $html;
        }
        return $html;
    }

    public function crewCoderLog(string $runId, int $n, int $offset = 0): array
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        if (!preg_match('/^crew_[0-9_]+$/', $runId) || $n < 1 || $n > 64) return ['data' => '', 'size' => 0];
        $f = $home . '/.ollamadev/crew/' . $runId . '/coder-' . $n . '.log';
        if (!is_file($f)) return ['data' => '', 'size' => 0];
        $size = (int) filesize($f);
        if ($offset >= $size) return ['data' => '', 'size' => $size];
        $fh = @fopen($f, 'rb');
        if (!$fh) return ['data' => '', 'size' => $size];
        if ($offset > 0) fseek($fh, $offset);
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        return ['data' => $data, 'size' => $size];
    }

    public function memoryGraph(): array
    {
        $root = $this->files->getRoot();
        $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($this->cli) . ' memory graph --json 2>/dev/null';
        $d = json_decode(trim((string) @shell_exec($cmd)), true);
        return is_array($d) && isset($d['nodes']) ? $d : ['nodes' => [], 'edges' => []];
    }

    public function getRoot(): string { return $this->files->getRoot(); }
    public function setRoot(string $path): array
    {
        $path = trim($path);
        if ($path !== '' && $path[0] === '~') $path = (getenv('HOME') ?: '') . substr($path, 1);
        $real = realpath($path);
        if ($real === false || !is_dir($real)) return ['error' => "Not a directory: $path"];
        $this->files->setRoot($real);
        return ['root' => $real];
    }
    public function listFiles(?string $path = null): array { return $this->files->listDir($path); }
    public function readFile(string $path): array { return $this->files->readFile($path); }
    public function writeFile(string $path, string $content): array { return $this->files->writeFile($path, $content); }

    // --- Workspaces: the named project list, shared with the CLI ------------
    public function wsList(): array { return Workspaces::load(); }
    public function wsAdd(string $path, string $name = ''): array { return Workspaces::add($path, $name); }
    public function wsRemove(string $id): bool { return Workspaces::remove($id); }
    public function wsSetActive(string $id): bool { return Workspaces::setActive($id); }
    // $state arrives as a JSON string (Boson-safe); decode to the array the store wants.
    public function wsSaveState(string $id, string $state): bool
    {
        $decoded = json_decode($state, true);
        return Workspaces::saveState($id, is_array($decoded) ? $decoded : []);
    }

    // --- Crew roles: the per-subtask role catalog the Director assigns. Backed
    // by the CLI so the built-ins + the global ~/.ollamadev/crew-roles store are
    // the single source of truth (same catalog the terminal sees). Add/remove
    // return the refreshed catalog so the UI re-renders from one call. --------
    public function crewRoleList(): array
    {
        $out = shell_exec('php ' . escapeshellarg($this->cli) . ' crew role list --json 2>/dev/null');
        $d = json_decode((string) $out, true);
        return is_array($d) && isset($d['roles']) ? $d : ['roles' => []];
    }
    public function crewRoleAdd(string $name, string $persona, string $desc = '', string $model = '', bool $readonly = false): array
    {
        $name = trim($name); $persona = trim($persona);
        if ($name !== '' && $persona !== '') {
            $cmd = 'php ' . escapeshellarg($this->cli) . ' crew role add ' . escapeshellarg($name) . ' ' . escapeshellarg($persona);
            if (trim($desc) !== '') $cmd .= ' --desc ' . escapeshellarg($desc);
            if (trim($model) !== '') $cmd .= ' --model ' . escapeshellarg($model);
            if ($readonly) $cmd .= ' --readonly';
            @shell_exec($cmd . ' 2>/dev/null');
        }
        return $this->crewRoleList();
    }
    public function crewRoleRemove(string $name): array
    {
        $name = trim($name);
        if ($name !== '') @shell_exec('php ' . escapeshellarg($this->cli) . ' crew role remove ' . escapeshellarg($name) . ' 2>/dev/null');
        return $this->crewRoleList();
    }

    // --- Web access toggle: flips the air-gap (config `offline`) flag through the
    // CLI, so the same setting governs the terminal, desktop, and web. ON = web
    // tools (search/fetch/remote git) allowed; OFF = air-gapped. Applies to new
    // agent runs. -------------------------------------------------------------
    public function webAccess(): bool
    {
        $v = trim((string) @shell_exec('php ' . escapeshellarg($this->cli) . ' config get offline 2>/dev/null'));
        return $v !== 'true'; // web is on unless explicitly offline
    }
    public function setWebAccess(bool $on): bool
    {
        @shell_exec('php ' . escapeshellarg($this->cli) . ' config set offline ' . ($on ? 'false' : 'true') . ' 2>/dev/null');
        return $this->webAccess();
    }

    // Search-only switch (distinct from the full air-gap above): toggles just web
    // search, leaving fetch/remote git alone. Defaults ON when unset.
    public function searchEnabled(): bool
    {
        $v = trim((string) @shell_exec('php ' . escapeshellarg($this->cli) . ' config get search.enabled 2>/dev/null'));
        return $v !== 'false'; // on unless explicitly disabled
    }
    public function setSearchEnabled(bool $on): bool
    {
        @shell_exec('php ' . escapeshellarg($this->cli) . ' config set search.enabled ' . ($on ? 'true' : 'false') . ' 2>/dev/null');
        return $this->searchEnabled();
    }
    // Sampling temperature (lower = more deterministic tool-calling).
    public function temperature(): string
    {
        $v = trim((string) @shell_exec('php ' . escapeshellarg($this->cli) . ' config get ollama.temperature 2>/dev/null'));
        $v = trim($v, '"');
        return $v !== '' ? $v : '0.3';
    }
    public function setTemperature(string $value): string
    {
        $f = (float) $value;
        if ($f < 0) $f = 0; if ($f > 2) $f = 2;   // clamp to a sane range
        @shell_exec('php ' . escapeshellarg($this->cli) . ' config set ollama.temperature ' . escapeshellarg((string) $f) . ' 2>/dev/null');
        return $this->temperature();
    }

    // --- Voice (STT) model + history — shared with the CLI /voice command via
    // the one engine (SttClient), so CLI/desktop/web stay in lockstep. ----------
    public function sttModel(): string
    {
        $v = trim((string) @shell_exec('php ' . escapeshellarg($this->cli) . ' voice model 2>/dev/null'));
        return $v !== '' ? $v : 'base';
    }
    public function setSttModel(string $size): string
    {
        $size = preg_replace('/[^a-z0-9.\-]/i', '', $size); // tiny|base|small|medium|large-v3|turbo|.en
        @shell_exec('php ' . escapeshellarg($this->cli) . ' voice model ' . escapeshellarg($size) . ' 2>/dev/null');
        return $this->sttModel();
    }
    public function sttHistory(int $limit = 20): array
    {
        $out = (string) @shell_exec('php ' . escapeshellarg($this->cli) . ' voice history --json ' . (int) $limit . ' 2>/dev/null');
        $j = json_decode(trim($out), true);
        return is_array($j) ? $j : [];
    }
    public function sttClearHistory(): bool
    {
        @shell_exec('php ' . escapeshellarg($this->cli) . ' voice clear-history 2>/dev/null');
        return true;
    }

    // --- Semantic code search: run the CLI's index/code-search in the OPEN
    // project folder (the index is project-local, under <root>/.ollamadev). -----
    private function inRoot(string $cliArgs): string
    {
        $root = $this->files->getRoot();
        return (string) @shell_exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($this->cli) . ' ' . $cliArgs . ' 2>/dev/null');
    }
    public function codeSearch(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') return ['error' => 'empty'];
        $limit = max(1, min(20, $limit));
        $out = $this->inRoot('code-search ' . escapeshellarg($query) . ' --limit ' . (int)$limit . ' --json');
        $d = json_decode(trim($out), true);
        return is_array($d) ? $d : ['error' => 'failed'];
    }
    public function codeIndexStatus(): array
    {
        $d = json_decode(trim($this->inRoot('index status --json')), true);
        return is_array($d) ? $d : ['exists' => false];
    }
    public function codeIndexBuild(): array
    {
        $this->inRoot('index build');   // may take a while; output ignored
        return $this->codeIndexStatus();
    }
    // Read-only working-tree diff of the open project, for the review panel.
    // Git stays agent-driven — this only *shows* changes, it doesn't act.
    public function reviewDiff(): array
    {
        $d = json_decode(trim($this->inRoot('diff --json')), true);
        return is_array($d) ? $d : ['repo' => false, 'diff' => ''];
    }
}
