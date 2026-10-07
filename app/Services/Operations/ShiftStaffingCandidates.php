<?php

namespace App\Services\Operations;

use App\Models\EmployeeQualification;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Support\Operations\OperationsAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only planning aid. Its score is neither a probability nor an assignment permission. */
class ShiftStaffingCandidates
{
    /**
     * Rank the complete matching population before the caller takes its visible slice.
     * Do not cache assessments across requests: qualifications and conflicts can change.
     *
     * @return Collection<int, User>
     */
    public function ranked(Shift $shift, array $filters = []): Collection
    {
        OperationsAccess::requireReady();
        $shift = Shift::with(['qualifications', 'order'])->findOrFail($shift->id);
        $query = $this->staffQuery()->whereNotIn('id', ShiftAssignment::blocking()
            ->where('shift_id', $shift->id)->select('user_id'));
        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100);
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }
        if (filled($filters['qualification_id'] ?? null)) {
            $query->whereIn('id', $this->validQualifications($shift)
                ->where('qualification_type_id', (int) $filters['qualification_id'])->select('user_id'));
        }
        if (filled($filters['pool_id'] ?? null)) {
            if (! $this->poolsReady()) {
                return collect();
            }
            $query->whereIn('id', DB::table('workforce_pool_user')
                ->whereIn('workforce_pool_id', WorkforcePool::where('is_active', true)
                    ->whereKey((int) $filters['pool_id'])->select('id'))->select('user_id'));
        }
        // The shared person component loads profile/team only for the visible slice.
        $users = $query->get(['id', 'name', 'email', 'profile_photo_path', 'current_team_id', 'role', 'status']);
        if ($users->isEmpty()) {
            return collect();
        }
        $assessments = app(StaffEligibilityService::class)->assessMany($shift, $users);
        $regions = app(StaffRegionalPreferenceService::class)->assessMany($shift, $users);
        $exceptions = app(ShiftAssignmentExceptionService::class);
        $rank = ['eligible' => 0, 'review' => 1, 'blocked' => 2];

        return $users->map(function (User $user) use ($assessments, $regions, $exceptions) {
            // Missing assessment must never be interpreted as an eligible employee.
            $issues = $assessments[$user->id] ?? [['code' => 'assessment_missing', 'message' => 'Eignungsprüfung nicht vollständig.']];
            $region = $regions[$user->id] ?? ['state' => 'unknown', 'label' => 'Region ungeklärt', 'detail' => 'Regionale Prüfung nicht verfügbar.', 'score_adjustment' => 0, 'blocked' => false];
            if (($region['blocked'] ?? false) || ($region['state'] ?? null) === 'no_go') {
                $issues[] = ['code' => 'region_no_go', 'message' => ($region['detail'] ?? '') ?: 'Der Einsatzort liegt in einem hinterlegten No-Go-Gebiet.'];
            }
            $issues = collect($issues)->unique('code')->values()->all();
            $temporal = count(array_filter($issues, fn (array $issue) => $exceptions->isTemporalIssue($issue)));
            $hard = count($issues) - $temporal;
            $state = $hard > 0 ? 'blocked' : ($temporal > 0 ? 'review' : 'eligible');
            $regionalAdjustment = (int) ($region['score_adjustment'] ?? 0);
            $temporalPenalty = $temporal > 0 ? 20 + 10 * ($temporal - 1) : 0;
            $hardPenalty = $hard * 40;
            $cap = match ($state) {
                'review' => 74,
                'blocked' => 39,
                default => 100,
            };
            $score = max(0, min($cap, 85 + $regionalAdjustment - $temporalPenalty - $hardPenalty));
            $reasons = $issues === [] ? ['Keine Konflikte in den hinterlegten Planungsregeln.'] : array_column($issues, 'message');
            if (filled($region['detail'] ?? null)) {
                $reasons[] = $region['detail'];
            }
            $label = match ($state) {
                'review' => 'Zeitkonflikt prüfen',
                'blocked' => 'Nicht einsetzbar',
                default => 'Geeignet',
            };
            $user->setAttribute('staffing_score', $score);
            $user->setAttribute('staffing_state', $state);
            $user->setAttribute('staffing_label', $label);
            $user->setAttribute('staffing_reasons', array_values(array_unique($reasons)));
            $user->setAttribute('planning_issues', $issues);
            $user->setAttribute('staffing_region', $region);
            $user->setAttribute('staffing_score_breakdown', [
                'base' => 85, 'region' => $regionalAdjustment,
                'temporal_penalty' => $temporalPenalty, 'blocking_penalty' => $hardPenalty,
                'maximum' => $cap,
            ]);

            return $user;
        })->filter(function (User $user) use ($filters) {
            $suitability = (string) ($filters['suitability'] ?? 'all');
            $region = (string) ($filters['region'] ?? 'all');

            return ($suitability === 'all' || $user->staffing_state === $suitability)
                && ($region === 'all' || ($user->staffing_region['state'] ?? 'unknown') === $region);
        })->sort(function (User $a, User $b) use ($rank) {
            return ($rank[$a->staffing_state] <=> $rank[$b->staffing_state])
                ?: ($b->staffing_score <=> $a->staffing_score)
                ?: strnatcmp(mb_strtolower($a->name), mb_strtolower($b->name))
                ?: ($a->id <=> $b->id);
        })->values();
    }

    /** Only offer stored, active filters that actually contain active employees. */
    public function filterOptions(?Shift $shift = null): array
    {
        OperationsAccess::requireReady();
        $qualifications = QualificationType::where('is_active', true)
            ->whereIn('id', $this->validQualifications($shift)
                ->whereIn('user_id', $this->staffQuery()->select('id'))->select('qualification_type_id'))
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])->toArray();
        $pools = $this->poolsReady()
            ? WorkforcePool::where('is_active', true)
                ->whereHas('users', fn (Builder $query) => $query->where('users.status', true)->where('users.role', 'staff'))
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])->toArray()
            : [];

        return compact('qualifications', 'pools');
    }

    private function staffQuery(): Builder
    {
        return User::query()->where('status', true)->where('role', 'staff');
    }

    private function validQualifications(?Shift $shift): Builder
    {
        $from = $shift?->starts_at?->setTimezone($shift->timezone)->toDateString()
            ?? now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString();
        $until = $shift?->ends_at?->setTimezone($shift->timezone)->toDateString() ?? $from;

        return EmployeeQualification::where('status', 'approved')
            ->whereIn('qualification_type_id', QualificationType::where('is_active', true)->select('id'))
            ->whereDate('valid_from', '<=', $from)->whereDate('valid_until', '>=', $until);
    }

    private function poolsReady(): bool
    {
        return Schema::hasTable('workforce_pools') && Schema::hasTable('workforce_pool_user');
    }
}
