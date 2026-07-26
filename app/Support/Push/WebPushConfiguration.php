<?php

namespace App\Support\Push;

use Minishlink\WebPush\VAPID;
use Throwable;

class WebPushConfiguration
{
    public const ISSUE_DISABLED = 'disabled';

    public const ISSUE_SUBJECT_MISSING = 'subject_missing';

    public const ISSUE_SUBJECT_INVALID = 'subject_invalid';

    public const ISSUE_PUBLIC_KEY_MISSING = 'public_key_missing';

    public const ISSUE_PRIVATE_KEY_MISSING = 'private_key_missing';

    public const ISSUE_PEM_FILE_UNAVAILABLE = 'pem_file_unavailable';

    public const ISSUE_CREDENTIALS_INVALID = 'credentials_invalid';

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
        return self::diagnostics()['configured'];
    }

    /**
     * Return non-sensitive reasons why Web Push is not ready.
     *
     * Secret values and validation exceptions deliberately never leave this
     * class. The issue codes are safe to render in an authenticated status UI.
     *
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     ready: bool,
     *     issues: list<string>
     * }
     */
    public static function diagnostics(): array
    {
        $enabled = (bool) config('webpush.enabled');
        $subject = trim((string) config('webpush.vapid.subject'));
        $publicKey = trim((string) config('webpush.vapid.public_key'));
        $privateKey = trim((string) config('webpush.vapid.private_key'));
        $pemFile = trim((string) config('webpush.vapid.pem_file'));
        $issues = [];

        if (! $enabled) {
            $issues[] = self::ISSUE_DISABLED;
        }

        $validSubject = self::hasValidSubject($subject);

        if ($subject === '') {
            $issues[] = self::ISSUE_SUBJECT_MISSING;
        } elseif (! $validSubject) {
            $issues[] = self::ISSUE_SUBJECT_INVALID;
        }

        if ($publicKey === '') {
            $issues[] = self::ISSUE_PUBLIC_KEY_MISSING;
        }

        if ($privateKey === '') {
            $issues[] = self::ISSUE_PRIVATE_KEY_MISSING;
        }

        $resolvedPemFile = self::resolvePemFile($pemFile);

        if ($pemFile !== '' && ($resolvedPemFile === null || ! is_readable($resolvedPemFile))) {
            $issues[] = self::ISSUE_PEM_FILE_UNAVAILABLE;
        }

        $hasCredentials = $validSubject
            && $publicKey !== ''
            && $privateKey !== ''
            && ! in_array(self::ISSUE_PEM_FILE_UNAVAILABLE, $issues, true);

        if ($hasCredentials) {
            $vapid = [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ];

            if ($resolvedPemFile !== null) {
                $vapid['pemFile'] = $resolvedPemFile;
            }

            try {
                VAPID::validate($vapid);
            } catch (Throwable) {
                $issues[] = self::ISSUE_CREDENTIALS_INVALID;
            }
        }

        $configurationIssues = array_values(array_filter(
            $issues,
            fn (string $issue): bool => $issue !== self::ISSUE_DISABLED,
        ));
        $configured = $configurationIssues === [];

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'ready' => $enabled && $configured,
            'issues' => $issues,
        ];
    }

    private static function hasValidSubject(string $subject): bool
    {
        if (str_starts_with($subject, 'mailto:')) {
            return filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        return filter_var($subject, FILTER_VALIDATE_URL) !== false
            && parse_url($subject, PHP_URL_SCHEME) === 'https';
    }

    private static function resolvePemFile(string $pemFile): ?string
    {
        if ($pemFile === '') {
            return null;
        }

        if (str_starts_with(str_replace('\\', '/', $pemFile), 'storage/')) {
            return base_path($pemFile);
        }

        return $pemFile;
    }
}
