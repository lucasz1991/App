<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Models\DeviceAssignment;
use App\Models\DeviceDesktopClient;
use App\Models\OutlookDesktopPairing;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outlook-Add-in erkennt die RailTime-App und koppelt genau einen PC.
 *
 * Ablauf: Das Add-in fordert eine Anfrage an (Nonce, 2 Minuten, nur als Hash gespeichert) und
 * öffnet die Kopplungsseite im Browser. Die Seite ruft `railtime-client://pair?n=…` auf, die App
 * fragt den Nutzer und bestätigt die Nonce mit ihrem Gerätezugang. Der Server nimmt sie nur an,
 * wenn das Gerät genau diesem Mitarbeiter zugewiesen ist. Danach holt das Add-in einmalig einen
 * Kopplungsschlüssel ab. Keine Funktion des Add-ins hängt davon ab.
 */
final class OutlookDesktopPairingService
{
    /** Gültigkeit einer Anfrage. */
    public const REQUEST_MINUTES = 2;

    /** Eine bestätigte Anfrage bleibt so lange über ihr Ablaufdatum hinaus abholbar. */
    public const CLAIM_GRACE_MINUTES = 3;

    /** Zuletzt gemeldet innerhalb dieser Zeit = App läuft. */
    public const RUNNING_SECONDS = 180;

    /** Anfragen je Mitarbeiter in zehn Minuten. */
    public const REQUESTS_PER_WINDOW = 6;

    public const KEY_PATTERN = '/\Artop_[A-Za-z0-9_-]{43}\z/';

    public const NONCE_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';

    public function ready(): bool
    {
        try {
            return Schema::hasTable('outlook_desktop_pairings') && Schema::hasTable('device_desktop_clients');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Zustand für das Konto und – mit gültigem Schlüssel – für diesen PC. */
    public function status(User $user, ?string $key): array
    {
        abort_unless($this->ready(), 503, 'Die Kopplung ist vorübergehend nicht verfügbar.');
        $clients = $this->clientsFor($user);
        $running = $clients->contains(fn (DeviceDesktopClient $client) => $this->running($client));
        $state = ['schema' => 1, 'account' => $clients->isEmpty() ? 'none' : ($running ? 'running' : 'installed'),
            'thisPc' => 'unknown', 'deviceName' => null, 'running' => false,
            'installUrl' => $this->publicUrl('/railtime-app'), 'pairingAvailable' => $clients->isNotEmpty()];

        if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
            return $state;
        }
        $pairing = OutlookDesktopPairing::query()->where('key_hash', hash('sha256', $key))
            ->where('user_id', $user->id)->first();
        if ($pairing === null) {
            return $state;
        }
        $client = $pairing->status === 'paired' && $pairing->client_id
            ? $clients->firstWhere('id', $pairing->client_id) : null;
        if (! $client instanceof DeviceDesktopClient) {
            // App entfernt, Gerät neu zugewiesen oder Kopplung gelöst: der Schlüssel taugt nicht mehr.
            if ($pairing->status === 'paired') {
                $pairing->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
            }

            return ['thisPc' => 'revoked'] + $state;
        }
        if ($pairing->last_used_at === null || $pairing->last_used_at->lt(now()->subMinutes(5))) {
            $pairing->forceFill(['last_used_at' => now()])->save();
        }

        return ['thisPc' => 'paired', 'deviceName' => $this->deviceName($client), 'running' => $this->running($client)] + $state;
    }

    /** Neue Anfrage für diesen Mitarbeiter. Die Nonce verlässt den Server nur in der Seitenadresse. */
    public function start(User $user): array
    {
        abort_unless($this->ready(), 503, 'Die Kopplung ist vorübergehend nicht verfügbar.');
        abort_if($this->clientsFor($user)->isEmpty(), 409, 'Die RailTime-App ist auf keinem deiner PCs eingerichtet.');

        return DB::transaction(function () use ($user): array {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $recent = OutlookDesktopPairing::query()->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinutes(10))->count();
            abort_if($recent >= self::REQUESTS_PER_WINDOW, 429, 'Bitte einen Moment warten und dann erneut koppeln.');
            OutlookDesktopPairing::query()->where('user_id', $user->id)->where('status', 'pending')
                ->update(['status' => 'expired', 'nonce_hash' => null]);
            $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pairing = OutlookDesktopPairing::query()->create([
                'user_id' => $user->id, 'nonce_hash' => hash('sha256', $nonce), 'status' => 'pending',
                'expires_at' => now()->addMinutes(self::REQUEST_MINUTES),
            ]);

            return ['id' => $pairing->public_id, 'url' => $this->publicUrl('/outlook-addin/geraet-koppeln/'.$nonce),
                'expiresAt' => $pairing->expires_at->toIso8601String(), 'pollAfterMs' => 2500];
        }, 3);
    }

