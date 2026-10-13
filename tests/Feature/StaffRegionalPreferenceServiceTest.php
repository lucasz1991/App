<?php

namespace Tests\Feature;

use App\Models\OperationAudit;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StaffRegionalPreference;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Support\Operations\TimelineLocationPreview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use UnexpectedValueException;

class StaffRegionalPreferenceServiceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private StaffRegionalPreferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_06_220000_create_staff_regional_preferences_table.php'))->up();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->service = app(StaffRegionalPreferenceService::class);
    }

    private function config(array $extra = []): array
    {
        return array_replace(['enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50, 'border_radius_km' => 200, 'no_go_areas' => []], $extra);
    }

    private function assessment(string $place): array
    {
        return $this->service->assessMany(new Shift(['location_name' => $place]), collect([$this->employee]))[$this->employee->id];
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_missing_preferences_are_neutral_and_never_inferred_from_profile_addresses(): void
    {
        $this->employee->profile()->create(['city' => 'München', 'country' => 'DE']);
        $this->assertSame(0, $this->service->get($this->employee)['revision']);
        $this->assertSame('neutral', $this->assessment('Hamburg')['state']);
        $this->assertSame(0, $this->assessment('Hamburg')['score_adjustment']);
        $this->assertSame(0, StaffRegionalPreference::count());
    }

    public function test_saved_preferences_use_canonical_explicit_city_and_versioned_audit(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config(['base_location' => 'Muenchen-Milbertshofen']), 0);
        $this->assertSame(1, $saved['revision']);
        $this->assertSame('München', $saved['base_location']);
        $this->assertSame($saved, $this->service->get($this->employee));
        $audit = OperationAudit::where('action', 'staff.regional_preferences.saved')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(0, $audit->data['before']['revision']);
        $this->assertSame(1, $audit->data['after']['revision']);
        $this->assertSame($this->employee->id, $audit->data['user_id']);
    }

    public function test_preferred_border_and_outside_use_approximate_distance_not_travel_time(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(), 0);
        $this->assertSame('preferred', $this->assessment('München Nord')['state']);
        $border = $this->assessment('Nürnberg');
        $this->assertSame('border', $border['state']);
        $this->assertGreaterThan(100, $border['distance_km']);
        $this->assertLessThan(200, $border['distance_km']);
        $this->assertSame('outside', $this->assessment('Hamburg')['state']);
        $this->assertFalse($this->assessment('Hamburg')['blocked']);
        $this->assertStringContainsString('keine Fahrzeit', $border['detail']);
    }

    public function test_no_go_has_precedence_even_inside_preferred_area_and_cannot_be_time_overridden(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => [['location' => 'München', 'radius_km' => 5]]]), 0);
        $this->assertSame('no_go', $this->assessment('München Milbertshofen')['state']);
        $this->assertTrue($this->assessment('München')['blocked']);
        $this->invalid(fn () => $this->service->assertAllowed(new Shift(['location_name' => 'München']), $this->employee, true), 'workflow');
        $this->service->assertAllowed(new Shift(['location_name' => 'Hamburg']), $this->employee);
    }

    public function test_new_map_only_fallbacks_do_not_change_regional_ranking_or_no_go_blocks(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => [['location' => 'München', 'radius_km' => 100]]]), 0);
        foreach (['München-Pasing', 'Bahnhof München', 'Ingolstadt Hbf', '86641 Rain'] as $name) {
            $shift = new Shift(['location_name' => $name]);
            $this->assertSame('located', TimelineLocationPreview::fromShift($shift)['state']);
            $assessment = $this->service->assessMany($shift, collect([$this->employee]))[$this->employee->id];
            $this->assertSame('unknown', $assessment['state']);
            $this->assertSame(0, $assessment['score_adjustment']);
            $this->assertFalse($assessment['blocked']);
            $this->assertNull($assessment['distance_km']);
        }
        $shift = new Shift;
        $shift->setRelation('order', new Order(['city' => 'Rain', 'postal_code' => '86641', 'country' => 'DE']));
        $this->assertSame('located', TimelineLocationPreview::fromShift($shift)['state']);
        $this->assertSame('unknown', $this->service->assessMany($shift, collect([$this->employee]))[$this->employee->id]['state']);
        $this->assertTrue($this->assessment('München')['blocked']);
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['base_location' => 'München-Pasing']), 1), 'regional_preferences.base_location');
        $this->assertSame(1, StaffRegionalPreference::where('user_id', $this->employee->id)->sole()->revision);
    }

    public function test_duplicate_city_alias_exclusions_retain_the_larger_radius(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => [['location' => 'München', 'radius_km' => 5], ['location' => 'Munich', 'radius_km' => 15]]]), 0);
        $this->assertSame([['location' => 'München', 'radius_km' => 15]], $saved['no_go_areas']);
    }

    public function test_unknown_and_ambiguous_shift_locations_never_invent_a_match(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => [['location' => 'München', 'radius_km' => 5]]]), 0);
        foreach (['Neustadt', 'Unbekanntes Depot', ''] as $place) {
            $assessment = $this->assessment($place);
            $this->assertSame('unknown', $assessment['state']);
            $this->assertNull($assessment['distance_km']);
            $this->assertSame(0, $assessment['score_adjustment']);
            $this->assertFalse($assessment['location_known']);
            $this->assertStringContainsString('manuell prüfen', $assessment['detail']);
        }
    }

    public function test_foreign_order_and_unrelated_shift_override_do_not_borrow_a_german_city(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(), 0);
        $shift = new Shift(['location_name' => '']);
        $shift->setRelation('order', new Order(['city' => 'München', 'country' => 'AT']));
        $this->assertSame('unknown', $this->service->assessMany($shift, collect([$this->employee]))[$this->employee->id]['state']);
        $shift->location_name = 'Unbekanntes Depot';
        $shift->setRelation('order', new Order(['city' => 'München', 'country' => 'DE', 'location_name' => 'München']));
        $this->assertSame('unknown', $this->service->assessMany($shift, collect([$this->employee]))[$this->employee->id]['state']);
    }

    public function test_disabled_preference_retains_config_but_removes_all_effects(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => [['location' => 'München', 'radius_km' => 5]]]), 0);
        unset($saved['revision']);
        $saved['enabled'] = false;
        $this->service->save($this->employee, $this->admin, $saved, 1);
        $this->assertSame('neutral', $this->assessment('München')['state']);
        $this->assertSame(0, $this->assessment('München')['score_adjustment']);
        $this->assertSame(2, $this->service->get($this->employee)['revision']);
        $this->service->assertAllowed(new Shift(['location_name' => 'München']), $this->employee);
    }

    public function test_stale_revision_rejects_write_and_does_not_append_audit(): void
    {
        $first = $this->service->save($this->employee, $this->admin, $this->config(), 0);
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['base_location' => 'Hamburg']), 0), 'regional_preferences');
        $this->assertSame($first, $this->service->get($this->employee));
        $this->assertSame(1, OperationAudit::where('action', 'staff.regional_preferences.saved')->count());
    }

    public function test_locations_radii_and_client_supplied_coordinates_are_validated(): void
    {
        foreach (['Neustadt', 'Narnia', ''] as $place) {
            $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['base_location' => $place]), 0), 'regional_preferences.base_location');
        }
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['border_radius_km' => 10]), 0), 'regional_preferences.border_radius_km');
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => [['location' => 'Neustadt', 'radius_km' => 10]]]), 0), 'regional_preferences.no_go_areas.0.location');
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['latitude' => 48.1]), 0), 'regional_preferences');
        $this->assertSame(0, StaffRegionalPreference::count());
    }

    public function test_inactive_stale_admin_object_cannot_save(): void
    {
        User::whereKey($this->admin->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        $this->service->save($this->employee, $this->admin, $this->config(), 0);
    }

    public function test_operations_permission_without_management_audience_is_insufficient(): void
    {
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Mitarbeiter', 'personal_team' => false, 'rbac_permissions' => ['operations.manage' => true]]);
        $actor = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $team->id]);
        $this->expectException(HttpException::class);
        $this->service->save($this->employee, $actor, $this->config(), 0);
    }

    public function test_active_management_with_explicit_permission_may_save(): void
    {
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => ['operations.manage' => true]]);
        $actor = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $team->id]);
        $this->assertSame(1, $this->service->save($this->employee, $actor, $this->config(), 0)['revision']);
    }

    public function test_management_role_does_not_replace_the_required_permission(): void
    {
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => []]);
        $actor = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $team->id]);
        $this->expectException(AuthorizationException::class);
        $this->service->save($this->employee, $actor, $this->config(), 0);
    }

    public function test_employee_status_is_rechecked_even_when_a_stale_active_model_is_supplied(): void
    {
        User::whereKey($this->employee->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        $this->service->save($this->employee, $this->admin, $this->config(), 0);
    }

    public function test_disabled_blank_defaults_can_be_saved_without_inventing_an_area(): void
    {
        $config = $this->service->get($this->employee);
        unset($config['revision']);
        $saved = $this->service->save($this->employee, $this->admin, $config, 0);
        $this->assertSame('', $saved['base_location']);
        $this->assertFalse($saved['enabled']);
        $this->assertSame('neutral', $this->assessment('München')['state']);
    }

    public function test_invalid_persisted_no_go_is_never_silently_ignored(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(), 0);
        $record = StaffRegionalPreference::sole();
        $config = $record->preferences;
        $config['no_go_areas'] = [['location' => 'Unbekanntes Depot', 'radius_km' => 5]];
        $record->update(['preferences' => $config]);
        $this->expectException(UnexpectedValueException::class);
        $this->assessment('München');
    }

    public function test_preference_query_is_batched_and_does_not_scale_with_employee_count(): void
    {
        $users = User::factory()->count(8)->create(['role' => 'staff', 'status' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $results = $this->service->assessMany(new Shift(['location_name' => 'München']), $users);
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "staff_regional_preferences"'));
        DB::disableQueryLog();
        $this->assertCount(8, $results);
        $this->assertCount(1, $queries);
    }

    public function test_optional_schema_absence_is_neutral_but_corrupt_saved_data_fails_closed(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(), 0);
        StaffRegionalPreference::query()->update(['preferences' => json_encode(['schema_version' => 2])]);
        try {
            $this->assessment('München');
            $this->fail('Corrupt data must not be ignored.');
        } catch (UnexpectedValueException) {
            $this->assertTrue(true);
        }
        (require database_path('migrations/2026_10_06_220000_create_staff_regional_preferences_table.php'))->down();
        $this->assertFalse(Schema::hasTable('staff_regional_preferences'));
        $this->assertSame('neutral', $this->assessment('München')['state']);
        $this->assertFalse($this->service->get($this->employee)['enabled']);
    }

    public function test_state_only_zones_score_all_three_tiers_and_outside_without_a_private_address(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config([
            'base_location' => '',
            'federal_states' => ['preferred' => ['DE-HH'], 'possible' => ['DE-NI'], 'emergency' => ['DE-BE']],
        ]), 0);

        $this->assertSame(2, $saved['schema_version']);
        $this->assertSame('', $saved['base_location']);
        foreach (['Hamburg' => ['preferred', 15], 'Braunschweig' => ['border', 5], 'Berlin' => ['emergency', -10], 'München' => ['outside', -20]] as $place => [$state, $adjustment]) {
            $assessment = $this->assessment($place);
            $this->assertSame($state, $assessment['state'], $place);
            $this->assertSame($adjustment, $assessment['score_adjustment']);
            $this->assertNull($assessment['distance_km']);
            $this->assertFalse($assessment['blocked']);
        }
    }

    public function test_emergency_radius_is_lower_priority_than_possible_and_state_radius_union_uses_best_tier(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config([
            'base_location' => 'Hamburg', 'preferred_radius_km' => 10, 'border_radius_km' => 30, 'emergency_radius_km' => 120,
            'federal_states' => ['preferred' => ['DE-BY'], 'possible' => [], 'emergency' => ['DE-HH']],
        ]), 0);
        $this->assertSame('preferred', $this->assessment('Hamburg')['state']);
        $this->assertSame('preferred', $this->assessment('München')['state']);
        $this->assertSame('emergency', $this->assessment('Lübeck')['state']);
        $this->assertSame(-10, $this->assessment('Lübeck')['score_adjustment']);
        $this->assertSame('outside', $this->assessment('Berlin')['state']);
        $this->assertSame(-20, $this->assessment('Berlin')['score_adjustment']);
        $this->assertSame($saved, $this->service->get($this->employee));
    }

    public function test_unknown_states_duplicate_tiers_and_invalid_emergency_radii_cannot_be_saved(): void
    {
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['federal_states' => ['preferred' => ['DE-XX']]]), 0), 'regional_preferences.federal_states.preferred.0');
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['federal_states' => ['preferred' => ['DE-HH'], 'emergency' => ['DE-HH']]]), 0), 'regional_preferences.federal_states.emergency');
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['emergency_radius_km' => 100]), 0), 'regional_preferences.emergency_radius_km');
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['emergency_radius_km' => 2001]), 0), 'regional_preferences.emergency_radius_km');
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['base_location' => '', 'emergency_radius_km' => 500, 'federal_states' => ['preferred' => ['DE-HH']]]), 0), 'regional_preferences.base_location');
        $this->assertSame(0, StaffRegionalPreference::count());
    }

    public function test_no_go_still_blocks_all_new_tiers_and_client_cannot_disable_configured_v2_zones(): void
    {
        $config = $this->config([
            'base_location' => '', 'federal_states' => ['preferred' => ['DE-BY'], 'possible' => [], 'emergency' => ['DE-HH']],
            'no_go_areas' => [['location' => 'München', 'radius_km' => 10], ['location' => 'Hamburg', 'radius_km' => 10]],
        ]);
        $this->service->save($this->employee, $this->admin, $config, 0);
        foreach (['München', 'Hamburg'] as $place) {
            $this->assertTrue($this->assessment($place)['blocked']);
            $this->invalid(fn () => $this->service->assertAllowed(new Shift(['location_name' => $place]), $this->employee), 'workflow');
        }
        $config['enabled'] = false;
        $saved = $this->service->save($this->employee, $this->admin, $config, 1);
        $this->assertTrue($saved['enabled']);
        $this->assertSame('no_go', $this->assessment('Hamburg')['state']);
    }

    public function test_legacy_roundtrip_keeps_original_outside_score_until_explicit_upgrade(): void
    {
        StaffRegionalPreference::create(['user_id' => $this->employee->id, 'revision' => 1, 'preferences' => ['schema_version' => 1] + $this->config(), 'updated_by' => $this->admin->id]);
        $saved = $this->service->get($this->employee);
        $this->assertSame(1, $saved['schema_version']);
        unset($saved['revision']);
        $roundtrip = $this->service->save($this->employee, $this->admin, $saved, 1);
        $this->assertSame(1, $roundtrip['schema_version']);
        $this->assertSame(-10, $this->assessment('Hamburg')['score_adjustment']);
        $saved['schema_version'] = 2;
        $this->service->save($this->employee, $this->admin, $saved, 2);
        $this->assertSame(-20, $this->assessment('Hamburg')['score_adjustment']);
    }

    public function test_v2_uses_own_confirmed_site_and_does_not_guess_from_unknown_or_malformed_locations(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config([
            'base_location' => '', 'federal_states' => ['preferred' => ['DE-HH']],
        ]), 0);
        $shift = new Shift(['location_name' => 'Unbekanntes Depot', 'location_snapshot' => ['confirmed' => true, 'name' => 'Depot', 'latitude' => 53.55, 'longitude' => 10.0]]);
        $this->assertSame('preferred', $this->service->assessMany($shift, collect([$this->employee]))[$this->employee->id]['state']);
        $shift->location_snapshot = ['confirmed' => true, 'name' => 'Depot', 'latitude' => 'invalid', 'longitude' => 10.0];
        $this->assertSame('unknown', $this->service->assessMany($shift, collect([$this->employee]))[$this->employee->id]['state']);
        $this->assertSame('unknown', $this->assessment('Neustadt')['state']);
        $this->assertSame('unknown', $this->assessment('Unbekanntes Depot')['state']);
    }

    public function test_stored_conflicting_state_tiers_fail_closed_instead_of_dropping_a_zone(): void
    {
        $this->service->save($this->employee, $this->admin, $this->config(['federal_states' => ['preferred' => ['DE-HH']]]), 0);
        $record = StaffRegionalPreference::sole();
        $config = $record->preferences;
        $config['federal_states']['emergency'] = ['DE-HH'];
        $record->update(['preferences' => $config]);
        $this->expectException(UnexpectedValueException::class);
        $this->assessment('Hamburg');
    }

    public function test_clearing_optional_emergency_radius_stores_null_and_malformed_arrays_are_validation_errors(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config(['schema_version' => 2, 'emergency_radius_km' => '']), 0);
        $this->assertNull($saved['emergency_radius_km']);
        foreach (['', null, 'invalid'] as $value) {
            $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['federal_states' => $value]), 1), 'regional_preferences.federal_states');
            $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['no_go_areas' => $value]), 1), 'regional_preferences.no_go_areas');
            $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['federal_states' => ['preferred' => $value]]), 1), 'regional_preferences.federal_states.preferred');
        }
        $this->invalid(fn () => $this->service->save($this->employee, $this->admin, $this->config(['federal_states' => ['preferred' => ['']]]), 1), 'regional_preferences.federal_states.preferred.0');
        $this->assertSame(1, $this->service->get($this->employee)['revision']);
    }

    public function test_empty_v2_is_neutral_and_configured_v2_is_automatically_active_without_inventing_private_origin(): void
    {
        $this->employee->profile()->create(['city' => 'München', 'country' => 'DE']);
        $config = $this->config(['schema_version' => 2, 'base_location' => '']);
        $empty = $this->service->save($this->employee, $this->admin, $config, 0);
        $this->assertFalse($empty['enabled']);
        $this->assertSame('', $empty['base_location']);
        $this->assertSame('neutral', $this->assessment('München')['state']);

        $config['enabled'] = false;
        $config['base_location'] = 'Hamburg';
        $active = $this->service->save($this->employee, $this->admin, $config, 1);
        $this->assertTrue($active['enabled']);
        $this->assertSame('preferred', $this->assessment('Hamburg')['state']);
    }

    public function test_previously_disabled_configured_v2_is_active_on_read_without_bulk_write(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config(['schema_version' => 2]), 0);
        $record = StaffRegionalPreference::sole();
        $stored = $record->preferences;
        $stored['enabled'] = false;
        $record->update(['preferences' => $stored]);

        $current = $this->service->get($this->employee);
        $this->assertTrue($current['enabled']);
        $this->assertSame($saved['revision'], $current['revision']);
        $this->assertSame('preferred', $this->assessment('München')['state']);
        $this->assertFalse($record->fresh()->preferences['enabled']);
        $this->assertSame(1, OperationAudit::where('action', 'staff.regional_preferences.saved')->count());
    }

    public function test_disabled_legacy_profile_remains_disabled_until_explicit_v2_save(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config(['enabled' => false]), 0);
        $this->assertSame(1, $saved['schema_version']);
        $this->assertFalse($this->service->get($this->employee)['enabled']);
        $this->assertSame('neutral', $this->assessment('München')['state']);
        unset($saved['revision']);
        $saved['schema_version'] = 2;
        $upgraded = $this->service->save($this->employee, $this->admin, $saved, 1);
        $this->assertTrue($upgraded['enabled']);
        $this->assertSame('preferred', $this->assessment('München')['state']);
    }

    public function test_exclusion_only_v2_is_active_and_other_places_are_neutral(): void
    {
        $saved = $this->service->save($this->employee, $this->admin, $this->config([
            'schema_version' => 2, 'enabled' => false, 'base_location' => '',
            'no_go_areas' => [['location' => 'Hamburg', 'radius_km' => 10]],
        ]), 0);
        $this->assertTrue($saved['enabled']);
        $this->assertSame('no_go', $this->assessment('Hamburg')['state']);
        $this->assertSame('neutral', $this->assessment('München')['state']);
        $this->assertSame(0, $this->assessment('München')['score_adjustment']);
        $this->assertSame('unknown', $this->assessment('Unbekanntes Depot')['state']);
    }
}
