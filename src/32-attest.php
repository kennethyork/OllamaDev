// ATTESTATION — prove an OllamaDev run can't send your code off the machine.
// For air-gapped / regulated use: a single command that audits the local
// configuration (model host, offline mode, network-capable tools) and emits a
// signed-by-hash report. The point isn't cryptographic trust; it's a clear,
// reproducible statement of what is and isn't reachable.
class Attest {
    // Is a model host loopback-only (so inference never leaves the box)?
    public static function isLoopbackHost(string $host): bool {
        $host = trim($host);
        if ($host === '') return false;
        // Pull the hostname out of a URL (or accept a bare host[:port]).
        $h = parse_url($host, PHP_URL_HOST);
        if ($h === null || $h === false) {
            $h = preg_replace('#^[a-z]+://#i', '', $host);
            $h = explode('/', $h)[0];
            $h = preg_replace('/:\d+$/', '', $h);
        }
        $h = trim((string)$h, '[]'); // strip IPv6 brackets
        if (strcasecmp($h, 'localhost') === 0) return true;
        if ($h === '::1') return true;
        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return strpos($h, '127.') === 0;
        return false;
    }

    // Build the attestation as structured data.
    public static function gather(): array {
        $provider = Config::get('provider', 'ollama');
        $host = ModelClient::default()->host();
        $loopback = self::isLoopbackHost($host);
        $offline = Permission::isOffline();
        $net = Permission::listNetwork();
        // In offline mode every network tool is blocked; otherwise they're live.
        $tools = [];
        foreach ($net as $t) $tools[$t] = $offline ? 'blocked' : 'allowed';

        $checks = [
            'offline_mode'    => $offline,
            'model_host_loopback' => $loopback,
            'network_tools_blocked' => $offline,
        ];
        // "Fully air-gapped" = offline mode on AND the model host is loopback.
        $airgapped = $offline && $loopback;

        $report = [
            'version'   => defined('OLLAMADEV_VERSION') ? OLLAMADEV_VERSION : '',
            'provider'  => $provider,
            'model_host' => $host,
            'model_host_loopback' => $loopback,
            'offline_mode' => $offline,
            'network_tools' => $tools,
            'air_gapped' => $airgapped,
            'checks' => $checks,
        ];
        // Hash the canonical body so the same config yields the same fingerprint.
        $body = json_encode($report, JSON_UNESCAPED_SLASHES);
        $report['fingerprint'] = hash('sha256', (string)$body);
        return $report;
    }

    // Human-readable report (or JSON when $json).
    public static function render(bool $json = false): string {
        $r = self::gather();
        if ($json) return json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $c = "\033[36m"; $d = "\033[2m"; $g = "\033[32m"; $y = "\033[33m"; $b = "\033[1m"; $rs = "\033[0m";
        $tick = fn(bool $ok) => $ok ? "{$g}✓{$rs}" : "{$y}✗{$rs}";
        $out  = "\n{$b}OllamaDev — air-gap attestation{$rs}  {$d}v{$r['version']}{$rs}\n";
        $out .= str_repeat('─', 48) . "\n";
        $out .= "  Provider          {$c}{$r['provider']}{$rs}\n";
        $out .= "  Model host        {$c}{$r['model_host']}{$rs}\n";
        $out .= "  " . $tick($r['model_host_loopback']) . " model host is loopback (inference stays local)\n";
        $out .= "  " . $tick($r['offline_mode']) . " offline mode " . ($r['offline_mode'] ? 'ON' : "OFF {$d}(network tools are LIVE){$rs}") . "\n";
        $out .= "\n  Network-capable tools:\n";
        foreach ($r['network_tools'] as $t => $state) {
            $mark = $state === 'blocked' ? "{$g}blocked{$rs}" : "{$y}allowed{$rs}";
            $out .= "    {$d}-{$rs} " . str_pad($t, 12) . " $mark\n";
        }
        $out .= "\n  " . ($r['air_gapped']
            ? "{$g}{$b}● FULLY AIR-GAPPED{$rs} {$d}— offline mode on and the model host is loopback.{$rs}"
            : "{$y}{$b}● NOT fully air-gapped.{$rs} {$d}Run with --offline and point the model at localhost to lock it down.{$rs}");
        $out .= "\n  {$d}fingerprint {$r['fingerprint']}{$rs}\n";
        return $out . "\n";
    }
}
