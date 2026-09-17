<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbsenceRequest;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeExport;
use App\Services\Operations\OperationsReportService;
use App\Services\Operations\PayrollReferenceService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsApiAccess as Access;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\ReportingPeriod;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    public function schedule(Request $request)
    {
        $actor = Access::authorize($request, 'operations:own:read');
        $data = $this->filters($request, false);
        [$from, $until] = ReportingPeriod::bounds($data['from'], $data['until']);
        abort_if($from->diffInDays($until) > 32, 422, 'Dienstabruf auf 31 Tage begrenzen.');
        $assignments = app(PersonalSchedule::class)->assignments($actor, $from, $until);

        return ['data' => $assignments->map(fn ($assignment) => ['id' => $assignment->id, 'shift_id' => $assignment->shift_id, 'plan_revision' => $assignment->plan_revision, 'status' => $assignment->status->value,
            'title' => $assignment->shift->title, 'starts_at' => $assignment->shift->starts_at->toIso8601String(), 'ends_at' => $assignment->shift->ends_at->toIso8601String(), 'timezone' => $assignment->shift->timezone])->values()];
    }

    public function ownTimes(Request $request)
    {
        return $this->times($request, true);
    }

    public function times(Request $request, bool $own = false)
    {
        $actor = Access::authorize($request, $own ? 'operations:own:read' : 'operations:times:read');
        $data = $this->filters($request, false);
        $query = ReportingPeriod::apply(WorkTimeEntry::query(), $data['from'], $data['until']);
        $query->when($own, fn ($q) => $q->where('user_id', $actor->id));
        $query->when(isset($data['employee_id']), fn ($q) => $q->where('user_id', $data['employee_id']));
        $query->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']));

        return $query->orderBy('id')->paginate($data['per_page'] ?? 50)->through(fn ($entry) => $this->time($entry));
    }

    public function ownAbsences(Request $request)
    {
        return $this->absences($request, true);
    }

    public function absences(Request $request, bool $own = false)
    {
        $actor = Access::authorize($request, $own ? 'operations:own:read' : 'operations:absences:read');
        $data = $this->filters($request, true);
        $query = ReportingPeriod::apply(AbsenceRequest::query(), $data['from'], $data['until'], true);
        $query->when($own, fn ($q) => $q->where('user_id', $actor->id));
        $query->when(isset($data['employee_id']), fn ($q) => $q->where('user_id', $data['employee_id']));
        $query->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']));
        $query->when(isset($data['kind']), fn ($q) => $q->where('kind', $data['kind']));

        return $query->orderBy('id')->paginate($data['per_page'] ?? 50)->through(fn ($record) => $this->absence($record));
    }

    public function requestAbsence(Request $request, PersonnelWorkflowService $service)
    {
        $actor = Access::authorize($request, 'operations:own:write');

        // Service validation, overlap locks and ownership are shared with Livewire.
        return response()->json(['data' => $this->absence($service->requestAbsence($actor, $request->all()))], 201);
    }

    public function withdrawAbsence(Request $request, int $id, PersonnelWorkflowService $service)
    {
        $actor = Access::authorize($request, 'operations:own:write');
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $record = AbsenceRequest::where('user_id', $actor->id)->findOrFail($id);
        $service->absence($record, $data['revision'], 'withdraw', '', $actor);

        return ['data' => $this->absence($record->fresh())];
    }

    public function reviewAbsence(Request $request, int $id, PersonnelWorkflowService $service)
    {
        $actor = Access::authorize($request, 'operations:absences:review');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:approve,reject,cancel', 'note' => 'nullable|string|max:1000']);
        $record = AbsenceRequest::findOrFail($id);
        $service->absence($record, $data['revision'], $data['action'], $data['note'] ?? '', $actor);

        return ['data' => $this->absence($record->fresh())];
    }

    public function start(Request $request, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:own:write');
        $data = $request->validate(['assignment_id' => 'required|integer|min:1', 'plan_revision' => 'required|integer|min:1', 'event_key' => 'required|uuid']);

        return response()->json(['data' => $this->time($service->start($data['assignment_id'], $data['plan_revision'], $data['event_key'], $actor))], 201);
    }

    public function manual(Request $request, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:own:write');
        $data = $request->validate(['assignment_id' => 'required|integer|min:1', 'plan_revision' => 'required|integer|min:1', 'event_key' => 'required|uuid']);

        return response()->json(['data' => $this->time($service->manual($data['assignment_id'], $data['plan_revision'], $request->all(), $data['event_key'], $actor))], 201);
    }

    public function clock(Request $request, int $id, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:own:write');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:pause,resume,stop,submit', 'event_key' => 'required|uuid']);

        return ['data' => $this->time($service->clock($id, $data['revision'], $data['action'], $data['event_key'], $actor))];
    }

    public function correct(Request $request, int $id, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:own:write');
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $service->correct($id, $data['revision'], $request->all(), $actor);

        return ['data' => $this->time(WorkTimeEntry::where('user_id', $actor->id)->findOrFail($id))];
    }

    public function reviewTime(Request $request, int $id, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:times:review');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'approve' => 'required|boolean', 'note' => 'nullable|string|max:1000']);
        $service->review($id, $data['revision'], (bool) $data['approve'], $data['note'] ?? '', $actor);

        return ['data' => $this->time(WorkTimeEntry::findOrFail($id))];
    }

    public function exportTimes(Request $request, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:times:export');
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'required|integer|distinct']);
        $export = $service->export($data['ids'], $actor);

        return response()->json(['id' => $export->public_id, 'schema_version' => $export->schema_version, 'csv_url' => route('api.operations.exports.show', $export->public_id)], 201);
    }

    public function exports(Request $request)
    {
        Access::authorize($request, 'operations:times:export');

        return WorkTimeExport::orderByDesc('id')->paginate(50)->through(fn ($export) => ['id' => $export->public_id, 'created_at' => $export->created_at?->toIso8601String(), 'schema_version' => $export->schema_version, 'csv_url' => route('api.operations.exports.show', $export->public_id)]);
    }

    public function download(Request $request, string $publicId, WorkTimeService $service)
    {
        $actor = Access::authorize($request, 'operations:times:export');
        $export = WorkTimeExport::where('public_id', $publicId)->firstOrFail();

        return response($service->csv($export, $actor))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="RailTime-Zeiten-'.$publicId.'.csv"');
    }

    public function absenceCsv(Request $request, OperationsReportService $service)
    {
        $actor = Access::authorize($request, 'operations:absences:read');
        $request->validate(['employee_id' => 'prohibited']);
        $data = $this->filters($request, true);

        return response($service->absences($actor, $data['from'], $data['until'], $data['status'] ?? 'all', $data['kind'] ?? 'all'))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="RailTime-Abwesenheiten.csv"');
    }

    public function payrollCsv(Request $request, string $publicId, PayrollReferenceService $service)
    {
        $actor = Access::authorize($request, 'operations:times:export');

        return response($service->csv(WorkTimeExport::where('public_id', $publicId)->firstOrFail(), $actor))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="RailTime-Lohnuebergabe-'.$publicId.'.csv"');
    }

    private function filters(Request $request, bool $absence): array
    {
        return $request->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'employee_id' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:'.($absence ? 'pending,approved,rejected,withdrawn,cancelled' : 'running,paused,completed,submitted,returned,approved'), 'kind' => 'sometimes|in:vacation,unavailable,other']);
    }

    private function time(WorkTimeEntry $entry): array
    {
        return ['id' => $entry->id, 'employee_id' => $entry->user_id, 'assignment_id' => $entry->shift_assignment_id, 'revision' => $entry->revision, 'status' => $entry->status, 'starts_at' => $entry->starts_at->toIso8601String(), 'ends_at' => $entry->ends_at?->toIso8601String(), 'timezone' => $entry->timezone, 'pause_seconds' => $entry->pause_seconds, 'net_seconds' => $entry->netSeconds(), 'order_number' => $entry->plan_snapshot['order_number'] ?? null, 'plan_revision' => $entry->plan_snapshot['revision'] ?? null, 'comparison' => app(WorkTimeService::class)->comparison($entry)];
    }

    private function absence(AbsenceRequest $record): array
    {
        return ['id' => $record->id, 'employee_id' => $record->user_id, 'kind' => $record->kind, 'revision' => $record->revision, 'status' => $record->status, 'starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'timezone' => $record->timezone];
    }
}
