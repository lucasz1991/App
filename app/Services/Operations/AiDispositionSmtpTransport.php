<?php

namespace App\Services\Operations;

use App\Models\AiIntakeDelivery;
use App\Services\SystemHealth\Transport\SmtpProbe;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;

class AiDispositionSmtpTransport
{
    public function configuration(array $settings): array
    {
        return ['transport' => 'smtp', 'scheme' => $settings['smtp_encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $settings['smtp_host'], 'port' => (int) $settings['smtp_port'], 'require_tls' => true, 'timeout' => 30,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            'from' => ['address' => $settings['from_address'], 'name' => $settings['from_name']]] + AiDispositionSettings::smtpCredentials($settings);
    }

    public function probe(array $settings): array
    {
        return app(SmtpProbe::class)->check($this->configuration($settings));
    }

    public function send(AiIntakeDelivery $delivery, array $settings): void
    {
        $mailer = app(MailManager::class)->build($this->configuration($settings));
        $mailer->raw((string) $delivery->body, function (Message $message) use ($settings, $delivery): void {
            $message->from($settings['from_address'], $settings['from_name'])->replyTo($settings['from_address'])->to($delivery->recipient_email)->subject($delivery->subject);
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addIdHeader('Message-ID', $delivery->message_id_header);
            if ($delivery->in_reply_to) {
                $headers->addIdHeader('In-Reply-To', trim($delivery->in_reply_to, '<>'));
            }
            if ($delivery->references) {
                $headers->addIdHeader('References', array_map(fn ($id) => trim($id, '<>'), $delivery->references));
            }
            $headers->addTextHeader('Auto-Submitted', 'auto-replied');
            $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        });
    }
}
