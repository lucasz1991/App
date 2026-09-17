<?php

namespace Tests\Feature;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Livewire\Admin\Operations\Calendar;
use App\Livewire\Admin\Operations\Customers;
use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\OrderLifecycleService;
use App\Services\Operations\OrderSchedulingService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsPlanningTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_operational_models_generate_numbers_and_expose_the_planning_relationships(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff']);
        $customer = $this->customer();
        $secondCustomer = $this->customer(['company_name' => 'HanseRail Services']);
        $order = $this->order($customer, $actor, [
            'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 18:00:00',
            'requirements' => ['Triebfahrzeugfuehrerschein'],
        ]);
        $shift = $this->shift($order, $actor);
        $assignment = ShiftAssignment::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'status' => ShiftAssignmentStatus::Confirmed,
            'assigned_by' => $actor->id,
        ]);

        $this->assertSame(sprintf('K-%06d', $customer->id), $customer->customer_number);
        $this->assertSame(sprintf('K-%06d', $secondCustomer->id), $secondCustomer->customer_number);
        $this->assertSame(sprintf('RT-2027-%06d', $order->id), $order->order_number);
        $this->assertTrue(Str::isUuid($customer->public_id));
        $this->assertTrue(Str::isUuid($order->public_id));
        $this->assertTrue(Str::isUuid($shift->public_id));

        $this->assertTrue($customer->orders->contains($order));
        $this->assertTrue($order->customer->is($customer));
        $this->assertTrue($order->shifts->contains($shift));
        $this->assertTrue($shift->order->is($order));
        $this->assertTrue($shift->assignments->contains($assignment));
        $this->assertTrue($shift->assignees->contains($employee));
        $this->assertTrue($assignment->user->is($employee));
        $this->assertTrue($assignment->assigner->is($actor));
        $this->assertSame(OrderStatus::Requested, $order->status);
        $this->assertSame(OrderPriority::Normal, $order->priority);
        $this->assertSame(ShiftStatus::Draft, $shift->status);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignment->status);
        $this->assertSame(['Triebfahrzeugfuehrerschein'], $order->requirements);
        $this->assertSame('2027-05-12 06:00:00', $order->getRawOriginal('starts_at'));
        $this->assertSame('2027-05-12 08:00:00', $order->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_order_lifecycle_updates_the_order_and_writes_an_audit_history(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $order = $this->order($this->customer(), $actor);
        $lifecycle = app(OrderLifecycleService::class);

        $lifecycle->transition($order, OrderStatus::Confirmed, $actor, 'Kunde hat bestaetigt.');
        $transitioned = $lifecycle->transition($order->fresh(), OrderStatus::Planned, $actor);

        $this->assertSame(OrderStatus::Planned, $transitioned->status);
        $this->assertTrue($transitioned->updater->is($actor));
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Requested->value,
            'to_status' => OrderStatus::Confirmed->value,
            'changed_by' => $actor->id,
            'note' => 'Kunde hat bestaetigt.',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Planned->value,
            'changed_by' => $actor->id,
        ]);
        $this->assertCount(2, $transitioned->statusHistory);
        $this->assertTrue($transitioned->statusHistory->first()->changedBy->is($actor));
    }

    public function test_order_lifecycle_rejects_an_invalid_transition_without_mutating_the_order(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $order = $this->order($this->customer(), $actor);

        try {
            app(OrderLifecycleService::class)->transition($order, OrderStatus::Completed, $actor);
            $this->fail('Eine ungueltige Statusfolge muss abgelehnt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_order_cancellation_requires_every_related_shift_to_be_cancelled(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $order = $this->order($this->customer(), $actor);
        $activeShift = $this->shift($order, $actor, ['title' => 'Noch offene Schicht']);
        $this->shift($order, $actor, [
            'title' => 'Bereits stornierte Schicht',
            'status' => ShiftStatus::Cancelled,
        ]);
        $lifecycle = app(OrderLifecycleService::class);

        try {
            $lifecycle->transition($order, OrderStatus::Cancelled, $actor);
            $this->fail('Ein Auftrag mit einer nicht stornierten Schicht darf nicht storniert werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertStringContainsString('alle zugehörigen Schichten', $exception->errors()['status'][0]);
        }

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);

        $activeShift->update(['status' => ShiftStatus::Cancelled]);
        $cancelledOrder = $lifecycle->transition($order->fresh(), OrderStatus::Cancelled, $actor);

        $this->assertSame(OrderStatus::Cancelled, $cancelledOrder->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Requested->value,
            'to_status' => OrderStatus::Cancelled->value,
            'changed_by' => $actor->id,
        ]);
    }

    public function test_shift_assignment_blocks_real_overlaps_but_allows_boundaries_and_non_blocking_statuses(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff']);
        $declinedEmployee = User::factory()->create(['role' => 'staff']);
        $cancelledEmployee = User::factory()->create(['role' => 'staff']);
        $serviceCancelledEmployee = User::factory()->create(['role' => 'staff']);
        $order = $this->order($this->customer(), $actor, [
            'starts_at' => '2026-08-03 06:00:00',
            'ends_at' => '2026-08-03 20:00:00',
        ]);
        $assignments = app(ShiftAssignmentService::class);

        $morning = $this->shift($order, $actor, [
            'title' => 'Fruehschicht',
            'starts_at' => '2026-08-03 08:00:00',
            'ends_at' => '2026-08-03 12:00:00',
        ]);
        $overlap = $this->shift($order, $actor, [
            'title' => 'Ueberlappende Schicht',
            'starts_at' => '2026-08-03 11:30:00',
            'ends_at' => '2026-08-03 13:00:00',
        ]);
        $adjacent = $this->shift($order, $actor, [
            'title' => 'Direkter Anschluss',
            'starts_at' => '2026-08-03 12:00:00',
            'ends_at' => '2026-08-03 14:00:00',
        ]);

        $assignments->assign($morning, $employee, $actor);

        try {
            $assignments->assign($overlap, $employee, $actor);
            $this->fail('Eine zeitlich ueberlappende Zuweisung muss abgelehnt werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }

        $adjacentAssignment = $assignments->assign($adjacent, $employee, $actor);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $adjacentAssignment->status);

        $nonBlocking = $this->shift($order, $actor, [
            'title' => 'Nicht blockierende Vormerkung',
            'starts_at' => '2026-08-03 14:00:00',
            'ends_at' => '2026-08-03 18:00:00',
        ]);
        $replacement = $this->shift($order, $actor, [
            'title' => 'Spaetdienst',
            'starts_at' => '2026-08-03 15:00:00',
            'ends_at' => '2026-08-03 17:00:00',
            'required_staff' => 3,
        ]);

        $assignments->assign($nonBlocking, $declinedEmployee, $actor, ShiftAssignmentStatus::Declined);
        $assignments->assign($nonBlocking, $cancelledEmployee, $actor, ShiftAssignmentStatus::Cancelled);
        $serviceCancelledAssignment = $assignments->assign($nonBlocking, $serviceCancelledEmployee, $actor);
        $cancelled = $assignments->cancel($serviceCancelledAssignment, $actor, 'Planung geaendert.');

        $this->assertSame(ShiftAssignmentStatus::Cancelled, $cancelled->status);
        $this->assertSame('Planung geaendert.', $cancelled->note);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignments->assign($replacement, $declinedEmployee, $actor)->status);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignments->assign($replacement, $cancelledEmployee, $actor)->status);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignments->assign($replacement, $serviceCancelledEmployee, $actor)->status);
    }

    public function test_assigned_shift_cannot_be_rescheduled_into_an_employee_conflict(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff']);
        $order = $this->order($this->customer(), $actor);
        $assignments = app(ShiftAssignmentService::class);
        $scheduling = app(ShiftSchedulingService::class);

        $morning = $this->shift($order, $actor, [
            'title' => 'Frühschicht',
            'starts_at' => '2026-08-03 08:00:00',
            'ends_at' => '2026-08-03 12:00:00',
        ]);
        $afternoon = $this->shift($order, $actor, [
            'title' => 'Spätschicht',
            'starts_at' => '2026-08-03 13:00:00',
            'ends_at' => '2026-08-03 16:00:00',
        ]);

        $assignments->assign($morning, $employee, $actor);
        $assignments->assign($afternoon, $employee, $actor);

        try {
            $scheduling->save($afternoon, [
                'starts_at' => Carbon::parse('2026-08-03 11:00:00'),
                'ends_at' => Carbon::parse('2026-08-03 14:00:00'),
            ], $actor);
            $this->fail('Eine belegte Schicht darf nicht in einen Mitarbeiterkonflikt verschoben werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('startsAt', $exception->errors());
        }

        $this->assertSame('2026-08-03 13:00:00', $afternoon->fresh()->starts_at->format('Y-m-d H:i:s'));

        $updated = $scheduling->save($afternoon->fresh(), [
            'starts_at' => Carbon::parse('2026-08-03 12:00:00'),
            'ends_at' => Carbon::parse('2026-08-03 15:00:00'),
        ], $actor);

        $this->assertSame('2026-08-03 12:00:00', $updated->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($actor->id, $updated->updated_by);
    }

    public function test_order_period_cannot_be_shortened_past_an_active_shift(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $order = $this->order($this->customer(), $actor);
        $this->shift($order, $actor, [
            'starts_at' => '2026-08-03 09:00:00',
            'ends_at' => '2026-08-03 15:00:00',
        ]);

        try {
            app(OrderSchedulingService::class)->save($order, [
                'starts_at' => Carbon::parse('2026-08-03 10:00:00'),
                'ends_at' => Carbon::parse('2026-08-03 14:00:00'),
            ], $actor);
            $this->fail('Ein Auftrag darf bestehende aktive Schichten zeitlich nicht ausschließen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('startsAt', $exception->errors());
        }

        $this->assertSame('2026-08-03 08:00:00', $order->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 18:00:00', $order->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_customer_create_flow_validates_and_persists_a_real_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Customers::class)
            ->dispatch('operations-create')
            ->set('email', 'keine-mailadresse')
            ->call('saveCustomer')
            ->assertHasErrors(['companyName', 'email'])
            ->set('companyName', 'NordCargo GmbH')
            ->set('contactName', 'Mara Hansen')
            ->set('email', 'mara@nordcargo.test')
            ->set('address', 'Gleisweg 7')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false);

        $customer = Customer::query()->sole();
        $this->assertSame('Gleisweg 7', $customer->street);
        $this->assertSame('Mara Hansen', $customer->contact_name);
    }

    public function test_order_create_flow_validates_and_persists_a_real_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();

        Livewire::actingAs($admin)
            ->test(Orders::class)
            ->dispatch('operations-create')
            ->set('customerId', null)
            ->set('title', '')
            ->set('endsAt', '2026-08-04T07:00')
            ->set('startsAt', '2026-08-04T08:00')
            ->call('saveOrder')
            ->assertHasErrors(['customerId', 'title', 'endsAt'])
            ->set('customerId', $customer->id)
            ->set('title', 'Hamburg Hbf nach Bremen Hbf')
            ->set('serviceType', 'Personalbereitstellung')
            ->set('startsAt', '2026-08-04T08:00')
            ->set('endsAt', '2026-08-04T16:00')
            ->set('address', 'Bahnsteig 4')
            ->set('requiredStaff', 2)
            ->set('requirementsText', "TfV\nOrtskenntnis")
            ->call('saveOrder')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false);

        $order = Order::query()->sole();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Bahnsteig 4', $order->street);
        $this->assertSame(['TfV', 'Ortskenntnis'], $order->requirements);
        $this->assertSame($admin->id, $order->created_by);
    }

    public function test_shift_create_flow_requires_a_role_and_persists_a_real_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($this->customer(), $admin, [
            'starts_at' => '2026-08-04 06:00:00',
            'ends_at' => '2026-08-04 18:00:00',
        ]);

        Livewire::actingAs($admin)
            ->test(ShiftManagement::class)
            ->dispatch('operations-create')
            ->set('orderId', $order->id)
            ->set('title', 'Fruehschicht Nord')
            ->set('roleName', '')
            ->call('saveShift')
            ->assertHasErrors(['roleName'])
            ->set('roleName', 'Triebfahrzeugfuehrer')
            ->set('startsAt', '2026-08-04T08:00')
            ->set('endsAt', '2026-08-04T12:00')
            ->set('requiredStaff', 1)
            ->call('saveShift')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false);

        $shift = Shift::query()->sole();
        $this->assertSame($order->id, $shift->order_id);
        $this->assertSame('Triebfahrzeugfuehrer', $shift->role_name);
        $this->assertSame($admin->id, $shift->created_by);
    }

    public function test_shift_management_assigns_an_active_employee_through_the_ui(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $order = $this->order($this->customer(), $admin);
        $shift = $this->shift($order, $admin);

        Livewire::actingAs($admin)
            ->test(ShiftManagement::class)
            ->call('selectShift', $shift->id)
            ->call('assignEmployee')
            ->assertHasErrors(['employeeId'])
            ->set('employeeId', $employee->id)
            ->set('assignmentStatus', ShiftAssignmentStatus::Confirmed->value)
            ->set('assignmentNote', 'Disposition bestaetigt.')
            ->call('assignEmployee')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'status' => ShiftAssignmentStatus::Confirmed->value,
            'assigned_by' => $admin->id,
            'note' => 'Disposition bestaetigt.',
        ]);

        $assignment = ShiftAssignment::query()->sole();

        Livewire::actingAs($admin)
            ->test(ShiftManagement::class)
            ->call('selectShift', $shift->id)
            ->call('removeAssignment', $assignment->id)
            ->assertHasNoErrors();

        $this->assertSame(ShiftAssignmentStatus::Cancelled, $assignment->fresh()->status);
    }

    public function test_calendar_renders_database_shifts_and_week_navigation(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00', 'Europe/Berlin'));

        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order($this->customer(), $admin, [
            'starts_at' => '2026-08-05 07:00:00',
            'ends_at' => '2026-08-05 18:00:00',
        ]);
        $shift = $this->shift($order, $admin, [
            'title' => 'Mittwoch Einsatz Hamburg',
            'starts_at' => '2026-08-05 08:00:00',
            'ends_at' => '2026-08-05 12:00:00',
        ]);

        Livewire::actingAs($admin)
            ->test(Calendar::class)
            ->assertSet('weekStart', '2026-08-03')
            ->assertSee('data-calendar-week', escape: false)
            ->assertSee('data-calendar-day="2026-08-05"', escape: false)
            ->assertSee('data-calendar-shift="'.$shift->id.'"', escape: false)
            ->assertSee('data-calendar-mobile-agenda', escape: false)
            ->assertSee('Mittwoch Einsatz Hamburg')
            ->call('nextWeek')
            ->assertSet('weekStart', '2026-08-10')
            ->assertDontSee('Mittwoch Einsatz Hamburg')
            ->call('previousWeek')
            ->assertSet('weekStart', '2026-08-03')
            ->assertSee('Mittwoch Einsatz Hamburg')
            ->call('nextWeek')
            ->call('today')
            ->assertSet('weekStart', '2026-08-03');
    }

    /** @param array<string, mixed> $overrides */
    private function customer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'company_name' => 'NordCargo GmbH',
            'contact_name' => 'Mara Hansen',
            'email' => 'dispo@nordcargo.test',
            'is_active' => true,
        ], $overrides));
    }

    public function test_calendar_views_keep_date_filters_and_handle_month_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31 09:00:00', 'Europe/Berlin'));
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(Calendar::class)
            ->call('switchView', 'month')->assertSet('viewMode', 'month')->assertSee('Januar 2026')
            ->call('nextPeriod')->assertSet('anchorDate', '2026-02-28')->assertSee('Februar 2026')
            ->call('showDay', '2026-02-14')->assertSet('viewMode', 'day')->assertSee('Samstag, 14. Februar 2026')
            ->call('nextPeriod')->assertSet('anchorDate', '2026-02-15')
            ->call('switchView', 'list')->assertSeeHtml('data-rt-premium-table')
            ->call('switchView', 'week')->assertSeeHtml('data-calendar-desktop-grid');
    }

    public function test_calendar_day_uses_exclusive_midnight_boundary_and_customer_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $order = $this->order($customer, $admin);
        $this->shift($order, $admin, ['title' => 'Nachtschicht', 'starts_at' => '2026-08-04 22:00:00', 'ends_at' => '2026-08-05 06:00:00']);
        $this->shift($order, $admin, ['title' => 'Endet Mitternacht', 'starts_at' => '2026-08-04 18:00:00', 'ends_at' => '2026-08-05 00:00:00']);
        $otherOrder = $this->order($this->customer(['company_name' => 'Anderer Kunde']), $admin);
        $this->shift($otherOrder, $admin, ['title' => 'Fremder Auftrag', 'starts_at' => '2026-08-05 08:00:00', 'ends_at' => '2026-08-05 12:00:00']);
        Livewire::actingAs($admin)->test(Calendar::class)
            ->call('showDay', '2026-08-05')->assertSee('Nachtschicht')->assertDontSee('Endet Mitternacht')
            ->set('customerFilter', (string) $customer->id)->assertDontSee('Fremder Auftrag')
            ->set('search', 'kein Treffer')->assertDontSee('Nachtschicht');
    }

    public function test_calendar_rejects_unknown_view_and_non_planner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(Calendar::class)->call('switchView', 'unsafe')->assertStatus(404);
        Livewire::actingAs(User::factory()->create(['role' => 'staff']))->test(Calendar::class)->assertStatus(403);
    }

    public function test_calendar_open_filter_distinguishes_reservations_and_closed_shifts(): void
    {
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'staff']);
        $order = $this->order($this->customer(), $admin);
        $reserved = $this->shift($order, $admin, ['title' => 'Bereits eingeplant', 'status' => ShiftStatus::Open]);
        ShiftAssignment::create(['shift_id' => $reserved->id, 'user_id' => $employee->id, 'status' => ShiftAssignmentStatus::Requested, 'assigned_by' => $admin->id]);
        $this->shift($order, $admin, ['title' => 'Platz unbesetzt', 'status' => ShiftStatus::Open]);
        $this->shift($order, $admin, ['title' => 'Schon abgeschlossen', 'status' => ShiftStatus::Completed]);
        $this->shift($order, $admin, ['title' => 'Nicht mehr geplant', 'status' => ShiftStatus::Cancelled]);
        Livewire::actingAs($admin)->test(Calendar::class)
            ->call('showDay', '2026-08-03')->assertSee('Bereits eingeplant')->assertSee('1/1 eingeplant')
            ->assertSee('Abgeschlossen')->assertDontSee('Nicht mehr geplant')
            ->set('onlyOpen', true)->assertSee('Platz unbesetzt')
            ->assertDontSee('Bereits eingeplant')->assertDontSee('Schon abgeschlossen')
            ->set('statusFilter', 'all')->assertDontSee('Nicht mehr geplant');
    }

    public function test_calendar_reports_invalid_dates_without_breaking_render(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(Calendar::class)->call('switchView', 'day')
            ->set('anchorDate', '2026-02-31')->assertHasErrors('anchorDate')
            ->set('anchorDate', '')->assertHasErrors('anchorDate')
            ->call('showDay', '2026-03-29')->assertHasNoErrors()
            ->assertSee('Sonntag, 29. März 2026')->call('nextPeriod')->assertSee('Montag, 30. März 2026');
    }

    public function test_calendar_order_scope_survives_views_and_customer_change_resets_incompatible_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $otherCustomer = $this->customer(['company_name' => 'Andere Bahn']);
        $order = $this->order($customer, $admin);
        $otherOrder = $this->order($customer, $admin, ['title' => 'Andere Leistung']);
        $this->shift($order, $admin, ['title' => 'Auftrag Nord']);
        $this->shift($otherOrder, $admin, ['title' => 'Auftrag West']);
        Livewire::actingAs($admin)->withQueryParams(['order' => $order->id])->test(Calendar::class)
            ->assertSet('customerFilter', (string) $customer->id)->assertSet('orderFilter', (string) $order->id)
            ->assertSet('anchorDate', '2026-08-03')->assertSee('Auftrag Nord')->assertDontSee('Auftrag West')
            ->call('switchView', 'month')->assertSee('Auftrag Nord')->assertDontSee('Auftrag West')
            ->set('customerFilter', (string) $otherCustomer->id)->assertSet('orderFilter', 'all')->assertDontSee('Auftrag Nord')
            ->call('resetFilters')->assertSet('customerFilter', 'all')->assertSet('viewMode', 'month')->assertSee('Auftrag West');
        Livewire::actingAs($admin)->withQueryParams(['order' => 999999])->test(Calendar::class)->assertStatus(404);
    }

    /** @param array<string, mixed> $overrides */
    private function order(Customer $customer, User $actor, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => 'Hamburg Hbf nach Bremen Hbf',
            'service_type' => 'Personalbereitstellung',
            'status' => OrderStatus::Requested,
            'priority' => OrderPriority::Normal,
            'starts_at' => '2026-08-03 08:00:00',
            'ends_at' => '2026-08-03 18:00:00',
            'timezone' => 'Europe/Berlin',
            'location_name' => 'Hamburg Hbf',
            'required_staff' => 2,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function shift(Order $order, User $actor, array $overrides = []): Shift
    {
        return Shift::query()->create(array_merge([
            'order_id' => $order->id,
            'title' => 'Fruehschicht Nord',
            'role_name' => 'Triebfahrzeugfuehrer',
            'starts_at' => '2026-08-03 08:00:00',
            'ends_at' => '2026-08-03 12:00:00',
            'timezone' => 'Europe/Berlin',
            'location_name' => 'Hamburg Hbf',
            'required_staff' => 1,
            'status' => ShiftStatus::Draft,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }
}
