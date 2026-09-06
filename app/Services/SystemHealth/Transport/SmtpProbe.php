<?php

namespace App\Services\SystemHealth\Transport;

use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\RawMessage;

class SmtpProbe
{
    /** @return array{authenticated: bool, tls: bool} */
    public function check(array $config): array
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 587);
        $implicitTls = ($config['scheme'] ?? '') === 'smtps' || $port === 465;
        $stream = new ProbeSmtpStream(new BoundedSocket);
        $transport = new class($host, $port, $implicitTls, stream: $stream) extends EsmtpTransport
        {
            public bool $authenticated = false;

            public function executeCommand(string $command, array $codes): string
            {
                $response = parent::executeCommand($command, $codes);
                if (str_starts_with($response, '235')) {
                    $this->authenticated = true;
                }

                return $response;
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('Diagnostic mail delivery is prohibited.');
            }
        };
        $transport->setRequireTls(true);
        $transport->setUsername((string) ($config['username'] ?? ''));
        $transport->setPassword((string) ($config['password'] ?? ''));
        $domain = (string) ($config['local_domain'] ?? 'localhost');
        if (! BoundedSocket::validHost($domain)) {
            throw new RuntimeException('Invalid SMTP greeting domain.');
        }
        $transport->setLocalDomain($domain);

        try {
            $transport->start();
            if (! $stream->isTLS()) {
                throw new RuntimeException('SMTP encryption was not established.');
            }

            return ['authenticated' => $transport->authenticated, 'tls' => true];
        } finally {
            try {
                $transport->stop();
            } finally {
                $stream->terminate();
            }
        }
    }
}
