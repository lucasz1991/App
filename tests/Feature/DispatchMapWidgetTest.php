<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\WidgetGrid;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Dashboard\DispatchMapData;
use App\Support\Dashboard\WidgetDataProvider;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\Operations\GermanyMap;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispatchMapWidgetTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_140000_create_dashboard_widget_placements_table.php'))->up();
        (require database_path('migrations/2026_09_17_160000_add_rows_to_dashboard_widget_placements_table.php'))->up();
        config(['operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08T22:30:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic Rail', 'city' => 'Berlin', 'is_active' => true]);
        $this->order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Synthetic order', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-11-01 00:00:00',
            'location_name' => 'Hamburg', 'city' => 'Hamburg', 'country' => 'DE',
            'status' => 'confirmed', 'priority' => 'normal', 'required_staff' => 1,
        ]);
    }

    private function shift(array $attributes = []): Shift
    {
        return Shift::create($attributes + [
            'order_id' => $this->order->id, 'title' => 'Synthetic shift', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-09 06:00:00', 'ends_at' => '2026-10-09 14:00:00',
            'location_name' => 'Hamburg', 'status' => 'open', 'required_staff' => 2,
        ]);
    }

    private function inquiry(array $attributes = []): OperationInquiry
    {
        return OperationInquiry::create($attributes + [
            'customer_id' => $this->order->customer_id, 'title' => 'Synthetic inquiry', 'channel' => 'manual',
            'original' => 'PRIVATE ORIGINAL MUST NOT BE IN THE MAP', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-10-09 08:00:00', 'ends_at' => '2026-10-09 16:00:00',
            'location_name' => 'Hamburg', 'status' => 'new', 'required_staff' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }

    private function manager(array $abilities, string $teamName = 'Verwaltung'): User
    {
        $user = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate([
            'user_id' => $this->admin->id, 'name' => $teamName, 'personal_team' => false,
            'rbac_permissions' => array_fill_keys($abilities, true),
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    private function map(?User $user = null, ?string $date = null): array
    {
        return app(DispatchMapData::class)->forUser($user ?? $this->admin, $date);
    }

    /** Keep Livewire rendering focused; do not mount unrelated widgets or schemas. */
    private function grid(User $user)
    {
        foreach (array_keys(WidgetRegistry::availableFor($user)) as $key) {
            if ($key !== 'operations_dispatch_map') {
                DashboardLayout::setHidden($user, $key, true);
            }
        }

        return Livewire::actingAs($user)->test(WidgetGrid::class);
    }

    public function test_confirmed_shift_markers_use_own_snapshot_and_explain_precision_in_rendered_maps(): void
    {
        $confirmed = $this->shift();
        $legacy = $this->shift();
        $this->addSnapshotColumns();
        DB::table('orders')->where('id', $this->order->id)->update(['location_snapshot' => json_encode($this->snapshot(['name' => 'Later order Berlin', 'latitude' => 52.52001, 'longitude' => 13.40495]), JSON_THROW_ON_ERROR)]);
        DB::table('shifts')->where('id', $confirmed->id)->update(['location_snapshot' => json_encode($this->snapshot(), JSON_THROW_ON_ERROR)]);

        $data = $this->map();
        $item = collect($data['items'])->firstWhere('id', $confirmed->id);
        $legacyItem = collect($data['items'])->firstWhere('id', $legacy->id);
        $marker = collect($data['markers'])->firstWhere('place', 'Depot München');
        $this->assertSame([48.13512, 11.58198], [$marker['latitude'], $marker['longitude']]);
        $point = GermanyMap::fromPreview($item['location']);
        $this->assertSame([$point['left'], $point['top']], [$marker['left'], $marker['top']]);
        $this->assertSame('confirmed', $marker['precision']);
        $this->assertSame('Depot München', $item['locationLabel']);
        $this->assertSame('Hamburg', $legacyItem['location']['place']);
        $this->assertStringContainsString('ungefähr', $legacyItem['location']['label']);
        $this->assertCount(2, $data['markers']);
        $this->assertStringNotContainsString('Later order Berlin', json_encode($data));
        $component = $this->grid($this->admin)->assertSee('Bestätigter Einsatzstandort')->assertSee('Stadtmitte · ungefähr');
        $xpath = $this->xpath($component->html());
        $this->assertSame(1, $xpath->query('//button[@data-dispatch-marker and contains(@aria-label, "Bestätigter Einsatzstandort")]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-dispatch-marker and contains(@aria-label, "ungefähr")]')->length);
        $mini = Blade::render('<x-operations.timeline-mini-map :preview="$preview" />', ['preview' => $item['location']]);
        $this->assertStringContainsString('bestätigter Einsatzstandort.', $mini);
        $this->assertStringNotContainsString('ungefähre Ortslage', $mini);
        $legacyMini = Blade::render('<x-operations.timeline-mini-map :preview="$preview" />', ['preview' => $legacyItem['location']]);
        $this->assertStringContainsString('ungefähre Ortslage, keine genaue Einsatzadresse.', $legacyMini);
    }

    public function test_confirmed_and_approximate_points_at_same_coordinates_are_labelled_mixed(): void
    {
        $confirmed = $this->shift();
        $this->shift();
        $this->addSnapshotColumns();
        $hamburg = TimelineLocationPreview::fromShift(new Shift(['location_name' => 'Hamburg']));
        DB::table('shifts')->where('id', $confirmed->id)->update(['location_snapshot' => json_encode($this->snapshot(['name' => 'Hamburg Depot', 'latitude' => $hamburg['latitude'], 'longitude' => $hamburg['longitude']]), JSON_THROW_ON_ERROR)]);
        $data = $this->map();
        $this->assertCount(1, $data['markers']);
        $this->assertSame('mixed', $data['markers'][0]['precision']);
        $this->assertSame('Bestätigte und ungefähre Standorte', $data['markers'][0]['label']);
        $this->assertSame(2, $data['markers'][0]['count']);
        $this->assertSame([true, false], array_map(fn (array $item) => $item['location']['confirmed'] ?? false, $data['markers'][0]['items']));
    }

    public function test_multiple_named_places_count_one_shift_and_each_point_keeps_the_same_native_entry(): void
    {
        $shift = $this->shift(['location_name' => 'Eidelstedt/Maschen']);
        $this->shift(['location_name' => 'Unknown synthetic locality']);
        $data = $this->map();
        $item = collect($data['items'])->firstWhere('id', $shift->id);
        $this->assertSame('multiple', $item['location']['state']);
        $this->assertArrayNotHasKey('latitude', $item['location']);
        $this->assertCount(2, $item['locationKeys']);
        $this->assertCount(2, $data['markers']);
        $this->assertSame([2, 2, 1, 1], [$data['shiftsCount'], $data['understaffedCount'], $data['locatedCount'], $data['unlocatedCount']]);
        $this->assertCount(1, $data['unlocatedItems']);
        foreach ($data['markers'] as $marker) {
            $this->assertSame(1, $marker['count']);
            $this->assertSame(1, $marker['shiftCount']);
            $this->assertContains($marker['key'], $item['locationKeys']);
            $this->assertSame('shift-'.$shift->id, $marker['items'][0]['key']);
            $this->assertSame($item['locationKeys'], $marker['items'][0]['locationKeys']);
        }
        $html = $this->grid($this->admin)->assertOk()->html();
        $xpath = $this->xpath($html);
        $row = $xpath->query('//a[@data-dispatch-entry="shift" and @data-dispatch-location-key="multiple"]')->item(0);
        $this->assertNotNull($row);
        $this->assertSame($item['locationKeys'], json_decode($row->getAttribute('data-dispatch-location-keys'), true));
        $this->assertStringContainsString('mapMatchesPlace', $row->getAttribute('x-show'));
        $this->assertSame('Eidelstedt/Maschen', $shift->fresh()->location_name);
        $this->assertNull($shift->fresh()->location_snapshot);
    }

    public function test_service_title_and_description_refine_dashboard_points_without_exposing_internal_text(): void
    {
        $shift = $this->shift();
        foreach (['shift_title', 'order_title', 'order_description'] as $source) {
            $shift->update(['title' => $source === 'shift_title' ? 'Hasselbrook Nachtdienst' : 'Synthetic shift']);
            $this->order->update([
                'title' => $source === 'order_title' ? 'Hasselbrook Nachtdienst' : 'Synthetic order',
                'description' => $source === 'order_description' ? 'Nachtdienst am Bahnhof Hasselbrook. PRIVATE SERVICE DESCRIPTION.' : null,
                'notes' => 'PRIVATE INTERNAL NOTES at Berlin',
            ]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $data = $this->map();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $item = $data['items'][0];
            $marker = $data['markers'][0];
            $this->assertSame('Hamburg-Hasselbrook', $item['locationLabel'], $source);
            $this->assertSame('Hamburg-Hasselbrook', $marker['place']);
            $this->assertSame('Bahnhofslage · ungefähr', $marker['label']);
            $this->assertSame('approximate', $marker['precision']);
            $this->assertSame([53.5649, 10.05697], [$marker['latitude'], $marker['longitude']]);
            $this->assertSame(GermanyMap::fromPreview($item['location'])['left'], $marker['left']);
            $this->assertStringNotContainsString('PRIVATE SERVICE DESCRIPTION', json_encode($data));
            $this->assertStringNotContainsString('PRIVATE INTERNAL NOTES', json_encode($data));
            $orderQueries = array_values(array_filter($queries, fn (array $query) => str_contains(strtolower($query['query']), 'from "orders"')));
            $this->assertCount(1, $orderQueries);
            $this->assertStringContainsString('"description"', $orderQueries[0]['query']);
            $this->assertStringNotContainsString('"notes"', $orderQueries[0]['query']);
        }

        $html = $this->grid($this->admin)->assertSee('Hamburg-Hasselbrook')->assertSee('Bahnhofslage · ungefähr')->html();
        $xpath = $this->xpath($html);
        $entry = $xpath->query('//a[@data-dispatch-entry="shift"]')->item(0);
        $this->assertStringContainsString('Hamburg-Hasselbrook', $entry->textContent);
        $this->assertStringContainsString('Bahnhofslage · ungefähr', $entry->textContent);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertSame('Hamburg', $shift->fresh()->location_name);
    }

    public function test_snapshot_loading_stays_batched_and_does_not_read_employee_locations(): void
    {
        $first = $this->shift();
        $this->addSnapshotColumns();
        DB::table('shifts')->where('id', $first->id)->update(['location_snapshot' => json_encode($this->snapshot(), JSON_THROW_ON_ERROR)]);
        $this->map();
        $queryCounts = [];
        foreach ([1, 30] as $size) {
            if ($size === 30) {
                for ($i = 1; $i < $size; $i++) {
                    $shift = $this->shift();
                    DB::table('shifts')->where('id', $shift->id)->update(['location_snapshot' => json_encode($this->snapshot(), JSON_THROW_ON_ERROR)]);
                }
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $data = $this->map();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $queryCounts[] = count($queries);
            $this->assertSame($size, $data['shiftsCount']);
            foreach (['shifts', 'orders', 'customers'] as $table) {
                $this->assertCount(1, array_filter($queries, fn (array $query) => str_contains(strtolower($query['query']), 'from "'.$table.'"')), 'Snapshots must be selected by the existing bounded eager-loading queries.');
            }
            $this->assertFalse(collect($queries)->contains(fn (array $query) => str_contains(strtolower($query['query']), 'employee_location')));
        }
        $this->assertSame($queryCounts[0], $queryCounts[1], 'One and thirty shifts must issue the same number of SQL queries.');
    }

    private function addSnapshotColumns(): void
    {
        foreach (['shifts', 'orders'] as $table) {
            Schema::table($table, fn ($table) => $table->json('location_snapshot')->nullable());
        }
    }

    private function snapshot(array $overrides = []): array
    {
        return array_replace(['confirmed' => true, 'name' => 'Depot München', 'latitude' => 48.13512, 'longitude' => 11.58198, 'revision' => 1], $overrides);
    }

    public function test_default_map_is_visible_large_for_only_authorized_active_dashboard_audiences(): void
    {
        $definition = WidgetRegistry::find('operations_dispatch_map');
        $this->assertTrue($definition['defaultVisible']);
        $this->assertSame('lg', $definition['defaultSize']);
        $this->assertSame(2, $definition['defaultRows']);
        foreach ([$this->admin, $this->manager(['operations.manage']), $this->manager(['operations.inquiries.manage'], 'Administration')] as $user) {
            $this->assertContains('operations_dispatch_map', array_column(DashboardLayout::visible($user), 'key'));
        }
        foreach ([$this->manager([]), $this->manager(['operations.manage'], 'Mitarbeiter'), $this->manager(['operations.inquiries.manage'], 'Gäste')] as $user) {
            $this->assertArrayNotHasKey('operations_dispatch_map', WidgetRegistry::availableFor($user));
            $this->assertSame([], $this->map($user));
        }
        $this->admin->forceFill(['status' => false])->save();
        $this->assertSame([], $this->map());
        $this->assertDatabaseCount('dashboard_widget_placements', 0);
    }

    public function test_map_is_the_first_default_widget_for_admin_and_verwaltung_dashboards(): void
    {
        $verwaltung = $this->manager(['operations.manage', 'operations.inquiries.manage', 'employees.view']);
        $administration = $this->manager(['operations.manage'], 'Administration');

        foreach ([$this->admin, $verwaltung, $administration] as $user) {
            $this->assertSame('operations_dispatch_map', DashboardLayout::visible($user)[0]['key']);
        }

        $employee = $this->manager(['operations.manage'], 'Mitarbeiter');
        $this->assertNotContains('operations_dispatch_map', array_column(DashboardLayout::forUser($employee), 'key'));
    }

    public function test_first_touch_on_another_widget_keeps_the_map_in_front(): void
    {
        $order = array_column(DashboardLayout::visible($this->admin), 'key');

        DashboardLayout::setSize($this->admin, 'messages', 'lg');
        DashboardLayout::setRows($this->admin, 'files', 2);

        $this->assertSame($order, array_column(DashboardLayout::visible($this->admin), 'key'));
        $this->assertSame('operations_dispatch_map', DashboardLayout::visible($this->admin)[0]['key']);
    }

    public function test_a_deliberate_reorder_still_wins_over_the_default(): void
    {
        $keys = array_column(DashboardLayout::visible($this->admin), 'key');
        $withMapLast = array_values(array_diff($keys, ['operations_dispatch_map']));
        $withMapLast[] = 'operations_dispatch_map';

        DashboardLayout::reorder($this->admin, $withMapLast);

        $this->assertSame($withMapLast, array_column(DashboardLayout::visible($this->admin), 'key'));
    }

    public function test_overlap_includes_overnight_and_excludes_boundary_cancelled_deleted_and_tomorrow(): void
    {
        $overnight = $this->shift(['title' => 'Overnight', 'starts_at' => '2026-10-08 22:00:00', 'ends_at' => '2026-10-09 06:00:00']);
        $nextNight = $this->shift(['title' => 'Next night', 'starts_at' => '2026-10-09 22:00:00', 'ends_at' => '2026-10-10 06:00:00']);
        $this->shift(['starts_at' => '2026-10-08 16:00:00', 'ends_at' => '2026-10-09 00:00:00']);
        $this->shift(['starts_at' => '2026-10-10 00:00:00', 'ends_at' => '2026-10-10 08:00:00']);
        $this->shift(['status' => 'cancelled']);
        $this->shift()->delete();
        $data = $this->map();
        $this->assertSame('2026-10-09', $data['date']);
        $this->assertSame(2, $data['shiftsCount']);
        $this->assertEqualsCanonicalizing([$overnight->id, $nextNight->id], array_column($data['items'], 'id'));
        $this->assertStringContainsString('08.10.2026 22:00', $data['items'][0]['timeLabel']);
        $this->assertSame(1, count($data['markers']));
        $this->assertSame(2, $data['markers'][0]['shiftCount']);
        $this->assertSame(OperationsPages::moduleUrl('shift-management', ['shift' => $overnight->id, 'from' => '2026-10-09', 'until' => '2026-10-09']), $data['items'][0]['href']);
    }

    public function test_inquiries_use_open_requested_periods_and_show_undated_and_partial_dates_explicitly(): void
    {
        foreach (['new', 'verified', 'offered', 'accepted'] as $status) {
            $this->inquiry(['status' => $status]);
        }
        $undated = $this->inquiry(['starts_at' => null, 'ends_at' => null]);
        $startOnly = $this->inquiry(['ends_at' => null]);
        $endOnly = $this->inquiry(['starts_at' => null]);
        foreach (['converted', 'cancelled', 'closed', 'rejected', 'duplicate'] as $status) {
            $this->inquiry(['status' => $status]);
        }
        $this->inquiry(['order_id' => $this->order->id]);
        $this->inquiry(['duplicate_of_id' => $undated->id]);
        $this->inquiry(['starts_at' => '2026-10-10 08:00', 'ends_at' => '2026-10-10 16:00']);
        $this->inquiry(['starts_at' => '2026-10-08 20:00', 'ends_at' => '2026-10-09 00:00']);
        $data = $this->map();
        $this->assertSame(7, $data['inquiriesCount']);
        $this->assertSame(1, $data['undatedCount']);
        $entries = collect($data['items'])->keyBy('id');
        $this->assertSame('Termin offen', $entries[$undated->id]['timeLabel']);
        $this->assertTrue($entries[$undated->id]['undated']);
        $this->assertStringContainsString('Ende offen', $entries[$startOnly->id]['timeLabel']);
        $this->assertStringContainsString('Beginn offen', $entries[$endOnly->id]['timeLabel']);
        $this->assertSame(1, $this->map(date: '2026-10-11')['inquiriesCount']);
        $this->assertStringNotContainsString('PRIVATE ORIGINAL', json_encode($data));
    }

    public function test_same_coordinate_groups_types_and_missing_site_never_borrows_customer_city(): void
    {
        $first = $this->shift();
        $this->shift(['location_name' => 'Hamburg Hbf']);
        $this->inquiry();
        $unknown = $this->inquiry(['location_name' => 'Unknown synthetic place']);
        $this->inquiry(['location_name' => null]);
        $confirmed = User::factory()->create(['role' => 'staff', 'status' => true]);
        $requested = User::factory()->create(['role' => 'staff', 'status' => true]);
        ShiftAssignment::create(['shift_id' => $first->id, 'user_id' => $confirmed->id, 'status' => 'confirmed']);
        ShiftAssignment::create(['shift_id' => $first->id, 'user_id' => $requested->id, 'status' => 'requested']);
        $data = $this->map();
        $this->assertSame(['shifts' => 2, 'inquiries' => 3, 'undated' => 0, 'located' => 3, 'unlocated' => 2], $data['totals']);
        $this->assertCount(1, $data['markers']);
        $marker = $data['markers'][0];
        $this->assertSame([2, 1, 3], [$marker['shiftCount'], $marker['inquiryCount'], $marker['count']]);
        $this->assertCount(3, $marker['itemKeys']);
        $entry = collect($data['items'])->firstWhere('key', 'shift-'.$first->id);
        $this->assertSame($marker['key'], $entry['locationKey']);
        $this->assertSame(1, $entry['assignedStaff']);
        $this->assertSame('unknown', collect($data['items'])->firstWhere('key', 'inquiry-'.$unknown->id)['location']['state']);
        $this->assertCount(2, $data['unlocatedItems']);
    }

    public function test_day_summary_counts_understaffed_shifts_and_marks_multi_entry_points(): void
    {
        $full = $this->shift(['required_staff' => 1, 'location_name' => 'Hamburg Hbf']);
        $this->shift(['required_staff' => 2]);
        $this->inquiry();
        ShiftAssignment::create(['shift_id' => $full->id, 'user_id' => User::factory()->create(['role' => 'staff'])->id, 'status' => 'confirmed']);

        $data = $this->map();
        $this->assertSame(1, $data['understaffedCount']);

        $html = $this->grid($this->admin)->html();
        $this->assertStringContainsString('wv-dispatch-map__stats', $html);
        $this->assertMatchesRegularExpression('/<b>1<\/b>unterbesetzt/', $html);
        $this->assertMatchesRegularExpression('/wv-dispatch-map__count[^>]*>3</', $html);
    }

    public function test_each_gate_omits_other_type_and_does_not_query_its_table(): void
    {
        $this->shift();
        $this->inquiry();
        foreach ([['operations.manage', 'inquiries', 'operation_inquiries', 'shift'], ['operations.inquiries.manage', 'shifts', 'shifts', 'inquiry']] as [$ability, $hiddenType, $table, $shownType]) {
            $user = $this->manager([$ability]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $data = $this->map($user);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame(0, $data['totals'][$hiddenType]);
            $this->assertSame([$shownType], array_unique(array_column($data['items'], 'type')));
            $this->assertSame([$shownType], array_unique(array_column($data['markers'][0]['items'], 'type')));
            $this->assertSame(0, $data['markers'][0][$shownType === 'shift' ? 'inquiryCount' : 'shiftCount']);
            $this->assertStringNotContainsString('PRIVATE ORIGINAL', json_encode($data['markers']));
            $this->assertFalse(collect($queries)->contains(fn (array $query) => str_contains(strtolower($query['query']), 'from "'.$table.'"')));
            $component = $this->grid($user)->assertOk();
            $xpath = $this->xpath($component->html());
            $widget = '//article[@data-widget-key="operations_dispatch_map"]';
            $kindInputs = $widget.'//input[@type="radio" and @x-model="kind"]';
            $this->assertSame(0, $xpath->query($kindInputs.'[@value="'.($shownType === 'shift' ? 'inquiry' : 'shift').'"]')->length);
            $this->assertSame(1, $xpath->query($kindInputs.'[@value="'.$shownType.'"]')->length);
            $this->assertSame(1, $xpath->query($kindInputs.'[@value="all"]')->length);
            $this->assertSame(1, $xpath->query($widget.'//input[@type="radio" and @x-model="place" and @value="'.$data['markers'][0]['key'].'"]')->length);
        }
    }

    public function test_streamed_totals_and_map_include_all_records_while_detail_list_is_bounded(): void
    {
        $template = $this->inquiry()->getAttributes();
        $rows = [];
        for ($index = 0; $index < 260; $index++) {
            $row = $template;
            unset($row['id']);
            $row['public_id'] = (string) Str::uuid();
            $rows[] = $row;
        }
        DB::table('operation_inquiries')->insert($rows);
        $data = $this->map();
        $this->assertSame(261, $data['inquiriesCount']);
        $this->assertSame(261, $data['locatedCount']);
        $this->assertSame(261, $data['markers'][0]['inquiryCount']);
        $this->assertSame(100, $data['displayedCount']);
        $this->assertCount(100, $data['items']);
        $this->assertCount(100, $data['markers'][0]['itemKeys']);
        $this->assertCount(3, $data['markers'][0]['items']);
        $this->assertSame(3, $data['markers'][0]['detailsPerType']);
        $this->assertTrue($data['markers'][0]['detailsTruncated']);
        $this->assertTrue($data['truncated']);
        $this->assertSame(100, $data['itemsPerType']);
    }

    public function test_every_marker_has_bounded_safe_native_details_after_global_lists_are_full_without_extra_queries(): void
    {
        foreach ([$this->shift(), $this->inquiry()] as $record) {
            $template = $record->getAttributes();
            $rows = [];
            for ($index = 0; $index < 100; $index++) {
                $row = $template;
                unset($row['id']);
                $row['public_id'] = (string) Str::uuid();
                $rows[] = $row;
            }
            DB::table($record->getTable())->insert($rows);
        }
        $lateShift = $this->shift(['title' => 'Late native shift', 'location_name' => 'Berlin']);
        $lateInquiry = $this->inquiry(['title' => 'Late native inquiry', 'location_name' => 'Berlin']);
        $assigned = User::factory()->create(['role' => 'staff', 'status' => true]);
        ShiftAssignment::create(['shift_id' => $lateShift->id, 'user_id' => $assigned->id, 'status' => 'confirmed']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $data = $this->map();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([102, 102, 204, 200], [$data['shiftsCount'], $data['inquiriesCount'], $data['locatedCount'], $data['displayedCount']]);
        $this->assertCount(2, $data['markers']);
        $this->assertTrue($data['truncated']);
        $this->assertNotContains('shift-'.$lateShift->id, array_column($data['items'], 'key'));
        $this->assertNotContains('inquiry-'.$lateInquiry->id, array_column($data['items'], 'key'));
        $lateMarker = collect($data['markers'])->firstWhere('place', 'Berlin');
        $this->assertSame([1, 1, 2], [$lateMarker['shiftCount'], $lateMarker['inquiryCount'], $lateMarker['count']]);
        $this->assertSame([], $lateMarker['itemKeys']);
        $this->assertFalse($lateMarker['detailsTruncated']);
        $this->assertEqualsCanonicalizing(['shift-'.$lateShift->id, 'inquiry-'.$lateInquiry->id], array_column($lateMarker['items'], 'key'));
        $shiftDetails = collect($lateMarker['items'])->firstWhere('type', 'shift');
        $this->assertSame('Late native shift', $shiftDetails['title']);
        $this->assertSame('06:00–14:00', $shiftDetails['timeLabel']);
        $this->assertSame('Berlin', $shiftDetails['locationLabel']);
        $this->assertSame('located', $shiftDetails['location']['state']);
        $this->assertSame(1, $shiftDetails['assignedStaff']);
        $this->assertSame(OperationsPages::moduleUrl('shift-management', ['shift' => $lateShift->id, 'from' => '2026-10-09', 'until' => '2026-10-09']), $shiftDetails['href']);
        $this->assertSame(OperationsPages::moduleUrl('inquiries', ['inquiry' => $lateInquiry->id]), collect($lateMarker['items'])->firstWhere('type', 'inquiry')['href']);
        $firstMarker = collect($data['markers'])->firstWhere('place', 'Hamburg');
        $this->assertSame([101, 101, 202], [$firstMarker['shiftCount'], $firstMarker['inquiryCount'], $firstMarker['count']]);
        $this->assertTrue($firstMarker['detailsTruncated']);
        $this->assertCount(6, $firstMarker['items']);
        foreach (['shift', 'inquiry'] as $type) {
            $this->assertCount(3, array_filter($firstMarker['items'], fn (array $item) => $item['type'] === $type));
        }
        $this->assertStringNotContainsString('PRIVATE ORIGINAL', json_encode($data['markers']));
        foreach (['shifts', 'operation_inquiries'] as $table) {
            $this->assertCount(1, array_filter($queries, fn (array $query) => str_contains(strtolower($query['query']), 'from "'.$table.'"')), 'Marker samples must not query individual records.');
        }
        $this->assertDatabaseCount('shifts', 102);
        $this->assertDatabaseCount('operation_inquiries', 102);
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_day_filter_is_correct_on_spring_and_autumn_dst_changes(): void
    {
        foreach ([['2026-03-29', '2026-03-28T23:00:00Z', '2026-03-29T22:00:00Z', 23], ['2026-10-25', '2026-10-24T22:00:00Z', '2026-10-25T23:00:00Z', 25]] as [$date, $start, $end, $hours]) {
            $day = DispatchMapData::day($date);
            $this->assertSame($hours * 3600, (int) $day->diffInSeconds($day->addDay()));
            $inside = $this->shift(['starts_at' => CarbonImmutable::parse($start), 'ends_at' => CarbonImmutable::parse($end)]);
            $outside = $this->shift(['starts_at' => CarbonImmutable::parse($end), 'ends_at' => CarbonImmutable::parse($end)->addHours(3)]);
            $this->assertSame([$inside->id], array_column($this->map(date: $date)['items'], 'id'));
            $this->assertSame([$outside->id], array_column($this->map(date: $day->addDay()->toDateString())['items'], 'id'));
        }
    }

    public function test_date_actions_render_native_controls_preserve_invalid_selection_and_reset_local_today(): void
    {
        $this->shift(['title' => '<script>alert("title")</script>', 'location_name' => '<img src=x onerror=alert(1)>']);
        $component = $this->grid($this->admin)
            ->assertSet('dispatchMapDate', '2026-10-09')
            ->assertSeeHtml('type="date"')
            ->assertSee('Datum der Dispositionskarte')->assertSee('Standort filtern')->assertSee('Einträge anzeigen')
            ->assertSeeHtml('wire:click="moveDispatchMapDate(-1)"')
            ->assertSeeHtml('wire:click="moveDispatchMapDate(1)"')
            ->assertSeeHtml('wire:click="resetDispatchMapDate"')
            ->assertSeeHtml('&lt;script&gt;')->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;')
            ->assertDontSeeHtml('<script>alert("title")</script>')
            ->assertDontSeeHtml('<img src=x onerror=alert(1)>');
        $xpath = $this->xpath($component->html());
        $widget = '//article[@data-widget-key="operations_dispatch_map"]';
        $toolbar = $widget.'//*[contains(concat(" ", normalize-space(@class), " "), " wv-dispatch-map__toolbar ")]';
        $this->assertSame(1, $xpath->query($toolbar)->length);
        $triggers = $xpath->query($toolbar.'//*[@data-rt-dropdown-trigger]/button');
        $this->assertSame(3, $triggers->length);
        foreach ($triggers as $trigger) {
            $this->assertSame('button', $trigger->getAttribute('type'));
            $this->assertNotSame('', trim($trigger->getAttribute('aria-label')));
            $this->assertSame('', trim($trigger->textContent), 'Toolbar triggers must contain only icons.');
        }
        $dialogs = $toolbar.'//*[@role="dialog"]';
        $this->assertSame(3, $xpath->query($dialogs.'[@aria-label]')->length);
        $this->assertSame(1, $xpath->query($dialogs.'//input[@type="date"]')->length);
        $this->assertSame(0, $xpath->query($widget.'//input[@type="date" and not(ancestor::*[@role="dialog"])]')->length);
        $this->assertSame(0, $xpath->query($widget.'//select')->length);
        foreach (['kind', 'place'] as $model) {
            $radios = $xpath->query($dialogs.'//input[@type="radio" and @x-model="'.$model.'"]');
            $this->assertGreaterThanOrEqual(2, $radios->length);
            $names = [];
            foreach ($radios as $radio) {
                $this->assertNotSame('', $radio->getAttribute('name'));
                $names[] = $radio->getAttribute('name');
                $this->assertSame('label', $radio->parentNode->nodeName);
            }
            $this->assertCount(1, array_unique($names), 'Each native radio group must share one unique name.');
        }
        $this->assertSame(1, $xpath->query($dialogs.'//input[@type="radio" and @x-model="place" and @value="unlocated"]')->length);
        foreach (['2026-02-30', '2026-10-9', '2026-10-09T00:00', 'tomorrow', '', '1899-12-31', '2101-01-01'] as $date) {
            $component->call('setDispatchMapDate', $date)->assertHasErrors('dispatchMapDate')->assertSet('dispatchMapDate', '2026-10-09');
        }
        $component->call('setDispatchMapDate', '2026-10-25')->assertHasNoErrors('dispatchMapDate')
            ->call('moveDispatchMapDate', 1)->assertSet('dispatchMapDate', '2026-10-26')
            ->call('moveDispatchMapDate', -1)->assertSet('dispatchMapDate', '2026-10-25')
            ->call('resetDispatchMapDate')->assertSet('dispatchMapDate', '2026-10-09');
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('operation_inquiries', 0);
    }

    public function test_hidden_widget_issues_no_map_queries_and_rejects_date_actions(): void
    {
        $user = $this->manager(['operations.inquiries.manage']);
        $component = $this->grid($user)->call('hideWidget', 'operations_dispatch_map');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh')->assertDontSeeHtml('data-dispatch-map');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertFalse(collect($queries)->contains(fn (array $query) => str_contains(strtolower($query['query']), 'from "operation_inquiries"')));
        $component->call('setDispatchMapDate', '2026-10-10')->assertForbidden();
    }

    public function test_selected_date_cannot_be_forged_as_a_public_property(): void
    {
        $component = $this->grid($this->admin);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('dispatchMapDate', '2026-10-10');
    }

    public function test_unavailable_date_action_is_forbidden_and_navigation_accepts_only_one_day(): void
    {
        $unauthorized = $this->manager(['operations.manage'], 'Mitarbeiter');
        $this->grid($unauthorized)->call('setDispatchMapDate', '2026-10-10')->assertForbidden();
        $this->grid($this->admin)->call('moveDispatchMapDate', 100)->assertStatus(422);
    }

    public function test_missing_operations_schema_and_unauthorized_provider_calls_return_no_map(): void
    {
        $guest = $this->manager(['operations.manage'], 'Gäste');
        $this->assertSame([], app(WidgetDataProvider::class)->data('operations_dispatch_map', $guest, 'lg', 2, 'malformed'));
        Schema::drop('operation_audits');
        $this->assertArrayNotHasKey('operations_dispatch_map', WidgetRegistry::availableFor($this->admin));
        $this->assertSame([], $this->map());
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }
}
