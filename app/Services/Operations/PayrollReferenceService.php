<?php

namespace App\Services\Operations;

use App\Models\EmployeePayrollReference;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeExport;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollReferenceService
{
    public function save(int $userId, ?int $revision, array $data, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.time.export');
        abort_unless(Schema::hasTable('employee_payroll_references'), 503);
        app(PersonnelScopeService::class)->authorize($actor, $userId, 'operations.time.export');
        OperationsTransaction::run(function () use ($userId, $revision, $data, $actor) {
            User::where('role', 'staff')->lockForUpdate()->findOrFail($userId);
            $record = EmployeePayrollReference::where('user_id', $userId)->lockForUpdate()->first();
            if (($record?->revision) !== $revision) {
                throw ValidationException::withMessages(['workflow' => 'Zuordnung wurde geändert. Bitte neu laden.']);
            }
            $data = Validator::make($data, ['employer_reference' => 'required|string|max:64|regex:/^[A-Za-z0-9_.-]+$/',
                'personnel_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('employee_payroll_references')->where('employer_reference', $data['employer_reference'] ?? '')->ignore($record?->id)],
                'external_employee_reference' => 'nullable|string|max:100|regex:/^[A-Za-z0-9_.-]+$/'])->validate();
            $record ??= new EmployeePayrollReference(['user_id' => $userId]);
            $record->fill($data + ['updated_by' => $actor->id, 'revision' => ($revision ?? 0) + 1])->save();
            app(OperationsAuditService::class)->record($record, $actor, 'payroll.reference.saved');
        }, 3);
    }

    public function snapshot(int $userId): ?array
    {
        if (! Schema::hasTable('employee_payroll_references')) {
            return null;
        }

        return EmployeePayrollReference::where('user_id', $userId)->first()?->only(['employer_reference', 'personnel_number', 'external_employee_reference', 'revision']);
    }

    public function csv(WorkTimeExport $export, User $actor): string
    {
        OperationsAccess::authorize($actor, 'operations.time.export');
        $rows = [];
        $items = $export->items()->orderBy('id')->get();
        foreach ($items as $item) {
            $employeeId = $item->snapshot['employee_id'] ?? WorkTimeEntry::findOrFail($item->work_time_entry_id)->user_id;
            app(PersonnelScopeService::class)->authorize($actor, (int) $employeeId, 'operations.time.export');
        }
        foreach ($items as $item) {
            $s = $item->snapshot;
            $ref = $s['payroll_reference'] ?? null;
            if (! $ref || ! isset($s['employee_id'])) {
                throw ValidationException::withMessages(['workflow' => 'Dieser Export enthält keine vollständigen Lohnzuordnungen. Standard-CSV verwenden; neue Exporte nach Zuordnung erstellen.']);
            }
            $start = CarbonImmutable::parse($s['starts_at'])->setTimezone($s['timezone']);
            $end = CarbonImmutable::parse($s['ends_at'])->setTimezone($s['timezone']);
            $crossesMonth = $start->format('Y-m') !== $end->subSecond()->format('Y-m');
            $rows[] = ['railtime.payroll-handoff.v1', $export->public_id, $item->work_time_entry_id, $item->revision,
                $s['employee_id'], $ref['employer_reference'], $ref['personnel_number'], $ref['external_employee_reference'], $ref['revision'], $s['employee'],
                $start->format('Y-m'), $start->toIso8601String(), $end->toIso8601String(), $s['timezone'], $s['pause_seconds'], $s['net_seconds'], number_format($s['net_seconds'] / 3600, 6, '.', ''),
                $s['plan_snapshot']['order_number'] ?? '', $crossesMonth ? 'Monatswechsel prüfen' : '', 'approved'];
        }
        app(OperationsAuditService::class)->record($export, $actor, 'payroll.handoff.downloaded');

        return app(OperationsReportService::class)->csv(['Schema', 'Export-ID', 'Zeit-ID', 'Zeitrevision', 'Mitarbeiter-ID', 'Arbeitgeberreferenz', 'Personalnummer', 'Externe Mitarbeiterreferenz', 'Zuordnungsrevision', 'Mitarbeiter', 'Beginnmonat', 'Beginn', 'Ende', 'Zeitzone', 'Pause (Sek.)', 'Netto (Sek.)', 'Netto (Dezimalstunden)', 'Auftrag', 'Prüfhinweis', 'Status'], $rows);
    }
}
