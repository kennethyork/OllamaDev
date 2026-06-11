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
    $b->bind('crewModels',      fn(): array => $bx->crewModels());
    $b->bind('homeDir',       fn(): string => $bx->homeDir());
    $b->bind('crewCoderLog',  fn(string $runId, int $n, int $offset = 0): array => $bx->crewCoderLog($runId, $n, $offset));
    $b->bind('memoryGraph',   fn(): array => $bx->memoryGraph());
    $b->bind('getRoot',       fn(): string => $bx->getRoot());
    $b->bind('setRoot',       fn(string $path): array => $bx->setRoot($path));
    $b->bind('listFiles',     fn(?string $path = null): array => $bx->listFiles($path));
    $b->bind('readFile',      fn(string $path): array => $bx->readFile($path));
    $b->bind('writeFile',     fn(string $path, string $content): array => $bx->writeFile($path, $content));
    $b->bind('wsList',        fn(): array => $bx->wsList());
    $b->bind('wsAdd',         fn(string $path, string $name = ''): array => $bx->wsAdd($path, $name));
    $b->bind('wsRemove',      fn(string $id): bool => $bx->wsRemove($id));
    $b->bind('wsSetActive',   fn(string $id): bool => $bx->wsSetActive($id));
    $b->bind('wsSaveState',   fn(string $id, string $state): bool => $bx->wsSaveState($id, $state));
    $b->bind('crewRoleList',   fn(): array => $bx->crewRoleList());
    $b->bind('crewRoleAdd',    fn(string $name, string $persona, string $desc = '', string $model = '', bool $readonly = false): array => $bx->crewRoleAdd($name, $persona, $desc, $model, $readonly));
    $b->bind('crewRoleRemove', fn(string $name): array => $bx->crewRoleRemove($name));
    $b->bind('webAccess',      fn(): bool => $bx->webAccess());
    $b->bind('setWebAccess',   fn(bool $on): bool => $bx->setWebAccess($on));
    $b->bind('searchEnabled',    fn(): bool => $bx->searchEnabled());
    $b->bind('setSearchEnabled', fn(bool $on): bool => $bx->setSearchEnabled($on));
    $b->bind('codeSearch',       fn(string $query, int $limit = 8): array => $bx->codeSearch($query, $limit));
    $b->bind('codeIndexStatus',  fn(): array => $bx->codeIndexStatus());
    $b->bind('codeIndexBuild',   fn(): array => $bx->codeIndexBuild());
    $b->bind('reviewDiff',       fn(): array => $bx->reviewDiff());
    $b->bind('temperature',      fn(): string => $bx->temperature());
    $b->bind('setTemperature',   fn(string $v): string => $bx->setTemperature($v));
    $b->bind('sttModel',         fn(): string => $bx->sttModel());
    $b->bind('setSttModel',      fn(string $s): string => $bx->setSttModel($s));
    $b->bind('sttHistory',       fn(int $n = 20): array => $bx->sttHistory($n));
    $b->bind('sttClearHistory',  fn(): bool => $bx->sttClearHistory());
    $b->bind('openExternal',     fn(string $url): bool => $bx->openExternal($url));
    $b->bind('proxyFetch',       fn(string $url): array => $bx->proxyFetch($url));
    $b->bind('termResize',       fn(string $id, int $cols, int $rows): bool => $bx->termResize($id, $cols, $rows));
    $b->bind('setCrewModels',    fn(array $models): array => $bx->setCrewModels($models));
    $b->bind('crewSteer',        fn(int $coder, string $msg): array => $bx->crewSteer($coder, $msg));
    $b->bind('skillsList',       fn(): array => $bx->skillsList());
    $b->bind('skillsGet',        fn(string $name): array => $bx->skillsGet($name));
    $b->bind('skillsSave',       fn(string $name, string $description, string $body): array => $bx->skillsSave($name, $description, $body));
    $b->bind('skillsRemove',     fn(string $name): array => $bx->skillsRemove($name));
    $b->bind('hooksList',        fn(): array => $bx->hooksList());
    $b->bind('hooksAdd',         fn(string $event, string $command, string $matcher = ''): array => $bx->hooksAdd($event, $command, $matcher));
    $b->bind('hooksRemove',      fn(string $event, int $index): array => $bx->hooksRemove($event, $index));
    $b->bind('chatList',         fn(): array => $bx->chatList());
    $b->bind('chatDelete',       fn(string $id): array => $bx->chatDelete($id));
    $b->bind('chatExport',       fn(string $id): array => $bx->chatExport($id));
});

$app->run();
