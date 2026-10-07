<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\AiIntake;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSubmission;
use App\Models\EmployeeQualification;
use App\Models\OperationsAttentionItem;
use App\Models\PersonnelPlanReview;
use App\Models\PersonnelTask;
use App\Models\ShiftAssignment;
use App\Models\StaffingCase;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnifiedOperationsInboxService
{
    public function items(User $actor, bool $personal = false): Collection
    {
        $actor = User::findOrFail($actor->id);
        if ($personal) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            OperationsAccess::authorize($actor, 'operations.inbox.view');
        }
        OperationsAccess::requireReady();
        $items = collect();
        $scope = app(PersonnelScopeService::class);
        $add = function (string $prefix, $rows, string $kind, string $module, string $titleField = 'title', int $priority = 2) use (&$items, $personal) {
            foreach ($rows as $row) {
                $items->push((object) [
                    'id' => $prefix.'-'.$row->id, 'kind' => $kind, 'title' => $row->$titleField ?? $kind, 'subject' => $row->user?->name ?? '',
                    'user_id' => $row->user_id ?? null, 'due_at' => $row->due_at ?? ($row->due_on ? CarbonImmutable::parse($row->due_on, config('operations.display_timezone', 'Europe/Berlin'))->endOfDay() : null),
                    'status' => $row->status ?? 'open', 'revision' => $row->revision ?? 1, 'priority' => $priority,
                    'module' => $module, 'target_tab' => null, 'record_id' => $row->id, 'personal' => $personal,
                ]);
            }
        };
        if (! $personal && $actor->can('operations.time.review')) {
            $rows = $scope->applyRelatedQuery(WorkTimeEntry::where('status', 'submitted')->with('user'), $actor, 'operations.time.review')->limit(100)->get();
            $add('time', $rows, 'Zeitfreigabe', 'times', 'context_title', 1);
        }
        if (! $personal && $actor->can('operations.absences.review')) {
            $query = $scope->applyRelatedQuery(AbsenceRequest::where('status', 'pending')->with('user'), $actor, 'operations.absences.review');
            // A configured approval chain must only appear to its current reviewer.
            if (class_exists(AbsenceApprovalChainService::class) && app(AbsenceApprovalChainService::class)->ready()) {
                $chains = app(AbsenceApprovalChainService::class)->inbox($actor);
                foreach ($chains as $item) {
                    $data = (array) $item;
                    $items->push((object) (array_merge(['subject' => '', 'revision' => 1], $data, ['record_id' => $data['id'], 'due_at' => $data['due_at'] ?? (filled($data['due_on'] ?? null) ? CarbonImmutable::parse($data['due_on'], config('operations.display_timezone', 'Europe/Berlin'))->endOfDay() : null), 'id' => 'approval-'.$data['id'], 'kind' => 'Abwesenheitsfreigabe', 'module' => 'absences', 'target_tab' => 'approvals', 'personal' => false, 'priority' => 1])));
                }
                if (Schema::hasTable('absence_approval_steps')) {
                    $query->whereNotIn('id', DB::table('absence_approval_steps')->select('absence_request_id'));
                }
            }
            $add('absence', $query->limit(100)->get(), 'Abwesenheitsfreigabe', 'absences', 'kind', 1);
        }
        if (! $personal && $actor->can('operations.qualifications.manage')) {
            $add('qualification', $scope->applyRelatedQuery(EmployeeQualification::where('status', 'pending')->with('user'), $actor, 'operations.qualifications.manage')->limit(100)->get(), 'Nachweisprüfung', 'qualifications', 'status', 1);
        }
        if (Schema::hasTable('personnel_tasks')) {
            $query = PersonnelTask::where('status', 'open')->with('assignee');
            if ($personal) {
                $query->where('user_id', $actor->id);
            } elseif ($actor->can('employees.master-data.view')) {
                $scope->applyRelatedQuery($query, $actor, 'employees.master-data.view');
            } else {
                $query->whereRaw('1=0');
            }
            $add('task', $query->limit(100)->get(), 'Aufgabe', 'personnel-processes');
        }
        if (! $personal && Schema::hasTable('personnel_plan_reviews') && $actor->can('employees.master-data.view')) {
            $add('plan-review', $scope->applyRelatedQuery(PersonnelPlanReview::where('status', 'open'), $actor, 'employees.master-data.view')->limit(100)->get(), 'Planprüfung', 'workforce-accounts', 'trigger', 1);
        }
        if (! $personal && Schema::hasTable('staffing_cases') && $actor->can('operations.manage')) {
            $add('case', StaffingCase::whereIn('status', ['open', 'escalated'])->where('responsible_id', $actor->id)->limit(100)->get(), 'Ausfall / Ablösung', 'workforce-planning', 'kind', 0);
        }
        if (app(OperationsReminderService::class)->ready()) {
            foreach (OperationsAttentionItem::where('recipient_user_id', $actor->id)->whereNull('resolved_at')->limit(200)->get() as $row) {
                if ($row->subject_user_id && $row->subject_user_id !== $actor->id && ! $actor->can('operations.manage')) {
                    continue;
                }
                if ($row->kind === 'monitor_case' && (! Schema::hasTable('staffing_cases') || ! StaffingCase::whereKey($row->record_id)->whereIn('status', ['open', 'escalated'])->exists())) {
                    continue;
                }
                $items->push((object) ['id' => 'notice-'.$row->id, 'kind' => $row->kind === 'monitor_case' ? 'Meldung prüfen' : 'Erinnerung', 'title' => $row->headline, 'subject' => '', 'user_id' => $row->subject_user_id, 'due_at' => $row->due_at, 'status' => $row->read_at ? 'read' : 'open', 'revision' => $row->revision, 'priority' => $row->kind === 'monitor_case' ? 0 : 2, 'module' => $row->module, 'target_tab' => null, 'record_id' => $row->record_id, 'personal' => $personal]);
                $items->last()->record_type = match ($row->kind) {
                    'monitor_case' => 'staffing-case', 'shift_reply', 'punch_start' => 'shift-assignment',
                    'wish_due' => 'availability-period', 'task_due' => 'task',
                    'qualification_expiry' => 'qualification', 'punch_end', 'time_incomplete' => 'work-time', default => '',
                };
                $items->last()->target_revision = $row->source_revision;
            }
        }
        if (class_exists(PersonnelEnhancementService::class) && app(PersonnelEnhancementService::class)->ready()) {
            foreach (app(PersonnelEnhancementService::class)->inbox($actor, $personal) as $row) {
                $data = (array) $row;
                $data['kind'] = match ($data['kind'] ?? '') {
                    'personnel_documents' => 'Unterlage',
                    'personnel_workflows' => 'Personalprozess',
                    'personnel_sickness' => 'Nachweisstatus',
                    default => $data['kind'] ?? 'Aufgabe',
                };
                $items->push((object) array_merge($data, ['id' => 'personnel-'.$data['id'], 'module' => 'personnel-enhancements', 'personal' => $personal, 'priority' => 2, 'subject' => $data['subject'] ?? '', 'record_id' => $data['record_id'] ?? null, 'due_at' => $data['due_at'] ?? (filled($data['due_on'] ?? null) ? CarbonImmutable::parse($data['due_on'], config('operations.display_timezone', 'Europe/Berlin'))->endOfDay() : null)]));
            }
        }

        if (CustomerPortalIntakeSchema::ready()) {
            if ($personal) {
                foreach (CustomerCapacityCommitment::where('user_id', $actor->id)->where('status', 'requested')->where('starts_at', '>', now()->utc())->limit(100)->get() as $row) {
                    $items->push((object) ['id' => 'portal-capacity-'.$row->id, 'kind' => 'Kapazitätsanfrage', 'title' => $row->role_name, 'subject' => '', 'user_id' => $actor->id, 'due_at' => $row->starts_at, 'status' => $row->status, 'revision' => $row->revision, 'priority' => 1, 'module' => 'customer-capacity', 'target_tab' => null, 'record_id' => $row->id, 'personal' => true]);
                }
            } elseif ($actor->can('customers.portal.manage')) {
                $items = $items->merge(app(CustomerCapacityService::class)->reviewItems($actor));
                $customers = app(CustomerPortalScope::class)->manageableCustomers($actor)->pluck('id');
                foreach (CustomerPortalSubmission::whereIn('customer_id', $customers)->whereIn('status', ['review', 'offered'])->limit(100)->get() as $row) {
                    $items->push((object) ['id' => 'portal-submission-'.$row->id, 'kind' => 'Kundenanfrage', 'title' => 'Leistungsanfrage #'.$row->id, 'subject' => '', 'user_id' => null, 'due_at' => null, 'status' => $row->status, 'revision' => $row->revision, 'priority' => 1, 'module' => 'customer-portal', 'target_tab' => 'requests', 'record_id' => $row->id, 'personal' => false]);
                    $items->last()->customer_id = $row->customer_id;
                }
                foreach (CustomerPortalRequest::whereIn('customer_id', $customers)->whereIn('status', ['submitted', 'reviewing'])->limit(100)->get() as $row) {
                    $items->push((object) ['id' => 'portal-request-'.$row->id, 'kind' => 'Kundenanliegen', 'title' => $row->title, 'subject' => '', 'user_id' => null, 'due_at' => null, 'status' => $row->status, 'revision' => $row->revision, 'priority' => 1, 'module' => 'customer-portal', 'target_tab' => 'requests', 'record_id' => $row->id, 'personal' => false]);
                    $items->last()->customer_id = $row->customer_id;
                }
            }
        }

        if (! $personal && $actor->can('operations.inquiries.manage') && AiIntakeSchema::ready()) {
            $hours = (int) AiDispositionSettings::all()['reply_timeout_hours'];
            $rows = AiIntake::where('supervising_user_id', $actor->id)->where(function ($query) use ($hours) {
                $query->whereIn('status', ['review', 'failed'])->orWhere(function ($query) use ($hours) {
                    $query->where('status', 'waiting_customer')->whereHas('deliveries',function($delivery) use ($hours) {
                        $delivery->where('status','sent')->whereColumn('source_revision','ai_intakes.source_revision')->whereColumn('question_round','ai_intakes.question_round')->where('sent_at','<=',now()->utc()->subHours($hours));
                    });
                });
            })->with(['deliveries'=>fn($delivery)=>$delivery->where('status','sent')->latest('sent_at')])->limit(100)->get();
            foreach ($rows as $row) {
                $lastSent = $row->deliveries->first(fn($delivery)=>$delivery->source_revision === $row->source_revision && $delivery->question_round === $row->question_round)?->sent_at;
                $items->push((object) ['id' => 'ai-intake-'.$row->id, 'kind' => $row->status === 'waiting_customer' ? 'Kundenantwort ausstehend' : 'AI-Eingang prüfen', 'title' => $row->title,
                    'subject' => '', 'user_id' => null, 'due_at' => $lastSent?->addHours($hours), 'status' => $row->status, 'revision' => $row->revision,
                    'priority' => 1, 'module' => 'ai-intake', 'target_tab' => 'ai-intake', 'record_id' => $row->id, 'record_type' => 'ai-intake', 'customer_id' => $row->customer_id, 'personal' => false]);
            }
        }

        return $items->sortBy(fn ($row) => [$row->priority, $row->due_at?->timestamp ?? PHP_INT_MAX, $row->id])->values();
    }

    public function destination(string $id, User $actor, bool $personal = false): string
    {
        $item = $this->items($actor, $personal)->firstWhere('id', $id);
        abort_unless($item, 404);
        if ($personal) {
            return route('operations.mine', array_filter(['area' => $item->module === 'customer-capacity' ? 'capacity' : ($item->module === 'personnel-enhancements' ? 'personnel' : ($item->module === 'operations-enhancements' ? 'operations' : 'work')), 'tab' => $item->target_tab]));
        }
        if ($item->module === 'ai-intake') {
            return OperationsPages::url('cases', array_filter(['view' => 'inbox', 'section' => 'ai-intake', 'source' => 'ai-intake', 'record' => $item->record_id, 'customer' => $item->customer_id]));
        }

        $type = $item->record_type ?? match (true) {
            str_starts_with($item->id, 'portal-submission-') => 'submission',
            str_starts_with($item->id, 'portal-request-') => 'request',
            str_starts_with($item->id, 'portal-reservation-') => 'reservation',
            str_contains($item->id, 'personnel_task:') || str_starts_with($item->id, 'task-') => 'task',
            str_contains($item->id, 'personnel_documents:') => 'signature',
            str_contains($item->id, 'personnel_workflows:') => 'workflow-run',
            str_contains($item->id, 'personnel_sickness:') => 'sickness',
            $item->module === 'times' => 'work-time',
            $item->module === 'absences' => 'absence',
            $item->module === 'qualifications' => 'qualification',
            str_starts_with($item->id, 'plan-review-') => 'plan-review',
            $item->module === 'workforce-planning' => 'staffing-case',
            default => '',
        };
        if ($type === 'shift-assignment') {
            OperationsAccess::authorize($actor, 'operations.manage');
            $assignment = ShiftAssignment::findOrFail($item->record_id);

            return OperationsPages::url('shifts', ['view' => 'plan', 'shift' => $assignment->shift_id]);
        }
        $tab = $item->target_tab;
        if ($type === 'signature') {
            return OperationsPages::url('people', array_filter(['view' => 'documents', 'section' => 'signatures', 'user' => $item->user_id, 'record' => $item->record_id, 'record_type' => $type, 'revision' => $item->revision]));
        }
        if ($type === 'sickness') {
            return OperationsPages::url('leave', array_filter(['view' => 'requests', 'section' => 'sickness', 'user' => $item->user_id, 'record' => $item->record_id, 'record_type' => $type, 'revision' => $item->revision]));
        }
        $tab = match ($type) {
            'plan-review' => 'checks', 'staffing-case' => 'cases', 'availability-period' => 'periods', 'task' => $item->module === 'personnel-enhancements' ? 'workflows' : $tab, default => $tab,
        };

        return OperationsPages::moduleUrl($item->module, array_filter([
            'tab' => $tab, 'customer' => $item->customer_id ?? null, 'user' => $item->user_id,
            'record' => $item->record_id, 'record_type' => $type,
            'source' => $item->module === 'customer-portal' ? $type : null, 'revision' => $type === 'reservation' ? null : ($item->target_revision ?? $item->revision),
        ]));
    }
}
