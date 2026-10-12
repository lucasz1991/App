<?php

namespace App\Services\EmployeeLocation;

use App\Models\BrowserLocationPoint;
use App\Models\SessionPermissionGrant;
use App\Models\Setting;
use App\Models\User;
use App\Services\Privacy\SessionPermissionService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BrowserLocationService
{
    public function ready(): bool
    {
        return EmployeeLocationSettings::ready() && Schema::hasTable('browser_location_points');
    }

    public function status(User $user): array
    {
        $settings = EmployeeLocationSettings::read();
        $enabled = $this->ready() && $settings['enabled'] && (int) $settings['explanation_version'] >= 3;
        $grant = $enabled ? app(SessionPermissionService::class)->current($user) : null;
        $consented = $grant && app(SessionPermissionService::class)->active($grant, 'location');
        $point = $consented ? BrowserLocationPoint::where('session_permission_grant_id', $grant->id)->where('user_id', $user->id)->where('measured_at', '>', $this->now()->subMinutes(15)->format('Y-m-d H:i:s.u'))->first() : null;

        return ['enabled' => $enabled && $user->isActive() && OperationsAccess::isEmployee($user), 'consented' => (bool) $consented,
            'reason' => ! $enabled ? 'disabled' : (! $consented ? 'consent_missing' : null),
            'last_point_at' => $point?->measured_at->toISOString(),
            'accuracy_m' => $point?->accuracy_m,
            'next_capture_at' => $point ? $point->received_at->addSeconds(60)->toISOString() : $this->now()->toISOString(),
            'server_time' => $this->now()->toISOString()];
    }

    public function capture(User $user, array $input): array
    {
        $data = Validator::make($input, [
            'event_id' => 'required|uuid', 'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180', 'accuracy_m' => 'required|numeric|between:0,50',
            'measured_at' => 'required|date',
        ])->validate();
        abort_unless($this->ready(), 503, 'Standortfunktion ist noch nicht eingerichtet.');

        return DB::transaction(function () use ($user, $data): array {
            // Same lock order as global disable, consent withdrawal and native work-location capture.
            $setting = Setting::where('type', EmployeeLocationSettings::GROUP)->where('key', EmployeeLocationSettings::KEY)->lockForUpdate()->first();
            $settings = array_replace(EmployeeLocationSettings::DEFAULTS, (array) $setting?->value);
            abort_unless($settings['enabled'] && (int) $settings['explanation_version'] >= 3, 403, 'Standortfunktion ist deaktiviert oder die neue Erklärung fehlt.');
            $user = User::lockForUpdate()->findOrFail($user->id);
            $current = app(SessionPermissionService::class)->current($user);
            $grant = $current ? SessionPermissionGrant::lockForUpdate()->find($current->id) : null;
            abort_unless($grant && app(SessionPermissionService::class)->active($grant, 'location'), 403, 'Standortfreigabe für diese Anmeldung fehlt.');
            $point = BrowserLocationPoint::where('session_permission_grant_id', $grant->id)->lockForUpdate()->first();
            $measured = CarbonImmutable::parse($data['measured_at'])->utc();
            $eventHash = hash_hmac('sha256', json_encode([(float) $data['latitude'], (float) $data['longitude'], (float) $data['accuracy_m'], $measured->toISOString()]), (string) config('app.key'));
            if ($point?->event_id === $data['event_id']) {
                abort_unless(hash_equals($point->event_hash, $eventHash), 409, 'Wiederholung enthält geänderte Messdaten.');

                return $this->response($point, true, false);
            }
            abort_unless($measured->gte($this->now()->subSeconds(30)) && $measured->lte($this->now()->addSeconds(5))
                && $grant->location_decided_at && $measured->gte($grant->location_decided_at), 422, 'Messung ist veraltet oder liegt außerhalb der Freigabe.');
            if ($point && $point->received_at->addSeconds(60)->gt($this->now())) {
                return $this->response($point, false, false);
            }
            abort_unless(! $point || $measured->gt($point->measured_at), 422, 'Eine neuere Position liegt bereits vor.');
            $point ??= new BrowserLocationPoint;
            $point->forceFill(['user_id' => $user->id, 'session_permission_grant_id' => $grant->id,
                'position' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']],
                'accuracy_m' => (float) $data['accuracy_m'], 'measured_at' => $measured, 'received_at' => $this->now(),
                'event_id' => $data['event_id'], 'event_hash' => $eventHash])->save();

            return $this->response($point, false, true);
        }, 3);
    }

    /** Internal batched reader. Caller supplies only personnel IDs it has already authorized. */
    public function latestForMany(Collection $allowedIds): array
    {
        return array_filter($this->latestDetailedForMany($allowedIds), fn (array $result): bool => $result['status'] === 'available');
    }

    /** Unavailable results never expose coordinates, including expired encrypted points. */
    public function latestDetailedForMany(Collection $allowedIds): array
    {
        $unavailable = static fn (string $status, string $reason): array => ['status' => $status, 'reason' => $reason, 'point' => null];
        $settings = EmployeeLocationSettings::read();
        if (! $this->ready() || ! $settings['enabled'] || (int) $settings['explanation_version'] < 3) {
            return $allowedIds->mapWithKeys(fn ($id) => [$id => $unavailable('disabled', 'App-Standorte sind deaktiviert.')])->all();
        }
        if ($allowedIds->isEmpty()) {
            return [];
        }
        $now = $this->now();
        $result = $allowedIds->mapWithKeys(fn ($id) => [$id => $unavailable('consent_missing', 'Aktuelle Standortfreigabe für eine aktive Anmeldung fehlt.')])->all();
        $grants = SessionPermissionGrant::whereIn('user_id', $allowedIds)->where('location_decision', 'accepted')
            ->whereNull('ended_at')->where('expires_at', '>', $now)->get()->keyBy('id');
        $active = app(SessionPermissionService::class)->activeMany($grants, 'location');
        $grants = $grants->filter(fn ($grant) => $active[$grant->id] ?? false);
        foreach ($grants as $grant) {
            $result[$grant->user_id] = $unavailable('no_point', 'Freigabe vorhanden; noch kein geeigneter App-Standort empfangen.');
        }
        $points = BrowserLocationPoint::whereIn('session_permission_grant_id', $grants->keys())->whereIn('user_id', $allowedIds)
            ->latest('measured_at')->orderByDesc('id')->get();
        $diagnosed = [];
        foreach ($points as $point) {
            $grant = $grants->get($point->session_permission_grant_id);
            if (($result[$point->user_id]['status'] ?? null) === 'available' || ! $grant || $grant->user_id !== $point->user_id
                || ! $grant->location_decided_at || $point->measured_at->lt($grant->location_decided_at)) {
                continue;
            }
            $failure = null;
            if ($point->measured_at->lte($now->subMinutes(15))) {
                $failure = $unavailable('stale', 'Letzter App-Standort ist mindestens 15 Minuten alt.');
            } elseif ($point->measured_at->gt($now->addSeconds(5))) {
                $failure = $unavailable('invalid_point', 'Messzeit des letzten App-Standorts ist ungültig.');
            } elseif ($point->accuracy_m < 0 || $point->accuracy_m > 50) {
                $failure = $unavailable('inaccurate', 'Letzter App-Standort ist nicht präzise genug (höchstens 50 Meter).');
            }
            $position = null;
            if ($failure === null) {
                try {
                    $position = $point->position;
                    if (! is_array($position) || ! isset($position['latitude'], $position['longitude'])
                        || ! is_numeric($position['latitude']) || ! is_numeric($position['longitude'])
                        || ! is_finite((float) $position['latitude']) || ! is_finite((float) $position['longitude'])
                        || abs((float) $position['latitude']) > 90 || abs((float) $position['longitude']) > 180) {
                        $failure = $unavailable('invalid_point', 'Letzter App-Standort ist nicht verwendbar.');
                    }
                } catch (\Throwable) {
                    $failure = $unavailable('invalid_point', 'Letzter App-Standort ist nicht verwendbar.');
                }
            }
            if ($failure !== null) {
                if (! isset($diagnosed[$point->user_id])) {
                    $result[$point->user_id] = $failure;
                    $diagnosed[$point->user_id] = true;
                }

                continue;
            }
            $result[$point->user_id] = ['status' => 'available', 'reason' => null, 'point' => [
                'latitude' => (float) $position['latitude'], 'longitude' => (float) $position['longitude'],
                'accuracy_m' => $point->accuracy_m, 'measured_at' => $point->measured_at->toISOString(), 'source' => 'browser',
            ]];
        }

        return $result;
    }

    public function forgetCurrent(User $user): void
    {
        if (! $this->ready()) {
            return;
        }
        DB::transaction(function () use ($user): void {
            User::lockForUpdate()->findOrFail($user->id);
            $grant = app(SessionPermissionService::class)->current($user);
            if ($grant) {
                $this->forgetForGrant($grant->id);
            }
        }, 3);
    }

    public function forgetForUser(int $userId): void
    {
        if (Schema::hasTable('browser_location_points')) {
            BrowserLocationPoint::where('user_id', $userId)->delete();
        }
    }

    public function forgetForGrant(int $grantId): void
    {
        if (Schema::hasTable('browser_location_points')) {
            BrowserLocationPoint::where('session_permission_grant_id', $grantId)->delete();
        }
    }

    public function purge(): int
    {
        if (! Schema::hasTable('browser_location_points')) {
            return 0;
        }
        $cutoff = $this->now()->subMinutes(15)->format('Y-m-d H:i:s.u');
        $count = 0;
        do {
            $ids = BrowserLocationPoint::where('measured_at', '<=', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            // Capture replaces the same row. A fresh replacement after this
            // selection must survive the cleanup of its previous expired value.
            $deleted = $ids->isEmpty() ? 0 : BrowserLocationPoint::whereIn('id', $ids)->where('measured_at', '<=', $cutoff)->delete();
            $count += $deleted;
        } while ($ids->count() === 1000);

        return $count;
    }

    private function response(BrowserLocationPoint $point, bool $duplicate, bool $stored): array
    {
        return ['accepted' => true, 'duplicate' => $duplicate, 'stored' => $stored,
            'next_capture_at' => $point->received_at->addSeconds(60)->toISOString(), 'server_time' => $this->now()->toISOString()];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
