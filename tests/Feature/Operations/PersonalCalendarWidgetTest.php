<?php

namespace Tests\Feature\Operations;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Dashboard\WidgetDataProvider;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonalCalendarWidgetTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12 06:00:00', 'UTC'));
    }

    public function test_next_work_widget_uses_published_fields_and_published_order_even_after_draft_changes(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $shift = $this->assignment($employee);
        $secretOrder = $this->order('Vertraulicher Entwurfkunde');
        $shift->forceFill([
            'revision' => 2,
            'order_id' => $secretOrder->id,
            'title' => 'Vertraulicher Entwurftitel',
            'location_name' => 'Vertraulicher Entwurfort',
            'starts_at' => CarbonImmutable::parse('2027-05-10 07:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2027-05-10 15:00', 'UTC'),
        ])->save();

        $data = app(WidgetDataProvider::class)->data('my_work', $employee, 'small', 2);
        $this->assertNotNull($data['nextAssignment']);
        $this->assertSame($shift->id, $data['nextAssignment']->shift->id);
        $html = view('dashboard.widgets.my_work', ['data' => $data, 'rows' => 2])->render();

        $this->assertStringContainsString('Veröffentlichter Dienst', $html);
        $this->assertStringContainsString('Hamburg Hbf', $html);
        $this->assertStringContainsString('Freigegebener Kunde', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringNotContainsString('Vertraulicher', $html);
        $this->assertSame(route('operations.mine', ['tab' => 'schedule']), $data['calendarHref']);
        $this->assertStringContainsString('Mein Kalender', $html);
        $this->assertStringContainsString('Mein Arbeitstag öffnen', $html);
        $this->assertStringContainsString('href="'.$data['calendarHref'].'"', $html);
    }

    public function test_widget_excludes_foreign_and_unpublished_assignments_and_keeps_calendar_entry(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->assignment($other);
        $this->assignment($employee)->forceFill(['published_revision' => 0, 'published_snapshot' => null])->save();

        $data = app(WidgetDataProvider::class)->data('my_work', $employee, 'small');
        $this->assertNull($data['nextAssignment']);
        $html = view('dashboard.widgets.my_work', ['data' => $data, 'rows' => 1])->render();
        $this->assertStringContainsString('Kein bevorstehender Dienst.', $html);
        $this->assertStringNotContainsString('Veröffentlichter Dienst', $html);
        $this->assertStringContainsString('Mein Kalender', $html);
        $this->assertStringContainsString('fa-calendar-days', $html);
    }

    private function order(string $customerName): Order
    {
        $customer = Customer::create(['company_name' => $customerName, 'is_active' => true]);

        return Order::create([
            'customer_id' => $customer->id,
            'title' => 'Kundenleistung',
            'status' => 'confirmed',
            'priority' => 'normal',
            'timezone' => 'Europe/Berlin',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(3),
            'required_staff' => 1,
        ]);
    }

    private function assignment(User $employee): Shift
    {
        $order = $this->order('Freigegebener Kunde');
        $snapshot = [
            'order_id' => $order->id,
            'title' => 'Veröffentlichter Dienst',
            'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-12T09:00:00+02:00',
            'ends_at' => '2027-05-12T17:00:00+02:00',
            'location_name' => 'Hamburg Hbf',
            'planned_break_minutes' => 30,
        ];
        $shift = Shift::create($snapshot + ['required_staff' => 1, 'status' => 'confirmed']);
        $shift->forceFill(['revision' => 1, 'published_revision' => 1, 'published_at' => now(), 'published_snapshot' => $snapshot])->save();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id, 'status' => 'confirmed']);
        $assignment->forceFill(['plan_revision' => 1])->save();

        return $shift;
    }
}
