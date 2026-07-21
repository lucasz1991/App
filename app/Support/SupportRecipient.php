<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

class SupportRecipient
{
    public static function resolve(): ?string
    {
        $candidates = [
            Setting::getValueUncached('mails', 'admin_email'),
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
