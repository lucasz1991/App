<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Calendar;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_calendar_uses_one_accessible_icon_view_control_and_iso_date_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Calendar::class)
            ->assertSeeHtml('data-multi-toggle')
            ->assertSeeHtml('data-rt-date-field')
            ->assertSeeHtml('id="calendar-anchor-date"')
            ->assertSeeHtml('aria-label="Kalenderdatum"')
            ->assertSeeHtml('data-autosave-model="anchorDate"')
            ->assertDontSeeHtml('type="date"');

        $html = $component->html();
        // Count real option attributes, not selectors inside the Alpine expression.
        $this->assertSame(4, preg_match_all('/data-toggle-value="(day|week|month|list)"/', $html));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        foreach (['Tag', 'Woche', 'Monat', 'Liste'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $html);
        }
        $this->assertStringNotContainsString('@click="clear()"', $html);
        $component->call('switchView', 'month')->assertSet('viewMode', 'month');
        $this->assertSame(1, substr_count($component->html(), 'aria-pressed="true"'));
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
