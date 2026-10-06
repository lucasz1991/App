<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeVacationPolicy;
use App\Models\PersonnelApplicant;
use App\Models\PersonnelDevelopmentReview;
use App\Models\PersonnelDocumentTemplate;
use App\Models\PersonnelSavedReport;
use App\Models\PersonnelSignatureRequest;
use App\Models\PersonnelSurvey;
use App\Models\PersonnelSurveyResponse;
use App\Models\PersonnelTask;
use App\Models\PersonnelWorkflowRun;
use App\Models\PersonnelWorkflowStep;
use App\Models\PersonnelWorkflowTemplate;
use App\Models\RegionalPersonnelCalendar;
use App\Models\SicknessEvidenceWorkflow;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PersonnelEnhancementService
{
    public function ready(): bool
    {
        $tables = [
            'personnel_workflow_templates' => ['title', 'status', 'payload', 'created_by', 'approved_by', 'approved_at'],
            'personnel_saved_reports' => ['title', 'status', 'payload', 'created_by', 'next_run_on', 'results'],
            'personnel_document_templates' => ['title', 'status', 'payload', 'created_by', 'approved_by', 'approved_at'],
            'absence_approval_policies' => ['title', 'status', 'payload', 'created_by', 'approved_by', 'approved_at'],
            'regional_personnel_calendars' => ['title', 'status', 'payload', 'created_by', 'approved_by', 'approved_at'],
            'personnel_surveys' => ['title', 'status', 'payload', 'created_by'],
            'personnel_workflow_runs' => ['title', 'status', 'user_id', 'template_id', 'anchor_on', 'snapshot', 'created_by'],
            'personnel_workflow_steps' => ['run_id', 'task_id', 'position', 'depends_on', 'delegate_id', 'escalate_to', 'escalate_on', 'escalated_at'],
            'personnel_signature_requests' => ['user_id', 'document_version_id', 'template_id', 'title', 'status', 'due_on', 'created_by', 'payload'],
            'employee_emergency_contacts' => ['user_id', 'payload', 'confirmed_at'],
            'absence_approval_steps' => ['absence_request_id', 'position', 'reviewer_id', 'delegate_id', 'decided_by', 'due_at', 'decided_at', 'status', 'snapshot'],
            'personnel_applicants' => ['user_id', 'title', 'status', 'due_on', 'created_by', 'payload'],
            'personnel_development_reviews' => ['user_id', 'title', 'status', 'due_on', 'created_by', 'payload'],
            'sickness_evidence_workflows' => ['user_id', 'title', 'status', 'due_on', 'created_by', 'payload', 'absence_request_id'],
            'personnel_survey_responses' => ['survey_id', 'user_id', 'status', 'payload'],
        ];
        foreach ($tables as $table => $columns) {
            if (! Schema::hasColumns($table, array_merge(['id', 'revision'], $columns))) {
                return false;
            }
        }

        return Schema::hasColumns('personnel_enhancement_locks', ['id', 'revision', 'lock_key']);
    }

    public function access(User $actor, int $userId, bool $edit = false, bool $own = true): void
    {
        abort_unless($this->ready() && $actor->status, $this->ready() ? 403 : 503);
        if ($own && (int) $actor->id === $userId) {
            OperationsAccess::own($actor, $userId);

            return;
        }
        app(PersonnelScopeService::class)->authorize($actor, $userId, $edit ? 'employees.master-data.edit' : 'employees.master-data.view');
    }

    public function createWorkflowTemplate(array $data, User $actor): PersonnelWorkflowTemplate
    {
        $this->global($actor);
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'type' => 'required|in:onboarding,offboarding,general', 'steps' => 'required|array|min:1|max:30', 'steps.*.title' => 'required|string|max:180', 'steps.*.offset_days' => 'required|integer|between:-365,365', 'steps.*.assigned_to' => 'required|integer|exists:users,id', 'steps.*.delegate_id' => 'nullable|integer|exists:users,id', 'steps.*.escalate_to' => 'nullable|integer|exists:users,id', 'steps.*.escalation_days' => 'required|integer|between:0,90', 'steps.*.depends_on' => 'nullable|integer|min:1|max:29'])->validate();
        foreach (array_values($data['steps']) as $i => $step) {
            $this->check(! ($step['depends_on'] ?? null) || $step['depends_on'] <= $i, 'Abhängigkeit muss auf einen früheren Schritt verweisen.');
            foreach (array_filter([$step['assigned_to'], $step['delegate_id'] ?? null, $step['escalate_to'] ?? null]) as $id) {
                $this->check(User::findOrFail($id)->status, 'Zuständige Person ist nicht aktiv.');
            }
        }

        return $this->draft(PersonnelWorkflowTemplate::class, $data['title'], $data, $actor);
    }

    public function instantiateWorkflow(PersonnelWorkflowTemplate $template, User $employee, string $anchor, int $revision, User $actor): PersonnelWorkflowRun
    {
        $this->access($actor, $employee->id, true, false);
        Validator::make(['anchor' => $anchor], ['anchor' => 'required|date_format:Y-m-d'])->validate();

        return OperationsTransaction::run(function () use ($template, $employee, $anchor, $revision, $actor) {
            User::lockForUpdate()->findOrFail($employee->id);
            $source = PersonnelWorkflowTemplate::lockForUpdate()->findOrFail($template->id);
            $this->check($source->status === 'active' && $source->revision === $revision, 'Vorlage wurde geändert oder ist nicht freigegeben.');
            $this->check(! PersonnelWorkflowRun::where('template_id', $source->id)->where('user_id', $employee->id)->where('anchor_on', $anchor)->exists(), 'Dieser Prozess wurde zum Stichtag bereits gestartet.');
            $run = PersonnelWorkflowRun::create(['user_id' => $employee->id, 'template_id' => $source->id, 'title' => $source->title, 'anchor_on' => $anchor, 'snapshot' => ['template_revision' => $revision] + $source->payload, 'created_by' => $actor->id]);
            foreach (array_values($source->payload['steps']) as $i => $step) {
                foreach (array_filter([$step['assigned_to'], $step['delegate_id'] ?? null, $step['escalate_to'] ?? null]) as $id) {
                    $responsible = User::findOrFail($id);
                    $this->access($responsible, $employee->id);
                }
                $due = CarbonImmutable::parse($anchor)->addDays($step['offset_days']);
                $task = app(PersonnelProcessService::class)->createTask($employee, ['title' => $step['title'], 'type' => $source->payload['type'], 'assigned_to' => $step['assigned_to'], 'due_on' => $due->toDateString()], $actor);
                PersonnelWorkflowStep::create(['run_id' => $run->id, 'task_id' => $task->id, 'position' => $i + 1, 'depends_on' => $step['depends_on'] ?? null, 'delegate_id' => $step['delegate_id'] ?? null, 'escalate_to' => $step['escalate_to'] ?? null, 'escalate_on' => $due->addDays($step['escalation_days'])->toDateString()]);
            }
            $this->audit($run, $actor, 'workflow.instantiated');

            return $run;
        });
    }

    public function delegateCanComplete(PersonnelTask $task, User $actor): bool
    {
        return Schema::hasColumns('personnel_workflow_steps', ['task_id', 'delegate_id']) && PersonnelWorkflowStep::where('task_id', $task->id)->where('delegate_id', $actor->id)->exists();
    }

    public function checkTaskDependency(PersonnelTask $task): void
    {
        if (! Schema::hasColumns('personnel_workflow_steps', ['task_id', 'run_id', 'depends_on']) || ! $step = PersonnelWorkflowStep::where('task_id', $task->id)->lockForUpdate()->first()) {
            return;
        }
        if ($step->depends_on) {
            $dependency = PersonnelWorkflowStep::where('run_id', $step->run_id)->where('position', $step->depends_on)->firstOrFail();
            $this->check(PersonnelTask::findOrFail($dependency->task_id)->status === 'done', 'Vorherigen Prozessschritt zuerst abschließen.');
        }
    }

    public function syncWorkflow(PersonnelTask $task): void
    {
        if (! Schema::hasTable('personnel_workflow_steps') || ! $step = PersonnelWorkflowStep::where('task_id', $task->id)->first()) {
            return;
        }
        abort_unless(Schema::hasTable('personnel_workflow_runs'), 503, 'Prozessstand muss geprüft werden.');
        $run = PersonnelWorkflowRun::lockForUpdate()->findOrFail($step->run_id);
        $ids = PersonnelWorkflowStep::where('run_id', $run->id)->pluck('task_id');
        if (! PersonnelTask::whereIn('id', $ids)->where('status', '!=', 'done')->exists()) {
            $run->forceFill(['status' => 'done', 'revision' => $run->revision + 1])->save();
        }
    }

    public function escalateWorkflows(): int
    {
        if (! $this->ready()) {
            return 0;
        }

        return PersonnelWorkflowStep::whereNull('escalated_at')->whereNotNull('escalate_to')->where('escalate_on', '<=', now()->toDateString())->get()->sum(function ($step) {
            return OperationsTransaction::run(function () use ($step) {
                $current = PersonnelWorkflowStep::lockForUpdate()->findOrFail($step->id);
                $task = PersonnelTask::find($current->task_id);
                if ($current->escalated_at || ! $task || $task->status !== 'open') {
                    return 0;
                }
                $current->forceFill(['escalated_at' => now()->utc(), 'revision' => $current->revision + 1])->save();

                return 1;
            });
        });
    }

    public function saveReport(array $data, User $actor): PersonnelSavedReport
    {
        OperationsAccess::authorize($actor, 'employees.master-data.view');
        abort_unless($this->ready(), 503);
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'user_ids' => 'required|array|min:1|max:100', 'user_ids.*' => 'integer|exists:users,id', 'interval_days' => 'required|integer|between:0,366'])->validate();
        $this->check(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['until'])) <= 366, 'Auswertungszeitraum ist zu groß.');
        foreach ($data['user_ids'] as $id) {
            $this->access($actor, (int) $id, false, false);
            app(PersonnelScopeService::class)->authorize($actor, (int) $id, 'operations.time.review');
        }

        return PersonnelSavedReport::create(['title' => $data['title'], 'status' => 'active', 'payload' => $data, 'created_by' => $actor->id, 'next_run_on' => now()->toDateString(), 'results' => []]);
    }

    public function runReport(PersonnelSavedReport $report, int $revision, User $actor, ?string $periodKey = null): array
    {
        OperationsAccess::authorize($actor, 'employees.master-data.view');
        abort_unless((int) $report->created_by === (int) $actor->id, 403);

        return OperationsTransaction::run(function () use ($report, $revision, $actor, $periodKey) {
            $record = PersonnelSavedReport::lockForUpdate()->findOrFail($report->id);
            $filter = $record->payload;
            $periodKey ??= 'manual:'.Str::uuid();
            $results = $record->results ?? [];
            if (isset($results[$periodKey])) {
                $this->authorizedReportResult($record, $actor);

                return $results[$periodKey];
            }
            $this->check($record->status === 'active' && $record->revision === $revision, 'Bericht wurde geändert.');
            $rows = [];
            foreach ($filter['user_ids'] as $id) {
                $this->access($actor, (int) $id, false, false);
                app(PersonnelScopeService::class)->authorize($actor, (int) $id, 'operations.time.review');
                $user = User::findOrFail($id);
                $summary = app(WorkforceAccountService::class)->summary($user, $filter['from'], $filter['until'], $actor);
                $rows[] = ['user_id' => $user->id, 'name' => $user->name, 'configured' => $summary['configured'], 'target_minutes' => $summary['target_minutes'], 'actual_minutes' => $summary['actual_minutes'], 'balance_minutes' => $summary['balance_minutes'], 'missing_days' => count($summary['missing_dates']), 'pending_absences' => AbsenceRequest::where('user_id', $id)->where('status', 'pending')->count()];
            }
            $previous = $results === [] ? null : end($results);
            $total = collect($rows)->sum('actual_minutes');
            $result = ['from' => $filter['from'], 'until' => $filter['until'], 'generated_at' => now()->utc()->toIso8601String(), 'rows' => $rows, 'actual_total_minutes' => $total, 'change_minutes' => $previous === null ? null : $total - $previous['actual_total_minutes']];
            $results[$periodKey] = $result;
            $record->forceFill(['results' => array_slice($results, -24, null, true), 'revision' => $revision + 1, 'next_run_on' => $filter['interval_days'] > 0 ? now()->addDays($filter['interval_days'])->toDateString() : null])->save();
            $this->audit($record, $actor, 'personnel_report.generated');

            return $result;
        });
    }

    public function authorizedReportResult(PersonnelSavedReport $report, User $actor): array
    {
        abort_unless($actor->status && (int) $report->created_by === (int) $actor->id, 403);
        foreach ($report->payload['user_ids'] as $id) {
            $this->access($actor, (int) $id, false, false);
            app(PersonnelScopeService::class)->authorize($actor, (int) $id, 'operations.time.review');
        }

        return $report->results ?? [];
    }

    public function reportState(PersonnelSavedReport $report, int $revision, bool $active, User $actor): void
    {
        OperationsAccess::authorize($actor, 'employees.master-data.view');
        abort_unless((int) $report->created_by === (int) $actor->id, 403);
        OperationsTransaction::run(function () use ($report, $revision, $active, $actor): void {
            $record = PersonnelSavedReport::lockForUpdate()->findOrFail($report->id);
            $this->check($record->revision === $revision && in_array($record->status, ['active', 'paused'], true), 'Bericht wurde geändert.');
            if ($active) {
                $this->authorizedReportResult($record, $actor);
            }
            $next = $active && $record->payload['interval_days'] > 0 ? now()->toDateString() : null;
            if ($next && isset(($record->results ?? [])['scheduled:'.$next])) {
                $next = now()->addDays($record->payload['interval_days'])->toDateString();
            }
            $record->forceFill(['status' => $active ? 'active' : 'paused', 'next_run_on' => $next, 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'personnel_report.state_changed');
        });
    }

    public function runScheduledReports(): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $count = 0;
        foreach (PersonnelSavedReport::where('status', 'active')->where('next_run_on', '<=', now()->toDateString())->get() as $report) {
            $actor = User::find($report->created_by);
            if (! $actor || ! $actor->status) {
                continue;
            }
            try {
                $this->runReport($report, $report->revision, $actor, 'scheduled:'.$report->next_run_on);
                $count++;
            } catch (AuthorizationException|HttpException $exception) {
                // A lost scope is not a reason to expose or email an old snapshot.
                continue;
            }
        }

        return $count;
    }

    public function saveDocumentTemplate(array $data, User $actor): PersonnelDocumentTemplate
    {
        $this->global($actor);
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'body' => 'required|string|max:30000'])->validate();
        preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $data['body'], $matches);
        $this->check(collect($matches[1])->every(fn ($key) => in_array(trim($key), ['employee_name', 'date', 'role'], true)), 'Unbekannter Vorlagenplatzhalter.');

        return $this->draft(PersonnelDocumentTemplate::class, $data['title'], $data, $actor);
    }

    public function renderDocument(PersonnelDocumentTemplate $template, User $employee, User $actor): string
    {
        $this->access($actor, $employee->id, true, false);
        $this->check($template->status === 'active', 'Vorlage ist nicht freigegeben.');
        $values = ['employee_name' => $employee->name, 'date' => now(config('operations.display_timezone'))->format('d.m.Y'), 'role' => $employee->role];

        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', fn ($match) => $values[trim($match[1])] ?? '', $template->payload['body']);
    }

    public function requestSignature(EmployeeDocumentVersion $version, array $data, User $actor): PersonnelSignatureRequest
    {
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'template_id' => 'nullable|integer|exists:personnel_document_templates,id'])->validate();
        $userId = (int) $version->requirement->user_id;
        $this->access($actor, $userId, true, false);

        return OperationsTransaction::run(function () use ($version, $data, $userId, $actor) {
            User::lockForUpdate()->findOrFail($userId);
            $version = EmployeeDocumentVersion::lockForUpdate()->findOrFail($version->id);
            $this->currentDocument($version);
            $this->check(! PersonnelSignatureRequest::where('document_version_id', $version->id)->whereIn('status', ['pending_external', 'result_submitted'])->exists(), 'Für diesen Dokumentstand ist bereits eine Unterzeichnung offen.');
            if ($data['template_id'] ?? null) {
                $this->check(PersonnelDocumentTemplate::findOrFail($data['template_id'])->status === 'active', 'Dokumentvorlage ist nicht freigegeben.');
            }
            $request = PersonnelSignatureRequest::create(['user_id' => $userId, 'document_version_id' => $version->id, 'template_id' => $data['template_id'] ?? null, 'title' => $data['title'], 'due_on' => $data['due_on'], 'created_by' => $actor->id, 'status' => 'pending_external', 'payload' => ['source_sha256' => $version->snapshot['sha256'] ?? null, 'external_adapter_enabled' => false, 'method' => 'uploaded_result']]);
            $this->audit($request, $actor, 'document_signature.requested');

            return $request;
        });
    }

    public function submitSignatureResult(PersonnelSignatureRequest $request, int $revision, UploadedFile $file, User $actor): void
    {
        $this->access($actor, (int) $request->user_id);
        abort_unless((int) $actor->id === (int) $request->user_id, 403);
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf|max:12288'])->validate();
        $path = $file->store('operations/signature-results', 'private');
        try {
            OperationsTransaction::run(function () use ($request, $revision, $path, $actor): void {
                User::lockForUpdate()->findOrFail($request->user_id);
                $current = PersonnelSignatureRequest::lockForUpdate()->findOrFail($request->id);
                $this->check($current->revision === $revision && $current->status === 'pending_external', 'Signaturanfrage wurde geändert.');
                $this->currentDocument(EmployeeDocumentVersion::findOrFail($current->document_version_id));
                $current->forceFill(['status' => 'result_submitted', 'payload' => $current->payload + ['result_path' => $path, 'result_sha256' => hash_file('sha256', Storage::disk('private')->path($path)), 'submitted_by' => $actor->id], 'revision' => $revision + 1])->save();
                $this->audit($current, $actor, 'document_signature.result_submitted');
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    public function reviewSignatureResult(PersonnelSignatureRequest $request, int $revision, bool $accept, User $actor): void
    {
        $this->access($actor, (int) $request->user_id, true, false);
        abort_if((int) $request->user_id === (int) $actor->id, 403);
        OperationsTransaction::run(function () use ($request, $revision, $accept, $actor): void {
            User::lockForUpdate()->findOrFail($request->user_id);
            $current = PersonnelSignatureRequest::lockForUpdate()->findOrFail($request->id);
            $this->check($current->status === 'result_submitted' && $current->revision === $revision, 'Ergebnis wurde geändert.');
            $this->currentDocument(EmployeeDocumentVersion::findOrFail($current->document_version_id));
            $path = $current->payload['result_path'] ?? '';
            $this->check(str_starts_with($path, 'operations/signature-results/') && Storage::disk('private')->exists($path) && hash_file('sha256', Storage::disk('private')->path($path)) === ($current->payload['result_sha256'] ?? null), 'Ergebnisdatei fehlt oder wurde geändert.');
            $current->forceFill(['status' => $accept ? 'verified_result' : 'rejected', 'revision' => $revision + 1, 'payload' => $current->payload + ['reviewed_by' => $actor->id, 'reviewed_at' => now()->utc()->toIso8601String()]])->save();
            $this->audit($current, $actor, 'document_signature.result_reviewed');
            // Verified uploaded bytes are not a qualified signature certificate.
        });
    }

    public function signatureResult(PersonnelSignatureRequest $request, User $actor)
    {
        $this->access($actor, (int) $request->user_id);
        $path = $request->payload['result_path'] ?? '';
        abort_unless(str_starts_with($path, 'operations/signature-results/') && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->download($path, 'Unterzeichnetes-Ergebnis.pdf', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function signatureSource(PersonnelSignatureRequest $request, User $actor)
    {
        $this->access($actor, (int) $request->user_id);
        $version = EmployeeDocumentVersion::findOrFail($request->document_version_id);
        $requirement = $version->requirement;
        abort_unless((int) $requirement->user_id === (int) $request->user_id, 403);

        return app(EmployeeDocumentVersionService::class)->download((int) $request->user_id, $requirement->document_type, $version->id, $actor);
    }

    public function saveEmergency(User $employee, array $data, int $revision, User $actor): EmployeeEmergencyContact
    {
        OperationsAccess::own($actor, $employee->id);
        abort_unless($this->ready(), 503);
        $data = Validator::make($data, ['name' => 'required|string|max:180', 'relationship' => 'required|string|max:100', 'phone' => 'required|string|max:50', 'consent' => 'accepted'])->validate();

        return OperationsTransaction::run(function () use ($employee, $data, $revision) {
            User::lockForUpdate()->findOrFail($employee->id);
            $record = EmployeeEmergencyContact::where('user_id', $employee->id)->lockForUpdate()->first();
            $this->check(($record?->revision ?? 0) === $revision, 'Notfallkontakt wurde geändert.');
            if (! $record) {
                $record = new EmployeeEmergencyContact(['user_id' => $employee->id]);
            }
            $record->forceFill(['payload' => $data, 'confirmed_at' => now()->utc(), 'revision' => $revision + 1])->save();

            return $record;
        });
    }

    public function readEmergency(User $employee, string $reason, User $actor): array
    {
        $this->access($actor, $employee->id);
        if ((int) $actor->id !== (int) $employee->id) {
            app(PersonnelScopeService::class)->authorize($actor, $employee->id, 'employees.emergency.access');
            Validator::make(['reason' => $reason], ['reason' => 'required|string|min:10|max:500'])->validate();
        }
        $record = EmployeeEmergencyContact::where('user_id', $employee->id)->firstOrFail();
        if ((int) $actor->id !== (int) $employee->id) {
            // The justification is encrypted in a dedicated audit payload, never the contact.
            $this->audit($record, $actor, 'emergency.break_glass', ['reason_ciphertext' => encrypt($reason)]);
        }

        return $record->payload + ['revision' => $record->revision, 'confirmed_at' => $record->confirmed_at->toIso8601String()];
    }

    public function saveCalendar(array $data, User $actor): RegionalPersonnelCalendar
    {
        $this->global($actor, 'operations.rules.manage');
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'region' => 'required|string|max:100', 'year' => 'required|integer|between:2000,2200', 'dates' => 'present|array|max:366', 'dates.*' => 'required|date_format:Y-m-d', 'source' => 'required|string|min:5|max:500'])->validate();
        $this->check(collect($data['dates'])->every(fn ($date) => (int) substr($date, 0, 4) === (int) $data['year']), 'Alle Daten müssen im Kalenderjahr liegen.');
        $data['dates'] = collect($data['dates'])->unique()->sort()->values()->all();

        return $this->draft(RegionalPersonnelCalendar::class, $data['title'], $data, $actor);
    }

    public function calendarImpact(RegionalPersonnelCalendar $calendar, User $employee, User $actor): array
    {
        $this->access($actor, $employee->id);
        $year = $calendar->payload['year'];
        $requests = AbsenceRequest::where('user_id', $employee->id)->where('kind', 'vacation')->whereIn('status', ['pending', 'approved'])->where('starts_at', '<', ($year + 1).'-01-01')->where('ends_at', '>', $year.'-01-01')->get(['id', 'status']);

        return ['year' => $year, 'region' => $calendar->payload['region'], 'dates' => $calendar->payload['dates'], 'affected_absences' => $requests->toArray(), 'existing_credits_unchanged' => true, 'carry_requires_separate_credit' => true];
    }

    public function applyCalendar(RegionalPersonnelCalendar $calendar, User $employee, array $data, int $revision, User $actor): EmployeeVacationPolicy
    {
        $this->access($actor, $employee->id, true, false);

        return OperationsTransaction::run(function () use ($calendar, $employee, $data, $revision, $actor) {
            $record = RegionalPersonnelCalendar::lockForUpdate()->findOrFail($calendar->id);
            $this->check($record->status === 'active' && $record->revision === $revision, 'Kalender wurde geändert oder ist nicht freigegeben.');
            $p = $record->payload;

            // A new policy is a draft; old allocations and historical snapshots are never rewritten.
            return app(WorkforceAccountService::class)->createPolicy($employee, ['name' => $record->title, 'starts_on' => $p['year'].'-01-01', 'ends_on' => $p['year'].'-12-31', 'unit' => $data['unit'] ?? 'days', 'holiday_region' => $p['region'], 'calendar_version' => 'calendar:'.$record->id.':'.$record->revision, 'non_working_dates' => $p['dates']], $actor);
        });
    }

    public function saveApplicant(array $data, User $actor): PersonnelApplicant
    {
        $this->global($actor, 'employees.recruiting.manage');
        $data = Validator::make($data, ['name' => 'required|string|max:180', 'email' => 'required|email|max:180', 'role' => 'required|string|max:120', 'retain_until' => 'required|date_format:Y-m-d|after:today', 'note' => 'nullable|string|max:2000'])->validate();

        return PersonnelApplicant::create(['title' => $data['role'], 'status' => 'received', 'due_on' => $data['retain_until'], 'created_by' => $actor->id, 'payload' => $data + ['history' => []]]);
    }

    public function applicantStage(PersonnelApplicant $applicant, int $revision, array $data, User $actor): void
    {
        $this->global($actor, 'employees.recruiting.manage');
        $data = Validator::make($data, ['stage' => 'required|in:screening,interview,offer,rejected,withdrawn', 'note' => 'required|string|min:5|max:2000', 'interview_on' => 'nullable|date_format:Y-m-d'])->validate();
        OperationsTransaction::run(function () use ($applicant, $revision, $data, $actor): void {
            $record = PersonnelApplicant::lockForUpdate()->findOrFail($applicant->id);
            $this->check($record->revision === $revision && ! in_array($record->status, ['converted', 'rejected', 'withdrawn', 'erased'], true), 'Bewerbung wurde geändert oder abgeschlossen.');
            $allowed = ['received' => ['screening', 'rejected', 'withdrawn'], 'screening' => ['interview', 'rejected', 'withdrawn'], 'interview' => ['offer', 'rejected', 'withdrawn'], 'offer' => ['rejected', 'withdrawn']];
            $this->check(in_array($data['stage'], $allowed[$record->status] ?? [], true), 'Unzulässiger Stufenwechsel.');
            $this->check($data['stage'] !== 'interview' || ! empty($data['interview_on']), 'Interviewtermin fehlt.');
            $p = $record->payload;
            $p['history'][] = $data + ['by' => $actor->id, 'at' => now()->utc()->toIso8601String()];
            $record->forceFill(['status' => $data['stage'], 'payload' => $p, 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'applicant.stage_changed');
        });
    }

    public function convertApplicant(PersonnelApplicant $applicant, int $revision, User $employee, bool $consent, User $actor): void
    {
        $this->global($actor, 'employees.recruiting.manage');
        $this->access($actor, $employee->id, true, false);
        $this->check($consent && $employee->role === 'staff' && $employee->status, 'Aktiver Mitarbeiter und ausdrückliche Übernahmebestätigung erforderlich.');
        OperationsTransaction::run(function () use ($applicant, $revision, $employee, $actor): void {
            $record = PersonnelApplicant::lockForUpdate()->findOrFail($applicant->id);
            $this->check($record->status === 'offer' && $record->revision === $revision, 'Bewerbung ist nicht zur Übernahme bereit.');
            $record->forceFill(['status' => 'converted', 'user_id' => $employee->id, 'payload' => $record->payload + ['converted_by' => $actor->id, 'converted_at' => now()->utc()->toIso8601String()], 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'applicant.converted');
            // Identity creation/invitation uses the existing controlled employee process.
        });
    }

    public function eraseApplicant(PersonnelApplicant $applicant, int $revision, User $actor): void
    {
        $this->global($actor, 'employees.recruiting.manage');
        OperationsTransaction::run(function () use ($applicant, $revision, $actor): void {
            $record = PersonnelApplicant::lockForUpdate()->findOrFail($applicant->id);
            $this->check($record->revision === $revision && in_array($record->status, ['rejected', 'withdrawn'], true) && CarbonImmutable::parse($record->due_on)->isPast(), 'Aufbewahrungsfrist oder Status erlaubt noch keine Bereinigung.');
            $record->forceFill(['payload' => ['erased_at' => now()->utc()->toIso8601String()], 'status' => 'erased', 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'applicant.erased');
        });
    }

    public function saveDevelopment(User $employee, array $data, User $actor): PersonnelDevelopmentReview
    {
        $this->access($actor, $employee->id, true, false);
        app(PersonnelScopeService::class)->authorize($actor, $employee->id, 'employees.development.manage');
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'goal' => 'required|string|max:3000', 'due_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'responsible_id' => 'required|integer|exists:users,id'])->validate();
        $responsible = User::findOrFail($data['responsible_id']);
        $this->access($responsible, $employee->id, true, false);
        app(PersonnelScopeService::class)->authorize($responsible, $employee->id, 'employees.development.manage');

        return PersonnelDevelopmentReview::create(['user_id' => $employee->id, 'title' => $data['title'], 'status' => 'open', 'due_on' => $data['due_on'], 'created_by' => $actor->id, 'payload' => $data + ['reviews' => []]]);
    }

    public function developmentFeedback(PersonnelDevelopmentReview $review, int $revision, string $feedback, bool $complete, User $actor): void
    {
        $this->access($actor, (int) $review->user_id);
        if ((int) $actor->id !== (int) $review->user_id) {
            app(PersonnelScopeService::class)->authorize($actor, (int) $review->user_id, 'employees.development.manage');
        }
        Validator::make(['feedback' => $feedback], ['feedback' => 'required|string|min:5|max:3000'])->validate();
        abort_if($complete && (int) $actor->id === (int) $review->user_id, 403);
        OperationsTransaction::run(function () use ($review, $revision, $feedback, $complete, $actor): void {
            $record = PersonnelDevelopmentReview::lockForUpdate()->findOrFail($review->id);
            $this->check($record->status === 'open' && $record->revision === $revision, 'Entwicklungsgespräch wurde geändert.');
            $p = $record->payload;
            $p['reviews'][] = ['by' => $actor->id, 'feedback' => $feedback, 'at' => now()->utc()->toIso8601String()];
            $record->forceFill(['payload' => $p, 'status' => $complete ? 'completed' : 'open', 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'development.feedback_recorded');
        });
    }

    public function createSurvey(array $data, User $actor): PersonnelSurvey
    {
        $this->global($actor);
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'question' => 'required|string|max:1000', 'threshold' => 'required|integer|between:5,100', 'until' => 'required|date_format:Y-m-d|after:today', 'user_ids' => 'required|array|min:5|max:1000', 'user_ids.*' => 'integer|exists:users,id'])->validate();
        $data['user_ids'] = array_values(array_unique(array_map('intval', $data['user_ids'])));
        $this->check(count($data['user_ids']) >= (int) $data['threshold'], 'Teilnehmerzahl unterschreitet die Aggregationsschwelle.');
        foreach ($data['user_ids'] as $id) {
            $this->check(User::findOrFail($id)->role === 'staff' && User::findOrFail($id)->status, 'Nur aktive Mitarbeiter können teilnehmen.');
        }

        return PersonnelSurvey::create(['title' => $data['title'], 'status' => 'open', 'payload' => $data, 'created_by' => $actor->id]);
    }

    public function surveyResponse(PersonnelSurvey $survey, int $revision, string $action, ?int $score, User $actor): void
    {
        OperationsAccess::own($actor, $actor->id);
        Validator::make(['action' => $action, 'score' => $score], ['action' => 'required|in:submit,decline,withdraw', 'score' => $action === 'submit' ? 'required|integer|between:1,5' : 'nullable'])->validate();
        OperationsTransaction::run(function () use ($survey, $revision, $action, $score, $actor): void {
            $record = PersonnelSurvey::lockForUpdate()->findOrFail($survey->id);
            abort_unless(in_array((int) $actor->id, $record->payload['user_ids'], true), 403);
            $this->check($record->status === 'open' && $record->revision === $revision && CarbonImmutable::parse($record->payload['until'])->endOfDay()->isFuture(), 'Umfrage wurde geändert oder ist abgeschlossen.');
            $response = PersonnelSurveyResponse::where('survey_id', $record->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            $this->check($action !== 'withdraw' || $response?->status === 'submitted', 'Keine Antwort zum Zurückziehen vorhanden.');
            if (! $response) {
                $response = new PersonnelSurveyResponse(['survey_id' => $record->id, 'user_id' => $actor->id]);
            }
            $response->forceFill(['status' => ['submit' => 'submitted', 'decline' => 'declined', 'withdraw' => 'withdrawn'][$action], 'payload' => $action === 'submit' ? ['score' => $score] : null, 'revision' => ($response->exists ? $response->revision : 0) + 1])->save();
            // No individual answer or participation status goes to the manager's audit/inbox.
        });
    }

    public function surveyAggregate(PersonnelSurvey $survey, User $actor): array
    {
        $this->global($actor);
        abort_unless((int) $survey->created_by === (int) $actor->id || $actor->isAdmin(), 403);
        // Results are released only after closing: live differencing cannot identify a responder.
        $this->check(CarbonImmutable::parse($survey->payload['until'])->endOfDay()->isPast(), 'Ergebnisse erst nach Ende der Umfrage anzeigen.');
        $responses = PersonnelSurveyResponse::where('survey_id', $survey->id)->where('status', 'submitted')->get();
        $this->check($responses->count() >= $survey->payload['threshold'], 'Zu wenige Antworten für eine geschützte Auswertung.');

        return ['count' => $responses->count(), 'average' => round($responses->avg(fn ($response) => $response->payload['score']), 1), 'distribution' => $responses->countBy(fn ($response) => $response->payload['score'])->all()];
    }

    public function openSicknessEvidence(AbsenceRequest $absence, string $due, User $actor): SicknessEvidenceWorkflow
    {
        $this->access($actor, (int) $absence->user_id, true, false);
        app(PersonnelScopeService::class)->authorize($actor, (int) $absence->user_id, 'operations.absences.review');
        Validator::make(['due' => $due], ['due' => 'required|date_format:Y-m-d'])->validate();
        $this->check($absence->kind === 'sick' && $absence->status === 'reported', 'Aktive Krankmeldung erforderlich.');

        return OperationsTransaction::run(function () use ($absence, $due, $actor) {
            User::lockForUpdate()->findOrFail($absence->user_id);
            $this->check(! SicknessEvidenceWorkflow::where('absence_request_id', $absence->id)->exists(), 'Nachweisprüfung ist bereits angelegt.');

            return SicknessEvidenceWorkflow::create(['user_id' => $absence->user_id, 'absence_request_id' => $absence->id, 'title' => 'Nachweisprüfung', 'status' => 'open', 'due_on' => $due, 'created_by' => $actor->id, 'payload' => ['absence_revision' => $absence->revision, 'covered_from' => null, 'covered_until' => null, 'history' => [], 'external_adapter_enabled' => false]]);
        });
    }

    public function sicknessEvidence(SicknessEvidenceWorkflow $workflow, int $revision, array $data, User $actor): void
    {
        $this->access($actor, (int) $workflow->user_id);
        $own = (int) $actor->id === (int) $workflow->user_id;
        if (! $own) {
            $this->access($actor, (int) $workflow->user_id, true, false);
            app(PersonnelScopeService::class)->authorize($actor, (int) $workflow->user_id, 'operations.absences.review');
        }
        $data = Validator::make($data, ['action' => 'required|in:follow_up,submitted,covered,uncovered', 'covered_from' => 'nullable|date_format:Y-m-d', 'covered_until' => 'nullable|date_format:Y-m-d|after_or_equal:covered_from', 'note' => 'required|string|min:5|max:1000'])->validate();
        abort_if($own && $data['action'] !== 'submitted', 403);
        OperationsTransaction::run(function () use ($workflow, $revision, $data, $actor): void {
            $record = SicknessEvidenceWorkflow::lockForUpdate()->findOrFail($workflow->id);
            $absence = AbsenceRequest::findOrFail($record->absence_request_id);
            $this->check($record->revision === $revision && $absence->kind === 'sick' && $absence->status === 'reported', 'Nachweisprüfung wurde geändert.');
            $p = $record->payload;
            if ($data['action'] === 'covered') {
                $this->check((int) $actor->id !== (int) $record->user_id && ! empty($data['covered_from']) && ! empty($data['covered_until']), 'Nachweisabdeckung und Personalprüfung erforderlich.');
                $from = $absence->starts_at->setTimezone($absence->timezone)->toDateString();
                $until = $absence->ends_at->setTimezone($absence->timezone)->subSecond()->toDateString();
                $this->check($data['covered_from'] <= $from && $data['covered_until'] >= $until, 'Nachweis deckt den gemeldeten Zeitraum nicht vollständig ab.');
                $p['covered_from'] = $data['covered_from'];
                $p['covered_until'] = $data['covered_until'];
                $p['absence_revision'] = $absence->revision;
            }
            $p['history'][] = $data + ['by' => $actor->id, 'at' => now()->utc()->toIso8601String()];
            $record->forceFill(['payload' => $p, 'status' => $data['action'], 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'sickness_evidence.updated');
            // Medical details never enter the operations timeline or a global inbox.
        });
    }

    public function activate(Model $model, int $revision, User $actor): void
    {
        abort_unless(in_array($model::class, [PersonnelWorkflowTemplate::class, PersonnelDocumentTemplate::class, RegionalPersonnelCalendar::class], true), 403);
        $this->global($actor, $model instanceof RegionalPersonnelCalendar ? 'operations.rules.manage' : 'employees.master-data.edit');
        OperationsTransaction::run(function () use ($model, $revision, $actor): void {
            $record = $model::lockForUpdate()->findOrFail($model->id);
            abort_if((int) $record->created_by === (int) $actor->id, 403);
            $this->check($record->status === 'draft' && $record->revision === $revision, 'Entwurf wurde geändert.');
            $record->forceFill(['status' => 'active', 'approved_by' => $actor->id, 'approved_at' => now()->utc(), 'revision' => $revision + 1])->save();
            $this->audit($record, $actor, 'personnel_template.activated');
        });
    }

    public function inbox(User $actor, bool $personal = false): Collection
    {
        if (! $this->ready() || ! $actor->status) {
            return collect();
        }
        $items = collect();
        foreach ([PersonnelSignatureRequest::class => 'documents', PersonnelWorkflowRun::class => 'workflows', SicknessEvidenceWorkflow::class => 'sickness'] as $class => $tab) {
            $query = $class::query()->whereNotIn('status', ['done', 'verified_result', 'rejected', 'cancelled']);
            if ($personal) {
                OperationsAccess::own($actor, $actor->id);
                $query->where('user_id', $actor->id);
            } else {
                if (! Gate::forUser($actor)->allows('employees.master-data.view')) {
                    continue;
                }
                app(PersonnelScopeService::class)->applyRelatedQuery($query, $actor, 'employees.master-data.view');
                if ($tab === 'sickness') {
                    if (! Gate::forUser($actor)->allows('employees.master-data.edit') || ! Gate::forUser($actor)->allows('operations.absences.review')) {
                        continue;
                    }
                    app(PersonnelScopeService::class)->applyRelatedQuery($query, $actor, 'employees.master-data.edit');
                    app(PersonnelScopeService::class)->applyRelatedQuery($query, $actor, 'operations.absences.review');
                }
            }
            foreach ($query->limit(100)->get() as $record) {
                $status = $record->status;
                if ($record instanceof SicknessEvidenceWorkflow && $status === 'covered') {
                    $absence = AbsenceRequest::find($record->absence_request_id);
                    if ($absence?->revision === ($record->payload['absence_revision'] ?? null)) {
                        continue;
                    }
                    $status = 'needs_review';
                }
                $items->push(['id' => 'personnel_'.$tab.':'.$record->id, 'record_id' => $record->id, 'kind' => 'personnel_'.$tab, 'title' => ['documents' => 'Unterlage prüfen', 'workflows' => 'Personalprozess', 'sickness' => 'Nachweisstatus prüfen'][$tab], 'user_id' => $record->user_id, 'due_on' => $record->due_on ?? null, 'status' => $status, 'revision' => $record->revision, 'target_tab' => $tab]);
            }
        }
        foreach (PersonnelWorkflowStep::where(fn ($q) => $q->where('delegate_id', $actor->id)->orWhere(fn ($q) => $q->where('escalate_to', $actor->id)->whereNotNull('escalated_at')))->limit(100)->get() as $step) {
            $task = PersonnelTask::find($step->task_id);
            if (! $task || $task->status !== 'open' || ($personal && (int) $task->user_id !== (int) $actor->id)) {
                continue;
            }
            if ((int) $task->user_id === (int) $actor->id) {
                if (! OperationsAccess::isEmployee($actor)) {
                    continue;
                }
            } elseif (! app(PersonnelScopeService::class)->allows($actor, (int) $task->user_id, 'employees.master-data.view')) {
                continue;
            }
            $items->push(['id' => 'personnel_task:'.$task->id, 'record_id' => $task->id, 'kind' => 'personnel_workflows', 'title' => $step->escalated_at ? 'Personalaufgabe · Eskalation' : 'Personalaufgabe · Vertretung', 'user_id' => $task->user_id, 'due_on' => $task->due_on, 'status' => $step->escalated_at ? 'escalated' : 'open', 'revision' => $task->revision, 'target_tab' => 'workflows']);
        }

        return $items;
    }

    private function global(User $actor, string $ability = 'employees.master-data.edit'): void
    {
        abort_unless($this->ready(), 503);
        app(PersonnelScopeService::class)->authorizeGlobal($actor, $ability);
    }

    private function draft(string $class, string $title, array $payload, User $actor): Model
    {
        return OperationsTransaction::run(function () use ($class, $title, $payload, $actor) {
            $record = $class::create(['title' => $title, 'payload' => $payload, 'created_by' => $actor->id]);
            $this->audit($record, $actor, 'personnel_template.drafted');

            return $record->fresh();
        });
    }

    private function currentDocument(EmployeeDocumentVersion $version): void
    {
        $requirement = $version->requirement;
        $this->check(! $version->withdrawn_at && $requirement->file?->id === $version->file_id, 'Unterlage ist nicht mehr aktuell.');
        $file = $version->file;
        $this->check($file && $file->disk === 'private' && Storage::disk('private')->exists($file->path) && hash_file('sha256', Storage::disk('private')->path($file->path)) === ($version->snapshot['sha256'] ?? null), 'Dokumentdatei fehlt oder wurde geändert.');
    }

    private function audit(Model $model, User $actor, string $action, array $data = []): void
    {
        app(OperationsAuditService::class)->record($model, $actor, $action, $data);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
