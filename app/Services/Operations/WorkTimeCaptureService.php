<?php

namespace App\Services\Operations;

use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeCaptureDevice;
use App\Models\WorkTimeCaptureReceipt;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeEvent;
use App\Services\Routing\TravelAssessmentService;
use App\Services\Routing\TravelPreflight;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkTimeCaptureService
{
    public function bootstrap(User $actor, ?string $deviceId, array $eventKeys = []): array
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        Validator::make(['device_id' => $deviceId, 'event_keys' => $eventKeys], ['device_id' => 'nullable|uuid', 'event_keys' => 'array|max:500', 'event_keys.*' => 'uuid|distinct'])->validate();
        $device = $deviceId ? WorkTimeCaptureDevice::where('user_id', $actor->id)->where('public_id', $deviceId)->whereNull('revoked_at')->first() : null;
        abort_if($deviceId && ! $device, 403, 'Erfassungsgerät nicht mehr freigegeben. Gespeicherte Erfassungen bleiben erhalten.');
        if (! $device) {
            $device = WorkTimeCaptureDevice::create(['user_id' => $actor->id, 'public_id' => (string) Str::uuid(), 'encryption_key' => base64_encode(random_bytes(32))]);
        }
        $active = WorkTimeEntry::where('user_id', $actor->id)->whereIn('status', ['running', 'paused'])->first();
        if ($active && ! $active->capture_id) {
            // Assign a secondary client correlation ID; the historical entry ID/snapshot is unchanged.
            OperationsTransaction::run(function () use ($active, $actor) {
                User::lockForUpdate()->findOrFail($actor->id);
                $entry = WorkTimeEntry::lockForUpdate()->findOrFail($active->id);
                if (! $entry->capture_id) {
                    $entry->forceFill(['capture_id' => (string) Str::uuid()])->save();
                }
            }, 3);
            $active->refresh();
        }

        return ['schema_version' => 2, 'actor_id' => $actor->id, 'device_id' => $device->public_id, 'key' => $device->encryption_key, 'last_sequence' => $device->last_sequence, 'server_time' => now()->utc()->toIso8601String(), 'active' => $active ? $this->projection($active) : null,
            'receipts' => $eventKeys ? WorkTimeCaptureReceipt::where('work_time_capture_device_id', $device->id)->whereIn('event_key', $eventKeys)->get()->map(fn ($receipt) => $this->receipt($receipt))->all() : [],
            'reviewed_event_keys' => WorkTimeCaptureReceipt::where('work_time_capture_device_id', $device->id)->where('status', 'reviewed')->latest('id')->limit(500)->pluck('event_key')->all()];
    }

    public function ingest(User $actor, string $deviceId, array $events, array $travelPreflights = []): array
    {
        OperationsAccess::own($actor->fresh(), $actor->id);
        WorkTimeSchema::requireReady();
Validator::make(compact('deviceId', 'events'), ['deviceId' => 'required|uuid', 'events' => 'required|array|min:1|max:50', 'events.*.event_key' => 'required|uuid|distinct', 'events.*.sequence' => 'required|integer|min:1|distinct', 'events.*.action' => 'required|in:start,start_day,start_duty,attach_duty,pause,resume,stop,submit,activity', 'events.*.correct_activity' => 'sometimes|boolean', 'events.*.kind' => 'nullable|in:'.implode(',', array_diff(array_keys(WorkTimeActivityService::KINDS), ['break'])), 'events.*.entry_capture_id' => 'required|uuid', 'events.*.revision' => 'required|integer|min:0', 'events.*.occurred_at' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'], 'events.*.timezone' => 'required|timezone', 'events.*.offset_minutes' => 'required|integer|min:-840|max:840', 'events.*.assignment_id' => 'nullable|integer|min:1', 'events.*.plan_revision' => 'nullable|integer|min:1', 'events.*.work_context' => 'nullable|in:shift,internal,training,unplanned', 'events.*.title' => 'nullable|string|max:180', 'events.*.order_id' => 'nullable|integer|min:1', 'events.*.training_session_id' => 'nullable|integer|min:1'])->validate();
        abort_if(strlen(json_encode($events)) > 65536, 413);
        $results = [];
        foreach ($events as $event) {
            $event = array_intersect_key($event, array_flip(['event_key', 'sequence', 'action', 'kind', 'correct_activity', 'entry_capture_id', 'revision', 'occurred_at', 'timezone', 'offset_minutes', 'assignment_id', 'plan_revision', 'work_context', 'title', 'order_id', 'training_session_id']));
            $travel = $travelPreflights[$event['event_key']] ?? null;
            if (! $travel instanceof TravelPreflight) {
                $travel = null;
            }
            $currentDevice = WorkTimeCaptureDevice::where('public_id', $deviceId)->where('user_id', $actor->id)->whereNull('revoked_at')->first();
            abort_unless($currentDevice, 403, 'Erfassungsgerät nicht mehr freigegeben.');
            $startsDuty = in_array($event['action'], ['start_duty', 'attach_duty'], true)
                || ($event['action'] === 'start' && ($event['work_context'] ?? 'shift') === 'shift');
            if (! $travel && $startsDuty && ! empty($event['assignment_id'])
                && (int) $event['sequence'] === (int) $currentDevice->last_sequence + 1 && ! WorkTimeCaptureReceipt::where('event_key', $event['event_key'])->exists()) {
                $assignment = ShiftAssignment::where('user_id', $actor->id)->with('shift')->find($event['assignment_id']);
                if ($assignment?->shift) {
                    $travel = app(TravelAssessmentService::class)->preflight($assignment->shift, collect([$actor]));
                }
            }
            $results[] = OperationsTransaction::run(function () use ($actor, $deviceId, $event, $travel, $startsDuty) {
                $device = WorkTimeCaptureDevice::where('public_id', $deviceId)->where('user_id', $actor->id)->whereNull('revoked_at')->lockForUpdate()->first();
                abort_unless($device, 403, 'Erfassungsgerät nicht mehr freigegeben.');
                ksort($event);
                $hash = hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $receipt = WorkTimeCaptureReceipt::where('event_key', $event['event_key'])->first();
                if ($receipt) {
                    abort_unless($receipt->work_time_capture_device_id === $device->id, 409, 'Ereignisschlüssel gehört zu einer anderen Sitzung.');
                    if ($receipt->payload_hash !== $hash) {
                        return ['event_key' => $event['event_key'], 'status' => 'conflict', 'reason' => 'Wiederholung enthält geänderte Daten.'];
                    }

                    return $this->receipt($receipt);
                }
                if ($event['sequence'] !== $device->last_sequence + 1) {
                    return ['event_key' => $event['event_key'], 'status' => 'awaiting', 'reason' => 'Vorherige Ereignisse zuerst synchronisieren.'];
                }
                $at = CarbonImmutable::parse($event['occurred_at'])->utc();
                $entry = null;
                $status = 'accepted';
                $reason = null;
                try {
                    if (WorkTimeEvent::where('event_key', $event['event_key'])->exists()) {
                        throw ValidationException::withMessages(['workflow' => 'Ereignisschlüssel wurde bereits außerhalb dieser Erfassung verwendet.']);
                    }
                    if ($at->gt(now()->utc()->addMinute()) || $at->lt($device->created_at->utc()->subMinute()) || $at->lt(now()->utc()->subDays(7))
                        || (int) ($at->setTimezone($event['timezone'])->offset / 60) !== (int) $event['offset_minutes']) {
                        throw ValidationException::withMessages(['workflow' => 'Erfassungszeit oder Zeitzonenversatz muss geprüft werden.']);
                    }
                    $service = app(WorkTimeService::class);
                    $startsEntry = in_array($event['action'], ['start', 'start_day', 'start_duty'], true);
                    if ($startsEntry) {
                        if ($event['revision'] !== 0 || WorkTimeEntry::where('capture_id', $event['entry_capture_id'])->exists()) {
                            throw ValidationException::withMessages(['workflow' => 'Start wurde bereits zugeordnet.']);
                        }
                    } else {
                        $entry = WorkTimeEntry::where('capture_id', $event['entry_capture_id'])->where('user_id', $actor->id)->firstOrFail();
                    }
                    if ($startsDuty && (empty($event['assignment_id']) || empty($event['plan_revision']))) {
                        throw ValidationException::withMessages(['workflow' => 'Dienstbezug fehlt.']);
                    }
                    $owner = $actor->fresh();
                    $key = $event['event_key'];
                    $revision = (int) $event['revision'];
                    $entry = match ($event['action']) {
                        'start_day' => $service->startDay($key, $owner, $event['timezone'], $at, requireNewEvent: true),
                        'start_duty', 'attach_duty' => $service->startDuty(
                            (int) $event['assignment_id'], (int) $event['plan_revision'], $key, $owner, $at,
                            expectedEntryId: $entry?->id, expectedRevision: $startsEntry ? null : $revision,
                            mustStartNew: $startsEntry, requireNewEvent: true, travel: $travel
                        ),
                        'start' => $startsDuty
                            ? $service->start((int) $event['assignment_id'], (int) $event['plan_revision'], $key, $owner, $at, null, true, $travel)
                            : $service->startContext($event, $key, $owner, $at, true),
                        'activity' => app(WorkTimeActivityService::class)->change($entry->id, $revision, (string) ($event['kind'] ?? ''), $key, $owner, $at, true, (bool) ($event['correct_activity'] ?? false)),
                        default => $service->clock($entry->id, $revision, $event['action'], $key, $owner, $at, null, true),
                    };
                    if ($startsEntry) {
                        $entry->forceFill(['capture_id' => $event['entry_capture_id'], 'source' => 'session_capture'])->save();
                    }
                } catch (ValidationException $exception) {
                    $status = 'conflict';
                    $reason = collect($exception->errors())->flatten()->first();
                    if (is_string($reason) && str_starts_with($reason, 'Fahrzeit, Reserve und Ruhezeit')) {
                        $reason = 'Fahrtkonflikt erkannt. Bitte die Disposition prüfen lassen.';
                    }
                } catch (HttpException $exception) {
                    $status = 'conflict';
                    $reason = $exception->getStatusCode() === 403 ? 'Berechtigung oder Freigabe geändert.' : 'Zuordnung oder Zeitstand muss geprüft werden.';
                } catch (ModelNotFoundException $exception) {
                    $status = 'conflict';
                    $reason = 'Dienst, Schulung oder Zeitmeldung nicht mehr verfügbar.';
                }
                $receipt = WorkTimeCaptureReceipt::create(['work_time_capture_device_id' => $device->id, 'event_key' => $event['event_key'], 'sequence' => $event['sequence'], 'payload_hash' => $hash, 'payload' => $event, 'status' => $status, 'reason' => $reason,
                    'work_time_entry_id' => $status === 'accepted' ? $entry?->id : null, 'applied_revision' => $status === 'accepted' ? $entry?->revision : null, 'occurred_at' => $at, 'received_at' => now()->utc()]);
                $device->update(['last_sequence' => $event['sequence']]);

                return $this->receipt($receipt);
            }, 3);
        }

        return ['schema_version' => 2, 'results' => $results];
    }

    private function receipt(WorkTimeCaptureReceipt $receipt): array
    {
        return ['event_key' => $receipt->event_key, 'status' => $receipt->status, 'reason' => $receipt->reason, 'entry_id' => $receipt->work_time_entry_id, 'revision' => $receipt->applied_revision];
    }

    public function projection(WorkTimeEntry $entry): array
    {
        $dayClock = (bool) ($entry->plan_snapshot['day_clock'] ?? false);
        $dutyStarts = $dayClock ? $entry->dutyStarts()->get()->sortBy('occurred_at')->values() : collect();
        $lastDuty = $dutyStarts->last();
        $activeDuty = $lastDuty && in_array($entry->status, ['running', 'paused'], true) ? [
            'assignment_id' => (int) $lastDuty->data['assignment_id'],
            'title' => $lastDuty->data['title'],
            'started_at' => $lastDuty->occurred_at->toIso8601String(),
        ] : null;
        $picker = app(WorkTimeActivityService::class)->pickerState($entry, $activeDuty['assignment_id'] ?? null);

        return ['id' => $entry->id, 'capture_id' => $entry->capture_id, 'revision' => $entry->revision, 'status' => $entry->status, 'work_context' => $entry->work_context, 'title' => $entry->plan_snapshot['title'] ?? $entry->contextLabel(), 'starts_at' => $entry->starts_at->toIso8601String(), 'ends_at' => $entry->ends_at?->toIso8601String(), 'paused_at' => $entry->paused_at?->toIso8601String(), 'timezone' => $entry->timezone, 'pause_seconds' => $entry->pause_seconds, 'net_seconds' => $entry->netSeconds(), 'day_clock' => $dayClock, 'active_duty' => $activeDuty,
            'duty_assignment_ids' => $dutyStarts->pluck('data.assignment_id')->map(fn ($id) => (int) $id)->all()] + $picker;
    }
}
