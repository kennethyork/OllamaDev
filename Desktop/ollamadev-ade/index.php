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
use OllamaDev\Bindings;

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

    // One shared implementation backs both the desktop (these bindings) and the
    // browser server mode (server.php /api/<name>) — see src/Bindings.php.
    $bx = new Bindings($pty, $files, $cli);
    $b->bind('listModels',    fn(): array => $bx->listModels());
    $b->bind('termCreate',    fn(string $id, string $model): bool => $bx->termCreate($id, $model));
    $b->bind('termRead',      fn(string $id, int $offset = 0): array => $bx->termRead($id, $offset));
    $b->bind('termWrite',     fn(string $id, string $b64): bool => $bx->termWrite($id, $b64));
    $b->bind('termKill',      fn(string $id): bool => $bx->termKill($id));
    $b->bind('agentRun',      fn(string $id, string $prompt): bool => $bx->agentRun($id, $prompt));
    $b->bind('cliPath',       fn(): string => $bx->cliPath());
    $b->bind('sttEnabled',    fn(): bool => $bx->sttEnabled());
    $b->bind('sttTranscribe', fn(string $b64, string $ext = 'webm'): string => $bx->sttTranscribe($b64, $ext));
    $b->bind('crewBoard',     fn(): array => $bx->crewBoard());
    $b->bind('homeDir',       fn(): string => $bx->homeDir());
    $b->bind('crewCoderLog',  fn(string $runId, int $n, int $offset = 0): array => $bx->crewCoderLog($runId, $n, $offset));
    $b->bind('memoryGraph',   fn(): array => $bx->memoryGraph());
    $b->bind('getRoot',       fn(): string => $bx->getRoot());
    $b->bind('setRoot',       fn(string $path): array => $bx->setRoot($path));
    $b->bind('listFiles',     fn(?string $path = null): array => $bx->listFiles($path));
    $b->bind('readFile',      fn(string $path): array => $bx->readFile($path));
    $b->bind('writeFile',     fn(string $path, string $content): array => $bx->writeFile($path, $content));
});

$app->run();
