<?php
/**
 * Owner-process cache server: ONE process owns a JudySimpleCache and serves
 * it over a unix socket. Single writer by construction — no locking — and
 * range operations (deletePrefix) stay O(matching keys).
 *
 * Protocol: newline-delimited JSON envelopes; values travel as
 * base64(serialize()) so PHP types round-trip exactly.
 *
 * Run: php examples/owner-process/CacheServer.php /tmp/judy-cache.sock
 */

require __DIR__ . '/bootstrap.php';

use Orieg\JudyCache\JudySimpleCache;

$sockPath = $argv[1] ?? '/tmp/judy-cache.sock';
@unlink($sockPath);

$server = stream_socket_server("unix://$sockPath", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot bind $sockPath: $errstr\n");
    exit(1);
}

$cache = new JudySimpleCache();
$clients = [];
fwrite(STDERR, "owner-process cache serving on $sockPath\n");

while (true) {
    $read = $clients;
    $read[] = $server;
    $write = $except = [];
    if (stream_select($read, $write, $except, null) === false) {
        break;
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn !== false) {
                $clients[(int) $conn] = $conn;
            }
            continue;
        }
        $line = fgets($stream);
        if ($line === false) { // client disconnected
            unset($clients[(int) $stream]);
            fclose($stream);
            continue;
        }
        $req = json_decode($line, true);
        $reply = ['ok' => true];
        try {
            switch ($req['op'] ?? '') {
                case 'get':
                    $v = $cache->get($req['key'], $miss = new stdClass());
                    $reply['hit'] = $v !== $miss;
                    $reply['value'] = $reply['hit'] ? base64_encode(serialize($v)) : null;
                    break;
                case 'set':
                    $cache->set($req['key'], unserialize(base64_decode($req['value'])), $req['ttl'] ?? null);
                    break;
                case 'has':
                    $reply['hit'] = $cache->has($req['key']);
                    break;
                case 'delete':
                    $cache->delete($req['key']);
                    break;
                case 'deletePrefix':
                    $reply['deleted'] = $cache->deletePrefix($req['prefix']);
                    break;
                case 'keysByPrefix':
                    $reply['keys'] = $cache->keysByPrefix($req['prefix'], $req['limit'] ?? PHP_INT_MAX);
                    break;
                case 'count':
                    $reply['count'] = $cache->count();
                    break;
                case 'shutdown':
                    fwrite($stream, json_encode($reply) . "\n");
                    exit(0);
                default:
                    $reply = ['ok' => false, 'error' => 'unknown op'];
            }
        } catch (\Throwable $e) {
            $reply = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        fwrite($stream, json_encode($reply) . "\n");
    }
}
