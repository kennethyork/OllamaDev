<?php
// Minimal stdio MCP test server (JSON-RPC 2.0, Content-Length framed)
$in = fopen('php://stdin', 'rb');
function readMsg($in) {
    $headers = '';
    while (strpos($headers, "\r\n\r\n") === false) {
        $c = fread($in, 1);
        if ($c === '' || $c === false) { if (feof($in)) return null; usleep(2000); continue; }
        $headers .= $c;
    }
    if (!preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) return null;
    $len = (int)$m[1]; $body = '';
    while (strlen($body) < $len) { $body .= fread($in, $len - strlen($body)); }
    return json_decode($body, true);
}
function send($msg) {
    $b = json_encode($msg);
    fwrite(STDOUT, "Content-Length: " . strlen($b) . "\r\n\r\n" . $b);
    fflush(STDOUT);
}
while (($req = readMsg($in)) !== null) {
    $id = $req['id'] ?? null;
    $method = $req['method'] ?? '';
    if ($method === 'initialize') {
        send(['jsonrpc'=>'2.0','id'=>$id,'result'=>['protocolVersion'=>'2024-11-05','capabilities'=>['tools'=>[]],'serverInfo'=>['name'=>'test','version'=>'1.0']]]);
    } elseif ($method === 'notifications/initialized') {
        // no response
    } elseif ($method === 'tools/list') {
        send(['jsonrpc'=>'2.0','id'=>$id,'result'=>['tools'=>[['name'=>'greet','description'=>'Greet someone','inputSchema'=>['type'=>'object','properties'=>['who'=>['type'=>'string']]]]]]]);
    } elseif ($method === 'tools/call') {
        $who = $req['params']['arguments']['who'] ?? 'world';
        send(['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>"Hello, $who! (from MCP server)"]]]]);
    } elseif ($id !== null) {
        send(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']]);
    }
}
