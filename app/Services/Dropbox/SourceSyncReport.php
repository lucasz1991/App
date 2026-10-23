<?php

namespace App\Services\Dropbox;

use App\Enums\ShiftStatus;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeCompetencyFact;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;

/** Counts source evidence and native results independently of chunk operations. */
class SourceSyncReport
{
    public const VERSION = '2026-10-23-rolling-history-v6';

    public function build(DropboxSource $source, array $parsed, array $summary, int $processed, int $total): array
    {
        $rows = collect($parsed['rows']);
        $availability = $rows->filter(fn ($r) => ($r['locator']['kind'] ?? '') === 'availability_note');
        $planning = $rows->where('domain', 'planning');
        $keys = $rows->mapWithKeys(fn ($r) => [$r['sheet'].'!'.$r['slot'] => $r['fingerprint']]);
        $appearances = DropboxAppearance::where('source_id', $source->id)->get()->filter(fn ($a) => isset($keys[$a->sheet.'!'.$a->slot]) && ($a->fingerprint === $keys[$a->sheet.'!'.$a->slot] || $a->seen_rev === $source->rev));
        $records = DropboxRecord::whereIn('id', $appearances->pluck('record_id')->unique())->get();
        $shifts = Shift::whereIn('id', $records->where('model_type', 'Shift')->pluck('model_id')->unique())->get(['id', 'order_id', 'status']);
        $activeShiftIds = $shifts->reject(fn ($shift) => in_array($shift->status, [ShiftStatus::Completed, ShiftStatus::Cancelled], true))->modelKeys();
        $completedShiftIds = $shifts->where('status', ShiftStatus::Completed)->modelKeys();
        $recordUsers = $records->where('model_type', 'Shift')->map(fn ($record) => (int) ($record->metadata['user_id'] ?? 0))->filter()->unique();
        $existingUsers = User::whereIn('id', $recordUsers)->pluck('id')->flip();
        $recognizedShiftIds = $records->where('model_type', 'Shift')
            ->filter(fn ($record) => isset($existingUsers[(int) ($record->metadata['user_id'] ?? 0)]))
            ->pluck('model_id')->intersect($shifts->modelKeys())->unique();
        $facts = EmployeeCompetencyFact::whereIn('id', $records->where('model_type', 'EmployeeCompetencyFact')->pluck('model_id')->unique())->get(['id', 'kind', 'value']);
        $nativeIds = $records->where('model_type', 'EmployeeAvailability')->pluck('model_id')->unique();
        $nativeRecords = $records->where('model_type', 'EmployeeAvailability')->keyBy('id');
        $activeNativeIds = $appearances->filter(fn ($a) => isset($nativeRecords[$a->record_id]) && ($a->last_excel['status'] ?? 'active') !== 'withdrawn')
            ->map(fn ($a) => $nativeRecords[$a->record_id]->model_id)->unique();
        $nativeWishes = $activeNativeIds->isEmpty() ? 0 : EmployeeAvailability::whereIn('id', $activeNativeIds)->count();
        $assigned = ShiftAssignment::blocking()->whereIn('shift_id', $shifts->modelKeys())->distinct()->count('shift_id');
        $activeAssigned = ShiftAssignment::blocking()->whereIn('shift_id', $activeShiftIds)->distinct()->count('shift_id');
        $historicalAssigned = ShiftAssignment::whereIn('shift_id', $completedShiftIds)->where('status', 'reported')->distinct()->count('shift_id');
        $conflicts = DropboxConflict::where('source_id', $source->id)->whereIn('state', ['open', 'rechecking'])->get(['reason', 'snapshot']);
        // Assignment hints can be record-scoped, so include only this source's records.
        $assignmentHints = DropboxConflict::whereIn('record_id', $records->modelKeys())->whereNull('source_id')->where('reason', 'assignment_pending')->whereIn('state', ['open', 'rechecking'])->count();
        $missing = $conflicts->countBy('reason')->all();
        if ($assignmentHints) {
            $missing['assignment_pending'] = ($missing['assignment_pending'] ?? 0) + $assignmentHints;
        }
        $missingEmployee = $planning->filter(fn ($r) => trim($r['values']['employee'] ?? '') === '')->count();
        if ($missingEmployee) {
            $missing['employee_not_in_source'] = $missingEmployee;
        }
        $coverage = $parsed['coverage'] ?? [];
        if (($coverage['embedded_images'] ?? 0) > 0) {
            $missing['embedded_images_unread'] = $coverage['embedded_images'];
        }
        $subjects = $rows->filter(fn ($r) => in_array($r['locator']['kind'] ?? '', ['availability_note', 'availability_submission'], true))->pluck('locator.subject')->unique()->values();
        $planningSubjects = $planning->pluck('values.employee')->filter(fn ($name) => trim((string) $name) !== '')->unique(fn ($name) => WorkbookReader::normalize($name))->values();
        $identities = DropboxIdentity::where('connection_id', $source->connection_id)->whereIn('alias', $subjects->merge($planningSubjects)->map(fn ($s) => WorkbookReader::normalize($s)))->get()->keyBy('alias');
        $availabilityIdentities = $identities->toBase()->only($subjects->map(fn ($s) => WorkbookReader::normalize($s))->all());
        $planningIdentities = $identities->toBase()->only($planningSubjects->map(fn ($s) => WorkbookReader::normalize($s))->all());
        $planningEmployees = User::whereIn('id', $planningIdentities->where('kind', 'employee')->pluck('user_id')->filter()->unique())->count();
        $identityReview = $subjects->filter(function ($name) use ($identities) {
            $identity = $identities->get(WorkbookReader::normalize($name));

            return ! $identity || ($identity->kind !== 'provider' && ($identity->kind !== 'employee' || ! $identity->user_id));
        });
        if ($identityReview->isNotEmpty()) {
            $missing['employee_unresolved'] = $identityReview->count();
        }
        $ignored = $coverage['ignored_sheets'] ?? [];
        if (($coverage['disabled_rows'] ?? 0) > 0) {
            $missing['domain_disabled'] = $coverage['disabled_rows'];
        }
        $issues = collect($parsed['issues'] ?? []);
        foreach ($issues->countBy('reason') as $reason => $count) {
            $missing[$reason] = max($count, $missing[$reason] ?? 0);
        }

        return [
            'schema' => 1, 'parser_version' => self::VERSION, 'generated_at' => now()->toIso8601String(),
            'rev' => $source->rev, 'preview' => (bool) ($summary['preview'] ?? false), 'complete' => $processed >= $total,
            'processed' => $processed, 'total' => $total,
            'next_history_due_at' => app(HistoricalImportCompletion::class)->nextDueAt($source, $records->modelKeys())?->toIso8601String(),
            'detected' => [
                ...$rows->countBy('domain')->all(),
                'competencies' => $rows->where('domain', 'competencies')->reject(fn ($r) => in_array($r['locator']['kind'] ?? '', ['availability_note', 'availability_submission'], true))->count(),
                'availability_cells' => $availability->count(),
                'availability_notes' => $availability->filter(fn ($r) => trim($r['values']['value'] ?? '') !== '')->count(),
                'availability_submissions' => $rows->filter(fn ($r) => ($r['locator']['kind'] ?? '') === 'availability_submission')->count(),
            ],
            'stored' => [
                'planning' => $shifts->count(), 'orders' => Order::whereIn('id', $shifts->pluck('order_id')->unique())->count(),
                'contacts' => $records->where('domain', 'contacts')->count(), 'competencies' => $facts->where('kind', '!=', 'availability_note')->count(),
                'availability_cells' => $facts->where('kind', 'availability_note')->count(),
                'availability_notes' => $facts->filter(fn ($f) => $f->kind === 'availability_note' && trim($f->value['value'] ?? '') !== '')->count(),
                'assigned_shifts' => $assigned, 'unassigned_shifts' => $shifts->count() - $assigned,
                'active_shifts' => count($activeShiftIds), 'completed_shifts' => count($completedShiftIds),
                'cancelled_shifts' => $shifts->where('status', ShiftStatus::Cancelled)->count(),
                'active_assigned_shifts' => $activeAssigned, 'unassigned_active_shifts' => count($activeShiftIds) - $activeAssigned,
                'historical_assigned_shifts' => $historicalAssigned,
                'recognized_employee_shifts' => $recognizedShiftIds->count(),
                'historical_recognized_shifts' => $recognizedShiftIds->intersect($completedShiftIds)->count(),
                'availability_submissions' => $nativeWishes,
                'withdrawn_submissions' => $nativeIds->count() - $nativeWishes,
            ],
            'employee_references' => $subjects->count(),
            'matched_employees' => $availabilityIdentities->filter(fn ($i) => $i->kind === 'employee' && $i->user_id)->count(),
            'matched_providers' => $availabilityIdentities->where('kind', 'provider')->count(),
            'planning_employee_references' => $planningSubjects->count(),
            'planning_matched_employees' => $planningEmployees,
            'planning_matched_providers' => $planningIdentities->where('kind', 'provider')->count(),
            'identity_review' => $identityReview->map(fn ($name) => ['name' => $name, 'reason' => 'employee_unresolved'])->take(20)->values()->all(),
            'missing_employee' => $missingEmployee, 'missing' => $missing,
            'ignored_sheets' => $ignored,
            'issue_examples' => $issues->map(fn ($i) => array_intersect_key($i, array_flip(['sheet', 'row', 'reason'])))->take(20)->values()->all(),
            'pending_export' => max(0, ($summary['file_outgoing'] ?? $summary['outgoing'] ?? 0) - ($summary['file_exported'] ?? $summary['exported'] ?? 0)),
            'returned' => $summary['file_exported'] ?? $summary['exported'] ?? 0,
        ];
    }

    public function failed(DropboxSource $source, string $code): void
    {
        $source->forceFill(['last_report' => [
            ...($source->last_report ?? []), 'schema' => 1, 'complete' => false, 'error_code' => $code,
            'generated_at' => now()->toIso8601String(), 'rev' => $source->rev,
        ]])->save();
    }
}
