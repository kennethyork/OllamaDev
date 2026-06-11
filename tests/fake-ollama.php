<?php

// Minimal fake Ollama server for HERMETIC chat tests — no real model, no GPU, fully
// deterministic and offline. It answers only the few endpoints OllamaClient touches,
// and crucially emits a `thinking` field (a reasoning model's chain-of-thought) on
// /api/chat so the smoke suite can assert `ollamadev chat` strips it and shows only
// the final answer. Run as a router script:
//   php -S 127.0.0.1:<port> tests/fake-ollama.php
// Point the CLI at it with:  ollamadev chat --host http://127.0.0.1:<port>

declare(strict_types=1);

// The chain-of-thought that must NEVER reach the user, and the answer that must.
const THINK_SENTINEL = 'REASONING_LEAK_SENTINEL';
const ANSWER = 'Hello there, world.';

$uri  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
header('Content-Type: application/json');

// Connection probe (checkConnection looks for a `models` key + HTTP 200).
if ($uri === '/api/tags') { echo json_encode(['models' => [['name' => 'fake-reasoner:latest']]]); return true; }

// /api/show — no trained context length / capabilities; the client copes (num_ctx falls
// back to the configured baseline).
if ($uri === '/api/show') { echo json_encode(['model_info' => new stdClass(), 'capabilities' => []]); return true; }

if ($uri === '/api/chat') {
    // If any message carried a base64 image (vision), echo that back so the suite can
    // assert /image actually attaches it.
    $hasImage = false;
    foreach (($body['messages'] ?? []) as $m) { if (!empty($m['images'])) { $hasImage = true; break; } }
    if ($hasImage) {
        if (!empty($body['stream'])) {
            header('Content-Type: application/x-ndjson');
            echo json_encode(['message' => ['role' => 'assistant', 'content' => 'I see your image.'], 'done' => true, 'eval_count' => 3]) . "\n";
        } else {
            echo json_encode(['message' => ['role' => 'assistant', 'content' => 'I see your image.'], 'done' => true]);
        }
        return true;
    }
    if (!empty($body['stream'])) {
        // NDJSON stream: a thinking-only chunk FIRST (content empty) — exactly the shape
        // that leaked — then the answer split across content chunks.
        header('Content-Type: application/x-ndjson');
        echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'thinking' => THINK_SENTINEL], 'done' => false]) . "\n";
        echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Hello there,'], 'done' => false]) . "\n";
        echo json_encode(['message' => ['role' => 'assistant', 'content' => ' world.'], 'done' => true, 'total_duration' => 1, 'eval_count' => 4]) . "\n";
        return true;
    }
    // Non-stream (used by `chat --json`): a single message carrying BOTH fields.
    echo json_encode(['message' => ['role' => 'assistant', 'content' => ANSWER, 'thinking' => THINK_SENTINEL], 'done' => true, 'eval_count' => 4]);
    return true;
}

http_response_code(404);
echo '{}';
return true;
