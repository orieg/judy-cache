<?php
/**
 * Client for the owner-process cache server. Each worker constructs one and
 * talks to the single owner over a unix socket.
 */

class CacheClient
{
    /** @var resource */
    private $sock;

    public function __construct(string $sockPath)
    {
        $sock = @stream_socket_client("unix://$sockPath", $errno, $errstr, 5.0);
        if ($sock === false) {
            throw new RuntimeException("cannot connect to $sockPath: $errstr");
        }
        $this->sock = $sock;
    }

    private function call(array $req): array
    {
        fwrite($this->sock, json_encode($req) . "\n");
        $line = fgets($this->sock);
        if ($line === false) {
            throw new RuntimeException('cache server closed the connection');
        }
        $reply = json_decode($line, true);
        if (!($reply['ok'] ?? false)) {
            throw new RuntimeException($reply['error'] ?? 'unknown cache server error');
        }
        return $reply;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $r = $this->call(['op' => 'get', 'key' => $key]);
        return $r['hit'] ? unserialize(base64_decode($r['value'])) : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->call(['op' => 'set', 'key' => $key, 'value' => base64_encode(serialize($value)), 'ttl' => $ttl]);
        return true;
    }

    public function has(string $key): bool
    {
        return $this->call(['op' => 'has', 'key' => $key])['hit'];
    }

    public function delete(string $key): bool
    {
        $this->call(['op' => 'delete', 'key' => $key]);
        return true;
    }

    public function deletePrefix(string $prefix): int
    {
        return $this->call(['op' => 'deletePrefix', 'prefix' => $prefix])['deleted'];
    }

    public function keysByPrefix(string $prefix, int $limit = PHP_INT_MAX): array
    {
        return $this->call(['op' => 'keysByPrefix', 'prefix' => $prefix, 'limit' => $limit])['keys'];
    }

    public function count(): int
    {
        return $this->call(['op' => 'count'])['count'];
    }

    public function shutdownServer(): void
    {
        $this->call(['op' => 'shutdown']);
    }
}
