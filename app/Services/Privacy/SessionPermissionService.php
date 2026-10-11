<?php

namespace App\Services\Privacy;

use App\Models\EmployeeLocationConsent;
use App\Models\SessionPermissionGrant;
use App\Models\User;
use App\Services\EmployeeLocation\EmployeeLocationService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use App\Support\Operations\OperationsAccess;
use App\Support\Privacy\SessionLifetimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SessionPermissionService
{
    private const SESSION_KEY = 'railtime.permission_grant';

    public function ready(): bool
    {
        return Schema::hasTable('session_permission_grants');
    }

    public function current(User $user): ?SessionPermissionGrant
    {
        if (! $this->ready() || ! $this->ownsSession($user)) {
            return null;
        }
        $uuid = request()->session()->get(self::SESSION_KEY);
        if (! is_string($uuid)) {
            return null;
        }
        $grant = SessionPermissionGrant::where('uuid', $uuid)->where('user_id', $user->id)->first();

        return $grant && $this->isCurrent($grant, $user) ? $grant : null;
    }

    /** Called only for actual authenticated web interaction, never by native polling. */
    public function touch(User $user): ?SessionPermissionGrant
    {
        if (! $this->ready() || ! $this->ownsSession($user) || ! $user->isActive()) {
            return null;
        }

        return DB::transaction(function () use ($user): SessionPermissionGrant {
            $user = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($user->isActive(), 403);
            $uuid = request()->session()->get(self::SESSION_KEY);
            $grant = is_string($uuid) ? SessionPermissionGrant::where('uuid', $uuid)->where('user_id', $user->id)->lockForUpdate()->first() : null;
            $now = $this->now();
            if (! $grant || ! $this->isCurrent($grant, $user)) {
                if ($grant && ! $grant->ended_at) {
                    $grant->forceFill(['ended_at' => $now, 'session_identifier' => null])->save();
                }
                // Parallel first requests can carry independent copies of the same empty session.
                $grant = SessionPermissionGrant::where('user_id', $user->id)->where('session_hash', $this->sessionHash())
                    ->whereNull('ended_at')->where('expires_at', '>', $now->format('Y-m-d H:i:s.u'))->lockForUpdate()->latest('id')->first()
                    ?? SessionPermissionGrant::create([
                        'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
                        'session_hash' => $this->sessionHash(), 'session_identifier' => request()->session()->getId(),
                        'last_activity_at' => $now, 'expires_at' => $now->addMinutes(SessionLifetimePolicy::minutes($user)),
                    ]);
                request()->session()->put(self::SESSION_KEY, $grant->uuid);
            } else {
                $grant->forceFill(['last_activity_at' => $now, 'expires_at' => $now->addMinutes(SessionLifetimePolicy::minutes($user))])->save();
                if (Schema::hasColumn('employee_location_consents', 'session_permission_grant_id')) {
                    EmployeeLocationConsent::where('session_permission_grant_id', $grant->id)->whereNull('withdrawn_at')->update(['expires_at' => $grant->expires_at]);
                }
            }

            return $grant;
        }, 3);
    }

    public function decide(User $user, string $purpose, bool $accepted, int $version = 1): SessionPermissionGrant
    {
        $this->assertPurpose($purpose);
        if ($purpose === 'location') {
            app(EmployeeLocationService::class)->decide($user, $accepted, $version);

            return $this->current($user) ?? abort(403, 'Anmeldesitzung ist abgelaufen.');
        }

        return $this->recordDecision($user, $purpose, $accepted, $version);
    }

    /** Internal writer shared with the auditable location service. */
    public function recordDecision(User $user, string $purpose, bool $accepted, int $version): SessionPermissionGrant
    {
        $this->assertPurpose($purpose);
        abort_unless($this->ready() && $this->ownsSession($user), 403, 'Eine aktive Anmeldesitzung ist erforderlich.');

        return DB::transaction(function () use ($user, $purpose, $accepted, $version): SessionPermissionGrant {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $grant = $this->current($user);
            abort_unless($grant && $user->isActive(), 403, 'Anmeldesitzung ist abgelaufen. Bitte neu anmelden.');
            $grant = SessionPermissionGrant::lockForUpdate()->findOrFail($grant->id);
            abort_unless($this->isCurrent($grant, $user), 403);
            $expected = $purpose === 'location' ? (int) EmployeeLocationSettings::read()['explanation_version'] : 1;
            abort_unless($version === $expected, 409, 'Die Erklärung wurde geändert. Bitte erneut lesen.');
            if ($grant->{$purpose.'_decision'} === ($accepted ? 'accepted' : 'declined') && $grant->{$purpose.'_version'} === $version) {
                return $grant;
            }
            $grant->forceFill([$purpose.'_decision' => $accepted ? 'accepted' : 'declined', $purpose.'_version' => $version, $purpose.'_decided_at' => $this->now()])->save();

            return $grant;
        }, 3);
    }

    public function revoke(User $user, string $purpose): void
    {
        $this->assertPurpose($purpose);
        if ($purpose === 'location') {
            app(EmployeeLocationService::class)->withdraw($user);
        } else {
            $this->recordDecision($user, 'push', false, 1);
        }
    }

    public function end(User $user): void
    {
        if (! $this->ready()) {
            return;
        }
        $uuid = request()->hasSession() ? request()->session()->get(self::SESSION_KEY) : null;
        $query = SessionPermissionGrant::where('user_id', $user->id)->whereNull('ended_at');
        if (is_string($uuid)) {
            $query->where('uuid', $uuid);
        }
        $query->update(['ended_at' => $this->now(), 'session_identifier' => null]);
        if (request()->hasSession()) {
            request()->session()->forget(self::SESSION_KEY);
        }
        app(EmployeeLocationService::class)->stopForUser((int) $user->id);
    }

    public function purge(): int
    {
        if (! $this->ready()) {
            return 0;
        }

        // Keep permission/audit references while removing the no longer needed encrypted session credential.
        return SessionPermissionGrant::whereNotNull('session_identifier')
            ->where(fn ($query) => $query->whereNotNull('ended_at')->orWhere('expires_at', '<=', $this->now()->format('Y-m-d H:i:s.u')))
            ->update(['session_identifier' => null]);
    }

    /** Read-only validation for native APIs, route readers and queued push delivery. */
    public function active(SessionPermissionGrant $grant, string $purpose): bool
    {
        return $this->activeMany(collect([$grant]), $purpose)[$grant->id] ?? false;
    }

    public function activeMany(Collection $grants, string $purpose): array
    {
        $this->assertPurpose($purpose);
        // Callers may hold relations loaded before a concurrent profile revocation.
        $grants = SessionPermissionGrant::whereIn('id', $grants->pluck('id')->unique())->get();
        $now = $this->now();
        $users = User::whereIn('id', $grants->pluck('user_id')->unique())->get()->keyBy('id');
        $settings = $purpose === 'location' ? EmployeeLocationSettings::read() : [];
        $database = config('session.driver') === 'database';
        $identifiers = [];
        if ($database) {
            foreach ($grants as $grant) {
                try {
                    $identifiers[$grant->id] = $grant->session_identifier;
                } catch (Throwable) {
                    // Corrupt or unavailable session credentials fail closed, without payload logging.
                }
            }
        }
        $sessions = collect();
        if ($database && $identifiers !== []) {
            try {
                $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->whereIn('id', array_values($identifiers))->get(['id', 'user_id', 'last_activity'])->keyBy('id');
            } catch (Throwable) {
                // A deleted/failed session store never permits native collection or queued push.
            }
        }
        $result = [];
        foreach ($grants as $grant) {
            $user = $users->get($grant->user_id);
            $accepted = $user?->isActive() && ! $grant->ended_at && $grant->expires_at?->gt($now) && $grant->{$purpose.'_decision'} === 'accepted';
            if ($purpose === 'location') {
                $accepted = $accepted && $settings['enabled'] && OperationsAccess::isEmployee($user) && $grant->location_version === (int) $settings['explanation_version'];
            } else {
                $accepted = $accepted && $grant->push_version === 1;
            }
            if ($accepted && $database) {
                // A newly created current web session is saved by StartSession after rendering.
                $currentHolder = $this->ownsSession($user) && hash_equals($grant->session_hash, $this->sessionHash());
                $session = $sessions->get($identifiers[$grant->id] ?? '');
                $accepted = $currentHolder || ($session && (int) $session->user_id === (int) $user->id
                    && (int) $session->last_activity > $now->subMinutes(SessionLifetimePolicy::minutes($user))->getTimestamp());
            }
            $result[$grant->id] = (bool) $accepted;
        }

        return $result;
    }

    public function snapshot(User $user): array
    {
        $grant = $this->current($user);
        $settings = EmployeeLocationSettings::read();
        $locationEnabled = $this->ready() && EmployeeLocationSettings::ready() && $settings['enabled'] && OperationsAccess::isEmployee($user);
        $pushEnabled = $this->ready() && (bool) config('webpush.enabled');
        $push = $grant?->push_version === 1 ? $grant->push_decision : null;
        $location = $grant?->location_version === (int) $settings['explanation_version'] ? $grant->location_decision : null;

        return ['needs_decision' => ($pushEnabled && $push === null) || ($locationEnabled && $location === null),
            'push_enabled' => $pushEnabled, 'location_enabled' => $locationEnabled,
            'push_decision' => $push, 'location_decision' => $location,
            'expires_at' => $grant?->expires_at->toISOString(), 'push_version' => 1,
            'location_version' => (int) $settings['explanation_version'], 'location_text' => $settings['explanation_text'],
            'session_lifetime_minutes' => SessionLifetimePolicy::minutes($user)];
    }

    private function ownsSession(User $user): bool
    {
        return request()->hasSession() && (int) auth('web')->id() === (int) $user->id && request()->session()->getId() !== '';
    }

    private function isCurrent(SessionPermissionGrant $grant, User $user): bool
    {
        return ! $grant->ended_at && $grant->expires_at->gt($this->now()) && (int) $grant->user_id === (int) $user->id
            && hash_equals($grant->session_hash, $this->sessionHash());
    }

    private function sessionHash(): string
    {
        return hash_hmac('sha256', request()->session()->getId(), (string) config('app.key'));
    }

    private function assertPurpose(string $purpose): void
    {
        abort_unless(in_array($purpose, ['push', 'location'], true), 422, 'Unbekannter Freigabezweck.');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
