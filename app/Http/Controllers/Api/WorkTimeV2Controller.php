<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkTimeBasicExport;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkTimeActivityService;
use App\Services\Operations\WorkTimeCaptureService;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsApiAccess;
use App\Support\Operations\ReportingPeriod;
use App\Support\Operations\WorkTimeSchema;
use Illuminate\Http\Request;

class WorkTimeV2Controller extends Controller
{
    public function times(Request $request, bool $own = true)
    {
        $actor = OperationsApiAccess::authorize($request, $own ? 'operations:own:read' : 'operations:times:read');
        WorkTimeSchema::requireReady();
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'per_page' => 'sometimes|integer|min:1|max:100', 'context' => 'sometimes|in:shift,internal,training,unplanned']);
        $query = ReportingPeriod::apply(WorkTimeEntry::query(), $data['from'], $data['until'])->when($own, fn ($q) => $q->where('user_id', $actor->id))->when(isset($data['context']), fn ($q) => $q->where('work_context', $data['context']));
        if (! $own) {
            $ids = app(PersonnelScopeService::class)->visibleUserIds($actor, $actor->can('operations.time.review') ? 'operations.time.review' : 'operations.time.export');
            $query->when($ids !== null, fn ($q) => $q->whereIn('user_id', $ids));
        }

        return $query->orderBy('id')->paginate($data['per_page'] ?? 50)->through(fn ($entry) => $this->time($entry));
    }

    public function allTimes(Request $request)
    {
        return $this->times($request, false);
    }

    public function start(Request $request, WorkTimeService $service)
    {
        $actor = OperationsApiAccess::authorize($request, 'operations:own:write');
        $data = $request->validate(['event_key' => 'required|uuid', 'work_context' => 'required|in:shift,internal,training,unplanned', 'assignment_id' => 'required_if:work_context,shift|integer|min:1', 'plan_revision' => 'required_if:work_context,shift|integer|min:1']);
        WorkTimeSchema::requireReady();
        $hash = hash('sha256', json_encode(['assignment_id' => (int) ($data['assignment_id'] ?? 0), 'plan_revision' => (int) ($data['plan_revision'] ?? 0), 'work_context' => $data['work_context']]));
        $entry = $data['work_context'] === 'shift' ? $service->start($data['assignment_id'], $data['plan_revision'], $data['event_key'], $actor, null, $hash) : $service->startContext($request->all(), $data['event_key'], $actor);

        return response()->json(['schema_version' => 2, 'data' => $this->time($entry)], 201);
    }

    public function manual(Request $request, WorkTimeService $service)
    {
        $actor = OperationsApiAccess::authorize($request, 'operations:own:write');
        $data = $request->validate(['event_key' => 'required|uuid']);

        return response()->json(['schema_version' => 2, 'data' => $this->time($service->manualContext($request->all(), $data['event_key'], $actor))], 201);
    }

    public function clock(Request $request, int $id, WorkTimeService $service)
    {
        WorkTimeSchema::requireReady();
        $actor = OperationsApiAccess::authorize($request, 'operations:own:write');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:pause,resume,stop,submit', 'event_key' => 'required|uuid']);

        $hash = hash('sha256', json_encode(['id' => $id, 'revision' => (int) $data['revision'], 'action' => $data['action']]));

        return ['schema_version' => 2, 'data' => $this->time($service->clock($id, $data['revision'], $data['action'], $data['event_key'], $actor, null, $hash))];
    }

    public function sections(Request $request, int $id, WorkTimeActivityService $service)
    {
        $actor = OperationsApiAccess::authorize($request, 'operations:own:write');
        $data = $request->validate(['revision' => 'required|integer|min:1', 'sections' => 'required|array|min:1|max:60']);
        $service->replace($id, $data['revision'], $data['sections'], $actor);

        return ['schema_version' => 2, 'data' => $this->time(WorkTimeEntry::where('user_id', $actor->id)->findOrFail($id))];
    }

    public function account(Request $request, WorkforceAccountService $service, WorkTimeExtensionService $times)
    {
        $actor = OperationsApiAccess::authorize($request, 'operations:own:read');
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_unless($service->ready(), 503);

        return ['schema_version' => 2, 'data' => $service->summary($actor, $data['from'], $data['until'], $actor), 'completeness' => $times->completeness($actor, $data['from'], $data['until'], $actor)];
    }

    public function export(Request $request, WorkTimeExtensionService $service)
    {
        $actor = OperationsApiAccess::authorize($request, 'operations:times:export');
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer|distinct']);
        $export = $service->export($data['ids'], $actor);

        return response()->json(['schema_version' => 2, 'id' => $export->public_id, 'csv_url' => route('api.operations.v2.exports.show', $export->public_id)], 201);
    }

    public function download(Request $request, string $publicId, WorkTimeExtensionService $service)
    {
        $actor = OperationsApiAccess::authorize($request, 'operations:times:export');

        return response($service->csv(WorkTimeBasicExport::where('public_id', $publicId)->firstOrFail(), $actor))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="RailTime-Arbeitszeit-v2-'.$publicId.'.csv"');
    }

    private function time(WorkTimeEntry $entry): array
    {
        return app(WorkTimeCaptureService::class)->projection($entry) + ['assignment_id' => $entry->shift_assignment_id, 'order_id' => $entry->order_id, 'training_session_id' => $entry->training_session_id, 'comparison' => app(WorkTimeService::class)->comparison($entry),
            'credited_seconds' => $entry->creditedSeconds(), 'activities' => $entry->activities()->orderBy('starts_at')->get()->map(fn ($a) => $a->only(['id', 'kind', 'starts_at', 'ends_at', 'is_paid', 'source']))->all()];
    }
}
