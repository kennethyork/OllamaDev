<?php

declare(strict_types=1);

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
