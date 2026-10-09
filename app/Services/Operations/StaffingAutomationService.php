<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\OperationAudit;
use App\Models\OperationsAttentionItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftOffer;
use App\Models\StaffingAutomationRun;
use App\Models\StaffingCase;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use App\Support\Operations\StaffingAutomationActor;
use App\Support\Operations\StaffingAutomationSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded portal invitations; neither interest nor automation creates an assignment. */
class StaffingAutomationService
{
    public function ready(): bool
    {
        return StaffingAutomationSchema::ready();
    }

    public function overview(User $actor, int $limit = 10): array
    {
        $this->authorize($actor);
        $settings = AiDispositionSettings::all(true);
        $ready = $this->ready();
        $supervisor = $this->supervisor($settings);
        $mode = $settings['staffing_request_mode'] ?? 'off';
        $counts = $ready ? StaffingAutomationRun::selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')->map(fn ($n) => (int) $n)->all() : [];
        $items = $ready ? StaffingAutomationRun::with('shift')->latest('id')->limit(max(1, min(50, $limit)))->get()->map(fn ($run) => $this->row($run))->all() : [];

        return ['mode' => $mode, 'schema_ready' => $ready, 'supervisor_ready' => $supervisor !== null,
            'status' => ! $ready ? 'schema_missing' : ($mode === 'off' ? 'disabled' : ($supervisor ? 'ready' : 'supervisor_not_authorized')),
            'counts' => $counts, 'items' => $items];
    }

    public function recentEvents(User $actor, int $limit = 20): array
    {
        $this->authorize($actor);
        if (! $this->ready()) {
            return [];
        }

        return OperationAudit::where('subject_type', 'StaffingAutomationRun')->where('action', 'like', 'staffing.automation.%')
            ->latest('id')->limit(max(1, min(100, $limit)))->get()->map(fn ($audit) => [
                'id' => $audit->id, 'action' => $audit->action, 'revision' => $audit->revision,
                'run_id' => $audit->subject_id, 'run_uuid' => $audit->data['staffing_run_uuid'] ?? null,
                'shift_id' => $audit->data['shift_id'] ?? null, 'plan_revision' => $audit->data['plan_revision'] ?? null,
                'reason_code' => $audit->data['reason_code'] ?? null, 'offer_id' => $audit->data['offer_id'] ?? null,
                'state' => $audit->data['state'] ?? null, 'wave_count' => $audit->data['wave_count'] ?? 0,
                'created_at' => CarbonImmutable::parse($audit->getRawOriginal('created_at'), 'UTC')->toIso8601String(),
            ])->all();
    }

    /** Explicit preparation targets. This read-only snapshot never contacts a person. */
    public function preparableTargets(User $actor, int $limit = 5): array
    {
        $this->authorize($actor);
        $settings = $this->settings();
        if (! $this->ready() || $settings['staffing_request_mode'] === 'off' || ! $this->supervisor($settings)) {
            return [];
        }

        return Shift::where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->where('starts_at', '>', now()->utc())
            ->where('starts_at', '<=', now()->utc()->addDays((int) $settings['staffing_request_horizon_days']))
            ->whereNotIn('status', ['draft', 'cancelled', 'completed', 'in_progress'])
            ->where('required_staff', '>', fn ($query) => $query->selectRaw('count(*)')->from('shift_assignments')
                ->whereColumn('shift_assignments.shift_id', 'shifts.id')->whereIn('status', ShiftAssignmentStatus::blockingValues()))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('staffing_automation_runs')->whereColumn('staffing_automation_runs.shift_id', 'shifts.id')->whereColumn('staffing_automation_runs.plan_revision', 'shifts.revision'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('shift_offers')->whereColumn('shift_offers.shift_id', 'shifts.id')->where('status', 'open')->where('expires_at', '>', now()->utc()))
            ->orderBy('starts_at')->limit(max(1, min(20, $limit)))->get()->map(fn ($shift) => [
                'id' => $shift->id, 'shift_id' => $shift->id, 'plan_revision' => $shift->revision, 'title' => $shift->title,
                'settings_revision' => (int) $settings['revision'], 'starts_at' => $shift->starts_at->toIso8601String(),
            ])->all();
    }

