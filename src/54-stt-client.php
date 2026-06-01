// SPEECH-TO-TEXT — engine-agnostic, 100% local. PHP can't run an STT model, but
// it can drive a local one, the same way it drives Ollama: either curl a local
// HTTP server (OpenAI-compatible /v1/audio/transcriptions — whisper.cpp server,
// faster-whisper, vosk-server, …) or exec a local CLI. You bring the engine;
// this just orchestrates it. Nothing leaves the machine. Configure one of:
//   "stt": { "host": "http://localhost:8081" }            // local HTTP server
//   "stt": { "command": "whisper-cli -f {file} -otxt" }    // local CLI ({file} = audio path)
class SttClient {
    public static function enabled(): bool {
        return trim((string) Config::get('stt.host', '')) !== '' || trim((string) Config::get('stt.command', '')) !== '';
    }

    // Transcribe an audio file to text. Returns '' if not configured or on failure.
    public static function transcribe(string $audioPath): string {
        if (!is_file($audioPath)) return '';
        $host = trim((string) Config::get('stt.host', ''));
        $cmd  = trim((string) Config::get('stt.command', ''));
        if ($host !== '') return self::viaHttp($host, $audioPath);
        if ($cmd !== '')  return self::viaCommand($cmd, $audioPath);
        return '';
    }

    // POST the audio to a local OpenAI-compatible transcription endpoint.
    private static function viaHttp(string $host, string $file): string {
        $url = rtrim($host, '/');
        if (!preg_match('#/transcriptions$#', $url)) $url .= '/v1/audio/transcriptions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new CURLFile($file), 'model' => trim((string) Config::get('stt.model', 'whisper-1')), 'response_format' => 'json'],
            CURLOPT_HTTPHEADER => ['Authorization: Bearer local'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return '';
        $j = json_decode((string) $resp, true);
        if (is_array($j) && isset($j['text'])) return trim((string) $j['text']);   // {"text": "..."}
        return ($j === null && is_string($resp)) ? trim($resp) : '';               // some servers reply plain text
    }

    // Run a local CLI: "{file}" is replaced with the audio path; else it's appended.
    private static function viaCommand(string $tpl, string $file): string {
        $cmd = strpos($tpl, '{file}') !== false
            ? str_replace('{file}', escapeshellarg($file), $tpl)
            : $tpl . ' ' . escapeshellarg($file);
        return trim((string) @shell_exec($cmd . ' 2>/dev/null'));
    }
}
