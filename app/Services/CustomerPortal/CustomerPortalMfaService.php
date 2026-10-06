<?php

namespace App\Services\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

final class CustomerPortalMfaService
{
    public function state(CustomerPortalIdentity $identity): array
    {
        return DB::transaction(function () use ($identity): array {
            [$fresh, $memberships] = $this->principal($identity);
            $pending = $fresh->two_factor_pending_secret && $fresh->two_factor_pending_expires_at?->isFuture() && hash_equals((string) $fresh->two_factor_pending_session_hash, $this->sessionHash());
            $secret = $pending ? $fresh->two_factor_pending_secret : null;
            $svg = null;
            if ($secret) {
                $url = app(TwoFactorAuthenticationProvider::class)->qrCodeUrl('RailTime Kundenportal', $fresh->email, $secret);
                $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 2), new SvgImageBackEnd)))->writeString($url);
                $svg = substr($svg, strpos($svg, '<svg'));
            }

            return ['enabled' => (bool) ($fresh->two_factor_confirmed_at && $fresh->two_factor_secret), 'pending' => (bool) $pending, 'secret' => $secret, 'qr_svg' => $svg,
                'expires_at' => $pending ? $fresh->two_factor_pending_expires_at : null, 'remaining_codes' => count($fresh->two_factor_recovery_codes ?? []),
                'required' => $memberships->contains(fn ($membership) => $membership->setting->require_mfa)];
        });
    }

    public function begin(CustomerPortalIdentity $identity, array $data): CustomerPortalIdentity
    {
        return DB::transaction(function () use ($identity, $data): CustomerPortalIdentity {
            [$fresh, $memberships] = $this->principal($identity);
            $this->password($fresh, $data);
            if ($fresh->two_factor_confirmed_at && $fresh->two_factor_secret) {
                $this->consumeFactor($fresh, $data);
            }
            $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
            $fresh->forceFill(['two_factor_pending_secret' => $secret, 'two_factor_pending_expires_at' => now()->addMinutes(10), 'two_factor_pending_session_hash' => $this->sessionHash()])->save();
            $this->audit($fresh, $memberships, 'mfa_setup_started');

            return $fresh;
        });
    }

    /** Recovery codes are returned only by this newly confirmed operation, never recoverable from storage. */
    public function confirm(CustomerPortalIdentity $identity, array $data): array
    {
        $code = Validator::make($data, ['code' => 'required|digits:6'])->validate()['code'];

        return DB::transaction(function () use ($identity, $code): array {
            [$fresh, $memberships] = $this->principal($identity);
            abort_unless($fresh->two_factor_pending_secret && $fresh->two_factor_pending_expires_at?->isFuture() && hash_equals((string) $fresh->two_factor_pending_session_hash, $this->sessionHash()), 409, 'Einrichtung neu beginnen.');
            $counter = $this->verifyTotp($fresh->two_factor_pending_secret, $code, null);
            $codes = $this->newCodes();
            $fresh->forceFill(['two_factor_secret' => $fresh->two_factor_pending_secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map([$this, 'codeHash'], $codes), 'two_factor_last_counter' => $counter,
                'two_factor_pending_secret' => null, 'two_factor_pending_expires_at' => null, 'two_factor_pending_session_hash' => null, 'revision' => $fresh->revision + 1])->save();
            $this->audit($fresh, $memberships, 'mfa_enabled');

            return ['identity' => $fresh, 'codes' => $codes];
        });
    }

    public function challenge(CustomerPortalIdentity $identity, array $data): CustomerPortalIdentity
    {
        return DB::transaction(function () use ($identity, $data): CustomerPortalIdentity {
            [$fresh, $memberships] = $this->principal($identity);
            $recovery = $this->consumeFactor($fresh, $data);
            $fresh->save();
            $this->audit($fresh, $memberships, $recovery ? 'mfa_recovery_used' : 'mfa_challenge_verified');

            return $fresh;
        });
    }

    public function regenerateCodes(CustomerPortalIdentity $identity, array $data): array
    {
        return DB::transaction(function () use ($identity, $data): array {
            [$fresh, $memberships] = $this->principal($identity);
            $this->password($fresh, $data);
            $this->consumeFactor($fresh, $data);
            $codes = $this->newCodes();
            $fresh->forceFill(['two_factor_recovery_codes' => array_map([$this, 'codeHash'], $codes), 'revision' => $fresh->revision + 1])->save();
            $this->audit($fresh, $memberships, 'mfa_recovery_regenerated');

            return ['identity' => $fresh, 'codes' => $codes];
        });
    }

    public function disable(CustomerPortalIdentity $identity, array $data): CustomerPortalIdentity
    {
        return DB::transaction(function () use ($identity, $data): CustomerPortalIdentity {
            [$fresh, $memberships] = $this->principal($identity);
            abort_if($memberships->contains(fn ($membership) => $membership->setting->require_mfa), 409, 'Zusätzliche Bestätigung ist für diesen Zugang erforderlich.');
            $this->password($fresh, $data);
            $this->consumeFactor($fresh, $data);
            $fresh->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_counter' => null,
                'two_factor_pending_secret' => null, 'two_factor_pending_expires_at' => null, 'two_factor_pending_session_hash' => null, 'revision' => $fresh->revision + 1])->save();
            $this->audit($fresh, $memberships, 'mfa_disabled');

            return $fresh;
        });
    }

    private function principal(CustomerPortalIdentity $identity): array
    {
        CustomerPortalSchema::requireReady();
        $guard = Auth::guard('customer_portal')->user();
        abort_unless($guard instanceof CustomerPortalIdentity && $guard->id === $identity->id && (int) session('customer_portal_identity_revision', 0) === $identity->revision && (int) session('customer_portal_authenticated_at', 0) >= now()->subMinutes(config('customer_portal.session_minutes', 120))->timestamp, 403);
        $ids = CustomerPortalMembership::where('identity_id', $identity->id)->where('status', 'active')->orderBy('customer_id')->pluck('customer_id');
        Customer::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $memberships = app(CustomerPortalScope::class)->memberships($identity);
        abort_unless($memberships->isNotEmpty(), 403);
        $fresh = CustomerPortalIdentity::whereKey($identity->id)->lockForUpdate()->firstOrFail();
        abort_unless($fresh->active && $fresh->email_verified_at && $fresh->revision === $identity->revision, 403);
        $memberships = $memberships->map(fn ($member) => app(CustomerPortalScope::class)->membership($fresh, $member->customer_id, null, true));

        return [$fresh, $memberships];
    }

    private function password(CustomerPortalIdentity $identity, array $data): void
    {
        $password = Validator::make($data, ['password' => 'required|string|max:128'])->validate()['password'];
        if (! Hash::check($password, $identity->password)) {
            throw ValidationException::withMessages(['password' => 'Passwort ungültig.']);
        }
    }

    private function consumeFactor(CustomerPortalIdentity $identity, array $data): bool
    {
        abort_unless($identity->two_factor_confirmed_at && $identity->two_factor_secret, 409, 'Zusätzliche Bestätigung zuerst einrichten.');
        $validated = Validator::make($data, ['code' => 'nullable|digits:6|required_without:recovery_code', 'recovery_code' => 'nullable|string|max:40|required_without:code'])->validate();
        if (filled($validated['recovery_code'] ?? null)) {
            $hash = $this->codeHash($validated['recovery_code']);
            $codes = $identity->two_factor_recovery_codes ?? [];
            $index = null;
            foreach ($codes as $key => $stored) {
                if (hash_equals($stored, $hash)) {
                    $index = $key;
                }
            }
            if ($index === null) {
                throw ValidationException::withMessages(['recovery_code' => 'Wiederherstellungscode ungültig.']);
            }
            unset($codes[$index]);
            $identity->forceFill(['two_factor_recovery_codes' => array_values($codes)]);

            return true;
        }
        $identity->forceFill(['two_factor_last_counter' => $this->verifyTotp($identity->two_factor_secret, $validated['code'], $identity->two_factor_last_counter)]);

        return false;
    }

    private function verifyTotp(string $secret, string $code, ?int $lastCounter): int
    {
        $counter = app(Google2FA::class)->verifyKeyNewer($secret, $code, $lastCounter ?? -1, 1, intdiv(now()->timestamp, 30));
        if ($counter === false) {
            throw ValidationException::withMessages(['code' => 'Code ungültig oder bereits verwendet.']);
        }

        return $counter === true ? intdiv(now()->timestamp, 30) : (int) $counter;
    }

    private function newCodes(): array
    {
        return array_map(fn () => strtoupper(implode('-', str_split(bin2hex(random_bytes(10)), 5))), range(1, 8));
    }

    private function codeHash(string $code): string
    {
        return hash('sha256', strtoupper(str_replace(['-', ' '], '', trim($code))));
    }

    private function sessionHash(): string
    {
        return hash('sha256', session()->getId());
    }

    private function audit(CustomerPortalIdentity $identity, $memberships, string $action): void
    {
        foreach ($memberships as $member) {
            CustomerPortalAudit::create(['customer_id' => $member->customer_id, 'membership_id' => $member->id, 'identity_id' => $identity->id, 'action' => $action, 'revision' => $identity->revision]);
        }
    }
}