    /** Explicit assisted preparation is a review proposal, never a solicitation. */
    public function prepare(int $shiftId, int $planRevision, User $actor): StaffingAutomationRun
    {
        $this->authorize($actor);
        abort_unless($this->ready(), 503);
        $this->check(($this->settings()['staffing_request_mode'] ?? 'off') !== 'off', 'Automatische Personalanfragen sind ausgeschaltet.');

        return $this->process($shiftId, $planRevision, $actor) ?? abort(409);
    }

    /** A corrected prerequisite may be retried; rounds and already contacted people remain bounded. */
    public function retry(int $runId, int $revision, User $actor): StaffingAutomationRun
    {
        $this->authorize($actor);
        abort_unless($this->ready(), 503);
        $run = StaffingAutomationRun::findOrFail($runId);
        OperationsTransaction::run(function () use ($run, $revision, $actor): void {
            PlanningLocks::acquire([$run->shift_id], [$actor->id]);
            $fresh = StaffingAutomationRun::lockForUpdate()->findOrFail($run->id);
            $this->authorize($actor);
            $this->check($fresh->revision === $revision && $fresh->state === 'review', 'Personalanfrage wurde inzwischen bearbeitet.');
            $settings = $this->settings(true);
            $this->check($settings['staffing_request_mode'] !== 'off' && $this->supervisor($settings) !== null, 'Automatik oder verantwortliche Disposition ist nicht verfügbar.');
            $this->check($fresh->wave_count < (int) $settings['staffing_request_max_waves'], 'Die maximale Anzahl Anfragerunden ist erreicht.');
            $this->check(! $fresh->offer?->responses()->where('status', 'interested')->exists(), 'Interessierte Rückmeldungen zuerst im vorhandenen Dienstangebot prüfen.');
            $fresh->update(['state' => 'waiting', 'reason_code' => null, 'next_run_at' => now()->utc(),
                'settings_revision' => $settings['revision'], 'supervising_user_id' => $settings['supervisor_id'], 'revision' => $fresh->revision + 1]);
            $this->audit($fresh, $actor, 'retry_requested');
        });

        return $this->process($run->shift_id, $run->plan_revision, $actor) ?? $run->fresh();
    }

