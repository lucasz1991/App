<?php

namespace App\Support\Operations;

use App\Models\ShiftOffer;
use App\Models\StaffingAutomationRun;
use App\Models\StaffingCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A portal-solicitation principal. It cannot assign, publish or waive rules. */
final readonly class StaffingAutomationActor
{
    public function __construct(public int $runId, public int $settingsRevision, public int $supervisingUserId) {}

    public function authorize(string $operation, string $ability = 'operations.manage'): User
    {
        abort_unless(StaffingAutomationSchema::ready(), 503);
        $run = StaffingAutomationRun::findOrFail($this->runId);
        $settings = AiDispositionSettings::all(true);
        abort_unless(($settings['staffing_request_mode'] ?? 'off') === 'automatic'
            && (int) $settings['revision'] === $this->settingsRevision
            && (int) $settings['supervisor_id'] === $this->supervisingUserId
            && $run->settings_revision === $this->settingsRevision && $run->supervising_user_id === $this->supervisingUserId, 409, 'Personalanfragen wurden geändert oder angehalten.');
        abort_unless(in_array($operation, ['offer.create', 'case.open', 'audit'], true) && $ability === 'operations.manage', 403);
        abort_unless($operation !== 'offer.create' || ($run->state === 'waiting' && $run->wave_count < (int) $settings['staffing_request_max_waves']), 409);
        $supervisor = User::findOrFail($this->supervisingUserId);
        OperationsAccess::authorize($supervisor, $ability);

        return $supervisor;
    }

    public function assertShiftScope(int $shiftId, ?int $planRevision = null): void
    {
        $run = StaffingAutomationRun::findOrFail($this->runId);
        abort_unless($run->shift_id === $shiftId && ($planRevision === null || $run->plan_revision === $planRevision), 403);
    }

    public function assertOfferScope(array $userIds): void
    {
        $run = StaffingAutomationRun::findOrFail($this->runId);
        abort_unless(array_diff($userIds, $run->basis['candidate_ids'] ?? []) === [], 403);
        $contacted = ShiftOffer::where('shift_id', $run->shift_id)->get()->flatMap(fn ($offer) => $offer->invited_user_ids)->all();
        abort_if(array_intersect($contacted, $userIds) !== [], 409, 'Person wurde für diesen Dienst bereits angefragt.');
    }

    public function assertSubjectScope(Model $subject): void
    {
        abort_unless($subject instanceof StaffingAutomationRun || $subject instanceof ShiftOffer || $subject instanceof StaffingCase, 403);
        if ($subject instanceof StaffingAutomationRun) {
            abort_unless($subject->id === $this->runId, 403);
        } else {
            $this->assertShiftScope((int) $subject->shift_id);
        }
    }

    public function references(): array
    {
        return ['actor_kind' => 'automation', 'supervising_user_id' => $this->supervisingUserId];
    }

    public function auditReferences(): array
    {
        return ['staffing_run_uuid' => StaffingAutomationRun::findOrFail($this->runId)->run_uuid, 'staffing_run_id' => $this->runId, 'settings_revision' => $this->settingsRevision];
    }
}
