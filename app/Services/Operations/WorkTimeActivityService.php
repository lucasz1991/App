<?php

namespace App\Services\Operations;

use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeEvent;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkTimeActivityService
{
    public const KINDS = ['work' => 'Arbeit', 'preparation' => 'Vorbereitung', 'driving' => 'Fahrt', 'shunting' => 'Rangieren', 'travel' => 'Anreise', 'waiting' => 'Wartezeit', 'on_call' => 'Bereitschaft', 'break' => 'Pause', 'internal' => 'Interne Arbeit', 'training' => 'Schulung'];

    public function begin(WorkTimeEntry $entry, CarbonImmutable $at): void
    {
        $entry->activities()->create(['kind' => in_array($entry->work_context, ['internal', 'training'], true) ? $entry->work_context : 'work', 'starts_at' => $at, 'is_paid' => null]);
    }

    public function clockTransition(WorkTimeEntry $entry, string $action, CarbonImmutable $at): void
    {
        $active = $entry->activities()->whereNull('ends_at')->lockForUpdate()->first();
        if ($active) {
            $active->update(['ends_at' => $at]);
        }
        if ($action !== 'stop') {
            $entry->activities()->create(['kind' => $action === 'pause' ? 'break' : (in_array($entry->work_context, ['internal', 'training'], true) ? $entry->work_context : 'work'), 'starts_at' => $at, 'is_paid' => $action === 'pause' ? $this->breakPaid($entry) : null]);
        }
    }

    public function change(int $id, int $revision, string $kind, string $eventKey, User $actor, ?CarbonImmutable $capturedAt = null, bool $requireNewEvent = false): WorkTimeEntry
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        Validator::make(compact('kind', 'eventKey'), ['kind' => 'required|in:'.implode(',', array_diff(array_keys(self::KINDS), ['break'])), 'eventKey' => 'required|uuid'])->validate();

        return OperationsTransaction::run(function () use ($id, $revision, $kind, $eventKey, $actor, $capturedAt, $requireNewEvent) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            if ($event = WorkTimeEvent::where('event_key', $eventKey)->first()) {
                abort_if($requireNewEvent, 409, 'Ereignisschlüssel wurde bereits außerhalb dieser Erfassung verwendet.');
                abort_unless($event->work_time_entry_id === $entry->id && $event->actor_id === $actor->id && $event->kind === 'activity' && ($event->data['kind'] ?? null) === $kind, 409);

                return $entry;
            }
            abort_unless($entry->revision === $revision && $entry->status === 'running', 409, 'Zeitstand wurde geändert.');
            $at = $capturedAt ?? CarbonImmutable::now('UTC');
            $latest = $entry->events()->max('occurred_at');
            abort_if($at->lt($entry->starts_at) || ($latest && $at->lt(CarbonImmutable::parse($latest, 'UTC'))), 409, 'Ereigniszeit liegt vor dem aktuellen Zeitstand.');
            $entry->activities()->whereNull('ends_at')->update(['ends_at' => $at]);
            $entry->activities()->create(['kind' => $kind, 'starts_at' => $at, 'is_paid' => null]);
            $entry->increment('revision');
            $entry->events()->create(['event_key' => $eventKey, 'kind' => 'activity', 'actor_id' => $actor->id, 'occurred_at' => $at, 'received_at' => now()->utc(), 'data' => ['revision' => $entry->revision, 'kind' => $kind]]);
            app(OperationsAuditService::class)->record($entry, $actor, 'time.activity', ['kind' => $kind]);

            return $entry;
        }, 3);
    }

    public function replace(int $id, int $revision, array $sections, User $actor): void
    {
        OperationsAccess::own($actor, $actor->id);
        WorkTimeSchema::requireReady();
        Validator::make(['sections' => $sections], ['sections' => 'required|array|min:1|max:60', 'sections.*.kind' => 'required|in:'.implode(',', array_keys(self::KINDS)), 'sections.*.starts_at' => 'required|date', 'sections.*.ends_at' => 'required|date', 'sections.*.note' => 'nullable|string|max:500'])->validate();
        OperationsTransaction::run(function () use ($id, $revision, $sections, $actor) {
            User::lockForUpdate()->findOrFail($actor->id);
            $entry = WorkTimeEntry::where('user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            abort_unless($entry->revision === $revision && in_array($entry->status, ['completed', 'returned'], true), 409);
            $cursor = $entry->starts_at->utc();
            $pause = 0;
            $values = [];
            foreach ($sections as $section) {
                [$start, $end] = OperationsDateTime::interval($section['starts_at'], $section['ends_at'], $entry->timezone);
                if (! $start->equalTo($cursor) || ! $end->gt($start) || $end->gt($entry->ends_at)) {
                    throw ValidationException::withMessages(['sections' => 'Abschnitte müssen die tatsächliche Arbeitszeit lückenlos und ohne Überschneidung abdecken.']);
                }
                $cursor = $end;
                if ($section['kind'] === 'break') {
                    $pause += (int) $start->diffInSeconds($end);
                }
                $values[] = ['kind' => $section['kind'], 'starts_at' => $start, 'ends_at' => $end, 'is_paid' => $section['kind'] === 'break' ? $this->breakPaid($entry) : null, 'source' => 'reported', 'note' => $section['note'] ?? null];
            }
            if (! $cursor->equalTo($entry->ends_at) || $pause !== $entry->pause_seconds) {
                throw ValidationException::withMessages(['sections' => 'Ende und Pausensumme müssen mit der Zeitmeldung übereinstimmen.']);
            }
            $previous = $entry->activities()->get()->toArray();
            // Superseded sections remain in the immutable time-revision snapshot.
            $entry->revisions()->create(['revision' => $entry->revision, 'action' => 'before_sections', 'actor_id' => $actor->id, 'snapshot' => ['activities' => $previous, 'net_seconds' => $entry->netSeconds()], 'created_at' => now()->utc()]);
            $entry->activities()->delete();
            $entry->activities()->createMany($values);
            $entry->increment('revision');
            app(OperationsAuditService::class)->record($entry, $actor, 'time.sections_reported');
        }, 3);
    }

    private function breakPaid(WorkTimeEntry $entry): ?bool
    {
        if (array_key_exists('break', $entry->plan_snapshot['valuation'] ?? [])) {
            return (int) $entry->plan_snapshot['valuation']['break'] > 0;
        }

        return ($entry->work_context ?? 'shift') === 'shift' && ! array_key_exists('valuation', $entry->plan_snapshot ?? []) ? false : null;
    }
}
