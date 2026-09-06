<?php

namespace App\Services\SystemHealth\Transport;

use RuntimeException;

class WebSocketProbe
{
    public function check(string $host, int $port, bool $tls, string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f]/', $path)) {
            throw new RuntimeException('Invalid WebSocket path.');
        }
        $socket = new BoundedSocket;
        $key = base64_encode(random_bytes(16));
        $authority = (str_contains($host, ':') ? '['.trim($host, '[]').']' : $host).':'.$port;

        try {
            $socket->open($host, $port, $tls);
            $socket->write("GET {$path} HTTP/1.1\r\nHost: {$authority}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
            if (! preg_match('/^HTTP\/1\.[01] 101(?: |\r)/', $socket->line())) {
                throw new RuntimeException('WebSocket upgrade failed.');
            }
            $headers = [];
            while (($line = $socket->line()) !== "\r\n") {
                $parts = explode(':', $line, 2);
                if (count($parts) !== 2) {
                    throw new RuntimeException('Invalid WebSocket response.');
                }
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (! hash_equals($expected, $headers['sec-websocket-accept'] ?? '')
                || strtolower($headers['upgrade'] ?? '') !== 'websocket'
                || ! in_array('upgrade', array_map('trim', explode(',', strtolower($headers['connection'] ?? ''))), true)) {
                throw new RuntimeException('WebSocket upgrade could not be verified.');
            }
            $frame = $socket->read(2);
            $length = ord($frame[1]) & 127;
            if (ord($frame[0]) !== 129 || (ord($frame[1]) & 128) !== 0 || $length === 127) {
                throw new RuntimeException('Unexpected WebSocket greeting.');
            }
            if ($length === 126) {
                $length = unpack('nlength', $socket->read(2))['length'];
            }
            $payload = json_decode($socket->read($length), true, 32, JSON_THROW_ON_ERROR);
            if (($payload['event'] ?? null) !== 'pusher:connection_established') {
                throw new RuntimeException('Realtime application did not accept the connection.');
            }
        } finally {
            $socket->close();
        }
    }
}
