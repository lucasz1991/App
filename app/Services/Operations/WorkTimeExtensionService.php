<?php

namespace App\Services\Operations;

use App\Models\PersonnelTraining;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeBasicExport;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\ReportingPeriod;
use App\Support\Operations\WorkTimeSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkTimeExtensionService
{
    public function completeness(User $subject, string $from, string $until, User $actor): array
    {
        if ($subject->id === $actor->id) {
            OperationsAccess::own($actor, $subject->id);
        } else {
            app(PersonnelScopeService::class)->authorize($actor, $subject, 'operations.time.review');
        }
        [$start, $end] = ReportingPeriod::bounds($from, $until);
        $missing = ShiftAssignment::where('user_id', $subject->id)->where('status', 'confirmed')
            ->whereHas('shift', fn ($q) => $q->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->where('ends_at', '<=', now()->utc())->where('published_revision', '>', 0)->where('status', '!=', 'cancelled'))
            ->whereDoesntHave('timeEntry')->with('shift')->get()->map(fn ($a) => ['assignment_id' => $a->id, 'title' => $a->shift->title, 'starts_at' => $a->shift->starts_at->toIso8601String()])->all();
        $unsubmitted = ReportingPeriod::apply(WorkTimeEntry::where('user_id', $subject->id), $from, $until)->whereIn('status', ['completed', 'returned', 'running', 'paused'])->get()->map(fn ($e) => ['id' => $e->id, 'revision' => $e->revision, 'status' => $e->status, 'title' => $e->plan_snapshot['title'] ?? $e->contextLabel()])->all();
        $missingTraining = [];
        if (Schema::hasColumns('personnel_trainings', ['id', 'title', 'status', 'starts_at', 'ends_at', 'timezone'])
            && Schema::hasColumns('personnel_training_participants', ['personnel_training_id', 'user_id', 'status'])
            && Schema::hasColumns('work_time_entries', ['work_context', 'training_session_id'])) {
            $missingTraining = PersonnelTraining::where('status', 'scheduled')->where('starts_at', '>=', $start)->where('starts_at', '<', $end)
                ->where('ends_at', '<=', now()->utc())->whereHas('participants', fn ($query) => $query->where('user_id', $subject->id)->where('status', 'attended'))
                // Attendance never creates an actual time. A correlated EXISTS also avoids
                // unrelated NULL references hiding missing times or counting open times twice.
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('work_time_entries')->where('user_id', $subject->id)
                    ->where('work_context', 'training')->whereColumn('training_session_id', 'personnel_trainings.id'))
                ->orderBy('starts_at')->get()->map(fn ($training) => ['training_session_id' => $training->id, 'title' => $training->title, 'starts_at' => $training->starts_at->toIso8601String()])->all();
        }

        return ['missing_shift_times' => $missing, 'missing_training_times' => $missingTraining, 'unfinished_times' => $unsubmitted, 'complete' => ! $missing && ! $missingTraining && ! $unsubmitted];
    }

    public function export(array $ids, User $actor): WorkTimeBasicExport
    {
        OperationsAccess::authorize($actor, 'operations.time.export');
        WorkTimeSchema::requireReady();
        Validator::make(['ids' => $ids], ['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer|distinct'])->validate();

        return OperationsTransaction::run(function () use ($ids, $actor) {
            $entries = WorkTimeEntry::with('user:id,name', 'activities')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($entries->count() !== count($ids) || $entries->contains(fn ($e) => $e->status !== 'approved')) {
                throw ValidationException::withMessages(['workflow' => 'Nur freigegebene Arbeitszeiten exportieren.']);
            }
            foreach ($entries as $entry) {
                app(PersonnelScopeService::class)->authorize($actor, (int) $entry->user_id, 'operations.time.export');
            }
            $snapshot = $entries->map(fn ($e) => ['id' => $e->id, 'revision' => $e->revision, 'employee_id' => $e->user_id, 'employee' => $e->user->name, 'work_context' => $e->work_context, 'assignment_id' => $e->shift_assignment_id,
                'order_number' => $e->plan_snapshot['order_number'] ?? null, 'training_session_id' => $e->training_session_id, 'title' => $e->plan_snapshot['title'] ?? $e->contextLabel(), 'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'timezone' => $e->timezone, 'pause_seconds' => $e->pause_seconds, 'net_seconds' => $e->netSeconds(), 'credited_seconds' => $e->creditedSeconds(), 'valuation' => $e->plan_snapshot['valuation'] ?? [], 'activities' => $e->activities->toArray()])->all();
            $export = WorkTimeBasicExport::create(['public_id' => (string) Str::uuid(), 'created_by' => $actor->id, 'schema_version' => 2, 'snapshot' => $snapshot, 'created_at' => now()->utc()]);
            app(OperationsAuditService::class)->record($export, $actor, 'time.basic_export', ['schema_version' => 2, 'entry_ids' => $ids]);

            return $export;
        }, 3);
    }

    public function csv(WorkTimeBasicExport $export, User $actor): string
    {
        OperationsAccess::authorize($actor, 'operations.time.export');
        foreach ($export->snapshot as $record) {
            app(PersonnelScopeService::class)->authorize($actor, (int) $record['employee_id'], 'operations.time.export');
        }

        return app(OperationsReportService::class)->csv(['Schema', 'Export', 'Zeit-ID', 'Revision', 'Mitarbeiter-ID', 'Mitarbeiter', 'Kontext', 'Dienstzuordnung', 'Auftrag', 'Schulung', 'Tätigkeit', 'Beginn', 'Ende', 'Zeitzone', 'Pause (Sek.)', 'Netto (Sek.)', 'Kontogutschrift (Sek.)'], array_map(fn ($s) => [2, $export->public_id, $s['id'], $s['revision'], $s['employee_id'], $s['employee'], $s['work_context'], $s['assignment_id'], $s['order_number'], $s['training_session_id'], $s['title'], $s['starts_at'], $s['ends_at'], $s['timezone'], $s['pause_seconds'], $s['net_seconds'], $s['credited_seconds'] ?? null], $export->snapshot));
    }
}
