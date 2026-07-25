<?php

namespace App\Support\Push;

use Minishlink\WebPush\VAPID;
use Throwable;

class WebPushConfiguration
{
    public static function accountBinding(int|string $userId): string
    {
        return hash_hmac(
            'sha256',
            'railtime-webpush-account:'.$userId,
            (string) config('app.key'),
        );
    }

    public static function isConfigured(): bool
    {
        $subject = (string) config('webpush.vapid.subject');
        $validSubject = str_starts_with($subject, 'mailto:')
            ? filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false
            : filter_var($subject, FILTER_VALIDATE_URL) !== false
                && parse_url($subject, PHP_URL_SCHEME) === 'https';

        if (! $validSubject) {
            return false;
        }

        try {
            VAPID::validate([
                'subject' => $subject,
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
