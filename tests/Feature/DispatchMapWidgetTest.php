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
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
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
            $this->assertFalse(collect($queries)->contains(fn (array $query) => str_contains(strtolower($query['query']), 'from "'.$table.'"')));
            $component = $this->grid($user)->assertOk();
            $xpath = $this->xpath($component->html());
            $kindInputs = '//*[@data-dispatch-map]//input[@type="radio" and @x-model="kind"]';
            $this->assertSame(0, $xpath->query($kindInputs.'[@value="'.($shownType === 'shift' ? 'inquiry' : 'shift').'"]')->length);
            $this->assertSame(1, $xpath->query($kindInputs.'[@value="'.$shownType.'"]')->length);
            $this->assertSame(1, $xpath->query($kindInputs.'[@value="all"]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-dispatch-map]//input[@type="radio" and @x-model="place" and @value="'.$data['markers'][0]['key'].'"]')->length);
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
        $this->assertTrue($data['truncated']);
        $this->assertSame(100, $data['itemsPerType']);
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
        $map = '//*[@data-dispatch-map]';
        $toolbar = $map.'/*[contains(concat(" ", normalize-space(@class), " "), " wv-dispatch-map__toolbar ")]';
        $this->assertSame(1, $xpath->query($toolbar)->length);
        $triggers = $xpath->query($toolbar.'//*[@data-rt-dropdown-trigger]/button');
        $this->assertSame(3, $triggers->length);
        foreach ($triggers as $trigger) {
            $this->assertSame('button', $trigger->getAttribute('type'));
            $this->assertNotSame('', trim($trigger->getAttribute('aria-label')));
            $this->assertSame('', trim($trigger->textContent), 'Toolbar triggers must contain only icons.');
        }
        $dialogs = $map.'//*[@role="dialog"]';
        $this->assertSame(3, $xpath->query($dialogs.'[@aria-label]')->length);
        $this->assertSame(1, $xpath->query($dialogs.'//input[@type="date"]')->length);
        $this->assertSame(0, $xpath->query($map.'//input[@type="date" and not(ancestor::*[@role="dialog"])]')->length);
        $this->assertSame(0, $xpath->query($map.'//select')->length);
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
