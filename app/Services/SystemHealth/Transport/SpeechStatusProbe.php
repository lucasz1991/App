<?php

namespace App\Services\SystemHealth\Transport;

use App\Services\Ai\SpeechServiceClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class SpeechStatusProbe
{
    public function configured(): bool
    {
        return (new SpeechServiceClient)->isConfigured();
    }

    public function check(): array
    {
        $stack = HandlerStack::create();
        $stack->push(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler) {
            // Retain the existing client's credential and destination restrictions,
            // but never inherit long inference timeouts for a diagnostic GET.
            $options['timeout'] = 5;
            $options['connect_timeout'] = 2;
            $options['allow_redirects'] = false;
            $options['verify'] = true;
            $options['proxy'] = '';
            $options['progress'] = static function ($total, $received): void {
                if ($total > 65536 || $received > 65536) {
                    throw new RuntimeException('Diagnostic response too large.');
                }
            };

            return $handler($request, $options);
        });

        return (new SpeechServiceClient(new Client(['handler' => $stack])))->status();
    }
}
