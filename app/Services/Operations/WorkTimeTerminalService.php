<?php

namespace App\Services\Operations;

use App\Models\OperationsTerminalProfile;
use App\Models\OperationsTerminalSession;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WorkTimeTerminalService
{
    public function configure(User $subject, array $data, User $actor): OperationsTerminalProfile
    {
        OperationsEnhancementsSchema::requireReady();
        if ($subject->id === $actor->id) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            app(PersonnelScopeService::class)->authorize($actor, $subject, 'operations.terminal.manage');
        }
        $data = Validator::make($data, ['pin' => 'required|string|regex:/^[0-9]{6,10}$/', 'terminal_id' => 'required|uuid', 'location_consent' => 'required|boolean', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'radius_metres' => 'nullable|integer|min:30|max:10000'])->validate();
        // Only the employee can give location consent; administrators cannot grant it on their behalf.
        abort_if($data['location_consent'] && $actor->id !== $subject->id, 403);
        abort_if($data['location_consent'] && (! isset($data['latitude'], $data['longitude'], $data['radius_metres'])), 422);

        return OperationsTransaction::run(function () use ($subject, $data) {
            User::lockForUpdate()->findOrFail($subject->id);
            $profile = OperationsTerminalProfile::updateOrCreate(['user_id' => $subject->id], ['pin_hash' => Hash::make($data['pin']), 'terminal_id' => $data['terminal_id'], 'location_consent' => $data['location_consent'], 'latitude' => $data['location_consent'] ? $data['latitude'] : null, 'longitude' => $data['location_consent'] ? $data['longitude'] : null, 'radius_metres' => $data['location_consent'] ? $data['radius_metres'] : null, 'consented_at' => $data['location_consent'] ? now()->utc() : null, 'revoked_at' => null]);
            OperationsTerminalSession::where('user_id', $subject->id)->update(['revoked_at' => now()->utc()]);

            return $profile;
        }, 3);
    }

    /** Returns a short-lived capability, never an authenticated ordinary application session. */
    public function authenticate(int $userId, string $pin, string $terminalId, string $sourceKey): array
    {
        OperationsEnhancementsSchema::requireReady();
        Validator::make(compact('userId', 'pin', 'terminalId'), ['userId' => 'required|integer|min:1', 'pin' => 'required|string|max:10', 'terminalId' => 'required|uuid'])->validate();
        $ipKey = 'rt-terminal-ip:'.hash('sha256', $sourceKey);
        $userKey = 'rt-terminal-user:'.$userId;
        abort_if(RateLimiter::tooManyAttempts($ipKey, 15) || RateLimiter::tooManyAttempts($userKey, 5), 429, 'Bitte später erneut versuchen.');
        RateLimiter::hit($ipKey, 300);
        RateLimiter::hit($userKey, 300);
        $actor = User::find($userId);
        $profile = OperationsTerminalProfile::where('user_id', $userId)->where('terminal_id', $terminalId)->whereNull('revoked_at')->first();
        abort_unless($actor && OperationsAccess::isEmployee($actor) && $profile && Hash::check($pin, $profile->pin_hash), 403, 'Terminalzugang nicht freigegeben.');

        return OperationsTransaction::run(function () use ($actor, $profile, $terminalId, $pin, $userKey) {
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            $profile = OperationsTerminalProfile::where('user_id', $actor->id)->where('terminal_id', $terminalId)->whereNull('revoked_at')->lockForUpdate()->find($profile->id);
            abort_unless(OperationsAccess::isEmployee($actor) && $profile && Hash::check($pin, $profile->pin_hash), 403, 'Terminalzugang nicht freigegeben.');
            $device = app(WorkTimeCaptureService::class)->bootstrap($actor, null);
            $token = Str::random(64);
            OperationsTerminalSession::create(['user_id' => $actor->id, 'operations_terminal_profile_id' => $profile->id, 'terminal_id' => $terminalId, 'device_id' => $device['device_id'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->utc()->addMinute()]);

            $active = $device['active'];
            if (! $active && ($completed = WorkTimeEntry::where('user_id', $actor->id)->where('status', 'completed')->latest('id')->first())) {
                $active = ['capture_id' => $completed->capture_id, 'revision' => $completed->revision, 'status' => $completed->status];
            }
            $choices = ShiftAssignment::where('user_id', $actor->id)->where('status', 'confirmed')->whereHas('shift', fn ($q) => $q->whereNotIn('status', ['draft', 'completed', 'cancelled'])->where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')->where('starts_at', '<=', now()->utc()->addMinutes(config('operations.clock_start_early_minutes')))->where('ends_at', '>', now()->utc()))->with('shift')->limit(20)->get()->map(fn ($a) => ['id' => $a->id, 'plan_revision' => $a->plan_revision, 'title' => $a->shift->title])->all();
            RateLimiter::clear($userKey);

            return ['token' => $token, 'expires_in' => 60, 'active' => $active ? array_intersect_key($active, array_flip(['capture_id', 'revision', 'status'])) : null, 'choices' => $choices, 'location_required' => $profile->location_consent];
        }, 3);
    }

    public function capture(string $token, string $terminalId, array $input, array $location = []): array
    {
        Validator::make(compact('token', 'terminalId') + $input, ['token' => 'required|string|size:64', 'terminalId' => 'required|uuid', 'event_key' => 'required|uuid', 'action' => 'required|in:start,pause,resume,stop,submit,activity', 'revision' => 'required|integer|min:0', 'kind' => 'nullable|string', 'assignment_id' => 'nullable|integer', 'plan_revision' => 'nullable|integer', 'work_context' => 'nullable|in:shift,internal,training,unplanned', 'title' => 'nullable|string|max:180', 'training_session_id' => 'nullable|integer'])->validate();

        return OperationsTransaction::run(function () use ($token, $terminalId, $input, $location) {
            $known = OperationsTerminalSession::where('token_hash', hash('sha256', $token))->where('terminal_id', $terminalId)->firstOrFail();
            $actor = User::lockForUpdate()->findOrFail($known->user_id);
            $session = OperationsTerminalSession::where('token_hash', hash('sha256', $token))->where('terminal_id', $terminalId)->lockForUpdate()->findOrFail($known->id);
            abort_unless(! $session->revoked_at && $session->expires_at->gt(now()->utc()), 403);
            $profile = OperationsTerminalProfile::whereNull('revoked_at')->where('terminal_id', $session->terminal_id)->lockForUpdate()->findOrFail($session->operations_terminal_profile_id);
            OperationsAccess::own($actor, $actor->id);
            $this->location($profile, $location);
            $active = WorkTimeEntry::where('user_id', $actor->id)->whereIn('status', ['running', 'paused', 'completed'])->latest('id')->first();
            abort_unless($input['action'] === 'start' || ($active && $active->capture_id), 409);
            $at = CarbonImmutable::now('UTC');
            $zone = config('operations.display_timezone', 'Europe/Berlin');
            $event = array_intersect_key($input, array_flip(['event_key', 'action', 'revision', 'kind', 'assignment_id', 'plan_revision', 'work_context', 'title', 'training_session_id'])) + ['sequence' => $session->sequence + 1, 'entry_capture_id' => $input['action'] === 'start' ? (string) Str::uuid() : $active->capture_id, 'occurred_at' => $at->toIso8601String(), 'timezone' => $zone, 'offset_minutes' => (int) ($at->setTimezone($zone)->offset / 60)];
            $receipts = app(WorkTimeCaptureService::class)->ingest($actor, $session->device_id, [$event]);
            $session->forceFill(['sequence' => $session->sequence + 1, 'revoked_at' => now()->utc()])->save();

            // No personnel/contact/plan data or encryption key is returned to a kiosk.
            return ['event_key' => $input['event_key'], 'status' => $receipts['results'][0]['status'], 'reason' => $receipts['results'][0]['reason'] ?? null];
        }, 3);
    }

    private function location(OperationsTerminalProfile $profile, array $location): void
    {
        if (! $profile->location_consent) {
            return;
        }
        $location = Validator::make($location, ['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:10000'])->validate();
        $a = deg2rad((float) $profile->latitude);
        $b = deg2rad((float) $location['latitude']);
        $delta = deg2rad((float) $location['longitude'] - (float) $profile->longitude);
        $distance = 6371000 * 2 * asin(min(1, sqrt(sin(($b - $a) / 2) ** 2 + cos($a) * cos($b) * sin($delta / 2) ** 2)));
        abort_unless($location['accuracy'] <= $profile->radius_metres && $distance + $location['accuracy'] <= $profile->radius_metres, 422, 'Standortfreigabe prüfen.');
    }
}
