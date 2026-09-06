<?php

namespace App\Services\SystemHealth\Transport;

use RuntimeException;

/** Private, finite diagnostic channel. Never retain protocol transcripts. */
class BoundedSocket
{
    /** @var resource|null */
    private $socket = null;

    private float $deadline;

    private int $received = 0;

    public function open(string $host, int $port, bool $tls, float $seconds = 6): void
    {
        if (! self::validHost($host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid diagnostic endpoint.');
        }

        $this->deadline = microtime(true) + $seconds;
        $this->received = 0;
        $peer = trim($host, '[]');
        $address = str_contains($peer, ':') ? '['.$peer.']' : $peer;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peer,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $this->socket = @stream_socket_client(
            ($tls ? 'tls' : 'tcp').'://'.$address.':'.$port,
            $errorCode,
            $errorMessage,
            min(2.0, $seconds),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($this->socket)) {
            throw new RuntimeException('Diagnostic connection failed.');
        }
        stream_set_blocking($this->socket, true);
    }

    public static function validHost(string $host): bool
    {
        $host = trim($host, '[]');

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
    }

    public function startTls(): bool
    {
        $this->setRemainingTimeout();

        return @stream_socket_enable_crypto($this->socket, true) === true;
    }

    public function write(string $bytes): void
    {
        while ($bytes !== '') {
            $this->setRemainingTimeout();
            $written = @fwrite($this->socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Diagnostic write failed.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    public function line(): string
    {
        $this->setRemainingTimeout();
        $line = @fgets($this->socket, 8193);
        if (! is_string($line) || ! str_ends_with($line, "\n")) {
            throw new RuntimeException('Diagnostic response incomplete.');
        }
        $this->accountBytes(strlen($line));

        return $line;
    }

    public function read(int $length): string
    {
        if ($length < 0 || $length > 65536) {
            throw new RuntimeException('Diagnostic response too large.');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->setRemainingTimeout();
            $chunk = @fread($this->socket, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Diagnostic response incomplete.');
            }
            $bytes .= $chunk;
            $this->accountBytes(strlen($chunk));
        }

        return $bytes;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function setRemainingTimeout(): void
    {
        $remaining = ($this->deadline ?? 0) - microtime(true);
        if (! is_resource($this->socket) || $remaining <= 0) {
            throw new RuntimeException('Diagnostic deadline exceeded.');
        }
        stream_set_timeout($this->socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
    }

    private function accountBytes(int $bytes): void
    {
        $this->received += $bytes;
        if ($this->received > 65536) {
            throw new RuntimeException('Diagnostic response too large.');
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
