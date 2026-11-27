<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Models\Device;
use App\Models\DeviceDesktopClient;
use App\Models\SupportCase;
use App\Models\SupportRemoteSession;
use App\Models\User;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Fernwartung mit Zustimmung je Sitzung (Stufe 5).
 *
 * Die IT fordert aus einem Supportfall eine Sitzung an. Die RailTime-App auf dem PC fragt den
 * Mitarbeiter in einem eigenen Dialog; nur nach „Erlauben“ zeigt RailTime der IT den Link zum
 * Fernwartungsdienst (MeshCentral über den Connector). Jede Sitzung hat ein belegtes Ende: durch den
 * Mitarbeiter, die IT oder Zeitablauf. Ohne eingerichteten Dienst bleibt es bei der Zustimmung.
 */
final class RemoteSupportService
{
    public const CAPABILITY = 'remote_consent';

    /** So lange wartet eine Anfrage auf die Antwort am PC. */
    public const REQUEST_MINUTES = 10;

    /** So lange gilt eine erteilte Zustimmung höchstens. */
    public const SESSION_MINUTES = 60;

    public function ready(): bool
    {
        try {
            return Schema::hasTable('support_remote_sessions') && Schema::hasTable('support_cases');
        } catch (Throwable) {
            return false;
        }
    }

    public function canRequest(?User $user): bool
    {
        return $user !== null && $user->isActive() && Gate::forUser($user)->allows('devices.support')
            && Gate::forUser($user)->allows('support.manage');
    }

    /** Aktiver Client für das Gerät eines Supportfalls, wenn Fernwartung dort grundsätzlich möglich ist. */
    public function clientFor(SupportCase $case): ?DeviceDesktopClient
    {
        if (! $case->device_id) {
            return null;
        }
        $client = DeviceDesktopClient::query()->with(['device', 'assignment'])->where('device_id', $case->device_id)
            ->where('status', 'active')->whereNull('revoked_at')->latest('id')->first();
        if (! $client || ! $client->device || $client->device->ownership !== 'corporate'
            || (int) $client->assignment?->user_id !== (int) $case->user_id || $client->assignment?->returned_at !== null) {
            return null;
        }

        return $client;
    }

    public function request(SupportCase $case, User $actor, bool $fileTransfer = false): SupportRemoteSession
    {
        abort_unless($this->ready() && $this->canRequest($actor), 403);
        if (in_array($case->status, ['resolved', 'closed'], true)) {
            throw ValidationException::withMessages(['remoteSupport' => 'Der Fall ist abgeschlossen.']);
        }
        $client = $this->clientFor($case);
        if (! $client) {
            throw ValidationException::withMessages(['remoteSupport' => 'Fernwartung braucht ein Firmengerät des Mitarbeiters mit verbundener RailTime-App.']);
        }

        return DB::transaction(function () use ($case, $actor, $client, $fileTransfer): SupportRemoteSession {
            Device::query()->whereKey($client->device_id)->lockForUpdate()->first();
            $this->expire($client->device_id);
            if (SupportRemoteSession::query()->where('device_id', $client->device_id)->whereIn('status', ['requested', 'consented'])->exists()) {
                throw ValidationException::withMessages(['remoteSupport' => 'Für dieses Gerät läuft bereits eine Anfrage oder Sitzung.']);
            }
            $session = SupportRemoteSession::query()->create(['support_case_id' => $case->id, 'device_id' => $client->device_id,
                'assignment_id' => $client->device_assignment_id, 'requested_by' => $actor->id, 'status' => 'requested',
                'file_transfer' => $fileTransfer, 'expires_at' => now()->addMinutes(self::REQUEST_MINUTES)]);
            $this->audit($session, $actor, 'requested');

            return $session;
        }, 3);
    }

