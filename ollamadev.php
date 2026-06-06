#!/usr/bin/env php
<?php
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/OllamaClient.php';
require_once __DIR__ . '/Agent.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Tools.php';
require_once __DIR__ . '/Terminal.php';

$config = Config::load();

if (isset($argv[1]) && $argv[1] === 'chat') {
    $session = new Session($config);
    $session->start();
} elseif (isset($argv[1]) && $argv[1] === 'new') {
    $session = new Session($config);
    $session->createNew();
    echo "New session created.\n";
} elseif (isset($argv[1]) && $argv[1] === 'list') {
    $sessions = Session::listAll($config);
    foreach ($sessions as $s) {
        echo "{$s['id']} | {$s['title']} | {$s['model']} | {$s['updated_at']}\n";
    }
} elseif (isset($argv[1]) && $argv[1] === 'load' && isset($argv[2])) {
    $session = new Session($config, $argv[2]);
    $session->start();
} elseif (isset($argv[1]) && $argv[1] === 'help') {
    echo "OllamaDev CLI - Local AI coding agent using Ollama\n\n";
    echo "Usage: ollamadev [command]\n\n";
    echo "Commands:\n";
    echo "  ollamadev           Start interactive chat\n";
    echo "  ollamadev chat       Start chat session\n";
    echo "  ollamadev new        Create new session\n";
    echo "  ollamadev list       List sessions\n";
    echo "  ollamadev load <id>  Load session\n";
    echo "  ollamadev help       Show this help\n";
} else {
    $session = new Session($config);
    $session->start();
}