    /** Queue cache deduplication is optional: parent locks and the unique ledger protect every wave. */
    public function process(int $shiftId, int $planRevision, ?User $requestedBy = null): ?StaffingAutomationRun
    {
        if (! $this->ready()) {
            return null;
        }
        $settings = $this->settings();
        if ($settings['staffing_request_mode'] === 'off' || (! $requestedBy && $settings['staffing_request_mode'] !== 'automatic')) {
            return null;
        }
        if ($requestedBy) {
            $this->authorize($requestedBy);
        }
        $shift = Shift::withTrashed()->find($shiftId);
        if (! $shift) {
            return null;
        }
        // Lock the complete staff population in native order before assessing mutable facts.
        $userIds = User::where('status', true)->where('role', 'staff')->pluck('id')->all();
        $userIds[] = (int) $settings['supervisor_id'];
        if ($requestedBy) {
            $userIds[] = $requestedBy->id;
        }

        return OperationsTransaction::run(function () use ($shiftId, $planRevision, $userIds, $requestedBy): ?StaffingAutomationRun {
            // Include removed rows solely to retire this run's invitation and expose a review state.
            $shift = PlanningLocks::acquire([$shiftId], $userIds, [], true)->get($shiftId);
            $settings = $this->settings(true);
            if ($settings['staffing_request_mode'] === 'off' || (! $requestedBy && $settings['staffing_request_mode'] !== 'automatic')) {
                return null;
            }
            if ($requestedBy) {
                $this->authorize($requestedBy);
            }
            $supervisor = $this->supervisor($settings);
            if ($supervisor && ! in_array($supervisor->id, $userIds, true)) {
                return null; // Configuration changed before the ordered actor locks; retry with fresh targets.
            }
            $run = StaffingAutomationRun::where('shift_id', $shiftId)->where('plan_revision', $planRevision)->lockForUpdate()->first();
            if (! $supervisor) {
                // Revoked authority stops all domain changes, but the existing ledger shows why.
                if ($run && ! in_array($run->state, ['completed', 'stopped'], true) && $run->reason_code !== 'supervisor_not_authorized') {
                    $run->update(['state' => 'review', 'reason_code' => 'supervisor_not_authorized', 'next_run_at' => null, 'revision' => $run->revision + 1]);
                }

                return $run;
            }
            $run ??= StaffingAutomationRun::create(['run_uuid' => (string) Str::uuid(), 'shift_id' => $shiftId, 'plan_revision' => $planRevision,
                'settings_revision' => $settings['revision'], 'supervising_user_id' => $supervisor->id, 'state' => 'waiting'])->fresh();
            $changedSettings = $run->settings_revision !== (int) $settings['revision'] || $run->supervising_user_id !== $supervisor->id;
            if ($changedSettings) {
                $run->update(['settings_revision' => $settings['revision'], 'supervising_user_id' => $supervisor->id]);
            }
            $actor = $settings['staffing_request_mode'] === 'automatic'
                ? new StaffingAutomationActor($run->id, (int) $settings['revision'], $supervisor->id) : $requestedBy;
            if ($run->state === 'stopped') {
                return $run;
            }
            $run->touch(); // Fair scheduler rotation, without invalidating a material review revision.
            if ($shift->trashed()) {
                $this->expireOwnedOffer($run, $actor);

                return $this->transition($run, $actor, 'review', 'shift_removed', null, 'review_required');
            }
            if ($shift->revision !== $planRevision || $shift->published_revision !== $planRevision || $planRevision < 1) {
                return $this->review($run, $shift, $actor, 'publication_changed', [], true);
            }
            if (! $shift->starts_at->isFuture() || in_array($shift->status->value, ['draft', 'cancelled', 'completed', 'in_progress'], true)
                || ! $shift->order || $shift->order->trashed() || in_array($shift->order->status->value, ['cancelled', 'completed'], true)) {
                $this->expireOwnedOffer($run, $actor);

                return $this->transition($run, $actor, 'stopped', 'shift_unavailable', null);
            }
            if ($changedSettings) {
                $ownedOffer = $run->offer;

                return $this->review($run, $shift, $actor, 'settings_changed', [], false, $ownedOffer?->status === 'open' ? $ownedOffer->expires_at : null);
            }
            if ($shift->starts_at->gt(now()->utc()->addDays((int) $settings['staffing_request_horizon_days']))) {
                return $this->review($run, $shift, $actor, 'outside_horizon');
            }
            if (! in_array($shift->order->status->value, ['confirmed', 'planned', 'in_progress'], true)) {
                return $this->review($run, $shift, $actor, 'order_not_confirmed');
            }
            $confirmed = $shift->assignments()->where('status', 'confirmed')->where('plan_revision', $planRevision)->count();
            $reserved = $shift->assignments()->blocking()->count();
            if ($confirmed >= $shift->required_staff) {
                $this->expireOwnedOffer($run, $actor, 'filled');

                // Cases remain reviewable: only the native case workflow may resolve them.
                return $this->transition($run, $actor, 'completed', 'confirmed_capacity', null);
            }
            if ($run->state === 'completed') {
                $this->transition($run, $actor, 'waiting', 'vacancy_reopened', now()->utc(), 'vacancy_reopened');
            }
            if ($reserved >= $shift->required_staff) {
                $this->expireOwnedOffer($run, $actor, 'filled');

                return $this->transition($run, $actor, 'awaiting_confirmation', 'reserved_capacity', now()->utc()->addMinutes(5));
            }
            $offer = $run->offer_id ? ShiftOffer::lockForUpdate()->find($run->offer_id) : null;
            if ($run->state === 'review' && $run->reason_code !== 'interest_received' && ! $requestedBy) {
                if ($offer?->status === 'open' && ! $offer->expires_at->isFuture()) {
                    $this->expireOwnedOffer($run, $actor);
                    $run->update(['next_run_at' => null]);
                }

                return $run;
            }
            if ($offer?->status === 'open') {
                $responses = $offer->responses()->lockForUpdate()->get();
                if ($responses->contains('status', 'interested')) {
                    if ($offer->expires_at->isFuture()) {
                        return $this->review($run, $shift, $actor, 'interest_received', [], false, $offer->expires_at);
                    }
                    $this->expireOwnedOffer($run, $actor);

                    return $this->review($run, $shift, $actor, 'response_expired');
                }
                $allDeclined = count($offer->invited_user_ids) === $responses->whereIn('status', ['declined', 'withdrawn', 'rejected'])->count();
                if ($offer->expires_at->isFuture() && ! $allDeclined) {
                    return $run;
                }
                $this->expireOwnedOffer($run, $actor);
                $run->update(['state' => 'waiting', 'reason_code' => null, 'next_run_at' => now()->utc(), 'revision' => $run->revision + 1]);
            }
            // A manual offer is authoritative and must never be expired or replaced here.
            if (ShiftOffer::where('shift_id', $shiftId)->where('status', 'open')->where('expires_at', '>', now()->utc())->exists()) {
                return $this->review($run, $shift, $actor, 'existing_offer');
            }
            if ($run->state === 'review' && ! $requestedBy) {
                return $run;
            }
            if ($run->wave_count >= (int) $settings['staffing_request_max_waves']) {
                return $this->review($run, $shift, $actor, 'round_limit');
            }
            $contacted = ShiftOffer::where('shift_id', $shiftId)->get()->flatMap(fn ($row) => $row->invited_user_ids)
                ->merge($shift->assignments()->pluck('user_id'))->unique()->all();
            $ranked = app(ShiftStaffingCandidates::class)->ranked($shift)->whereNotIn('id', $contacted);
            $reasons = [];
            $safe = $ranked->filter(function (User $candidate) use ($shift, $settings, &$reasons): bool {
                $codes = collect($candidate->planning_issues ?? [])->pluck('code')->all();
                if ($candidate->staffing_state !== 'eligible') {
                    $reasons = array_merge($reasons, $codes ?: ['eligibility_uncertain']);

                    return false;
                }
                if (($candidate->staffing_region['state'] ?? 'unknown') === 'unknown') {
                    $reasons[] = 'region_unknown';

                    return false;
                }
                if ((int) $candidate->staffing_score < (int) $settings['staffing_request_min_score']) {
                    $reasons[] = 'score_below_threshold';

                    return false;
                }
                if (app(WorkforcePlanningService::class)->wishSummary($shift, $candidate)['state'] === 'free_requested') {
                    $reasons[] = 'free_requested';

                    return false;
                }
                // The native manual workflow allows legacy people with no model. Automation requires actual approved data.
                $accounts = app(WorkforceAccountService::class);
                for ($day = $shift->starts_at->setTimezone($shift->timezone)->startOfDay(); $day->lt($shift->ends_at->setTimezone($shift->timezone)); $day = $day->addDay()) {
                    $model = $accounts->effectiveModel($candidate, $day);
                    if (! $model?->approved_at || ! $model->approved_by || ! is_array($model->daily_minutes) || count($model->daily_minutes) !== 7) {
                        $reasons[] = 'approved_work_model_missing';

                        return false;
                    }
                }
                try {
                    app(ShiftAssignmentService::class)->assertCapacity($shift, $candidate);
                } catch (ValidationException) {
                    $reasons[] = 'capacity_unavailable';

                    return false;
                }

                return true;
            })->take((int) $settings['staffing_request_wave_size'])->values();
            $basis = ['schema_version' => 1, 'shift_id' => $shiftId, 'plan_revision' => $planRevision,
                'candidate_ids' => $safe->pluck('id')->all(), 'scores' => $safe->mapWithKeys(fn ($u) => [$u->id => (int) $u->staffing_score])->all(),
                'issue_codes' => array_slice(array_values(array_unique($reasons)), 0, 50), 'reserved_count' => $reserved, 'required_staff' => $shift->required_staff];
            $basis['hash'] = hash('sha256', json_encode([$basis, $shift->starts_at->toIso8601String(), $shift->ends_at->toIso8601String(), $settings['revision']], JSON_THROW_ON_ERROR));
            $run->update(['basis' => $basis]);
            if ($safe->isEmpty()) {
                return $this->review($run, $shift, $actor, $ranked->isEmpty() ? 'no_uncontacted_candidates' : 'uncertain_candidates', $basis['issue_codes']);
            }
            if ($settings['staffing_request_mode'] === 'assisted') {
                return $this->review($run, $shift, $actor, 'assisted_proposal');
            }
            $expiry = now()->utc()->addHours((int) $settings['staffing_request_timeout_hours'])->min($shift->starts_at->subMinute());
            if (! $expiry->isFuture()) {
                return $this->review($run, $shift, $actor, 'start_imminent');
            }
            $run->update(['state' => 'waiting']);
            $offer = app(WorkforcePlanningService::class)->offer($shiftId, $planRevision, $safe->pluck('id')->all(), $expiry->format('Y-m-d\TH:i:s'), 'UTC', $actor)->fresh();
            $run->update(['offer_id' => $offer->id, 'wave_count' => $run->wave_count + 1]);
            foreach ($safe as $employee) {
                OperationsAttentionItem::firstOrCreate(['recipient_user_id' => $employee->id, 'source_key' => hash('sha256', 'staffing-offer:'.$offer->id.':'.$employee->id)],
                    ['subject_user_id' => $employee->id, 'kind' => 'staffing_offer', 'headline' => 'Dienstangebot · Rückmeldung erbeten', 'module' => 'workforce-planning',
                        'record_id' => $offer->id, 'source_revision' => $offer->revision, 'due_at' => $expiry]);
            }

            return $this->transition($run, $actor, 'soliciting', 'portal_invitation', $expiry, 'wave_created');
        });
    }