    /** Für den Abgleich: offene Anfrage und laufende Sitzung dieses Geräts. */
    public function forClient(DeviceDesktopClient $client): array
    {
        if (! $this->ready()) {
            return [];
        }
        $this->expire($client->device_id);
        $sessions = SupportRemoteSession::query()->where('device_id', $client->device_id)
            ->where('assignment_id', $client->device_assignment_id)->whereIn('status', ['requested', 'consented'])
            ->orderBy('id')->limit(2)->get();
        $out = [];
        if ($requested = $sessions->firstWhere('status', 'requested')) {
            $out['request'] = ['id' => $requested->public_id, 'requested_by' => $this->displayName($requested->requested_by),
                'expires_at' => $requested->expires_at->toIso8601String(), 'file_transfer' => (bool) $requested->file_transfer];
        }
        if ($active = $sessions->firstWhere('status', 'consented')) {
            $out['active'] = ['id' => $active->public_id, 'requested_by' => $this->displayName($active->requested_by),
                'until' => $active->expires_at->toIso8601String()];
        }

        return $out;
    }

    /** Antwort vom PC: erlauben, ablehnen oder eine laufende Sitzung beenden. */
    public function answer(DeviceDesktopClient $client, string $publicId, string $decision): array
    {
        abort_unless($this->ready(), 503);
        abort_unless(in_array($decision, ['granted', 'declined', 'ended'], true), 422);
        $session = SupportRemoteSession::query()->where('public_id', $publicId)->where('device_id', $client->device_id)
            ->where('assignment_id', $client->device_assignment_id)->lockForUpdate()->first();
        abort_unless($session !== null, 404);
        $live = $session->expires_at->isFuture();
        match (true) {
            $decision === 'granted' && $session->status === 'requested' && $live => $session->forceFill(['status' => 'consented',
                'consented_at' => now(), 'expires_at' => now()->addMinutes(self::SESSION_MINUTES)])->save(),
            $decision === 'declined' && $session->status === 'requested' => $session->forceFill(['status' => 'declined', 'ended_at' => now()])->save(),
            $decision === 'ended' && $session->status === 'consented' => $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save(),
            default => abort(409, 'Diese Anfrage ist nicht mehr offen.'),
        };
        $this->audit($session, null, $session->status, ['by' => 'employee']);

        return ['id' => $session->public_id, 'status' => $session->status];
    }

    public function end(SupportRemoteSession $session, User $actor): void
    {
        abort_unless($this->canRequest($actor), 403);
        DB::transaction(function () use ($session, $actor): void {
            $locked = SupportRemoteSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($locked->status, ['requested', 'consented'], true)) {
                return;
            }
            $locked->forceFill(['status' => $locked->status === 'requested' ? 'cancelled' : 'ended', 'ended_at' => now()])->save();
            $this->audit($locked, $actor, $locked->status, ['by' => 'support']);
        });
    }

    /** Link zum Fernwartungsdienst – nur solange die Zustimmung gilt. */
    public function remoteUrl(SupportRemoteSession $session, User $actor): ?string
    {
        if (! $this->canRequest($actor) || $session->status !== 'consented' || $session->expires_at->isPast() || $session->ended_at !== null) {
            return null;
        }
        $device = Device::query()->find($session->device_id);
        if (! $device) {
            return null;
        }
        try {
            foreach (app(DeviceProviderRegistry::class)->enabled() as $provider) {
                if (($provider->capabilities()['remote_support'] ?? false) && $provider->supportsPlatform($device->platform->value)
                    && ($url = $provider->remoteSupportUrl($device))) {
                    return $url;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** Zeitablauf ist ein belegtes Ende. */
    private function expire(int $deviceId): void
    {
        SupportRemoteSession::query()->where('device_id', $deviceId)->whereIn('status', ['requested', 'consented'])
            ->where('expires_at', '<=', now())->get()->each(function (SupportRemoteSession $session): void {
                $session->forceFill(['status' => $session->status === 'requested' ? 'expired' : 'ended', 'ended_at' => now()])->save();
                $this->audit($session, null, $session->status, ['by' => 'timeout']);
            });
    }

    private function displayName(int $userId): string
    {
        $name = (string) User::query()->whereKey($userId)->value('name');

        return mb_substr($name !== '' ? $name : 'IT-Support', 0, 80);
    }

    private function audit(SupportRemoteSession $session, ?User $actor, string $event, array $properties = []): void
    {
        $log = activity('device-management')->performedOn($session)->event('remote-support.'.$event)
            ->withProperties(['session_id' => $session->public_id, 'device_id' => $session->device_id] + $properties);
        if ($actor) {
            $log->causedBy($actor);
        }
        $log->log('Fernwartung '.$event);
    }
}
