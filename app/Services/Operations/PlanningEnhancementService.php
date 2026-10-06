<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\EmployeeQualification;
use App\Models\PlanningTeam;
use App\Models\QualificationBundle;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftBundleSnapshot;
use App\Models\ShiftDependency;
use App\Models\User;
use App\Models\WorkforcePosition;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningEnhancementSchema;
use App\Support\Operations\PlanningLocks;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanningEnhancementService
{
    public function ready(): bool
    {
        return PlanningEnhancementSchema::ready();
    }

    public function mandatoryRequirementIds(int $shiftId): array
    {
        if (! Schema::hasTable('shift_bundle_snapshots') || ! Schema::hasColumns('shift_bundle_snapshots', ['shift_id', 'requirements'])) {
            return [];
        }

        return ShiftBundleSnapshot::where('shift_id', $shiftId)->get()->flatMap(fn ($snapshot) => collect($snapshot->requirements)->where('mandatory', true)->pluck('id'))->unique()->values()->all();
    }

    /** Called by the existing variant-copy workflow before assigning the copied duty. */
    public function copySnapshots(int $sourceId, Shift $target, User $actor): void
    {
        if (! Schema::hasTable('shift_bundle_snapshots') || ! Schema::hasColumns('shift_bundle_snapshots', ['shift_id', 'requirements', 'bundle_version', 'scope', 'qualification_bundle_id', 'shift_revision', 'created_by'])) {
            return;
        }
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
        OperationsTransaction::run(function () use ($sourceId, $target, $actor) {
            OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
            PlanningLocks::acquire(array_values(array_unique([$sourceId, $target->id])));
            $target = Shift::findOrFail($target->id);
            $this->check($target->status->value === 'draft' && $target->published_revision === 0 && $target->starts_at->isFuture() && ! $target->assignments()->blocking()->exists(), 'Anforderungskopie nur für unbesetzte zukünftige Dienstentwürfe.');
            foreach (ShiftBundleSnapshot::where('shift_id', $sourceId)->get() as $source) {
                $this->check(! ShiftBundleSnapshot::where('shift_id', $target->id)->where('scope', $source->scope)->exists(), 'Anforderungssnapshot besteht bereits.');
                $target->qualifications()->syncWithoutDetaching(collect($source->requirements)->where('mandatory', true)->pluck('id')->all());
                $copy = ShiftBundleSnapshot::create(['shift_id' => $target->id, 'qualification_bundle_id' => $source->qualification_bundle_id, 'scope' => $source->scope, 'requirements' => $source->requirements, 'bundle_version' => $source->bundle_version, 'shift_revision' => $target->revision, 'created_by' => $actor->id]);
                app(OperationsAuditService::class)->record($copy, $actor, 'bundle.snapshot.copied', ['source_shift_id' => $sourceId]);
            }
        }, 3);
    }

    public function access(User $actor): void
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
        OperationsAccess::requireReady();
        abort_unless($this->ready(), 503, 'Planungsbereich nicht verfügbar.');
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }

    /** Each edit creates a new version; approved requirements are immutable. */
    public function createBundle(array $data, User $actor, ?int $sourceId = null, ?int $sourceRevision = null): QualificationBundle
    {
        $this->access($actor);
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'role_name' => 'required|string|max:160', 'qualification_ids' => 'required|array|min:1|max:50', 'qualification_ids.*' => 'integer|distinct|exists:qualification_types,id', 'development_ids' => 'present|array|max:50', 'development_ids.*' => 'integer|distinct|exists:qualification_types,id'])->validate();
        $data['qualification_ids'] = array_map('intval', $data['qualification_ids']);
        $data['development_ids'] = array_map('intval', $data['development_ids']);

        return OperationsTransaction::run(function () use ($data, $actor, $sourceId, $sourceRevision) {
            $this->access($actor);
            $reference = $sourceId ? QualificationBundle::findOrFail($sourceId) : null;
            $versions = $reference ? QualificationBundle::where('code', $reference->code)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $source = $sourceId ? $versions->get($sourceId) : null;
            $this->check(! $source || $source->revision === $sourceRevision, 'Bündel wurde geändert.');
            $ids = array_unique(array_merge($data['qualification_ids'], $data['development_ids']));
            $types = QualificationType::whereKey($ids)->where('is_active', true)->orderBy('id')->get();
            $this->check($types->count() === count($ids), 'Nur aktive Nachweisarten verwenden.');
            $bundle = QualificationBundle::create(['code' => $source?->code ?? (string) Str::uuid(), 'version' => $source ? QualificationBundle::where('code', $source->code)->max('version') + 1 : 1, 'name' => $data['name'], 'role_name' => $data['role_name'], 'requirements' => $types->map(fn ($type) => ['id' => $type->id, 'name' => $type->name, 'mandatory' => in_array($type->id, $data['qualification_ids'], true)])->all(), 'created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($bundle, $actor, 'bundle.version.created');

            return $bundle->fresh();
        }, 3);
    }

    public function approveBundle(int $id, int $revision, User $actor): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($id, $revision, $actor) {
            $this->access($actor);
            $bundle = QualificationBundle::lockForUpdate()->findOrFail($id);
            $this->check($bundle->revision === $revision && $bundle->status === 'draft', 'Bündel wurde geändert.');
            $this->check((int) $bundle->created_by !== (int) $actor->id, 'Bündelfreigabe benötigt eine zweite berechtigte Person.');
            $ids = array_column($bundle->requirements, 'id');
            $this->check(QualificationType::whereKey($ids)->where('is_active', true)->count() === count($ids), 'Nachweisart wurde deaktiviert.');
            $bundle->update(['status' => 'approved', 'revision' => $revision + 1, 'approved_by' => $actor->id, 'approved_at' => now()->utc()]);
            app(OperationsAuditService::class)->record($bundle, $actor, 'bundle.approved');
        }, 3);
    }

    /** Coverage suggests training only. Partial coverage never grants assignment eligibility. */
    public function bundleCoverage(int $id, string $date, User $actor): array
    {
        $this->access($actor);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        $bundle = QualificationBundle::findOrFail($id);
        $types = QualificationType::whereKey(array_column($bundle->requirements, 'id'))->get()->keyBy('id');
        $rows = [];
        $users = User::where('role', 'staff')->where('status', true)->orderBy('name')->get();
        $proofs = EmployeeQualification::whereIn('user_id', $users->pluck('id'))->whereIn('qualification_type_id', array_column($bundle->requirements, 'id'))->where('status', 'approved')->whereDate('valid_from', '<=', $date)->whereDate('valid_until', '>=', $date)->get()->groupBy('user_id');
        foreach ($users as $user) {
            $mandatory = 0;
            $covered = 0;
            $development = 0;
            $devCovered = 0;
            $missing = [];
            foreach ($bundle->requirements as $requirement) {
                $valid = (($types->get($requirement['id'])?->is_active) ?? false) && $proofs->get($user->id, collect())->contains('qualification_type_id', $requirement['id']);
                if ($requirement['mandatory']) {
                    $mandatory++;
                    $covered += (int) $valid;
                } else {
                    $development++;
                    $devCovered += (int) $valid;
                }
                if (! $valid) {
                    $missing[] = $requirement['name'];
                }
            }
            $rows[] = ['id' => $user->id, 'name' => $user->name, 'mandatory_coverage' => $covered.' / '.$mandatory, 'development_coverage' => $devCovered.' / '.$development, 'requirement_state' => $covered === $mandatory ? 'Vollständig' : 'Pflichtnachweis fehlt', 'training_need' => implode(', ', $missing) ?: '—'];
        }

        return $rows;
    }

    public function attachBundle(int $shiftId, int $shiftRevision, int $bundleId, int $bundleRevision, string $scope, User $actor): ShiftBundleSnapshot
    {
        $this->access($actor);
        Validator::make(compact('scope'), ['scope' => 'required|string|max:120'])->validate();

        return OperationsTransaction::run(function () use ($shiftId, $shiftRevision, $bundleId, $bundleRevision, $scope, $actor) {
            $this->access($actor);
            PlanningLocks::acquire([$shiftId]);
            $shift = Shift::findOrFail($shiftId);
            $bundle = QualificationBundle::lockForUpdate()->findOrFail($bundleId);
            $this->check($shift->revision === $shiftRevision && $shift->status->value === 'draft' && $shift->published_revision === 0 && $shift->starts_at->isFuture(), 'Nur unveränderte zukünftige Dienstentwürfe ergänzen.');
            $this->check($bundle->revision === $bundleRevision && $bundle->status === 'approved' && $bundle->role_name === $shift->role_name, 'Freigegebenes Bündel muss zur Tätigkeit passen.');
            $this->check(! ShiftBundleSnapshot::where('shift_id', $shiftId)->where('scope', $scope)->exists(), 'Anforderungssnapshot besteht bereits. Einen neuen Dienstentwurf verwenden.');
            $snapshot = ShiftBundleSnapshot::create(['shift_id' => $shiftId, 'qualification_bundle_id' => $bundleId, 'scope' => $scope, 'requirements' => $bundle->requirements, 'bundle_version' => $bundle->version, 'shift_revision' => $shiftRevision + 1, 'created_by' => $actor->id]);
            // Existing expiry/impact reporting reuses the same pivot. The immutable snapshot remains authoritative.
            $shift->qualifications()->syncWithoutDetaching(collect($bundle->requirements)->where('mandatory', true)->pluck('id')->all());
            $shift->forceFill(['revision' => $shiftRevision + 1])->save();
            foreach ($shift->assignments()->blocking()->with('user')->get() as $assignment) {
                app(StaffEligibilityService::class)->assertEligible($shift, $assignment->user);
            }
            app(OperationsAuditService::class)->record($snapshot, $actor, 'bundle.snapshot.attached');

            return $snapshot;
        }, 3);
    }

    public function saveTeam(?int $id, ?int $revision, array $data, User $actor): PlanningTeam
    {
        $this->access($actor);
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'user_ids' => 'required|array|min:1|max:100', 'user_ids.*' => 'integer|distinct|exists:users,id', 'is_active' => 'required|boolean'])->validate();
        $data['user_ids'] = array_map('intval', $data['user_ids']);

        return OperationsTransaction::run(function () use ($id, $revision, $data, $actor) {
            $this->access($actor);
            User::whereKey($data['user_ids'])->orderBy('id')->lockForUpdate()->get();
            $team = $id ? PlanningTeam::lockForUpdate()->findOrFail($id) : new PlanningTeam;
            $this->check(! $id || $team->revision === $revision, 'Team wurde geändert.');
            $this->check(User::whereKey($data['user_ids'])->where('role', 'staff')->where('status', true)->count() === count($data['user_ids']), 'Team enthält nicht einsatzberechtigte Personen.');
            $team->fill($data)->forceFill(['revision' => $id ? $revision + 1 : 1, 'created_by' => $id ? $team->created_by : $actor->id])->save();
            app(OperationsAuditService::class)->record($team, $actor, 'team.saved');

            return $team;
        }, 3);
    }

    /** All selected places succeed, or no assignment is written. */
    public function bulkPreview(array $entries, User $actor): array
    {
        $this->access($actor);
        $entries = Validator::make(['entries' => $entries], ['entries' => 'required|array|min:1|max:100', 'entries.*.shift_id' => 'required|integer|distinct|exists:shifts,id', 'entries.*.revision' => 'required|integer|min:1', 'entries.*.user_ids' => 'required|array|min:1|max:100', 'entries.*.user_ids.*' => 'integer|exists:users,id'])->validate()['entries'];
        $entries = array_map(fn ($entry) => ['shift_id' => (int) $entry['shift_id'], 'revision' => (int) $entry['revision'], 'user_ids' => array_map('intval', $entry['user_ids'])], $entries);
        foreach ($entries as $entry) {
            $this->check(count(array_unique($entry['user_ids'])) === count($entry['user_ids']), 'Mitarbeiter je Dienst nur einmal wählen.');
        }
        $shifts = Shift::whereKey(array_column($entries, 'shift_id'))->with(['qualifications', 'order', 'assignments'])->get()->keyBy('id');
        $rows = [];
        $virtual = [];
        foreach ($entries as $entry) {
            foreach ($entry['user_ids'] as $id) {
                $virtual[] = ['shift' => $shifts[$entry['shift_id']], 'user_id' => $id];
            }
        }
        foreach ($entries as $entry) {
            $shift = $shifts[$entry['shift_id']];
            $schedule = [];
            if ($shift->revision !== $entry['revision'] || ! $shift->starts_at->isFuture() || in_array($shift->status->value, ['cancelled', 'completed', 'in_progress']) || in_array($shift->order->status->value, ['cancelled', 'completed', 'invoiced'])) {
                $schedule[] = 'Dienst wurde geändert oder ist nicht mehr einteilbar.';
            }
            if ($shift->assignments->filter(fn ($a) => $a->status->blocksAvailability())->count() + count($entry['user_ids']) > $shift->required_staff) {
                $schedule[] = 'Nicht genügend offene Einsatzplätze.';
            }
            foreach ($entry['user_ids'] as $id) {
                $user = User::findOrFail($id);
                $additional = collect($virtual)->filter(fn ($v) => $v['user_id'] === $id && $v['shift']->id !== $shift->id)->pluck('shift')->all();
                $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]), false, ['additional_shifts' => $additional])[$id];
                if ($shift->assignments->contains(fn ($a) => $a->user_id === $id && $a->status->blocksAvailability())) {
                    $issues[] = ['code' => 'assigned', 'message' => 'Bereits eingeteilt.'];
                }
                try {
                    app(OrderDemandService::class)->assertCapacity($shift, $id, false, $virtual);
                } catch (ValidationException $e) {
                    foreach (collect($e->errors())->flatten() as $message) {
                        $issues[] = ['code' => 'capacity', 'message' => $message];
                    }
                }
                $rows[] = ['shift_id' => $shift->id, 'revision' => $entry['revision'], 'user_id' => $id, 'title' => $shift->title, 'name' => $user->name, 'issues' => $issues, 'schedule_issues' => $schedule];
            }
        }
        $valid = collect($rows)->every(fn ($r) => $r['issues'] === [] && $r['schedule_issues'] === []);

        return ['rows' => $rows, 'valid' => $valid, 'fingerprint' => hash('sha256', json_encode([$entries, $rows], JSON_THROW_ON_ERROR))];
    }

    public function bulkAssign(array $entries, string $fingerprint, User $actor): array
    {
        $this->access($actor);

        return OperationsTransaction::run(function () use ($entries, $fingerprint, $actor) {
            $this->access($actor);
            PlanningLocks::acquire(array_column($entries, 'shift_id'), collect($entries)->pluck('user_ids')->flatten()->all());
            $preview = $this->bulkPreview($entries, $actor);
            $this->check($preview['valid'] && hash_equals($preview['fingerprint'], $fingerprint), 'Sammelzuweisung enthält neue Konflikte. Vorschau erneuern.');
            $saved = [];
            foreach (collect($entries)->sortBy(fn ($entry) => Shift::findOrFail($entry['shift_id'])->starts_at) as $entry) {
                foreach ($entry['user_ids'] as $id) {
                    $shift = Shift::findOrFail($entry['shift_id']);
                    $saved[] = app(ShiftAssignmentService::class)->assign($shift, User::findOrFail((int) $id), $actor, ShiftAssignmentStatus::Requested, null, $shift->revision)->id;
                }
            }

            return $saved;
        }, 3);
    }

    public function createDependency(array $data, User $actor): ShiftDependency
    {
        $this->access($actor);
        $data = Validator::make($data, ['predecessor_id' => 'required|integer|different:successor_id|exists:shifts,id', 'successor_id' => 'required|integer|exists:shifts,id', 'predecessor_revision' => 'required|integer', 'successor_revision' => 'required|integer', 'handover_location' => 'required|string|max:160', 'transfer_minutes' => 'required|integer|min:0|max:10080', 'train_code' => 'nullable|string|max:120', 'vehicle_code' => 'nullable|string|max:120', 'same_employee' => 'required|boolean'])->validate();

        return OperationsTransaction::run(function () use ($data, $actor) {
            $this->access($actor);
            $shifts = PlanningLocks::acquire([$data['predecessor_id'], $data['successor_id']]);
            foreach (['predecessor', 'successor'] as $key) {
                $s = $shifts[$data[$key.'_id']];
                $this->check($s->revision === $data[$key.'_revision'] && $s->status->value === 'draft' && $s->published_revision === 0 && $s->starts_at->isFuture(), 'Dienstkette nur für unveränderte zukünftige Entwürfe anlegen.');
            }
            $a = $shifts[$data['predecessor_id']];
            $b = $shifts[$data['successor_id']];
            $this->check($a->ends_at->addMinutes($data['transfer_minutes'])->lte($b->starts_at), 'Gepflegter Übergabepuffer passt nicht zwischen die Dienste.');
            $dependency = ShiftDependency::create(collect($data)->except(['predecessor_revision', 'successor_revision'])->all() + ['created_by' => $actor->id]);
            foreach ([$a, $b] as $s) {
                $this->check($this->scheduleIssues($s) === [], 'Dienstkette verletzt Ressourcen-/Übergaberegeln.');
                foreach ($s->assignments()->blocking()->with('user')->get() as $assignment) {
                    app(StaffEligibilityService::class)->assertEligible($s, $assignment->user);
                }
                $s->forceFill(['revision' => $s->revision + 1])->save();
            }
            app(OperationsAuditService::class)->record($dependency, $actor, 'dependency.created');

            return $dependency;
        }, 3);
    }

    /** Safe without the optional tables; used by scheduling and all central assignments. */
    public function scheduleIssues(Shift $shift): array
    {
        if (! $shift->id || ! Schema::hasTable('shift_dependencies') || ! Schema::hasColumns('shift_dependencies', ['predecessor_id', 'successor_id', 'transfer_minutes', 'train_code', 'vehicle_code'])) {
            return [];
        }
        $issues = [];
        foreach (ShiftDependency::where('predecessor_id', $shift->id)->orWhere('successor_id', $shift->id)->get() as $edge) {
            $a = $edge->predecessor_id === $shift->id ? $shift : Shift::find($edge->predecessor_id);
            $b = $edge->successor_id === $shift->id ? $shift : Shift::find($edge->successor_id);
            if (! $a || ! $b || $a->status->value === 'cancelled' || $b->status->value === 'cancelled' || $a->ends_at->addMinutes($edge->transfer_minutes)->gt($b->starts_at)) {
                $issues[] = ['code' => 'dependency_gap', 'message' => 'Dienstkette oder gepflegter Übergabepuffer ist nicht erfüllt.'];
            }
            foreach (['train_code', 'vehicle_code'] as $field) {
                if ($edge->$field && (($a?->disposition_details[$field] ?? $edge->$field) !== $edge->$field || ($b?->disposition_details[$field] ?? $edge->$field) !== $edge->$field)) {
                    $issues[] = ['code' => 'dependency_resource', 'message' => 'Zug-/Fahrzeugreferenz der Dienstkette widerspricht dem Dienst.'];
                }
            }
        }

        return $issues;
    }

    public function planningIssues(Shift $shift, User $user, array $context = []): array
    {
        $issues = $this->scheduleIssues($shift);
        if (! $shift->id || ! Schema::hasTable('shift_dependencies') || ! Schema::hasColumns('shift_dependencies', ['successor_id', 'same_employee', 'predecessor_id'])) {
            return $issues;
        }
        foreach (ShiftDependency::where('successor_id', $shift->id)->where('same_employee', true)->get() as $edge) {
            $available = Shift::whereKey($edge->predecessor_id)->whereHas('assignments', fn ($q) => $q->blocking()->where('user_id', $user->id))->exists() || collect($context['additional_shifts'] ?? [])->contains(fn ($s) => $s->id === $edge->predecessor_id);
            if (! $available) {
                $issues[] = ['code' => 'dependency_person', 'message' => 'Mitarbeiter muss auch den vorhergehenden Dienst der Kette übernehmen.'];
            }
        }

        return $issues;
    }

    public function createPosition(array $data, User $actor): WorkforcePosition
    {
        $this->access($actor);
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'role_name' => 'required|string|max:160', 'location_name' => 'nullable|string|max:160', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from', 'target_fte' => 'required|numeric|min:0|max:999', 'full_time_week_minutes' => 'nullable|integer|min:1|max:10080', 'qualification_ids' => 'present|array|max:50', 'qualification_ids.*' => 'integer|distinct|exists:qualification_types,id'])->validate();

        return OperationsTransaction::run(function () use ($data, $actor) {
            $this->access($actor);
            $record = WorkforcePosition::create($data + ['created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($record, $actor, 'position.created');

            return $record;
        }, 3);
    }

    public function approvePosition(int $id, int $revision, User $actor): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($id, $revision, $actor) {
            $this->access($actor);
            $record = WorkforcePosition::lockForUpdate()->findOrFail($id);
            $this->check($record->revision === $revision && $record->status === 'draft', 'Stellenplan wurde geändert.');
            $this->check((int) $record->created_by !== (int) $actor->id, 'Stellenfreigabe benötigt eine zweite berechtigte Person.');
            $record->update(['status' => 'approved', 'revision' => $revision + 1, 'approved_by' => $actor->id, 'approved_at' => now()->utc()]);
            app(OperationsAuditService::class)->record($record, $actor, 'position.approved');
        }, 3);
    }
}
