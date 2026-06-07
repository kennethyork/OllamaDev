<?php

declare(strict_types=1);

// OllamaDev ADE — browser/server mode. Serves the SAME frontend as the desktop
// over a local HTTP server, with each desktop binding exposed as POST /api/<name>
// (backed by the shared src/Bindings.php). The browser is just another front-end;
// all model/agent/crew work still runs locally through the ollamadev CLI.
//
// Run from the ADE root:  php -S localhost:41434 web/server.php   (or: composer serve)
// Localhost-only by default. For LAN/another device, set OLLAMADEV_SERVE_TOKEN and
// pass it as ?token= or the X-ODV-Token header.

require_once __DIR__ . '/../vendor/autoload.php';

use OllamaDev\Config;
use OllamaDev\PtyManager;
use OllamaDev\FileBrowser;
use OllamaDev\Bindings;

Config::load();
$ROOT = dirname(__DIR__);                       // the ADE app dir (public/, src/)
$home = getenv('HOME') ?: sys_get_temp_dir();
$token = getenv('OLLAMADEV_SERVE_TOKEN') ?: '';
$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// --- The app page: same HTML as the desktop, with the browser bridge injected
// BEFORE app.js so window.<binding> exist when it runs. ------------------------
if ($uri === '/' || $uri === '/index.html') {
    $html = (string) file_get_contents($ROOT . '/public/index.html');
    $html = str_replace('/* {{CSS}} */', (string) file_get_contents($ROOT . '/public/app.css'), $html);
    $bridge = (string) file_get_contents(__DIR__ . '/bridge.js');
    $html = str_replace('/* {{JS}} */', $bridge . "\n" . (string) file_get_contents($ROOT . '/public/app.js'), $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    return true;
}

// --- SSE: stream a terminal's output (lower latency than polling termRead) -----
// One long-lived GET pushes new pty bytes as they arrive, so the browser stops
// round-tripping every 80ms. It holds a worker for its lifetime, so it engages
// ONLY when the built-in server has spare workers (PHP_CLI_SERVER_WORKERS>=2);
// otherwise it 503s and the client falls back to polling — never blocks the app.
if (str_starts_with($uri, '/api/stream')) {
    if ($token !== '') {
        $given = $_SERVER['HTTP_X_ODV_TOKEN'] ?? ($_GET['token'] ?? '');
        if (!hash_equals($token, (string) $given)) { http_response_code(403); echo 'forbidden'; return true; }
    }
    if ((int) getenv('PHP_CLI_SERVER_WORKERS') < 2) { http_response_code(503); echo 'streaming needs PHP_CLI_SERVER_WORKERS>=2'; return true; }
    $id = (string) ($_GET['term'] ?? '');
    if ($id === '') { http_response_code(400); echo 'no term'; return true; }
    $offset = (int) ($_GET['offset'] ?? 0);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');   // tell any proxy not to buffer
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_flush();
    $pty = new PtyManager();
    $bx = new Bindings($pty, new FileBrowser(), PtyManager::cliBinary());
    $deadline = time() + 600;   // cap one stream at 10 min; EventSource auto-reconnects
    while (!connection_aborted() && time() < $deadline) {
        $r = $bx->call('termRead', [$id, $offset]);
        if (is_array($r) && !empty($r['data'])) {
            $offset = (int) ($r['offset'] ?? $offset);
            echo 'data: ' . json_encode(['data' => $r['data'], 'offset' => $offset]) . "\n\n";
            @flush();
        } else {
            usleep(60000);   // 60ms server-side poll of the file-backed pty — no client round-trip
        }
    }
    return true;
}

// --- API: POST /api/<binding>, body = JSON array of positional args ----------
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json');
    if ($token !== '') {
        $given = $_SERVER['HTTP_X_ODV_TOKEN'] ?? ($_GET['token'] ?? '');
        if (!hash_equals($token, (string) $given)) { http_response_code(403); echo json_encode(['error' => 'forbidden']); return true; }
    }
    $name = substr($uri, 5);
    $args = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($args)) $args = [];

    // Per-request objects. Terminals are file-backed so they persist across
    // requests; the workspace root isn't, so we persist it ourselves.
    $pty = new PtyManager();
    $files = new FileBrowser();
    $rootFile = $home . '/.ollamadev/ade-web-root';
    if (is_file($rootFile)) { $saved = trim((string) @file_get_contents($rootFile)); if ($saved !== '' && is_dir($saved)) $files->setRoot($saved); }

    $bx = new Bindings($pty, $files, PtyManager::cliBinary());
    try {
        $result = $bx->call($name, $args);
    } catch (\Throwable $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()]);
        return true;
    }
    if ($name === 'setRoot' && is_array($result) && isset($result['root'])) @file_put_contents($rootFile, $result['root']);
    echo json_encode(['result' => $result]);
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
