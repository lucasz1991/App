<?php

namespace App\Services\Operations;

use App\Models\DutyReport;
use App\Models\Shift;
use App\Models\ShiftSection;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\PlanningSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DutyActivityService
{
    public const KINDS = ['preparation' => 'Vorbereitung', 'travel' => 'Fahrt', 'shunting' => 'Rangieren', 'break' => 'Pause', 'handover' => 'Ablösung'];

    public const REPORTS = ['delay' => 'Verspätung', 'relief' => 'Ablösebedarf', 'information' => 'Betriebshinweis'];

    public function section(int $shiftId, int $revision, ?int $id, ?array $data, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        PlanningSchema::requireReady();
        if ($data !== null) {
            $data = Validator::make($data, ['kind' => 'required|in:'.implode(',', array_keys(self::KINDS)), 'label' => 'nullable|string|max:180', 'starts_at' => 'required|string', 'ends_at' => 'required|string'])->validate();
        }
        DB::transaction(function () use ($shiftId, $revision, $id, $data, $actor) {
            $shift = Shift::lockForUpdate()->findOrFail($shiftId);
            $this->check($shift->revision === $revision && ! in_array($shift->status->value, ['cancelled', 'completed']), 'Dienst wurde geändert oder abgeschlossen.');
            $this->check(! WorkTimeEntry::whereHas('assignment', fn ($q) => $q->where('shift_id', $shiftId))->exists(), 'Für diesen Dienst sind bereits Zeiten erfasst.');
            if ($shift->published_revision > 0 && ! $shift->published_snapshot) {
                $shift->published_snapshot = $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']) + ['sections' => $this->snapshot($shift)];
                $shift->save();
            }
            $section = $id ? ShiftSection::where('shift_id', $shiftId)->findOrFail($id) : new ShiftSection;
            if ($data === null) {
                abort_unless($id, 422);
                $section->delete();
            } else {
                [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $shift->timezone);
                $this->check($start->gte($shift->starts_at) && $end->lte($shift->ends_at), 'Abschnitt muss innerhalb des Dienstes liegen.');
                $this->check(! ShiftSection::where('shift_id', $shiftId)->when($id, fn ($q) => $q->where('id', '!=', $id))->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 'Dienstabschnitte dürfen sich nicht überschneiden.');
                $section->fill(array_merge($data, ['shift_id' => $shiftId, 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $shift->timezone]))->save();
            }
            $this->validateSections($shift);
            $shift->increment('revision');
            app(OperationsAuditService::class)->record($shift, $actor, 'shift.sections', ['section_id' => $section->id, 'removed' => $data === null]);
        }, 3);
    }

    public function validateSections(Shift $shift): void
    {
        if (! PlanningSchema::ready()) {
            return;
        }
        $sections = ShiftSection::where('shift_id', $shift->id)->orderBy('starts_at')->get();
        $pause = 0;
        $previous = null;
        foreach ($sections as $section) {
            $this->check($section->starts_at->gte($shift->starts_at) && $section->ends_at->lte($shift->ends_at) && (! $previous || $section->starts_at->gte($previous)), 'Dienstabschnitte liegen außerhalb des Plans oder überschneiden sich.');
            if ($section->kind === 'break') {
                $pause += $section->starts_at->diffInSeconds($section->ends_at);
            }
            $previous = $section->ends_at;
        }
        $this->check($pause <= $shift->planned_break_minutes * 60, 'Pausenabschnitte überschreiten die geplante Pause.');
    }

    public function snapshot(Shift $shift): array
    {
        if (! PlanningSchema::ready()) {
            return [];
        }

        return ShiftSection::where('shift_id', $shift->id)->orderBy('starts_at')->get()->map(fn ($s) => [
            'id' => $s->id, 'kind' => $s->kind, 'label' => $s->label, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(), 'timezone' => $shift->timezone,
        ])->all();
    }

    public function report(int $shiftId, int $revision, array $data, string $key, User $actor): DutyReport
    {
        PlanningSchema::requireReady();
        $data = Validator::make($data + ['key' => $key], ['kind' => 'required|in:delay,relief,information', 'message' => 'required|string|min:3|max:2000', 'delay_minutes' => 'nullable|required_if:kind,delay|integer|min:1|max:1440', 'key' => 'required|uuid'])->validate();
        unset($data['key']);
        if ($data['kind'] !== 'delay') {
            $data['delay_minutes'] = null;
        }

        return DB::transaction(function () use ($shiftId, $revision, $data, $key, $actor) {
            $shift = Shift::lockForUpdate()->findOrFail($shiftId);
            if ($actor->can('operations.manage')) {
                OperationsAccess::authorize($actor, 'operations.manage');
                $this->check($shift->revision === $revision, 'Dienst wurde geändert.');
            } else {
                $assignment = $shift->assignments()->where('user_id', $actor->id)->firstOrFail();
                $visible = app(PersonalSchedule::class)->assignment($actor, $assignment->id);
                $this->check($visible->plan_revision === $revision, 'Planrevision wurde geändert.');
            }
            $this->check(! in_array($shift->status->value, ['cancelled', 'completed']), 'Dienst ist abgeschlossen oder storniert.');
            if ($existing = DutyReport::where('request_key', $key)->first()) {
                $this->check($existing->user_id === $actor->id && $existing->shift_id === $shiftId && $existing->plan_revision === $revision && $existing->kind === $data['kind'] && $existing->message === $data['message'] && $existing->delay_minutes === (isset($data['delay_minutes']) ? (int) $data['delay_minutes'] : null), 'Meldungsschlüssel wurde bereits verwendet.');

                return $existing;
            }
            $report = DutyReport::create($data + ['request_key' => $key, 'shift_id' => $shiftId, 'plan_revision' => $revision, 'user_id' => $actor->id, 'revision' => 1, 'status' => 'open']);
            app(OperationsAuditService::class)->record($report, $actor, 'duty.reported');

            return $report;
        }, 3);
    }

    public function resolve(int $id, int $revision, string $note, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        PlanningSchema::requireReady();
        Validator::make(['note' => $note], ['note' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($id, $revision, $note, $actor) {
            $report = DutyReport::lockForUpdate()->findOrFail($id);
            $this->check($report->status === 'open' && $report->revision === $revision, 'Meldung bereits bearbeitet.');
            $report->update(['status' => 'resolved', 'revision' => $revision + 1, 'resolution' => $note, 'resolved_by' => $actor->id, 'resolved_at' => now()->utc()]);
            app(OperationsAuditService::class)->record($report, $actor, 'duty.resolved');
        }, 3);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
