<?php

namespace App\Services\SystemHealth\Transport;

use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/** Symfony ESMTP-compatible stream with an overall deadline and no debug transcript. */
final class ProbeSmtpStream extends AbstractStream
{
    private string $host = 'localhost';

    private int $port = 25;

    private bool $tls = true;

    public function __construct(private readonly BoundedSocket $channel) {}

    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function disableTls(): void
    {
        $this->tls = false;
    }

    public function isTLS(): bool
    {
        return $this->tls;
    }

    public function startTLS(): bool
    {
        return $this->tls = $this->channel->startTls();
    }

    public function initialize(): void
    {
        $this->channel->open($this->host, $this->port, $this->tls);
    }

    public function write(string $bytes, bool $debug = true): void
    {
        $this->channel->write($bytes);
    }

    public function readLine(): string
    {
        return $this->channel->line();
    }

    public function terminate(): void
    {
        $this->channel->close();
    }

    protected function getReadConnectionDescription(): string
    {
        return 'system health SMTP';
    }
}
