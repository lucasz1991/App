<?php

namespace App\Services\Personnel;

use App\Models\SessionPermissionGrant;
use App\Models\User;
use App\Models\UserAppRuntimeReceipt;
use App\Services\Privacy\SessionPermissionService;
use App\Support\Privacy\SessionLifetimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserAppRuntimeReceiptService
{
    private const PAYLOAD_FIELDS = ['platform', 'display_mode', 'notification_permission', 'geolocation_permission'];

    public function ready(): bool
    {
        return Schema::hasColumns('user_app_runtime_receipts', ['id', 'user_id', 'session_permission_grant_id', ...self::PAYLOAD_FIELDS, 'received_at', 'created_at', 'updated_at'])
            && Schema::hasColumns('session_permission_grants', ['id', 'uuid', 'user_id', 'session_hash', 'session_identifier', 'last_activity_at', 'expires_at', 'ended_at'])
            && Schema::hasColumns('users', ['id', 'status', 'role']);
    }

    public function record(User $user, array $input): UserAppRuntimeReceipt
    {
        abort_unless($this->ready(), 503, 'App-Statusmeldungen sind noch nicht eingerichtet.');
        if (array_diff(array_keys($input), self::PAYLOAD_FIELDS) !== []) {
            throw ValidationException::withMessages(['runtime_status' => 'Eine App-Statusmeldung darf nur die vier Einrichtungszustände enthalten.']);
        }
        $data = Validator::make($input, [
            'platform' => ['required', 'string', Rule::in(['ios', 'android', 'desktop', 'unknown'])],
            'display_mode' => ['required', 'string', Rule::in(['standalone', 'browser', 'unknown'])],
            'notification_permission' => ['required', 'string', Rule::in(['granted', 'denied', 'default', 'unsupported', 'unknown'])],
            'geolocation_permission' => ['required', 'string', Rule::in(['granted', 'denied', 'prompt', 'unsupported', 'unknown'])],
        ])->validate();
        $permissions = app(SessionPermissionService::class);
        $current = $permissions->current($user);
        abort_unless($current, 403, 'Eine aktive Anmeldesitzung ist erforderlich.');

        return DB::transaction(function () use ($user, $data, $current, $permissions): UserAppRuntimeReceipt {
            $user = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($user->isActive(), 403);
            $grant = SessionPermissionGrant::lockForUpdate()->find($current->id);
            abort_unless($grant && (int) $grant->user_id === (int) $user->id && $permissions->current($user)?->id === $grant->id, 403, 'Anmeldesitzung ist abgelaufen.');

            // These are technical browser observations, never a consent, device assignment or GPS point.
            return UserAppRuntimeReceipt::updateOrCreate(['session_permission_grant_id' => $grant->id],
                $data + ['user_id' => $user->id, 'received_at' => CarbonImmutable::now('UTC')]);
        }, 3);
    }

    public function latestMobileForUser(int $userId): ?UserAppRuntimeReceipt
    {
        return $this->latestEligible($userId, ['ios', 'android']);
    }

    public function latestForUser(int $userId): ?UserAppRuntimeReceipt
    {
        return $this->latestEligible($userId, ['ios', 'android', 'desktop', 'unknown']);
    }

    public function isFresh(UserAppRuntimeReceipt $receipt): bool
    {
        $now = CarbonImmutable::now('UTC');

        return $receipt->received_at?->lte($now) && $receipt->received_at->gte($now->subMinutes(15));
    }

    /** Return a retained observation, including stale data, only while its login remains valid. */
    private function latestEligible(int $userId, array $platforms): ?UserAppRuntimeReceipt
    {
        if (! $this->ready()) {
            return null;
        }
        $user = User::find($userId);
        if (! $user?->isActive()) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        // Only a real interaction advances last_activity_at. A periodic status
        // report must not displace the device the employee actually used last.
        $query = UserAppRuntimeReceipt::query()->select('user_app_runtime_receipts.*')
            ->join('session_permission_grants as runtime_grant', 'runtime_grant.id', '=', 'user_app_runtime_receipts.session_permission_grant_id')
            ->where('user_app_runtime_receipts.user_id', $userId)->whereIn('platform', $platforms)
            ->where('received_at', '>', $now->subDays(7)->format('Y-m-d H:i:s.u'))
            ->where('received_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->where('runtime_grant.user_id', $userId)->whereNull('runtime_grant.ended_at')
            ->where('runtime_grant.expires_at', '>', $now->format('Y-m-d H:i:s.u'))
            ->where('runtime_grant.last_activity_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->with('sessionPermissionGrant')->orderByDesc('runtime_grant.last_activity_at')->orderByDesc('runtime_grant.id');
        $currentGrantId = app(SessionPermissionService::class)->current($user)?->id;
        $database = config('session.driver') === 'database';

        do {
            $batch = (clone $query)->limit(25)->get();
            if ($batch->isEmpty()) {
                return null;
            }
            $identifiers = [];
            if ($database) {
                foreach ($batch as $receipt) {
                    try {
                        $identifier = $receipt->sessionPermissionGrant?->session_identifier;
                        if (is_string($identifier) && $identifier !== '') {
                            $identifiers[$receipt->id] = $identifier;
                        }
                    } catch (Throwable) {
                        // No credential or exception payload is logged; a corrupt binding is unavailable.
                    }
                }
            }
            $sessions = collect();
            if ($database && $identifiers !== []) {
                try {
                    $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                        ->whereIn('id', array_values($identifiers))->get(['id', 'user_id', 'last_activity'])->keyBy('id');
                } catch (Throwable) {
                    return null;
                }
            }
            foreach ($batch as $receipt) {
                $grant = $receipt->sessionPermissionGrant;
                // The grant is eager-loaded separately; a concurrent logout
                // or account-session removal must not revive its observation.
                if (! $grant || $grant->ended_at || $grant->expires_at->lte($now)
                    || (int) $grant->user_id !== $userId || $grant->last_activity_at->gt($now)) {
                    continue;
                }
                if (! $database || $receipt->session_permission_grant_id === $currentGrantId) {
                    return $receipt;
                }
                $session = $sessions->get($identifiers[$receipt->id] ?? '');
                if ($session && (int) $session->user_id === $userId
                    && (int) $session->last_activity > $now->subMinutes(SessionLifetimePolicy::minutes($user))->getTimestamp()) {
                    return $receipt;
                }
            }
            $last = $batch->last();
            $lastTime = $last->sessionPermissionGrant?->last_activity_at?->format('Y-m-d H:i:s.u');
            if ($lastTime === null) {
                return null;
            }
            $query->where(fn (Builder $query) => $query->where('runtime_grant.last_activity_at', '<', $lastTime)
                ->orWhere(fn (Builder $query) => $query->where('runtime_grant.last_activity_at', $lastTime)->where('runtime_grant.id', '<', $last->session_permission_grant_id)));
        } while ($batch->count() === 25);

        return null;
    }

    public function purge(): int
    {
        if (! Schema::hasColumns('user_app_runtime_receipts', ['id', 'received_at'])) {
            return 0;
        }
        $cutoff = CarbonImmutable::now('UTC')->subDays(7)->format('Y-m-d H:i:s.u');
        $count = 0;
        do {
            $ids = UserAppRuntimeReceipt::where('received_at', '<=', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : UserAppRuntimeReceipt::whereIn('id', $ids)->delete();
            $count += $deleted;
        } while ($deleted === 1000);

        return $count;
    }
}
