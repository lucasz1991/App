<?php

namespace Tests\Feature;

use App\Livewire\Operations\PlanningEnhancements;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PlanningTeam;
use App\Models\QualificationBundle;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBundleSnapshot;
use App\Models\User;
use App\Services\Operations\DeterministicPlanOptimizer;
use App\Services\Operations\PlanningCapacityService;
use App\Services\Operations\PlanningEnhancementService;
use App\Services\Operations\PlanVariantService;
use App\Services\Operations\RotationPlanningService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\StaffEligibilityService;
use App\Support\Operations\PlanningEnhancementSchema;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PlanningEnhancementsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $reviewer;

    private User $anna;

    private User $ben;

    private Order $order;

    private PlanningEnhancementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('shifts', fn (Blueprint $t) => $t->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->reviewer = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Alpha', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Freigegeben', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Jahresauftrag', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-05-01T00:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
        $this->service = app(PlanningEnhancementService::class);
    }

    private function shift(array $extra = []): Shift
    {
        return Shift::create(array_merge(['order_id' => $this->order->id, 'title' => 'Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'location_name' => 'Bremen', 'created_by' => $this->manager->id], $extra))->fresh();
    }

    private function bundle(QualificationType $type): QualificationBundle
    {
        $b = $this->service->createBundle(['name' => 'Tf Bündel', 'role_name' => 'Tf', 'qualification_ids' => [$type->id], 'development_ids' => []], $this->manager);
        $this->service->approveBundle($b->id, $b->revision, $this->reviewer);

        return $b->fresh();
    }

    private function proof(User $user, QualificationType $type): void
    {
        EmployeeQualification::create(['user_id' => $user->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2028-04-30', 'status' => 'approved']);
    }

    private function invalid(callable $action, string $expected = ''): void
    {
        try {
            $action();
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $e) {
            if ($expected !== '') {
                $this->assertStringContainsString($expected, collect($e->errors())->flatten()->implode(' '));
            } else {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    private function goals(): array
    {
        return ['wish_weight' => 30, 'load_weight' => 1, 'night_weight' => 10, 'weekend_weight' => 10, 'night_start' => '22:00', 'night_end' => '06:00'];
    }

    private function team(): PlanningTeam
    {
        return $this->service->saveTeam(null, null, ['name' => 'Trupp', 'user_ids' => [$this->anna->id, $this->ben->id], 'is_active' => true], $this->manager);
    }

    public function test_optional_schema_is_complete_idempotent_and_down_protects_evidence(): void
    {
        $this->assertTrue(PlanningEnhancementSchema::ready());
        (require database_path('migrations/2026_10_06_100000_create_planning_enhancements.php'))->up();
        $this->assertTrue($this->service->ready());
        try {
            (require database_path('migrations/2026_10_06_100000_create_planning_enhancements.php'))->down();
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('erhalten', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('shift_bundle_snapshots'));
        Schema::drop('workforce_positions');
        $this->assertFalse($this->service->ready());
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($this->shift(), collect([$this->anna]))[$this->anna->id]);
    }

    public function test_unauthorized_and_inactive_actor_cannot_read_or_mutate_planning(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->saveTeam(null, null, ['name' => 'No', 'user_ids' => [$this->anna->id], 'is_active' => true], $this->anna);
    }

    public function test_bundle_versions_require_second_approval_and_snapshots_do_not_change(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $other = QualificationType::create(['name' => 'Strecke', 'is_active' => true]);
        $draft = $this->service->createBundle(['name' => 'Tf', 'role_name' => 'Tf', 'qualification_ids' => [(string) $type->id], 'development_ids' => [$other->id]], $this->manager);
        $this->invalid(fn () => $this->service->approveBundle($draft->id, 1, $this->manager), 'zweite');
        $this->service->approveBundle($draft->id, 1, $this->reviewer);
        $draft = $draft->fresh();
        $s = $this->shift();
        $snapshot = $this->service->attachBundle($s->id, $s->revision, $draft->id, $draft->revision, 'Fahrt', $this->manager);
        $this->assertTrue($snapshot->requirements[0]['mandatory']);
        $this->assertFalse($snapshot->requirements[1]['mandatory']);
        $this->assertSame([$type->id], $s->fresh()->qualifications->modelKeys());
        $this->assertCount(1, app(StaffEligibilityService::class)->assessMany($s->fresh(), collect([$this->anna]))[$this->anna->id]);
        $this->proof($this->anna, $type);
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($s->fresh(), collect([$this->anna]))[$this->anna->id]);
        $new = $this->service->createBundle(['name' => 'Tf neu', 'role_name' => 'Tf', 'qualification_ids' => [$other->id], 'development_ids' => []], $this->manager, $draft->id, $draft->revision);
        $this->assertSame(2, $new->version);
        $this->assertSame($snapshot->requirements, $snapshot->fresh()->requirements);
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($s->fresh(), collect([$this->anna]))[$this->anna->id]);
    }

    public function test_bundle_attach_rolls_back_if_existing_assignee_has_no_proof_and_rejects_stale(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $b = $this->bundle($type);
        $s = $this->shift();
        ShiftAssignment::create(['shift_id' => $s->id, 'user_id' => $this->anna->id, 'status' => 'requested', 'assigned_by' => $this->manager->id]);
        $this->invalid(fn () => $this->service->attachBundle($s->id, $s->revision, $b->id, $b->revision, 'Dienst', $this->manager), 'Nachweis');
        $this->assertSame(0, ShiftBundleSnapshot::count());
        $this->assertSame($s->revision, $s->fresh()->revision);
        $this->invalid(fn () => $this->service->attachBundle($s->id, $s->revision + 1, $b->id, $b->revision, 'Dienst', $this->manager), 'unveränderte');
    }

    public function test_bulk_preview_is_read_only_and_all_requested_places_are_atomic(): void
    {
        $s = $this->shift(['required_staff' => 2]);
        $entries = [['shift_id' => $s->id, 'revision' => $s->revision, 'user_ids' => [$this->anna->id, $this->ben->id]]];
        $p = $this->service->bulkPreview($entries, $this->manager);
        $this->assertTrue($p['valid']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertCount(2, $this->service->bulkAssign($entries, $p['fingerprint'], $this->manager));
        $this->assertSame(['requested'], ShiftAssignment::pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all());
        $this->assertSame(0, $s->fresh()->published_revision);
    }

    public function test_bulk_rechecks_fresh_absence_and_keeps_every_assignment_unwritten(): void
    {
        $s = $this->shift(['required_staff' => 2]);
        $entries = [['shift_id' => $s->id, 'revision' => $s->revision, 'user_ids' => [$this->anna->id, $this->ben->id]]];
        $p = $this->service->bulkPreview($entries, $this->manager);
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'vacation', 'status' => 'approved', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00']);
        $this->invalid(fn () => $this->service->bulkAssign($entries, $p['fingerprint'], $this->manager), 'Konflikte');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_bulk_prospective_overlap_and_capacity_are_blocked(): void
    {
        $a = $this->shift();
        $b = $this->shift();
        $p = $this->service->bulkPreview([['shift_id' => $a->id, 'revision' => $a->revision, 'user_ids' => [$this->anna->id]], ['shift_id' => $b->id, 'revision' => $b->revision, 'user_ids' => [$this->anna->id]]], $this->manager);
        $this->assertFalse($p['valid']);
        $this->assertContains('rest_overlap', array_column($p['rows'][0]['issues'], 'code'));
        $p = $this->service->bulkPreview([['shift_id' => $a->id, 'revision' => $a->revision, 'user_ids' => [$this->anna->id, $this->ben->id]]], $this->manager);
        $this->assertFalse($p['valid']);
    }

    public function test_team_edit_rejects_stale_and_non_staff_members(): void
    {
        $team = $this->team();
        $this->invalid(fn () => $this->service->saveTeam($team->id, 9, ['name' => 'X', 'user_ids' => [$this->anna->id], 'is_active' => true], $this->manager), 'geändert');
        $this->invalid(fn () => $this->service->saveTeam(null, null, ['name' => 'X', 'user_ids' => [$this->manager->id], 'is_active' => true], $this->manager), 'einsatzberechtigte');
        $this->assertSame(1, PlanningTeam::count());
    }

    public function test_dependency_puffer_is_real_and_edit_hook_protects_unstaffed_duties(): void
    {
        $a = $this->shift(['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T12:00']);
        $b = $this->shift(['starts_at' => '2027-05-13T13:00', 'ends_at' => '2027-05-13T17:00']);
        $this->service->createDependency(['predecessor_id' => $a->id, 'successor_id' => $b->id, 'predecessor_revision' => $a->revision, 'successor_revision' => $b->revision, 'handover_location' => 'Bremen', 'transfer_minutes' => 60, 'same_employee' => false], $this->manager);
        $this->invalid(fn () => app(ShiftSchedulingService::class)->save($b->fresh(), ['starts_at' => CarbonImmutable::parse('2027-05-13T12:30+02:00'), 'ends_at' => $b->ends_at, 'expected_revision' => $b->fresh()->revision], $this->manager), 'Übergabepuffer');
        $this->assertSame('13:00', $b->fresh()->starts_at->setTimezone('Europe/Berlin')->format('H:i'));
    }

    public function test_same_employee_dependency_checks_prospective_chain_and_bulk_orders_it(): void
    {
        $a = $this->shift(['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T12:00']);
        $b = $this->shift(['starts_at' => '2027-05-13T13:00', 'ends_at' => '2027-05-13T17:00']);
        $this->service->createDependency(['predecessor_id' => $a->id, 'successor_id' => $b->id, 'predecessor_revision' => $a->revision, 'successor_revision' => $b->revision, 'handover_location' => 'Bremen', 'transfer_minutes' => 30, 'same_employee' => true], $this->manager);
        $this->assertContains('dependency_person', array_column(app(StaffEligibilityService::class)->assessMany($b->fresh(), collect([$this->anna]))[$this->anna->id], 'code'));
        $entries = [['shift_id' => $b->id, 'revision' => $b->fresh()->revision, 'user_ids' => [$this->anna->id]], ['shift_id' => $a->id, 'revision' => $a->fresh()->revision, 'user_ids' => [$this->anna->id]]];
        $p = $this->service->bulkPreview($entries, $this->manager);
        $this->assertTrue($p['valid']);
        $this->assertCount(2, $this->service->bulkAssign($entries, $p['fingerprint'], $this->manager));
    }

    public function test_capacity_is_per_duty_not_sum_and_unknown_models_remain_unknown(): void
    {
        $s = $this->shift(['required_staff' => 3]);
        $matrix = app(PlanningCapacityService::class)->matrix('2027-05-13', '2027-05-20', [], $this->manager);
        $this->assertSame(2, $matrix['duties'][0]['eligible']);
        $this->assertSame(1, $matrix['duties'][0]['missing']);
        $this->assertSame(2, $matrix['weeks'][0]['unknown']);
        $this->assertNull($matrix['weeks'][0]['remaining_hours']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_fairness_night_and_weekend_use_civil_overlap_without_health_fields(): void
    {
        $s = $this->shift(['starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T06:00']);
        ShiftAssignment::create(['shift_id' => $s->id, 'user_id' => $this->anna->id, 'status' => 'confirmed', 'assigned_by' => $this->manager->id]);
        $rows = app(PlanningCapacityService::class)->fairness('2027-05-13', '2027-05-16', ['night_start' => '22:00', 'night_end' => '06:00', 'unfavourable_roles' => ['Tf']], $this->manager);
        $this->assertSame(1, $rows[0]['nights']);
        $this->assertSame(1, $rows[0]['weekends']);
        $this->assertSame(1, $rows[0]['unfavourable']);
        $this->assertArrayNotHasKey('absence', $rows[0]);
        $this->assertNull($rows[0]['weekly_target']);
    }

    public function test_position_approval_is_second_person_and_unset_reference_never_invents_fte(): void
    {
        $p = $this->service->createPosition(['name' => 'Tf Personalbedarf', 'role_name' => 'Tf', 'from' => '2027-05-01', 'until' => '2027-12-31', 'target_fte' => 2, 'full_time_week_minutes' => null, 'qualification_ids' => []], $this->manager);
        $this->invalid(fn () => $this->service->approvePosition($p->id, 1, $this->manager), 'zweite');
        $this->service->approvePosition($p->id, 1, $this->reviewer);
        $rows = app(PlanningCapacityService::class)->positions('2027-05-13', $this->manager);
        $this->assertSame(2, $rows[0]['unknown']);
        $this->assertNull($rows[0]['gap_fte']);
        $this->assertSame('approved', $rows[0]['status']);
    }

    public function test_rotation_anchors_offsets_exceptions_and_preview_do_not_create_duties(): void
    {
        $s = $this->shift();
        $cycle = app(RotationPlanningService::class)->save(null, null, ['name' => 'Wochenfolge', 'anchor' => '2027-05-13', 'cycle_days' => 7, 'timezone' => 'Europe/Berlin', 'slots' => [['day' => 0, 'shift_id' => $s->id, 'team_id' => null, 'offset' => 0]], 'exceptions' => [['date' => '2027-05-20', 'user_id' => null]]], $this->manager);
        $p = app(RotationPlanningService::class)->preview($cycle->id, 1, '2027-05-13', '2027-05-28', $this->manager);
        $this->assertCount(2, $p['entries']);
        $this->assertSame(['2027-05-13T08:00', '2027-05-27T08:00'], array_column($p['entries'], 'starts_at'));
        $this->assertTrue($p['valid']);
        $this->assertSame(1, Shift::count());
        $this->assertSame(0, DB::table('plan_variants')->count());
        $v = app(RotationPlanningService::class)->createVariant($cycle->id, 1, '2027-05-13', '2027-05-28', $p['fingerprint'], $this->manager);
        $this->assertSame('draft', $v->status);
        $this->assertSame(1, Shift::count());
    }

    public function test_rotation_team_conflicts_and_dst_nonexistent_time_are_visible(): void
    {
        $s = $this->shift(['starts_at' => '2028-03-19T02:30', 'ends_at' => '2028-03-19T06:30']);
        $cycle = app(RotationPlanningService::class)->save(null, null, ['name' => 'DST Folge', 'anchor' => '2028-03-19', 'cycle_days' => 7, 'timezone' => 'Europe/Berlin', 'slots' => [['day' => 0, 'shift_id' => $s->id, 'team_id' => null, 'offset' => 0]], 'exceptions' => []], $this->manager);
        $p = app(RotationPlanningService::class)->preview($cycle->id, 1, '2028-03-26', '2028-03-26', $this->manager);
        $this->assertFalse($p['valid']);
        $this->assertStringContainsString('Zeitumstellung', implode(' ', $p['rows'][0]['issues']));
    }

    public function test_optimizer_is_deterministic_read_only_keeps_rest_need_and_central_eligibility(): void
    {
        $a = $this->shift(['required_staff' => 3]);
        $b = $this->shift(['starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00']);
        $optimizer = app(DeterministicPlanOptimizer::class);
        $before = DB::table('operation_audits')->count();
        $p = $optimizer->preview('2027-05-13', '2027-05-14', $this->goals(), $this->manager);
        $this->assertSame($p, $optimizer->preview('2027-05-13', '2027-05-14', $this->goals(), $this->manager));
        $this->assertSame(1, $p['open']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame($before, DB::table('operation_audits')->count());
        $variant = $optimizer->createVariant('2027-05-13', '2027-05-14', $this->goals(), $p['fingerprint'], $this->manager);
        $this->assertSame('draft', $variant->status);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_optimizer_fresh_revision_change_rejects_stale_preview(): void
    {
        $s = $this->shift();
        $optimizer = app(DeterministicPlanOptimizer::class);
        $p = $optimizer->preview('2027-05-13', '2027-05-13', $this->goals(), $this->manager);
        $s->forceFill(['revision' => $s->revision + 1])->save();
        $this->invalid(fn () => $optimizer->createVariant('2027-05-13', '2027-05-13', $this->goals(), $p['fingerprint'], $this->manager), 'geändert');
        $this->assertSame(0, DB::table('plan_variants')->count());
    }

    public function test_copied_variant_preserves_immutable_bundle_snapshot_before_assignment(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $b = $this->bundle($type);
        $s = $this->shift();
        $snapshot = $this->service->attachBundle($s->id, $s->revision, $b->id, $b->revision, 'Fahrt', $this->manager);
        $v = app(PlanVariantService::class)->capture([$s->id], 'Kopie', 7, $this->manager);
        $preview = app(PlanVariantService::class)->preview($v, $this->manager);
        $this->assertTrue($preview['valid']);
        app(PlanVariantService::class)->approve($v->id, $v->revision, $preview['fingerprint'], $this->manager);
        $ids = app(PlanVariantService::class)->apply($v->id, $v->fresh()->revision, $this->manager);
        $copy = ShiftBundleSnapshot::where('shift_id', $ids[0])->firstOrFail();
        $this->assertSame($snapshot->requirements, $copy->requirements);
        $this->assertSame($snapshot->bundle_version, $copy->bundle_version);
        $this->assertContains('qualification_'.$type->id, array_column(app(StaffEligibilityService::class)->assessMany(Shift::findOrFail($ids[0]), collect([$this->anna]))[$this->anna->id], 'code'));
    }

    public function test_livewire_tabs_and_standard_modal_host_render_without_touching_timeline(): void
    {
        $hash = hash_file('sha256', resource_path('js/staff-timeline.js'));
        Livewire::actingAs($this->manager)->test(PlanningEnhancements::class)->assertSee('Planungswerkzeuge')->call('setTab', 'bundles')->call('edit', 'bundle')->assertSet('formOpen', true)->assertSee('Zwingende Nachweise')->call('setTab', 'teams')->assertSet('modal', '')->call('edit', 'team')->assertSee('Mitglieder');
        $this->assertSame($hash, hash_file('sha256', resource_path('js/staff-timeline.js')));
    }

    public function test_copied_variant_preview_requires_bundle_proofs_before_approval(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $bundle = $this->bundle($type);
        $source = $this->shift();
        $this->service->attachBundle($source->id, $source->revision, $bundle->id, $bundle->revision, 'Dienst', $this->manager);
        // A separate draft edit may remove the ordinary pivot; snapshot requirements must still protect copies.
        $source->qualifications()->detach();
        $variant = app(PlanVariantService::class)->capture([$source->id], 'Kopie', 7, $this->manager)->fresh();
        $entries = $variant->entries;
        $entries[0]['user_ids'] = [$this->anna->id];
        $variant = app(PlanVariantService::class)->save($variant->id, $variant->revision, ['name' => $variant->name, 'from' => $variant->from->toDateString(), 'until' => $variant->until->toDateString(), 'timezone' => $variant->timezone, 'entries' => $entries], $this->manager)->fresh();
        $preview = app(PlanVariantService::class)->preview($variant, $this->manager);
        $this->assertFalse($preview['valid']);
        $this->assertContains('qualification_'.$type->id, array_column($preview['rows'][0]['issues'][$this->anna->id], 'code'));
        $this->assertSame(0, ShiftAssignment::count());
        $this->proof($this->anna, $type);
        $this->assertTrue(app(PlanVariantService::class)->preview($variant, $this->manager)['valid']);
    }

    public function test_date_scoped_fte_uses_explicit_reference_and_required_qualifications(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $this->proof($this->anna, $type);
        EmployeeWorkModel::create(['user_id' => $this->anna->id, 'name' => 'Halbzeit', 'starts_on' => '2027-01-01', 'ends_on' => null, 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 1200, 'daily_minutes' => [1 => 240, 2 => 240, 3 => 240, 4 => 240, 5 => 240, 6 => 0, 7 => 0], 'status' => 'active', 'created_by' => $this->manager->id, 'approved_by' => $this->reviewer->id, 'approved_at' => now()->utc()]);
        $position = $this->service->createPosition(['name' => 'Tf Bedarf', 'role_name' => 'Tf', 'from' => '2027-05-01', 'until' => '2027-06-30', 'target_fte' => 2, 'full_time_week_minutes' => 2400, 'qualification_ids' => [$type->id]], $this->manager);
        $this->service->approvePosition($position->id, 1, $this->reviewer);
        $rows = app(PlanningCapacityService::class)->positions('2027-05-13', $this->manager);
        $this->assertSame(0.5, $rows[0]['known_fte']);
        $this->assertSame(1.5, $rows[0]['gap_fte']);
        $this->assertSame(0, $rows[0]['unknown']);
        $this->assertSame([], app(PlanningCapacityService::class)->positions('2027-07-01', $this->manager));
    }

    public function test_annual_rotation_preview_cannot_apply_more_than_existing_variant_limits(): void
    {
        $source = $this->shift();
        $cycle = app(RotationPlanningService::class)->save(null, null, ['name' => 'Jahresrotation', 'anchor' => '2027-05-13', 'cycle_days' => 7, 'timezone' => 'Europe/Berlin', 'slots' => [['day' => 0, 'shift_id' => $source->id, 'team_id' => null, 'offset' => 1]], 'exceptions' => []], $this->manager);
        $preview = app(RotationPlanningService::class)->preview($cycle->id, 1, '2027-05-13', '2028-05-12', $this->manager);
        $this->assertSame('2027-05-14T08:00', $preview['entries'][0]['starts_at']);
        $this->assertFalse($preview['can_create']);
        $this->assertSame(1, Shift::count());
        $this->invalid(fn () => app(RotationPlanningService::class)->createVariant($cycle->id, 1, '2027-05-13', '2028-05-12', $preview['fingerprint'], $this->manager), 'Teilzeiträume');
        $this->assertSame(0, DB::table('plan_variants')->count());
    }

    public function test_livewire_chain_selection_keeps_revision_and_reports_changed_duty(): void
    {
        $first = $this->shift(['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T12:00']);
        $last = $this->shift(['starts_at' => '2027-05-13T13:00', 'ends_at' => '2027-05-13T17:00']);
        $test = Livewire::actingAs($this->manager)->test(PlanningEnhancements::class)->call('setTab', 'chains')->call('edit', 'chain')->set('form.predecessor_id', $first->id)->set('form.successor_id', $last->id)->set('form.handover_location', 'Bremen');
        $last->forceFill(['revision' => $last->revision + 1])->save();
        $test->call('save')->assertHasErrors('workflow');
        $this->assertSame(0, DB::table('shift_dependencies')->count());
    }

    public function test_livewire_personal_or_unknown_tabs_are_denied(): void
    {
        Livewire::actingAs($this->manager)->test(PlanningEnhancements::class)->call('setTab', 'unknown')->assertStatus(422);
        Livewire::actingAs($this->manager)->test(PlanningEnhancements::class, ['personal' => true])->assertForbidden();
    }

    public function test_all_nonempty_thematic_lists_and_modals_render_standard_row_actions(): void
    {
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $bundle = $this->bundle($type);
        $team = $this->team();
        $source = $this->shift(['required_staff' => 2]);
        $cycle = app(RotationPlanningService::class)->save(null, null, ['name' => 'Liste Rotation', 'anchor' => '2027-05-13', 'cycle_days' => 7, 'timezone' => 'Europe/Berlin', 'slots' => [['day' => 0, 'shift_id' => $source->id, 'team_id' => null, 'offset' => 0]], 'exceptions' => []], $this->manager);
        $position = $this->service->createPosition(['name' => 'Stellenbedarf', 'role_name' => 'Tf', 'from' => '2027-05-01', 'until' => '2027-06-30', 'target_fte' => 2, 'full_time_week_minutes' => null, 'qualification_ids' => [$type->id]], $this->manager);
        $test = Livewire::actingAs($this->manager)->test(PlanningEnhancements::class)->call('setTab', 'bundles')->assertSee('Tf Bündel')->assertSee('Zuordnen')->call('setTab', 'teams')->assertSee('Trupp')->assertSee('Einteilen')->call('edit', 'bulk', $team->id)->set('form.shift_ids', [$source->id])->call('prepare')->assertSet('preview.valid', true)->assertSee('Alle anfragen')->call('setTab', 'rotations')->assertSee('Liste Rotation')->assertSee('Vorschau')->call('setTab', 'positions')->assertSee('Stellenbedarf')->assertSee('Freigeben')->call('setTab', 'fairness')->assertSee('Anna Alpha')->call('setTab', 'optimizer')->call('prepare')->assertSee('Als Planvariante speichern');
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, DB::table('plan_variants')->count());
    }

    public function test_bundle_coverage_lists_training_needs_without_partial_eligibility(): void
    {
        $first = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $second = QualificationType::create(['name' => 'Strecke', 'is_active' => true]);
        $development = QualificationType::create(['name' => 'Nachwuchs', 'is_active' => true]);
        $bundle = $this->service->createBundle(['name' => 'Fahrtbündel', 'role_name' => 'Tf', 'qualification_ids' => [$first->id, $second->id], 'development_ids' => [$development->id]], $this->manager);
        $this->proof($this->anna, $first);
        $rows = $this->service->bundleCoverage($bundle->id, '2027-05-13', $this->manager);
        $this->assertSame('1 / 2', $rows[0]['mandatory_coverage']);
        $this->assertSame('Pflichtnachweis fehlt', $rows[0]['requirement_state']);
        $this->assertSame('Strecke, Nachwuchs', $rows[0]['training_need']);
        Livewire::actingAs($this->manager)->test(PlanningEnhancements::class)->call('setTab', 'bundles')->call('edit', 'bundle-coverage', $bundle->id)->call('prepare')->assertSee('Schulungsbedarf')->assertSee('Pflichtnachweis fehlt');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_rotation_template_and_revision_are_always_server_sourced(): void
    {
        $source = $this->shift();
        $data = ['name' => 'Vorlagenprüfung', 'anchor' => '2027-05-13', 'cycle_days' => 7, 'timezone' => 'Europe/Berlin', 'slots' => [['day' => 0, 'shift_id' => $source->id, 'team_id' => null, 'offset' => 0, 'source_revision' => 999, 'template' => ['title' => 'Injected', 'start_time' => '10:00', 'qualification_ids' => []]]], 'exceptions' => []];
        $cycle = app(RotationPlanningService::class)->save(null, null, $data, $this->manager);
        $this->assertSame($source->title, $cycle->slots[0]['template']['title']);
        $this->assertSame($source->revision, $cycle->slots[0]['source_revision']);
        $updated = $cycle->only(['name', 'anchor', 'cycle_days', 'timezone', 'slots', 'exceptions']);
        $updated['slots'][0]['template']['title'] = 'Wrong retained snapshot';
        $next = $this->shift(['title' => 'Neue Vorlage', 'starts_at' => '2027-05-13T09:00', 'ends_at' => '2027-05-13T17:00']);
        $updated['slots'][0]['shift_id'] = $next->id;
        $cycle = app(RotationPlanningService::class)->save($cycle->id, $cycle->revision, $updated, $this->manager);
        $this->assertSame('Neue Vorlage', $cycle->slots[0]['template']['title']);
        $this->assertSame('09:00', $cycle->slots[0]['template']['start_time']);
    }

    public function test_partial_new_tables_do_not_crash_existing_central_eligibility(): void
    {
        Schema::drop('shift_bundle_snapshots');
        Schema::create('shift_bundle_snapshots', fn (Blueprint $t) => $t->id());
        $this->assertFalse(PlanningEnhancementSchema::ready());
        $s = $this->shift();
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($s, collect([$this->anna]))[$this->anna->id]);
    }
}
