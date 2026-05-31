<?php

declare(strict_types=1);

// The Boson runtime requires PHP 8.4+. composer.json pins "php": "^8.4", but the
// PHP that launches this file may differ from the one composer resolved against,
// so fail fast with a clear message instead of a cryptic runtime error later.
if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "OllamaDev ADE requires PHP 8.4 or newer (you have " . PHP_VERSION . ").\n");
    fwrite(STDERR, "The CLI works on PHP 8.0+, but the desktop app's Boson runtime needs 8.4+.\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

use Boson\Application;
use Boson\ApplicationCreateInfo;
use Boson\Window\WindowCreateInfo;
use Boson\WebView\WebViewCreateInfo;
use Boson\WebView\Api\Bindings\BindingsExtension;
use Boson\WebView\Api\Scripts\ScriptsExtension;
use Boson\WebView\Api\Data\DataExtension;
use Boson\WebView\Api\Security\SecurityExtension;
use Boson\WebView\Api\Schemes\SchemesExtension;
use Boson\WebView\Api\LifecycleEvents\LifecycleEventsExtension;
use OllamaDev\Config;
use OllamaDev\PtyManager;
use OllamaDev\FileBrowser;

Config::load();

// Build one self-contained HTML page: inline vanilla CSS + JS (no file serving,
// no third-party libraries).
$html = file_get_contents(__DIR__ . '/public/index.html');
$html = str_replace('/* {{CSS}} */', file_get_contents(__DIR__ . '/public/app.css'), $html);
$html = str_replace('/* {{JS}} */', file_get_contents(__DIR__ . '/public/app.js'), $html);

$app = new Application(new ApplicationCreateInfo(
    name: 'OllamaDev ADE',
    window: new WindowCreateInfo(
        title: 'OllamaDev ADE',
        width: 1280,
        height: 820,
        visible: true,
        resizable: true,
        webview: new WebViewCreateInfo(extensions: [
            new ScriptsExtension(),
            new BindingsExtension(),
            new DataExtension(),
            new SecurityExtension(),
            new SchemesExtension(),
            new LifecycleEventsExtension(),
        ]),
    ),
));

// Reap any orphaned terminals from a previous run before starting fresh.
PtyManager::cleanupStale();

$pty = new PtyManager();
$files = new FileBrowser();
$cli = PtyManager::cliBinary();

$app->on(\Boson\Event\ApplicationStarted::class, function () use ($app, $html, $pty, $files, $cli) {
    $webview = $app->window->webview;
    $webview->html = $html;

    $b = $webview->get('bindings');

    // --- Models / status (delegated to the ollamadev CLI) ---
    $b->bind('listModels', function () use ($cli): array {
        $out = shell_exec('php ' . escapeshellarg($cli) . ' models --json 2>/dev/null');
        $data = json_decode((string) $out, true);
        return is_array($data) ? $data : ['connected' => false, 'models' => []];
    });

    // --- Terminals (the frontend supplies the id) ---
    $b->bind('termCreate', function (string $id, string $model) use ($pty): bool {
        $pty->create($id, $model);
        $pty->start($id);
        return true;
    });
    $b->bind('termRead', function (string $id, int $offset = 0) use ($pty): array {
        return $pty->read($id, $offset);
    });
    $b->bind('termWrite', function (string $id, string $b64) use ($pty): bool {
        return $pty->write($id, $b64);
    });
    $b->bind('termKill', function (string $id) use ($pty): bool {
        $pty->delete($id);
        return true;
    });
    $b->bind('agentRun', function (string $id, string $prompt) use ($pty): bool {
        return $pty->agentRun($id, $prompt);
    });

    // Resolved ollamadev CLI path, so the frontend can auto-launch it in a pty.
    $b->bind('cliPath', function () use ($cli): string {
        return $cli;
    });

    // Live Crew board (Director's plan + per-subtask state) for the kanban view.
    $b->bind('crewBoard', function (): array {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $f = $home . '/.ollamadev/crew/current.json';
        if (!is_file($f)) return [];
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : [];
    });

    // Home directory, so the UI can show ~ instead of the full /home/<user> prefix.
    $b->bind('homeDir', function (): string {
        return getenv('HOME') ?: '';
    });

    // Live per-coder log tail (one pane per coder while a crew runs).
    $b->bind('crewCoderLog', function (string $runId, int $n, int $offset = 0) {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        // runId is validated to the crew_YYYYmmdd_HHMMSS shape — no path traversal.
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
    });

    // Project knowledge graph (nodes + [[link]] edges) for the Graph view.
    $b->bind('memoryGraph', function () use ($cli, $files): array {
        $root = $files->getRoot();
        $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($cli) . ' memory graph --json 2>/dev/null';
        $out = (string) @shell_exec($cmd);
        $d = json_decode(trim($out), true);
        return is_array($d) && isset($d['nodes']) ? $d : ['nodes' => [], 'edges' => []];
    });

    // --- Files ---
    $b->bind('getRoot', function () use ($files): string {
        return $files->getRoot();
    });
    // Point the workspace at a project folder (expands ~). Returns the real path.
    $b->bind('setRoot', function (string $path) use ($files): array {
        $path = trim($path);
        if ($path !== '' && $path[0] === '~') $path = (getenv('HOME') ?: '') . substr($path, 1);
        $real = realpath($path);
        if ($real === false || !is_dir($real)) return ['error' => "Not a directory: $path"];
        $files->setRoot($real);
        return ['root' => $real];
    });
    $b->bind('listFiles', function (?string $path = null) use ($files): array {
        return $files->listDir($path);
    });
    $b->bind('readFile', function (string $path) use ($files): array {
        return $files->readFile($path);
    });
    $b->bind('writeFile', function (string $path, string $content) use ($files): array {
        return $files->writeFile($path, $content);
    });
});

$app->run();
