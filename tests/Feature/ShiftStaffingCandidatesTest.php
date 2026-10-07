<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\EmployeeRuleAssignment;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftDependency;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Services\Operations\ShiftStaffingCandidates;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Services\Operations\TimelinePlanningSuggestionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ShiftStaffingCandidatesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Shift $shift;

    private ShiftStaffingCandidates $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        Schema::create('workforce_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('workforce_pool_user', function (Blueprint $table) {
            $table->foreignId('workforce_pool_id');
            $table->foreignId('user_id');
            $table->primary(['workforce_pool_id', 'user_id']);
        });
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Testprofil', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Testkunde', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Testleistung', 'service_type' => 'Wagenmeister', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-01T00:00', 'ends_at' => '2027-05-31T00:00', 'required_staff' => 2, 'created_by' => $this->admin->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'Testdienst', 'role_name' => 'Wagenmeister', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 2, 'planned_break_minutes' => 30, 'status' => 'open', 'location_name' => 'Bremen', 'created_by' => $this->admin->id]);
        $this->service = app(ShiftStaffingCandidates::class);
    }

    private function employee(string $name, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['name' => $name, 'role' => 'staff', 'status' => true], $attributes));
    }

    private function qualification(User $user, QualificationType $type, array $attributes = []): EmployeeQualification
    {
        return EmployeeQualification::create(array_merge(['user_id' => $user->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31', 'status' => 'approved'], $attributes));
    }

    private function regions(array $states): void
    {
        $service = Mockery::mock(StaffRegionalPreferenceService::class);
        $service->shouldReceive('assessMany')->andReturnUsing(fn (Shift $shift, Collection $users) => $users->mapWithKeys(function (User $user) use ($states) {
            $state = $states[$user->id] ?? 'neutral';

            return [$user->id => [
                'state' => $state, 'label' => $state, 'detail' => 'Region: '.$state,
                'score_adjustment' => match ($state) {
                    'preferred' => 15, 'border' => 5, 'outside' => -10, 'no_go' => -100, default => 0,
                },
                'blocked' => $state === 'no_go',
            ]];
        })->all());
        $this->app->instance(StaffRegionalPreferenceService::class, $service);
    }

    private function assign(User $user, ?Shift $shift = null, string $status = 'confirmed'): void
    {
        ShiftAssignment::create(['shift_id' => ($shift ?? $this->shift)->id, 'user_id' => $user->id, 'status' => $status, 'assigned_by' => $this->admin->id]);
    }

    public function test_ranking_is_global_before_the_visible_slice_and_never_writes_assignments(): void
    {
        $type = QualificationType::create(['name' => 'Zwingender Nachweis', 'is_active' => true]);
        $this->shift->qualifications()->attach($type);
        for ($i = 1; $i <= 15; $i++) {
            $this->employee(sprintf('A Testperson %02d', $i));
        }
        $best = $this->employee('Z Beste Person');
        $this->qualification($best, $type);
        $this->regions([$best->id => 'preferred']);
        $before = DB::table('operation_audits')->count();
        $ranked = $this->service->ranked($this->shift);
        $this->assertCount(16, $ranked);
        $this->assertSame($best->id, $ranked->take(12)->first()->id);
        $this->assertSame(100, $ranked->first()->staffing_score);
        $this->assertSame('eligible', $ranked->first()->staffing_state);
        $this->assertSame('blocked', $ranked->last()->staffing_state);
        $this->assertCount(16, $ranked->pluck('id')->unique());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame($before, DB::table('operation_audits')->count());
    }

    public function test_eligible_review_and_blocked_tiers_dominate_regional_preferences(): void
    {
        $eligible = $this->employee('Z Geeignet');
        $review = $this->employee('A Zeitkonflikt');
        $blocked = $this->employee('B Nachweis fehlt');
        $type = QualificationType::create(['name' => 'Nachweis', 'is_active' => true]);
        $this->shift->qualifications()->attach($type);
        $this->qualification($eligible, $type);
        $this->qualification($review, $type);
        $other = $this->shift->replicate(['public_id']);
        $other->save();
        $this->assign($review, $other);
        $this->regions([$eligible->id => 'outside', $review->id => 'preferred', $blocked->id => 'preferred']);
        $ranked = $this->service->ranked($this->shift);
        $this->assertSame([$eligible->id, $review->id, $blocked->id], $ranked->pluck('id')->all());
        $this->assertSame(['eligible', 'review', 'blocked'], $ranked->pluck('staffing_state')->all());
        $this->assertSame([75, 74, 39], $ranked->pluck('staffing_score')->all());
        $this->assertSame('rest_overlap', $ranked[1]->planning_issues[0]['code']);
        $this->assertContains('Region: preferred', $ranked[1]->staffing_reasons);
    }

    public function test_optional_missing_or_unknown_regions_do_not_reduce_score_but_no_go_blocks(): void
    {
        $neutral = $this->employee('A Ohne Vorgabe');
        $unknown = $this->employee('B Unbekannter Ort');
        $noGo = $this->employee('C No-Go');
        $this->regions([$unknown->id => 'unknown', $noGo->id => 'no_go']);
        $ranked = $this->service->ranked($this->shift)->keyBy('id');
        $this->assertSame(85, $ranked[$neutral->id]->staffing_score);
        $this->assertSame(85, $ranked[$unknown->id]->staffing_score);
        $this->assertSame('eligible', $ranked[$unknown->id]->staffing_state);
        $this->assertSame(0, $ranked[$noGo->id]->staffing_score);
        $this->assertSame('blocked', $ranked[$noGo->id]->staffing_state);
        $this->assertSame('region_no_go', $ranked[$noGo->id]->planning_issues[0]['code']);
    }

    public function test_only_active_unreserved_staff_are_returned_and_declined_or_cancelled_may_reappear(): void
    {
        $confirmed = $this->employee('A Bestätigt');
        $requested = $this->employee('B Angefragt');
        $declined = $this->employee('C Abgelehnt');
        $cancelled = $this->employee('D Storniert');
        $this->employee('E Inaktiv', ['status' => false]);
        $this->employee('F Gast', ['role' => 'guest']);
        $this->assign($confirmed);
        $this->assign($requested, status: 'requested');
        $this->assign($declined, status: 'declined');
        $this->assign($cancelled, status: 'cancelled');
        $this->regions([]);
        $this->assertSame([$declined->id, $cancelled->id], $this->service->ranked($this->shift)->pluck('id')->all());
    }

    public function test_qualification_filter_requires_approved_active_type_and_entire_local_shift_validity(): void
    {
        $this->shift->update(['starts_at' => '2027-05-13T22:00', 'ends_at' => '2027-05-14T06:00']);
        $type = QualificationType::create(['name' => 'Nachtqualifikation', 'is_active' => true]);
        $valid = $this->employee('A Gültig');
        $earlyEnd = $this->employee('B Vor Ende abgelaufen');
        $lateStart = $this->employee('C Zu später Beginn');
        $pending = $this->employee('D Ausstehend');
        $revoked = $this->employee('E Widerrufen');
        $this->qualification($valid, $type, ['valid_from' => '2027-05-13', 'valid_until' => '2027-05-14']);
        $this->qualification($earlyEnd, $type, ['valid_until' => '2027-05-13']);
        $this->qualification($lateStart, $type, ['valid_from' => '2027-05-14']);
        $this->qualification($pending, $type, ['status' => 'pending']);
        $this->qualification($revoked, $type, ['status' => 'revoked']);
        $this->regions([]);
        $this->assertSame([$valid->id], $this->service->ranked($this->shift, ['qualification_id' => (string) $type->id])->pluck('id')->all());
        $type->update(['is_active' => false]);
        $this->assertCount(0, $this->service->ranked($this->shift, ['qualification_id' => $type->id]));
    }

    public function test_search_pool_suitability_and_region_filters_combine_without_reordering(): void
    {
        $anna = $this->employee('Anna Nord');
        $ben = $this->employee('Ben Nord');
        $pool = WorkforcePool::create(['name' => 'Nord', 'is_active' => true]);
        $pool->users()->attach([$anna->id, $ben->id]);
        $this->regions([$anna->id => 'border', $ben->id => 'preferred']);
        $filters = ['search' => 'Anna', 'pool_id' => $pool->id, 'suitability' => 'eligible', 'region' => 'border'];
        $this->assertSame([$anna->id], $this->service->ranked($this->shift, $filters)->pluck('id')->all());
        $this->assertCount(0, $this->service->ranked($this->shift, ['suitability' => 'made_up']));
        $this->assertCount(0, $this->service->ranked($this->shift, ['qualification_id' => 99999]));
        $this->assertCount(0, $this->service->ranked($this->shift, ['pool_id' => 99999]));
        $pool->update(['is_active' => false]);
        $this->assertCount(0, $this->service->ranked($this->shift, ['pool_id' => $pool->id]));
    }

    public function test_filter_options_use_existing_current_qualifications_and_active_populated_pools(): void
    {
        $employee = $this->employee('A Person');
        $inactive = $this->employee('B Inaktiv', ['status' => false]);
        $type = QualificationType::create(['name' => 'Verfügbar', 'is_active' => true]);
        $expired = QualificationType::create(['name' => 'Abgelaufen', 'is_active' => true]);
        $disabled = QualificationType::create(['name' => 'Deaktiviert', 'is_active' => false]);
        $inactiveOnly = QualificationType::create(['name' => 'Nur inaktive Mitarbeiter', 'is_active' => true]);
        $this->qualification($employee, $type);
        $this->qualification($employee, $expired, ['valid_until' => '2027-05-12']);
        $this->qualification($employee, $disabled);
        $this->qualification($inactive, $inactiveOnly);
        $pool = WorkforcePool::create(['name' => 'Verfügbarer Pool', 'is_active' => true]);
        $pool->users()->attach($employee);
        WorkforcePool::create(['name' => 'Leer', 'is_active' => true]);
        $disabledPool = WorkforcePool::create(['name' => 'Deaktiviert', 'is_active' => false]);
        $disabledPool->users()->attach($employee);
        $inactivePool = WorkforcePool::create(['name' => 'Nur inaktiv', 'is_active' => true]);
        $inactivePool->users()->attach($inactive);
        $options = $this->service->filterOptions($this->shift);
        $this->assertSame([$type->id], array_column($options['qualifications'], 'id'));
        $this->assertSame([$pool->id], array_column($options['pools'], 'id'));
        Schema::drop('workforce_pool_user');
        $this->assertSame([], $this->service->filterOptions($this->shift)['pools']);
        $this->assertCount(0, $this->service->ranked($this->shift, ['pool_id' => $pool->id]));
    }

    public function test_equal_scores_have_stable_name_id_order_and_new_data_is_not_cached(): void
    {
        $first = $this->employee('Gleicher Name');
        $second = $this->employee('Gleicher Name');
        $type = QualificationType::create(['name' => 'Nachweis', 'is_active' => true]);
        $this->shift->qualifications()->attach($type);
        $certificate = $this->qualification($first, $type);
        $this->qualification($second, $type);
        $this->regions([]);
        $this->assertSame([$first->id, $second->id], $this->service->ranked($this->shift)->pluck('id')->all());
        $certificate->update(['status' => 'revoked']);
        $ranked = $this->service->ranked($this->shift);
        $this->assertSame([$second->id, $first->id], $ranked->pluck('id')->all());
        $this->assertSame('blocked', $ranked->last()->staffing_state);
        $type->update(['is_active' => false]);
        $this->assertSame(['blocked'], $this->service->ranked($this->shift)->pluck('staffing_state')->unique()->values()->all());
    }

    public function test_missing_regional_table_is_optional_and_real_assessment_remains_neutral(): void
    {
        $employee = $this->employee('Ohne Regionalvorgabe');
        $candidate = $this->service->ranked($this->shift)->first();
        $this->assertSame($employee->id, $candidate->id);
        $this->assertSame('neutral', $candidate->staffing_region['state']);
        $this->assertSame(85, $candidate->staffing_score);
        $this->assertFalse($candidate->relationLoaded('profile'));
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($this->shift, collect([$employee]))[$employee->id]);
    }

    public function test_saved_no_go_is_also_a_central_guard_and_never_appears_as_a_timeline_suggestion(): void
    {
        (require database_path('migrations/2026_10_06_220000_create_staff_regional_preferences_table.php'))->up();
        $employee = $this->employee('Regional begrenzt');
        $this->shift->update(['location_name' => 'München']);
        $service = app(StaffRegionalPreferenceService::class);
        $config = ['enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => [['location' => 'München', 'radius_km' => 10]]];
        $service->save($employee, $this->admin, $config, 0);
        $eligibility = app(StaffEligibilityService::class);
        $issues = $eligibility->assessMany($this->shift, collect([$employee]))[$employee->id];
        $this->assertSame(['region_no_go'], array_column($issues, 'code'));
        $this->assertCount(0, app(TimelinePlanningSuggestionService::class)->rankedCandidates($this->shift, $this->admin));
        $this->assertCount(1, $this->service->ranked($this->shift)->first()->planning_issues);
        $this->assertSame('blocked', $this->service->ranked($this->shift)->first()->staffing_state);
        $config['enabled'] = false;
        $service->save($employee, $this->admin, $config, 1);
        $this->assertSame([], $eligibility->assessMany($this->shift, collect([$employee]))[$employee->id]);
    }

    public function test_sixty_candidates_use_one_central_assessment_and_do_not_load_personal_profiles(): void
    {
        $this->measureCandidates('minimal schema');
    }

    public function test_sixty_candidates_with_optional_workforce_modules_still_use_the_full_population(): void
    {
        $this->optionalModules();
        $this->measureCandidates('optional workforce modules');
    }

    private function optionalModules(): void
    {
        foreach ([
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_100000_create_planning_enhancements.php',
            '2026_10_06_101000_create_personnel_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php',
            '2026_10_06_220000_create_staff_regional_preferences_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function training(User $user, string $start, string $end, string $title = 'Testschulung', string $status = 'confirmed'): PersonnelTraining
    {
        $training = PersonnelTraining::create(['title' => $title, 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'capacity' => 1, 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $user->id, 'status' => $status, 'created_by' => $this->admin->id]);

        return $training;
    }

    public function test_training_batch_matches_single_and_locked_checks_without_cross_user_leakage(): void
    {
        $this->optionalModules();
        $before = $this->employee('A Schulung davor');
        $after = $this->employee('B Schulung danach');
        $overlap = $this->employee('C Schulung gleichzeitig');
        $unaffected = $this->employee('D Keine Schulung');
        $users = collect([$before, $after, $overlap, $unaffected]);
        $this->training($before, '2027-05-13T05:00', '2027-05-13T07:00', 'Vorher');
        // Gap is 11 hours. A newly effective 12-hour rule on the training date
        // must still be read, not replaced by the shift date's 10-hour profile.
        $longer = OperationsRuleProfile::create(['name' => 'Folgetag', 'minimum_rest_minutes' => 720, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()->utc()]);
        EmployeeRuleAssignment::create(['user_id' => $after->id, 'operations_rule_profile_id' => $longer->id, 'starts_on' => '2027-05-14', 'created_by' => $this->admin->id]);
        $this->training($after, '2027-05-14T03:00', '2027-05-14T04:00', 'Nachher', 'attended');
        $this->training($overlap, '2027-05-13T12:00', '2027-05-13T13:00', 'Parallel');
        $this->training($unaffected, '2027-05-13T12:00', '2027-05-13T13:00', 'Abgemeldet', 'cancelled');
        $this->training($unaffected, '2027-05-16T12:00', '2027-05-16T13:00', 'Später');
        $service = app(StaffEligibilityService::class);
        $batch = $service->assessMany($this->shift, $users);
        $this->assertSame(['training_rest_before'], array_column($batch[$before->id], 'code'));
        $this->assertSame(['training_rest_after'], array_column($batch[$after->id], 'code'));
        $this->assertStringContainsString('720 min', $batch[$after->id][0]['message']);
        $this->assertSame(['training_overlap'], array_column($batch[$overlap->id], 'code'));
        $this->assertSame([], $batch[$unaffected->id]);
        foreach ($users as $user) {
            $this->assertSame($batch[$user->id], $service->assessMany($this->shift, collect([$user]))[$user->id]);
        }
        $this->assertSame($batch, DB::transaction(fn () => $service->assessMany($this->shift, $users, true)));
    }

    public function test_training_exclusions_and_later_changes_are_rechecked_without_a_cached_snapshot(): void
    {
        $this->optionalModules();
        $employee = $this->employee('Testperson');
        $training = $this->training($employee, '2027-05-13T12:00', '2027-05-13T13:00');
        $service = app(StaffEligibilityService::class);
        $users = collect([$employee]);
        $this->assertSame(['training_overlap'], array_column($service->assessMany($this->shift, $users)[$employee->id], 'code'));
        $this->assertSame([], $service->assessMany($this->shift, $users, false, ['exclude_training_ids' => [$training->id]])[$employee->id]);
        $training->update(['status' => 'cancelled']);
        $this->assertSame([], $service->assessMany($this->shift, $users)[$employee->id]);
        $this->training($employee, '2027-05-13T18:00', '2027-05-13T19:00');
        $this->assertSame(['training_rest_after'], array_column($service->assessMany($this->shift, $users)[$employee->id], 'code'));
    }

    public function test_missing_rule_profile_does_not_hide_training_overlap_or_other_hard_guards(): void
    {
        $this->optionalModules();
        $employee = $this->employee('Testperson');
        $this->training($employee, '2027-05-13T12:00', '2027-05-13T13:00');
        OperationsRuleProfile::query()->update(['is_active' => false]);
        $type = QualificationType::create(['name' => 'Pflichtnachweis', 'is_active' => true]);
        $this->shift->qualifications()->attach($type);
        $issues = app(StaffEligibilityService::class)->assessMany($this->shift, collect([$employee]))[$employee->id];
        $this->assertSame(['rules_missing', 'qualification_'.$type->id, 'training_overlap'], array_column($issues, 'code'));
        Schema::table('operations_rate_rules', fn (Blueprint $table) => $table->dropColumn('configuration'));
        $issues = app(StaffEligibilityService::class)->assessMany($this->shift, collect([$employee]))[$employee->id];
        $this->assertContains('additional_rules_incomplete', array_column($issues, 'code'));
    }

    public function test_dependency_assignment_remains_employee_specific_in_a_batch(): void
    {
        $this->optionalModules();
        $assigned = $this->employee('A Vorgänger zugewiesen');
        $missing = $this->employee('B Vorgänger fehlt');
        $previous = $this->shift->replicate(['public_id']);
        $previous->fill(['starts_at' => '2027-05-11T08:00', 'ends_at' => '2027-05-11T16:00'])->save();
        $this->assign($assigned, $previous);
        ShiftDependency::create(['predecessor_id' => $previous->id, 'successor_id' => $this->shift->id, 'same_employee' => true, 'transfer_minutes' => 0, 'handover_location' => 'Bremen', 'created_by' => $this->admin->id]);
        $service = app(StaffEligibilityService::class);
        $users = collect([$assigned, $missing]);
        $batch = $service->assessMany($this->shift, $users);
        $this->assertSame([], $batch[$assigned->id]);
        $this->assertSame(['dependency_person'], array_column($batch[$missing->id], 'code'));
        foreach ($users as $user) {
            $this->assertSame($batch[$user->id], $service->assessMany($this->shift, collect([$user]))[$user->id]);
        }
        $this->assertSame([], $service->assessMany($this->shift, collect([$missing]), false, ['additional_shifts' => [$previous]])[$missing->id]);
    }

    private function measureCandidates(string $schemaLabel): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->employee(sprintf('Testperson %02d', $i));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = microtime(true);
        $candidates = $this->service->ranked($this->shift);
        $elapsed = microtime(true) - $started;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(60, $candidates);
        $this->assertFalse($candidates->contains(fn (User $user) => $user->relationLoaded('profile')));
        $this->assertCount(0, array_filter($queries, fn (array $query) => str_contains($query['query'], 'from "user_profiles"')));
        // Existing optional rule services account for most of this baseline.
        // Catch growth while keeping the next batch optimization measurable.
        $this->assertLessThanOrEqual($schemaLabel === 'minimal schema' ? 500 : 1250, count($queries));
        // Keep the measured central-service cost visible without a hardware-sensitive time assertion.
        fwrite(STDERR, sprintf("\nStaffing candidates (60, %s): %d queries, %.3fs\n", $schemaLabel, count($queries), $elapsed));
        if (getenv('STAFFING_QUERY_AUDIT')) {
            $counts = array_count_values(array_column($queries, 'query'));
            arsort($counts);
            foreach (array_slice($counts, 0, 15, true) as $sql => $count) {
                fwrite(STDERR, $count.' × '.mb_substr($sql, 0, 500)."\n");
            }
        }
    }
}
