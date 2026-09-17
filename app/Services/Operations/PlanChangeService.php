<?php

namespace App\Services\Operations;

use App\Models\OperationAudit;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PersonalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlanChangeService
{
    private const FIELDS = [
        'order_id' => 'Auftrag', 'title' => 'Titel', 'role_name' => 'Funktion',
        'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'timezone' => 'Zeitzone',
        'location_name' => 'Einsatzort', 'planned_break_minutes' => 'Pause (min)',
    ];

    public function changes(Shift $shift): array
    {
        $before = $shift->published_snapshot ?? [];
        $changes = [];
        foreach (self::FIELDS as $key => $label) {
            $old = $this->format($key, $before[$key] ?? null, $before['timezone'] ?? $shift->timezone);
            $new = $this->format($key, $shift->$key, $shift->timezone);
            if ($old !== $new) {
                $changes[] = ['field' => $key, 'label' => $label, 'before' => $old, 'after' => $new];
            }
        }
        $previous = OperationAudit::where('subject_type', 'Shift')->where('subject_id', $shift->id)
            ->where('action', 'shift.published')->where('revision', $shift->published_revision)->latest('id')->first();
        $requirements = $shift->qualifications()->orderBy('name')->pluck('name')->implode(', ') ?: '—';
        $oldRequirements = $previous?->data['requirements'] ?? ($shift->published_revision ? null : '—');
        if ($oldRequirements !== null && $requirements !== $oldRequirements) {
            $changes[] = ['field' => 'requirements', 'label' => 'Nachweise', 'before' => $oldRequirements, 'after' => $requirements];
        }
        $sections = app(DutyActivityService::class)->snapshot($shift);
        $beforeSections = $before['sections'] ?? [];
        if ($sections !== $beforeSections) {
            $describe = fn ($items) => collect($items)->map(fn ($item) => (DutyActivityService::KINDS[$item['kind']] ?? $item['kind']).' '.CarbonImmutable::parse($item['starts_at'])->setTimezone($item['timezone'])->format('d.m. H:i').'–'.CarbonImmutable::parse($item['ends_at'])->setTimezone($item['timezone'])->format('H:i').(filled($item['label'] ?? null) ? ' · '.$item['label'] : ''))->implode('; ') ?: '—';
            $changes[] = ['field' => 'sections', 'label' => 'Dienstabschnitte', 'before' => $describe($beforeSections), 'after' => $describe($sections)];
        }

        return $changes;
    }

    private function format(string $key, mixed $value, string $timezone): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($key, ['starts_at', 'ends_at'], true)) {
            return CarbonImmutable::parse($value)->setTimezone($timezone)->format('d.m.Y H:i P');
        }
        if ($key === 'order_id') {
            return Order::withTrashed()->find($value)?->order_number ?? '#'.$value;
        }

        return (string) $value;
    }

    public function opened(int $assignmentId, int $revision, User $actor): void
    {
        OperationsAccess::own($actor, $actor->id);
        DB::transaction(function () use ($assignmentId, $revision, $actor) {
            $assignment = ShiftAssignment::where('user_id', $actor->id)->findOrFail($assignmentId);
            Shift::lockForUpdate()->findOrFail($assignment->shift_id);
            $assignment = ShiftAssignment::lockForUpdate()->findOrFail($assignment->id);
            // Re-resolve the published employee projection after acquiring locks.
            $visible = app(PersonalSchedule::class)->assignment($actor, $assignmentId);
            abort_unless($visible->plan_revision === $revision, 409);
            if (! $this->opening($assignmentId, $revision)) {
                app(OperationsAuditService::class)->record($assignment, $actor, 'assignment.opened', ['plan_revision' => $revision]);
            }
        }, 3);
    }

    public function opening(int $assignmentId, int $revision): ?OperationAudit
    {
        $saved = OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignmentId)
            ->where('action', 'assignment.saved')->max('id') ?? 0;

        return OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignmentId)
            ->where('action', 'assignment.opened')->where('data->plan_revision', $revision)->where('id', '>', $saved)->first();
    }

    public function publishedChanges(int $shiftId, int $revision): array
    {
        return OperationAudit::where('subject_type', 'Shift')->where('subject_id', $shiftId)
            ->where('action', 'shift.published')->where('revision', $revision)->latest('id')->first()?->data['changes'] ?? [];
    }

    public function openings(Collection $assignments, int $revision): Collection
    {
        if ($assignments->isEmpty() || $revision < 1) {
            return collect();
        }
        $base = OperationAudit::where('subject_type', 'ShiftAssignment')->whereIn('subject_id', $assignments->pluck('id'));
        $saved = (clone $base)->where('action', 'assignment.saved')->selectRaw('subject_id, MAX(id) as last_saved')->groupBy('subject_id')->pluck('last_saved', 'subject_id');

        return (clone $base)->where('action', 'assignment.opened')->where('data->plan_revision', $revision)->orderBy('id')->get()
            ->filter(fn ($audit) => $audit->id > ($saved[$audit->subject_id] ?? 0))->unique('subject_id')->keyBy('subject_id');
    }
}
