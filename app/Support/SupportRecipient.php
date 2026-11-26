<?php

namespace App\Support;

use App\Models\User;
use App\Support\Settings\MailSettings;

class SupportRecipient
{
    public static function resolve(): ?string
    {
        $candidates = [
            MailSettings::adminEmail(),
            config('mail.super_admin'),
            User::query()->where('role', 'admin')->orderBy('id')->value('email'),
        ];

        foreach ($candidates as $candidate) {
            $email = trim((string) $candidate);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }
}