    /** Bounded scheduler snapshot. Jobs must repeat every gate after dispatch. */
    public function scheduledTargets(int $limit = 25): Collection
    {
        $settings = $this->settings();
        if (! $this->ready() || $settings['staffing_request_mode'] !== 'automatic' || ! $this->supervisor($settings)) {
            return collect();
        }
        $limit = max(1, min(100, $limit));
        $due = StaffingAutomationRun::whereNotIn('state', ['completed', 'stopped'])->whereNotNull('next_run_at')->where('next_run_at', '<=', now()->utc())
            ->orderBy('next_run_at')->limit($limit)->get(['shift_id', 'plan_revision']);
        // Poll active waves for responses as well as deadlines; no re-send occurs while open.
        $active = StaffingAutomationRun::where(fn ($query) => $query->whereIn('state', ['soliciting', 'awaiting_confirmation'])->orWhere(fn ($review) => $review->where('state', 'review')->where('reason_code', 'interest_received')))
            ->orderBy('updated_at')->limit($limit)->get(['shift_id', 'plan_revision']);
        $reopened = StaffingAutomationRun::where('state', 'completed')->whereHas('shift', fn ($query) => $query->where('starts_at', '>', now()->utc())
            ->whereColumn('revision', 'published_revision')->whereColumn('shifts.revision', 'staffing_automation_runs.plan_revision')
            ->whereNotIn('status', ['draft', 'cancelled', 'completed', 'in_progress'])
            ->where('required_staff', '>', fn ($count) => $count->selectRaw('count(*)')->from('shift_assignments')->whereColumn('shift_assignments.shift_id', 'shifts.id')
                ->where('status', 'confirmed')->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')))
            ->orderBy('updated_at')->limit($limit)->get(['shift_id', 'plan_revision']);
        $fresh = Shift::where('published_revision', '>', 0)->whereColumn('revision', 'published_revision')->where('starts_at', '>', now()->utc())
            ->where('starts_at', '<=', now()->utc()->addDays((int) $settings['staffing_request_horizon_days']))->whereNotIn('status', ['draft', 'cancelled', 'completed', 'in_progress'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('staffing_automation_runs')->whereColumn('staffing_automation_runs.shift_id', 'shifts.id')->whereColumn('staffing_automation_runs.plan_revision', 'shifts.revision'))
            ->orderBy('starts_at')->limit($limit)->get(['id', 'revision'])->map(fn ($shift) => ['shift_id' => $shift->id, 'plan_revision' => $shift->revision]);

        return $due->concat($active)->concat($reopened)->concat($fresh)->unique(fn ($row) => $row['shift_id'].':'.$row['plan_revision'])->values();
    }

    public static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'portal_invitation' => 'Personalanfrage im Portal offen', 'interest_received' => 'Interesse gemeldet · persönliche Freigabe erforderlich',
            'response_expired' => 'Rückmeldung vor Fristende nicht freigegeben', 'round_limit' => 'Anfragerunden ausgeschöpft',
            'uncertain_candidates' => 'Eignung oder gepflegte Grundlagen müssen geprüft werden', 'no_uncontacted_candidates' => 'Keine weiteren geeigneten Personen verfügbar',
            'publication_changed' => 'Aktuelle Dienstveröffentlichung erforderlich', 'existing_offer' => 'Vorhandenes Dienstangebot zuerst bearbeiten',
            'assisted_proposal' => 'Geprüfter Anfragevorschlag · keine Person kontaktiert', 'settings_changed' => 'Geänderte Automatik prüfen',
            'supervisor_not_authorized' => 'Verantwortliche Disposition nicht berechtigt', 'reserved_capacity' => 'Angefragte Einsatzplätze warten auf Zusage',
            'confirmed_capacity' => 'Einsatzplätze aktuell bestätigt', 'shift_unavailable' => 'Dienst nicht mehr anfragbar', 'start_imminent' => 'Dienstbeginn steht unmittelbar bevor',
            'outside_horizon' => 'Dienst liegt außerhalb des eingestellten Anfragezeitraums', 'order_not_confirmed' => 'Auftragsfreigabe zuerst prüfen',
            'vacancy_reopened' => 'Einsatzplatz nach Änderung erneut offen',
            'shift_removed' => 'Dienst wurde entfernt · keine weiteren Anfragen',
            default => 'Personalanfrage prüfen',
        };
    }

    private function review(StaffingAutomationRun $run, Shift $shift, User|StaffingAutomationActor $actor, string $reason, array $issues = [], bool $expire = false, mixed $next = null): StaffingAutomationRun
    {
        if ($expire) {
            $this->expireOwnedOffer($run, $actor);
        }
        $case = $run->staffing_case_id ? StaffingCase::find($run->staffing_case_id) : null;
        $caseActive = $case && in_array($case->status, ['open', 'escalated'], true) && $case->responsible_id === $run->supervising_user_id;
        if ($caseActive && $run->reason_code === $reason && $run->state === 'review' && ($run->next_run_at?->timestamp ?? null) === ($next?->timestamp ?? null)) {
            return $run;
        }
        $caseChanged = false;
        if (! $caseActive && ! in_array($shift->status->value, ['cancelled', 'completed'], true)) {
            $case = app(WorkforcePlanningService::class)->openCase(['shift_id' => $shift->id, 'kind' => 'reserve',
                'responsible_id' => $run->supervising_user_id, 'due_at' => now()->utc()->format('Y-m-d\TH:i:s'), 'timezone' => 'UTC',
                'note' => 'Automatische Personalanfrage: '.self::reasonLabel($reason).'. Referenz '.$run->run_uuid], $actor);
            $run->staffing_case_id = $case->id;
            $run->save();
            $caseChanged = true;
        }
        if ($run->staffing_case_id) {
            $headline = 'Personalanfrage · '.self::reasonLabel($reason);
            $notice = OperationsAttentionItem::firstOrCreate(['recipient_user_id' => $run->supervising_user_id, 'source_key' => hash('sha256', 'staffing-review:'.$run->run_uuid.':'.$run->staffing_case_id)],
                ['kind' => 'monitor_case', 'headline' => 'Personalanfrage · '.self::reasonLabel($reason), 'module' => 'workforce-planning', 'record_id' => $run->staffing_case_id,
                    'source_revision' => StaffingCase::find($run->staffing_case_id)?->revision ?? 1, 'due_at' => now()->utc()]);
            if (! $notice->resolved_at && $notice->headline !== $headline) {
                $notice->update(['headline' => $headline, 'due_at' => now()->utc(), 'source_revision' => StaffingCase::find($run->staffing_case_id)?->revision ?? 1,
                    'read_at' => null, 'revision' => $notice->revision + 1]);
            }
        }

        return $this->transition($run, $actor, 'review', $reason, $next, 'review_required', ['issue_codes' => $issues], $caseChanged);
    }

    private function expireOwnedOffer(StaffingAutomationRun $run, User|StaffingAutomationActor $actor, string $status = 'expired'): void
    {
        if (! $run->offer_id) {
            return;
        }
        $offer = ShiftOffer::lockForUpdate()->find($run->offer_id);
        if ($offer && $offer->status === 'open') {
            $offer->update(['status' => $status, 'revision' => $offer->revision + 1]);
            app(OperationsAuditService::class)->record($offer, $actor, 'staffing.automation.offer_'.$status, ['plan_revision' => $run->plan_revision]);
            OperationsAttentionItem::where('kind', 'staffing_offer')->where('record_id', $offer->id)->whereNull('resolved_at')->update(['resolved_at' => now()->utc()]);
        }
    }

    private function transition(StaffingAutomationRun $run, User|StaffingAutomationActor $actor, string $state, string $reason, mixed $next, string $event = 'state_changed', array $extra = [], bool $force = false): StaffingAutomationRun
    {
        if (! $force && $run->state === $state && $run->reason_code === $reason) {
            return $run;
        }
        $run->update(['state' => $state, 'reason_code' => $reason, 'next_run_at' => $next, 'revision' => $run->revision + 1]);
        $this->audit($run, $actor, $event, $extra);

        return $run;
    }

    private function audit(StaffingAutomationRun $run, User|StaffingAutomationActor $actor, string $event, array $extra = []): void
    {
        app(OperationsAuditService::class)->record($run, $actor, 'staffing.automation.'.$event,
            ['staffing_run_uuid' => $run->run_uuid, 'shift_id' => $run->shift_id, 'plan_revision' => $run->plan_revision, 'settings_revision' => $run->settings_revision,
                'state' => $run->state, 'reason_code' => $run->reason_code, 'wave_count' => $run->wave_count, 'offer_id' => $run->offer_id,
                'staffing_case_id' => $run->staffing_case_id, 'basis_hash' => $run->basis['hash'] ?? null,
                'validated_user_ids' => $run->basis['candidate_ids'] ?? []] + $extra);
    }

    private function settings(bool $lock = false): array
    {
        if ($lock) {
            Setting::where('type', AiDispositionSettings::GROUP)->where('key', AiDispositionSettings::KEY)->lockForUpdate()->first();
        }

        return AiDispositionSettings::all(true);
    }

    private function supervisor(array $settings): ?User
    {
        $user = User::find((int) ($settings['supervisor_id'] ?? 0));

        return $user?->isActive() && $user->can('operations.manage') ? $user : null;
    }

    private function authorize(User $actor): void
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['staffing_automation' => $message]);
        }
    }

    private function row(StaffingAutomationRun $run): array
    {
        return $run->only(['id', 'run_uuid', 'revision', 'shift_id', 'plan_revision', 'settings_revision', 'state', 'reason_code', 'offer_id', 'staffing_case_id', 'wave_count'])
            + ['shift_revision' => $run->shift?->revision, 'label' => self::reasonLabel($run->reason_code), 'next_run_at' => $run->next_run_at?->toIso8601String(),
                'candidate_ids' => $run->basis['candidate_ids'] ?? [], 'issue_codes' => $run->basis['issue_codes'] ?? [], 'basis_hash' => $run->basis['hash'] ?? null];
    }
}