    /** Ergebnis einer Anfrage; ein bestätigter Schlüssel wird genau einmal ausgegeben. */
    public function poll(User $user, string $publicId): array
    {
        abort_unless($this->ready(), 503, 'Die Kopplung ist vorübergehend nicht verfügbar.');

        return DB::transaction(function () use ($user, $publicId): array {
            $pairing = OutlookDesktopPairing::query()->where('public_id', $publicId)
                ->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($pairing->status === 'pending' && $pairing->expires_at->isPast()) {
                $pairing->forceFill(['status' => 'expired', 'nonce_hash' => null])->save();
            }
            if ($pairing->status === 'confirmed') {
                $client = $pairing->client_id ? $this->clientsFor($user)->firstWhere('id', $pairing->client_id) : null;
                if (! $client instanceof DeviceDesktopClient || $pairing->expires_at->copy()->addMinutes(self::CLAIM_GRACE_MINUTES)->isPast()) {
                    $pairing->forceFill(['status' => 'expired'])->save();

                    return ['state' => 'expired'];
                }
                $key = 'rtop_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $pairing->forceFill(['status' => 'paired', 'key_hash' => hash('sha256', $key), 'paired_at' => now(), 'last_used_at' => now()])->save();
                $this->audit($pairing, $client, 'outlook-paired');

                return ['state' => 'paired', 'pairingKey' => $key, 'deviceName' => $this->deviceName($client)];
            }

            return match ($pairing->status) {
                'pending' => ['state' => 'pending'],
                'paired' => ['state' => 'paired', 'deviceName' => $pairing->client ? $this->deviceName($pairing->client) : null],
                'rejected' => ['state' => 'rejected'],
                default => ['state' => 'expired'],
            };
        }, 3);
    }

    /** Die App bestätigt eine Anfrage. Läuft innerhalb der Clientsperre aus DeviceDesktopService. */
    public function confirm(DeviceDesktopClient $client, string $nonce): array
    {
        abort_unless($this->ready(), 503, 'Die Kopplung ist vorübergehend nicht verfügbar.');
        abort_unless(preg_match(self::NONCE_PATTERN, $nonce), 422, 'Ungültige Kopplungsanfrage.');

        $pairing = OutlookDesktopPairing::query()->where('nonce_hash', hash('sha256', $nonce))->lockForUpdate()->first();
        abort_unless($pairing !== null && $pairing->status === 'pending' && $pairing->expires_at->isFuture(), 404, 'Kopplungsanfrage abgelaufen.');
        $owner = (int) DeviceAssignment::query()->whereKey($client->device_assignment_id)->value('user_id');
        if ($owner !== (int) $pairing->user_id) {
            // Ein fremder PC hat den Link geöffnet: die Anfrage ist verbraucht, das Add-in erfährt es.
            $pairing->forceFill(['status' => 'rejected', 'nonce_hash' => null])->save();
            $this->audit($pairing, $client, 'outlook-pairing-rejected');

            // Kein abort hier: die Ablehnung muss die Transaktion überdauern; der Controller antwortet 403.
            return ['confirmed' => false];
        }
        $pairing->forceFill(['status' => 'confirmed', 'client_id' => $client->id, 'confirmed_at' => now(), 'nonce_hash' => null])->save();
        $this->audit($pairing, $client, 'outlook-pairing-confirmed');

        return ['confirmed' => true];
    }

    /** Für die Kopplungsseite: ob die Nonce noch gilt (ohne sie zu verbrauchen). */
    public function pending(string $nonce): bool
    {
        if (! $this->ready() || ! preg_match(self::NONCE_PATTERN, $nonce)) {
            return false;
        }

        return OutlookDesktopPairing::query()->where('nonce_hash', hash('sha256', $nonce))
            ->where('status', 'pending')->where('expires_at', '>', now())->exists();
    }

    /** Aktive Clients auf Geräten, die diesem Mitarbeiter gerade zugewiesen sind. */
    public function clientsFor(User $user): Collection
    {
        $assignments = DeviceAssignment::query()->active()->where('user_id', $user->id)->pluck('id');
        if ($assignments->isEmpty()) {
            return collect();
        }

        return DeviceDesktopClient::query()->with('device')->where('status', 'active')->whereNull('revoked_at')
            ->whereIn('device_assignment_id', $assignments)->get();
    }

    /** Adressen für das Add-in kommen von derselben geprüften HTTPS-Basis wie seine übrigen Endpunkte. */
    private function publicUrl(string $path): string
    {
        $base = app(OutlookAddinConfiguration::class)->baseUrl(throwWhenInvalid: false);

        return ($base !== '' ? rtrim($base, '/') : rtrim(url('/'), '/')).$path;
    }

    private function running(DeviceDesktopClient $client): bool
    {
        return $client->last_seen_at !== null && $client->last_seen_at->gte(now()->subSeconds(self::RUNNING_SECONDS));
    }

    private function deviceName(DeviceDesktopClient $client): string
    {
        $device = $client->device;

        return (string) ($device?->display_name ?: $device?->hostname ?: $client->reported_name ?: 'RailTime-Gerät');
    }

    private function audit(OutlookDesktopPairing $pairing, DeviceDesktopClient $client, string $event): void
    {
        activity('device-management')->performedOn($client)->event('device-desktop.'.$event)
            ->withProperties(['client_id' => $client->public_id, 'pairing_id' => $pairing->public_id])
            ->log('Desktopclient '.$event);
    }
}
