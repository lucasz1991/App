<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Support\EmailTemplateBuilder;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use Illuminate\Support\Str;
use RuntimeException;

/** Private local resolver. Never accepts message HTML, profile values or media paths from mail. */
class OutlookUniversalTransitSnapshotProvider
{
    public function __construct(
        private readonly OutlookAddinPayloadService $payloads,
        private readonly OutlookUniversalPayloadPresentation $universal,
    ) {}

    public function resolve(string $tenantId, int $userId, string $envelopeSender, string $templateHeader): array
    {
        $tenant = strtolower((string) config('outlook_addin.entra.tenant_id'));
        if (! Str::isUuid($tenant) || ! hash_equals($tenant, strtolower($tenantId)) || $userId < 1
            || strtolower($envelopeSender) !== 'it-technik@rail-time.de'
            || ! preg_match('/\Au2e-[a-f0-9]{24}\z/', $templateHeader)) {
            throw new RuntimeException('U2E_TRANSIT_BINDING_INVALID');
        }
        $user = User::query()->with('profile')->find($userId);
        if (! $user || ! OutlookUniversalPayloadPresentation::isPilot($user) || ! $user->isActive()
            || $user->email_verified_at === null || ! in_array($user->role, ['admin', 'staff'], true)) {
            throw new RuntimeException('U2E_TRANSIT_BINDING_INVALID');
        }
        $accounts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->where('user_id', $userId)->get();
        $account = $accounts->count() === 1 ? $accounts->first() : null;
        if (! $account || ! Str::isUuid((string) $account->external_id)
            || ! hash_equals($tenant, strtolower((string) $account->tenant_id))
            || ! in_array(strtolower($envelopeSender), array_map(
                static fn ($value): string => strtolower(trim((string) $value)), [$account->principal, $account->email],
            ), true)) {
            throw new RuntimeException('U2E_TRANSIT_BINDING_INVALID');
        }

        $before = $this->fingerprint($user);
        $draft = $this->projection($user);
        if ($draft === null) {
            throw new RuntimeException('U2E_TRANSIT_SOURCE_REJECTED');
        }
        if (($draft['mode'] ?? null) !== 'send'
            || ($draft['templateHash'] ?? null) !== hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents(resource_path('mail-templates/u2e-universal-v1.html'))))) {
            throw new RuntimeException('U2E_TRANSIT_SOURCE_REJECTED');
        }
        if (! hash_equals((string) ($draft['header'] ?? ''), $templateHeader)) {
            throw new RuntimeException('U2E_TRANSIT_VERSION_NOT_CURRENT');
        }
        $media = $this->media($draft);
        $fresh = $user->fresh(['profile']);
        $freshAccounts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->where('user_id', $userId)->get();
        $freshAccount = $freshAccounts->count() === 1 ? $freshAccounts->first() : null;
        $bindingFields = ['user_id', 'provider', 'external_id', 'tenant_id', 'principal', 'email', 'lifecycle_status'];
        $binding = static fn ($model): array => array_intersect_key($model->getRawOriginal(), array_fill_keys($bindingFields, true));
        if (! $fresh || ! $fresh->isActive() || $fresh->email_verified_at === null
            || ! in_array($fresh->role, ['admin', 'staff'], true) || ! $freshAccount
            || $freshAccount->id !== $account->id || $binding($account) !== $binding($freshAccount)
            || ! hash_equals($before, $this->fingerprint($fresh))) {
            throw new RuntimeException('U2E_TRANSIT_SOURCE_CHANGED');
        }

        return ['schema' => 1, 'ok' => true, 'projection' => $draft, 'media' => $media];
    }

    protected function fingerprint(User $user): string
    {
        return $this->payloads->sourceFingerprint($user);
    }

    protected function projection(User $user): ?array
    {
        $canonical = OutlookStaticLinkedTestProfiles::payload(
            OutlookClientPresentation::payload($this->payloads->forUser($user)), $user,
        );

        return $this->universal->review($canonical, (new EmailTemplateBuilder($user))->profileValues(), mode: 'send', user: $user)['draft'];
    }

    private function media(array $draft): array
    {
        $result = [];
        $seen = [];
        $total = 0;
        $origin = rtrim((string) config('app.url'), '/');
        foreach ($draft['media'] ?? [] as $entry) {
            $url = $entry['url'] ?? null;
            $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
            if (! is_string($path) || ! preg_match('~\A/m/[a-f0-9]{10}\.png\z~', $path)
                || $url !== $origin.$path || isset($seen[$url]) || ($entry['mime'] ?? null) !== 'image/png') {
                throw new RuntimeException('U2E_TRANSIT_ASSET_INVALID');
            }
            $seen[$url] = true;
            $file = public_path(ltrim($path, '/'));
            if (! is_file($file) || is_link($file) || filesize($file) > 2097152) {
                throw new RuntimeException('U2E_TRANSIT_ASSET_INVALID');
            }
            $bytes = file_get_contents($file);
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || ! is_array($size) || ($size['mime'] ?? null) !== 'image/png'
                || $size[0] !== ($entry['width'] ?? null) || $size[1] !== ($entry['height'] ?? null)
                || ! hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $bytes))) {
                throw new RuntimeException('U2E_TRANSIT_ASSET_INVALID');
            }
            $total += strlen($bytes);
            if ($total > 2097152) {
                throw new RuntimeException('U2E_TRANSIT_ASSET_INVALID');
            }
            $result[] = ['source' => $url, 'mime' => 'image/png', 'sha256' => $entry['sha256'], 'dataBase64' => base64_encode($bytes)];
        }
        if (count($result) < 8 || count($result) > 9) {
            throw new RuntimeException('U2E_TRANSIT_ASSET_INVALID');
        }

        return $result;
    }
}
