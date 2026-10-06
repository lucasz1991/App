<?php

namespace App\Services\Operations;

use App\Models\AvailabilityPeriod;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeQualification;
use App\Models\OperationsAttentionItem;
use App\Models\OperationsReminderPreference;
use App\Models\PersonnelTask;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PersonalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsReminderService
{
    public const KINDS = ['qualification_expiry' => 'Nachweisfrist', 'shift_reply' => 'Dienstrückmeldung', 'punch_start' => 'Dienstbeginn', 'punch_end' => 'Dienstende', 'wish_due' => 'Wunschabgabe', 'time_incomplete' => 'Offene Zeiten', 'task_due' => 'Aufgabenfrist'];

    public function ready(): bool
    {
        return Schema::hasColumns('operations_reminder_preferences', ['id', 'user_id', 'kind', 'lead_minutes', 'quiet_from', 'quiet_until', 'timezone', 'is_active', 'revision', 'created_by', 'created_at', 'updated_at'])
            && Schema::hasColumns('operations_attention_items', ['id', 'recipient_user_id', 'subject_user_id', 'source_key', 'kind', 'headline', 'module', 'record_id', 'due_at', 'read_at', 'resolved_at', 'source_revision', 'revision', 'created_at', 'updated_at']);
    }

    public function savePreference(User $subject, array $data, User $actor, ?int $id = null, ?int $revision = null): OperationsReminderPreference
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($this->ready() && $actor->status && $subject->status, 403);
        if ($actor->id === $subject->id) {
            OperationsAccess::own($actor, $subject->id);
        } else {
            app(PersonnelScopeService::class)->authorize($actor, $subject, 'employees.master-data.edit');
        }
        $data = Validator::make($data, ['kind' => ['required', Rule::in(array_keys(self::KINDS))], 'lead_minutes' => 'required|integer|min:0|max:86400', 'timezone' => 'required|timezone', 'quiet_from' => 'nullable|date_format:H:i', 'quiet_until' => 'nullable|date_format:H:i', 'is_active' => 'required|boolean'])->validate();
        if (filled($data['quiet_from'] ?? null) !== filled($data['quiet_until'] ?? null) || (filled($data['quiet_from'] ?? null) && $data['quiet_from'] === $data['quiet_until'])) {
            throw ValidationException::withMessages(['workflow' => 'Ruhefenster vollständig und mit unterschiedlichen Uhrzeiten angeben.']);
        }

        return OperationsTransaction::run(function () use ($subject, $data, $actor, $id, $revision) {
            User::lockForUpdate()->findOrFail($subject->id);
            $record = $id ? OperationsReminderPreference::where('user_id', $subject->id)->lockForUpdate()->findOrFail($id) : new OperationsReminderPreference;
            if ($id && $record->revision !== $revision) {
                throw ValidationException::withMessages(['workflow' => 'Erinnerung wurde geändert.']);
            }
            if (OperationsReminderPreference::where('user_id', $subject->id)->where('kind', $data['kind'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['workflow' => 'Für diesen Bereich gibt es bereits eine Erinnerung.']);
            }
            $record->fill($data + ['user_id' => $subject->id]);
            if ($id) {
                $record->revision++;
            } else {
                $record->created_by = $actor->id;
            }
            $record->save();
            app(OperationsAuditService::class)->record($record, $actor, 'reminder.preference.saved');

            return $record;
        }, 3);
    }

    /** Internal, no email/push/third-party dispatch. Idempotent per source revision. */
    public function syncUser(User $subject): int
    {
        if (! $this->ready() || ! $subject->fresh()?->status || $subject->role !== 'staff') {
            return 0;
        }

        return OperationsTransaction::run(function () use ($subject) {
            User::lockForUpdate()->findOrFail($subject->id);
            $count = 0;
            $active = [];
            foreach (OperationsReminderPreference::where('user_id', $subject->id)->where('is_active', true)->get() as $preference) {
                $local = CarbonImmutable::now($preference->timezone);
                $quiet = $this->quiet($local->format('H:i'), $preference);
                foreach ($this->sources($subject, $preference) as $source) {
                    $key = hash('sha256', $preference->kind.':'.$source['id'].':'.$source['revision'].':'.$source['due']->utc()->toIso8601String());
                    $active[] = $key;
                    // An existing active notice is retained during quiet hours; a new notice waits.
                    if ($quiet) {
                        continue;
                    }
                    $item = OperationsAttentionItem::firstOrCreate(['recipient_user_id' => $subject->id, 'source_key' => $key], [
                        'subject_user_id' => $subject->id, 'kind' => $preference->kind, 'headline' => $source['headline'], 'module' => $source['module'],
                        'record_id' => $source['id'], 'source_revision' => $source['revision'], 'due_at' => $source['due'],
                    ]);
                    if ($item->wasRecentlyCreated) {
                        $count++;
                    }
                    if ($item->resolved_at) {
                        $item->forceFill(['resolved_at' => null, 'revision' => $item->revision + 1])->save();
                    }
                }
            }
            OperationsAttentionItem::where('recipient_user_id', $subject->id)->whereIn('kind', array_keys(self::KINDS))->whereNull('resolved_at')->whereNotIn('source_key', $active)
                ->update(['resolved_at' => now()->utc(), 'updated_at' => now()->utc()]);

            return $count;
        }, 3);
    }

    public function runScheduled(): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $count = 0;
        User::where('role', 'staff')->where('status', true)->whereIn('id', OperationsReminderPreference::select('user_id'))->chunkById(100, function ($users) use (&$count) {
            foreach ($users as $user) {
                $count += $this->syncUser($user);
            }
        });

        return $count;
    }

    public function markRead(int $id, int $revision, User $actor): void
    {
        OperationsAccess::own(User::findOrFail($actor->id), $actor->id);
        abort_unless($this->ready(), 503);
        OperationsTransaction::run(function () use ($id, $revision, $actor) {
            $item = OperationsAttentionItem::where('recipient_user_id', $actor->id)->lockForUpdate()->findOrFail($id);
            if ($item->revision !== $revision) {
                throw ValidationException::withMessages(['workflow' => 'Hinweis wurde geändert.']);
            }
            if (! $item->read_at) {
                $item->forceFill(['read_at' => now()->utc(), 'revision' => $revision + 1])->save();
            }
        }, 3);
    }

    private function quiet(string $time, OperationsReminderPreference $preference): bool
    {
        if (! $preference->quiet_from || ! $preference->quiet_until) {
            return false;
        }

        return $preference->quiet_from < $preference->quiet_until
            ? $time >= $preference->quiet_from && $time < $preference->quiet_until
            : $time >= $preference->quiet_from || $time < $preference->quiet_until;
    }

    private function sources(User $subject, OperationsReminderPreference $preference): Collection
    {
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->addMinutes($preference->lead_minutes);
        $make = fn ($id, $revision, $due, $headline, $module) => ['id' => (int) $id, 'revision' => (int) $revision, 'due' => CarbonImmutable::instance($due), 'headline' => mb_substr($headline, 0, 180), 'module' => $module];
        if ($preference->kind === 'qualification_expiry') {
            return EmployeeQualification::where('user_id', $subject->id)->where('status', 'approved')->whereDate('valid_until', '<=', $cutoff->setTimezone($preference->timezone)->toDateString())->whereDate('valid_until', '>=', $now->subDays(30)->toDateString())->with('type')->limit(200)->get()
                ->map(fn ($row) => $make($row->id, $row->revision, CarbonImmutable::parse($row->valid_until->toDateString(), $preference->timezone)->endOfDay(), $row->type->name.' · Nachweisfrist', 'qualifications'))->filter(fn ($row) => $row['due']->lte($cutoff));
        }
        if (in_array($preference->kind, ['shift_reply', 'punch_start'], true)) {
            return ShiftAssignment::where('user_id', $subject->id)->where('status', $preference->kind === 'shift_reply' ? 'requested' : 'confirmed')
                ->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->whereNotIn('status', ['cancelled', 'completed'])->whereColumn('shifts.published_revision', 'shift_assignments.plan_revision')->where(fn ($q) => $q->whereColumn('shifts.revision', '!=', 'shifts.published_revision')->orWhere(fn ($q) => $q->where('starts_at', '<=', $cutoff)->where('ends_at', '>', $now))))
                ->when($preference->kind === 'punch_start', fn ($q) => $q->whereDoesntHave('timeEntry'))->with('shift')->limit(200)->get()
                ->map(function ($row) use ($make, $preference, $cutoff, $now) {
                    $window = app(PersonalSchedule::class)->publishedWindow($row);

                    return $window && $window['starts_at']->lte($cutoff) && $window['ends_at']->gt($now) ? $make($row->id, $row->plan_revision, $window['starts_at'], $window['title'].' · '.($preference->kind === 'shift_reply' ? 'Rückmeldung offen' : 'Startmeldung offen'), 'shift-management') : null;
                })->filter();
        }
        if ($preference->kind === 'punch_end') {
            return WorkTimeEntry::where('user_id', $subject->id)->whereIn('status', ['running', 'paused'])->whereNull('ends_at')->whereHas('assignment.shift', fn ($q) => $q->whereNotIn('status', ['cancelled'])->where(fn ($q) => $q->whereColumn('shifts.revision', '!=', 'shifts.published_revision')->orWhere('ends_at', '<=', $cutoff)))->with('assignment.shift')->limit(200)->get()
                ->map(function ($row) use ($make, $cutoff) {
                    $window = app(PersonalSchedule::class)->publishedWindow($row->assignment);

                    return $window && $window['ends_at']->lte($cutoff) ? $make($row->id, $row->revision, $window['ends_at'], $window['title'].' · Endmeldung offen', 'times') : null;
                })->filter();
        }
        if ($preference->kind === 'wish_due' && Schema::hasTable('availability_periods') && Schema::hasTable('employee_availabilities')) {
            return AvailabilityPeriod::where('due_at', '<=', $cutoff)->whereDate('until', '>=', $now->setTimezone($preference->timezone)->toDateString())->whereNotIn('id', EmployeeAvailability::where('user_id', $subject->id)->whereNotNull('availability_period_id')->select('availability_period_id'))->limit(200)->get()
                ->map(fn ($row) => $make($row->id, $row->revision, CarbonImmutable::parse($row->getRawOriginal('due_at'), 'UTC'), $row->name.' · Wunschabgabe', 'workforce-planning'));
        }
        if ($preference->kind === 'time_incomplete') {
            return WorkTimeEntry::where('user_id', $subject->id)->whereIn('status', ['completed', 'returned'])->where('starts_at', '>=', $now->subDays(60))->where('starts_at', '<=', $now)->limit(200)->get()
                ->map(fn ($row) => $make($row->id, $row->revision, $row->ends_at ?? $row->starts_at, 'Arbeitszeit · Prüfung offen', 'times'));
        }
        if ($preference->kind === 'task_due' && Schema::hasTable('personnel_tasks')) {
            return PersonnelTask::where('user_id', $subject->id)->where('status', 'open')->whereNotNull('due_on')->whereDate('due_on', '<=', $cutoff->setTimezone($preference->timezone)->toDateString())->limit(200)->get()
                ->map(fn ($row) => $make($row->id, $row->revision, CarbonImmutable::parse($row->due_on, $preference->timezone)->endOfDay(), $row->title.' · Aufgabenfrist', 'personnel-processes'))->filter(fn ($row) => $row['due']->lte($cutoff));
        }

        return collect();
    }
}
