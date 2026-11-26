<?php

namespace Tests\Feature;

use App\Livewire\Operations\Orders;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OrderPlanningLinksTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected User $admin;

    protected Order $order;

    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Planning Link Test', 'is_active' => true]);
        $this->order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Auftragsbezogener Test',
            'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal',
            'timezone' => 'Europe/Berlin', 'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3), 'required_staff' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        $this->shift = Shift::create([
            'order_id' => $this->order->id, 'title' => 'Direkt erreichbarer Dienst',
            'role_name' => 'Tf', 'status' => 'draft', 'timezone' => 'Europe/Berlin',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(8),
            'required_staff' => 1, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
    }

    public function test_native_planning_links_keep_order_and_shift_context_without_mutation(): void
    {
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $this->assertTrue(OperationsAccess::ready());
        $this->assertPlanningLinks(null);
    }

    public function test_legacy_planning_links_use_existing_preview_routes(): void
    {
        $this->assertFalse(OperationsAccess::ready());
        $this->assertPlanningLinks('admin.operations.preview');
    }

    public function test_employee_without_planning_permission_cannot_open_orders(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($employee)->test(Orders::class)->assertForbidden();
    }

    private function assertPlanningLinks(?string $route): void
    {
        $orderBefore = $this->order->fresh()->getAttributes();
        $shiftBefore = $this->shift->fresh()->getAttributes();
        $component = Livewire::actingAs($this->admin)->test(Orders::class)
            ->call('openDetails', $this->order->id)
            ->assertSet('detailOpen', true)
            ->assertSeeHtml('aria-label="Planung dieser Leistung"');

        foreach (['shift-management', 'calendar'] as $module) {
            $component->assertSeeHtml('href="'.e($this->planningUrl($route, $module, ['order' => $this->order->id])).'"');
        }
        $component->assertSeeHtml('href="'.e($this->planningUrl($route, 'shift-management', ['order' => $this->order->id, 'shift' => $this->shift->id])).'"');
        $this->assertSame($orderBefore, $this->order->fresh()->getAttributes());
        $this->assertSame($shiftBefore, $this->shift->fresh()->getAttributes());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Shift::count());
    }

    /** Ohne Routenname (Schema bereit) zeigen die Links direkt auf die kanonische Seite. */
    private function planningUrl(?string $route, string $module, array $parameters): string
    {
        return $route === null
            ? OperationsPages::moduleUrl($module, $parameters)
            : route($route, ['module' => $module] + $parameters);
    }
}
