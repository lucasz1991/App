<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Calendar;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsPages;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CalendarUiComponentsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(Carbon::parse('2026-09-17 09:00:00', 'Europe/Berlin'));
    }

    public function test_calendar_starts_in_month_with_one_header_row_of_minimal_dropdowns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Calendar::class)
            ->assertSet('viewMode', 'month')
            ->assertSeeHtml('data-calendar-header-controls')
            ->assertSeeHtml('data-rt-date-field')
            ->assertSeeHtml('id="calendar-anchor-date"')
            ->assertSeeHtml('aria-label="Kalenderdatum"')
            ->assertSeeHtml('data-autosave-model="anchorDate"')
            ->assertDontSeeHtml('type="date"')
            ->assertDontSeeHtml('data-multi-toggle')
            ->assertSee('September 2026');

        $html = $component->html();
        // Ansicht als ein Auswahlmenü: vier Optionen, genau eine gewählt.
        $this->assertSame(4, preg_match_all('/data-calendar-view-option="(day|week|month|list)"/', $html));
        $this->assertSame(1, substr_count($html, 'role="menuitemradio" aria-checked="true"'));
        $this->assertStringContainsString('data-calendar-view-option="month"', $html);
        $this->assertStringContainsString('aria-label="Kalenderansicht ändern: Monat"', $html);
        // Filter wandern ins Filter-Menü; keine eigene Filterzeile mehr.
        $this->assertStringContainsString('id="calendar-filters"', $html);
        $this->assertStringNotContainsString('rt-disposition-toolbar', $html);
        $this->assertStringNotContainsString('@click="clear()"', $html);
        $component->call('switchView', 'week')->assertSet('viewMode', 'week')
            ->assertSeeHtml('aria-label="Kalenderansicht ändern: Woche"');
        $this->assertSame(1, substr_count($component->html(), 'role="menuitemradio" aria-checked="true"'));
        $component->set('onlyOpen', true)->assertSeeHtml('aria-pressed="true"');
    }

    public function test_month_cells_show_state_in_text_and_lead_with_open_shifts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($admin);
        $staffed = $this->shift($order, $admin, 'Frueh besetzt', ['required_staff' => 1, 'starts_at' => '2026-09-17 06:00:00', 'ends_at' => '2026-09-17 14:00:00']);
        ShiftAssignment::create(['shift_id' => $staffed->id, 'user_id' => User::factory()->create(['role' => 'staff'])->id, 'status' => 'confirmed', 'assigned_by' => $admin->id]);
        $open = $this->shift($order, $admin, 'Spaet offen', ['required_staff' => 2, 'starts_at' => '2026-09-17 14:00:00', 'ends_at' => '2026-09-17 22:00:00']);
        $html = Livewire::actingAs($admin)->test(Calendar::class)->html();
        $day = substr($html, strpos($html, 'data-calendar-day="2026-09-17"'));
        $day = substr($day, 0, strpos($day, '</section>'));
        $this->assertStringContainsString('2 offen', $day);
        $this->assertStringContainsString('data-calendar-state="staffed"', $day);
        // Lücken zuerst, auch wenn die besetzte Schicht früher beginnt.
        $this->assertLessThan(strpos($day, 'data-calendar-shift="'.$staffed->id.'"'), strpos($day, 'data-calendar-shift="'.$open->id.'"'));
    }

    public function test_full_page_calendar_owns_one_surface_through_empty_view_changes_while_embedded_calendar_keeps_document_flow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $embedded = Livewire::actingAs($admin)->test(Calendar::class)->call('switchView', 'list');
        $embedded->assertSet('viewport', false)
            ->assertDontSeeHtml('data-disposition-viewport')
            ->assertDontSeeHtml('data-disposition-calendar-surface')
            ->assertDontSeeHtml('data-table-scroll-body');

        $page = Livewire::actingAs($admin)->test(Calendar::class, ['viewport' => true]);
        foreach (['month', 'week', 'day', 'list', 'month'] as $view) {
            $page->call('switchView', $view)->assertSet('viewport', true);
            $this->assertSame(1, substr_count($page->html(), 'data-disposition-viewport="calendar"'));
            $this->assertSame(1, substr_count($page->html(), 'data-disposition-calendar-surface'));
        }
        $page->call('switchView', 'list')->assertSee('Keine Schichten in diesem Zeitraum.')
            ->assertSeeHtml('data-table-scroll-body tabindex="0" aria-label="Kalenderschichten – scrollbarer Inhalt"');
    }

    public function test_calendar_viewport_placement_cannot_be_changed_by_client_hydration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = Livewire::actingAs($admin)->test(Calendar::class, ['viewport' => true]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('viewport', false);
    }

    public function test_calendar_returns_to_shared_planning_with_its_current_order_and_customer(): void
    {
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $order = $this->order($admin);
        $shift = $this->shift($order, $admin, 'Return context');
        $original = $shift->fresh()->getRawOriginal();

        $component = Livewire::actingAs($admin)->withQueryParams(['order' => $order->id])->test(Calendar::class)->call('switchView', 'week');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//a[@data-calendar-planning-return]');
        $this->assertSame(1, $links->length);
        $this->assertSame(OperationsPages::url('cases', [
            'view' => 'shifts', 'section' => 'plan', 'order' => $order->id, 'customer' => $order->customer_id,
            'from' => '2026-09-14', 'until' => '2026-09-20',
        ]), $links->item(0)->getAttribute('href'));
        $this->assertSame('menuitem', $links->item(0)->getAttribute('role'));
        $this->assertStringContainsString('Schichtplanung öffnen', $links->item(0)->textContent);
        $this->assertSame(4, $xpath->query('//button[@data-calendar-view-option]')->length);
        $this->assertSame($original, $shift->fresh()->getRawOriginal());
    }

    public function test_calendar_keeps_the_incoming_plan_period_until_the_user_changes_its_date_or_view(): void
    {
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $order = $this->order($admin);
        $period = ['from' => '2027-05-17', 'until' => '2027-05-23'];
        $component = Livewire::actingAs($admin)->withQueryParams(['order' => $order->id, 'customer' => $order->customer_id] + $period)
            ->test(Calendar::class)->assertSet('anchorDate', '2027-05-17')->assertSet('weekStart', '2027-05-17')
            ->assertSet('planningRange', $period)->assertSet('customerFilter', (string) $order->customer_id)
            ->assertDispatched('operations-period-changed', ...$period)
            ->assertDispatched('operations-planning-filters-changed', customer: $order->customer_id, order: $order->id);
        $this->assertSame(1, collect($component->effects['dispatches'])->where('name', 'operations-period-changed')->count());
        $returnQuery = function () use ($component): array {
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
            $link = (new \DOMXPath($dom))->query('//a[@data-calendar-planning-return]')->item(0);
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);

            return $query;
        };
        $this->assertSame($period, array_intersect_key($returnQuery(), $period));
        $component->call('switchView', 'week')->call('nextPeriod')->assertSet('planningRange', [])
            ->assertSet('anchorDate', '2027-05-24');
        $this->assertSame(['from' => '2027-05-24', 'until' => '2027-05-30'], array_intersect_key($returnQuery(), $period));
        $component->call('showDay', '2027-05-27');
        $this->assertSame(['from' => '2027-05-27', 'until' => '2027-05-27'], array_intersect_key($returnQuery(), $period));
        $this->assertDatabaseCount('shifts', 0);
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_date_and_view_navigation_preserve_customer_order_search_and_open_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($admin);
        $wanted = $this->shift($order, $admin, 'Nordkorridor offen');
        $this->shift($order, $admin, 'Nordkorridor abgeschlossen', ['status' => 'completed']);
        $this->shift($order, $admin, 'Andere Strecke');

        $component = Livewire::actingAs($admin)->withQueryParams(['order' => $order->id])->test(Calendar::class)
            ->set('search', 'Nordkorridor')->set('onlyOpen', true);
        foreach (['day', 'week', 'month', 'list'] as $view) {
            $component->call('switchView', $view)
                ->assertSet('customerFilter', (string) $order->customer_id)
                ->assertSet('orderFilter', (string) $order->id)
                ->assertSet('search', 'Nordkorridor')->assertSet('onlyOpen', true)
                ->assertViewHas('shifts', fn ($shifts) => $shifts->modelKeys() === [$wanted->id]);
        }

        $component->call('nextPeriod')->assertSet('anchorDate', '2026-09-24')->assertSet('weekStart', '2026-09-21')
            ->call('previousPeriod')->assertSet('anchorDate', '2026-09-17')
            ->set('anchorDate', '2026-10-02')->assertSet('weekStart', '2026-09-28')
            ->call('today')->assertSet('anchorDate', '2026-09-17')->assertSet('weekStart', '2026-09-14')
            ->assertSet('search', 'Nordkorridor')->assertSet('onlyOpen', true)
            ->assertSet('orderFilter', (string) $order->id)
            ->call('resetFilters')->assertSet('customerFilter', 'all')->assertSet('orderFilter', 'all')
            ->assertSet('search', '')->assertSet('onlyOpen', false)
            ->assertSet('viewMode', 'list')->assertSet('anchorDate', '2026-09-17');
    }

    public function test_clicking_an_entry_opens_the_shift_side_panel_instead_of_leaving_the_calendar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($admin);
        $shift = $this->shift($order, $admin, 'Nordkorridor Panel', ['required_staff' => 2]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => User::factory()->create(['role' => 'staff', 'name' => 'Ida Weber'])->id, 'status' => 'requested', 'assigned_by' => $admin->id]);
        $component = Livewire::actingAs($admin)->test(Calendar::class)
            ->assertSeeHtml('x-data="rtShiftDetailDrawer"')
            ->assertSeeHtml("\$dispatch('operations-shift-detail-request', { id: {$shift->id} })")
            ->assertSeeHtml('x-data="rtCalendarReveal"')
            ->assertDontSeeHtml('wire:click="openShift('.$shift->id.')"');
        $component->call('openDetails', $shift->id)
            ->assertSet('detailOpen', true)->assertSet('selectedShiftId', $shift->id)->assertNoRedirect()
            ->assertSee('Nordkorridor Panel')->assertSee('1 Platz offen')->assertSee('Ida Weber')->assertSee('1/2 eingeplant')
            ->assertSeeHtml('data-calendar-panel-content')->assertSeeHtml('calendar-shift-detail-');
        // Bearbeiten bleibt dem Schichtplan vorbehalten.
        $component->call('openShift', $shift->id)->assertRedirect();
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($admin)->test(Calendar::class)->call('openDetails', 999999);
    }

    #[DataProvider('berlinClockChangeDates')]
    public function test_calendar_day_preserves_real_utc_boundaries_at_clock_changes(string $date, string $fromUtc, string $untilUtc): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($admin);
        $overnight = $this->shift($order, $admin, 'Uebernacht');
        $inside = $this->shift($order, $admin, 'Innerhalb');
        $before = $this->shift($order, $admin, 'Endet davor');
        $after = $this->shift($order, $admin, 'Beginnt danach');
        $from = Carbon::parse($fromUtc, 'UTC');
        $until = Carbon::parse($untilUtc, 'UTC');

        // Insert UTC directly: tests exercise the query boundary, not the model's input conversion.
        foreach ([
            [$overnight, $from->copy()->subHour(), $from->copy()->addHour()],
            [$inside, $until->copy()->subHour(), $until],
            [$before, $from->copy()->subHours(2), $from],
            [$after, $until, $until->copy()->addHour()],
        ] as [$shift, $starts, $ends]) {
            DB::table('shifts')->where('id', $shift->id)->update(['starts_at' => $starts->format('Y-m-d H:i:s'), 'ends_at' => $ends->format('Y-m-d H:i:s')]);
        }

        Livewire::actingAs($admin)->test(Calendar::class)->call('showDay', $date)
            ->assertViewHas('days', fn ($days) => $days->count() === 1 && $days->first()['date']->toDateString() === $date)
            ->assertViewHas('shifts', fn ($shifts) => $shifts->modelKeys() === [$overnight->id, $inside->id])
            ->assertViewHas('shiftCount', 2)->assertViewHas('requiredCount', 4)->assertViewHas('openCount', 4);
    }

    public static function berlinClockChangeDates(): array
    {
        return [
            'summer time' => ['2026-03-29', '2026-03-28 23:00:00', '2026-03-29 22:00:00'],
            'winter time' => ['2026-10-25', '2026-10-24 22:00:00', '2026-10-25 23:00:00'],
        ];
    }

    private function order(User $admin): Order
    {
        $customer = Customer::create(['company_name' => 'Nordbahn Test', 'is_active' => true]);

        return Order::create([
            'customer_id' => $customer->id, 'title' => 'Nordkorridor', 'service_type' => 'Triebfahrzeugführer',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-09-17 00:00:00', 'ends_at' => '2026-09-18 00:00:00',
            'required_staff' => 2, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
    }

    private function shift(Order $order, User $admin, string $title, array $overrides = []): Shift
    {
        return Shift::create(array_merge([
            'order_id' => $order->id, 'title' => $title, 'role_name' => 'Triebfahrzeugführer', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-09-17 08:00:00', 'ends_at' => '2026-09-17 16:00:00',
            'required_staff' => 2, 'status' => 'open', 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ], $overrides));
    }
}
