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
use OllamaDev\AssetInliner;
use OllamaDev\Config;
use OllamaDev\SessionManager;
use OllamaDev\MemoryStore;
use OllamaDev\TaskStore;
use OllamaDev\FileBrowser;
use OllamaDev\PromptStore;
use OllamaDev\SettingsStore;
use OllamaDev\PtyManager;

Config::load();

$html = file_get_contents(__DIR__ . '/public/index.html');

$inliner = new AssetInliner(__DIR__);
$html = $inliner->inlineAssets($html);

$webviewCreateInfo = new WebViewCreateInfo(
    extensions: [
        new ScriptsExtension(),
        new BindingsExtension(),
        new DataExtension(),
        new SecurityExtension(),
        new SchemesExtension(),
        new LifecycleEventsExtension(),
    ],
);

$windowCreateInfo = new WindowCreateInfo(
    title: 'OllamaDev ADE',
    width: 1400,
    height: 900,
    visible: true,
    resizable: true,
    webview: $webviewCreateInfo,
);

$appInfo = new ApplicationCreateInfo(
    name: 'OllamaDev ADE',
    window: $windowCreateInfo,
);

$app = new Application($appInfo);

$sessions = new SessionManager();
$memory = new MemoryStore();
$tasks = new TaskStore();
$files = new FileBrowser();
$prompts = new PromptStore();
$settings = new SettingsStore();

$app->on(\Boson\Event\ApplicationStarted::class, function () use ($app, $html, $sessions, $memory, $tasks, $files, $prompts, $settings) {
    /** @var \Boson\WebView\WebView $webview */
    $webview = $app->window->webview;

    $webview->html = $html;

    $bindings = $webview->get('bindings');

    $bindings->bind('ollamaStatus', function (): array {
        // Single source of truth: ask the ollamadev CLI for models/status.
        $bin = PtyManager::cliBinary();
        $out = shell_exec('php ' . escapeshellarg($bin) . ' models --json 2>/dev/null');
        $data = json_decode((string)$out, true);
        if (is_array($data) && isset($data['connected'])) return $data;
        return ['connected' => false, 'models' => []];
    });

    $bindings->bind('getTasks', function () use ($tasks): array {
        return $tasks->all();
    });

    $bindings->bind('createTask', function (array $data) use ($tasks): string {
        return $tasks->create($data);
    });

    $bindings->bind('updateTask', function (string $id, array $data) use ($tasks): bool {
        return $tasks->update($id, $data);
    });

    $bindings->bind('deleteTask', function (string $id) use ($tasks): bool {
        return $tasks->delete($id);
    });

    $bindings->bind('getNotes', function () use ($memory): array {
        return $memory->listNotes();
    });

    $bindings->bind('getNote', function (string $id) use ($memory): ?array {
        return $memory->get($id);
    });

    $bindings->bind('createNote', function (string $title, string $content = '') use ($memory): string {
        return $memory->create($title, $content);
    });

    $bindings->bind('updateNote', function (string $id, array $data) use ($memory): bool {
        return $memory->update($id, $data);
    });

    $bindings->bind('deleteNote', function (string $id) use ($memory): bool {
        return $memory->delete($id);
    });

    $bindings->bind('getSessions', function () use ($sessions): array {
        return $sessions->listTerminals();
    });

    $bindings->bind('createSession', function (string $model) use ($sessions): string {
        return $sessions->createTerminal($model);
    });

    $bindings->bind('readSession', function (string $id, int $offset = 0) use ($sessions): array {
        return $sessions->readTerminal($id, $offset);
    });

    $bindings->bind('writeToSession', function (string $id, string $data) use ($sessions): bool {
        return $sessions->writeToTerminal($id, $data);
    });

    $bindings->bind('resizeSession', function (string $id, int $cols, int $rows) use ($sessions): bool {
        return $sessions->resizeTerminal($id, $cols, $rows);
    });

    $bindings->bind('killSession', function (string $id) use ($sessions): bool {
        return $sessions->killTerminal($id);
    });

    $bindings->bind('agentRun', function (string $id, string $prompt) use ($sessions): bool {
        return $sessions->agentRun($id, $prompt);
    });

    $bindings->bind('getBlocks', function (string $id) use ($sessions): array {
        return $sessions->getBlocks($id);
    });

    $bindings->bind('readFile', function (string $path) use ($files): array {
        return $files->readFile($path);
    });

    $bindings->bind('writeFile', function (string $path, string $content) use ($files): array {
        return $files->writeFile($path, $content);
    });

    $bindings->bind('getPrompts', function () use ($prompts): array {
        return $prompts->list();
    });

    $bindings->bind('createPrompt', function (string $title, string $body) use ($prompts): string {
        return $prompts->create($title, $body);
    });

    $bindings->bind('deletePrompt', function (string $id) use ($prompts): bool {
        return $prompts->delete($id);
    });

    $bindings->bind('getConfig', function () use ($settings): array {
        return $settings->get();
    });

    $bindings->bind('setConfig', function (array $s) use ($settings): bool {
        return $settings->set($s);
    });

    $bindings->bind('listFiles', function (?string $path = null) use ($files): array {
        return $files->listDir($path);
    });

    $bindings->bind('searchFiles', function (string $query, ?string $path = null) use ($files): array {
        return $files->search($query, $path);
    });
});

$app->run();