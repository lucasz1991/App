<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\InquiryInbox;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispositionTableSortingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        Mail::fake();
        Notification::fake();
        $this->travelTo(now()->setDate(2027, 5, 12)->setTime(7, 0)->utc());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Bravo Rail', 'is_active' => true]);
    }

    private function inquiry(string $title, array $attributes = []): OperationInquiry
    {
        return OperationInquiry::create(array_merge([
            'title' => $title, 'channel' => 'manual', 'original' => 'Synthetic sorting probe',
            'customer_id' => $this->customer->id, 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'timezone' => 'Europe/Berlin',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ], $attributes));
    }

    private function order(string $title, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $this->customer->id, 'title' => $title, 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-12 08:00:00', 'ends_at' => '2027-05-12 16:00:00',
            'required_staff' => 1, 'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function shift(Order $order, string $title, array $attributes = []): Shift
    {
        return Shift::create(array_merge([
            'order_id' => $order->id, 'title' => $title, 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'required_staff' => 1, 'status' => 'open',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function assignment(Shift $shift, string $status): User
    {
        $user = User::factory()->create(['role' => 'staff', 'status' => true]);
        $user->forceFill(['profile_photo_path' => 'profile-photos/synthetic.jpg'])->save();
        $user->profile()->create(['first_name' => 'Synthetic', 'last_name' => 'Employee']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => $status, 'assigned_by' => $this->admin->id]);

        return $user;
    }

    public function test_inquiries_sort_all_columns_stably_and_preserve_filter_selection(): void
    {
        $alpha = Customer::create(['company_name' => 'Alpha Rail', 'is_active' => true]);
        $first = $this->inquiry('Zulu', ['status' => 'accepted', 'starts_at' => '2027-05-14 08:00:00']);
        $second = $this->inquiry('Alpha', ['status' => 'new', 'customer_id' => $alpha->id]);
        $tie = $this->inquiry('Alpha', ['status' => 'new', 'customer_id' => $alpha->id]);
        $component = Livewire::actingAs($this->admin)->test(InquiryInbox::class)->call('select', $first->id);
        foreach (['title' => [$second->id, $tie->id, $first->id], 'customer' => [$second->id, $tie->id, $first->id], 'schedule' => [$second->id, $tie->id, $first->id], 'status' => [$first->id, $second->id, $tie->id], 'updated_at' => [$first->id, $second->id, $tie->id]] as $key => $ids) {
            $component->call('tableSort', $key, 'asc')->assertSet('sortBy', $key)->assertSet('sortDir', 'asc')
                ->assertSet('selectedId', $first->id)->assertViewHas('inquiries', fn ($items) => $items->getCollection()->modelKeys() === $ids);
        }
        $component->call('tableSort', 'title', 'desc')->assertViewHas('inquiries', fn ($items) => $items->getCollection()->modelKeys() === [$first->id, $second->id, $tie->id])
            ->set('statusFilter', 'new')->assertViewHas('inquiries', fn ($items) => $items->total() === 2);
    }

    public function test_inquiry_ordering_precedes_pagination_and_sort_resets_page(): void
    {
        foreach (range(17, 1) as $number) {
            $this->inquiry(sprintf('Probe %02d', $number));
        }
        Livewire::actingAs($this->admin)->test(InquiryInbox::class)->call('gotoPage', 2)
            ->call('tableSort', 'title', 'asc')->assertSet('paginators.page', 1)
            ->assertViewHas('inquiries', fn ($items) => $items->first()->title === 'Probe 01' && $items->last()->title === 'Probe 15')
            ->call('gotoPage', 2)->assertViewHas('inquiries', fn ($items) => $items->first()->title === 'Probe 16' && $items->last()->title === 'Probe 17');
    }

    public function test_order_sort_uses_columns_before_pagination_and_retains_context(): void
    {
        $records = collect();
        foreach (range(28, 1) as $number) {
            $records->push($this->order(sprintf('Probe %02d', $number), ['starts_at' => sprintf('2027-05-%02d 08:00:00', $number), 'required_staff' => $number, 'status' => $number % 2 === 0 ? 'confirmed' : 'planned']));
        }
        $component = Livewire::actingAs($this->admin)->test(Orders::class)->call('selectOrder', $records->first()->id);
        foreach (['title' => 'title', 'period' => 'starts_at', 'staff' => 'required_staff', 'status' => 'status'] as $key => $column) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $records->sort(function ($a, $b) use ($column, $direction) {
                    $comparison = $a->getRawOriginal($column) <=> $b->getRawOriginal($column);

                    return $comparison === 0 ? $a->id <=> $b->id : $comparison * ($direction === 'desc' ? -1 : 1);
                })->take(25)->pluck('id')->all();
                $component->call('tableSort', $key, $direction)->assertViewHas('orders', fn ($items) => $items->getCollection()->modelKeys() === $expected);
            }
        }
        $component->call('gotoPage', 2, 'ordersPage')->call('tableSort', 'title', 'asc')->assertSet('paginators.ordersPage', 1)
            ->assertSet('selectedOrderId', $records->first()->id)->call('gotoPage', 2, 'ordersPage')
            ->assertViewHas('orders', fn ($items) => $items->first()->title === 'Probe 26' && $items->last()->title === 'Probe 28');
    }

    public function test_shift_table_and_grouped_table_sort_but_daily_view_stays_chronological(): void
    {
        $alpha = Customer::create(['company_name' => 'Alpha Rail', 'is_active' => true]);
        $order = $this->order('Bravo');
        $other = $this->order('Alpha', ['customer_id' => $alpha->id]);
        $early = $this->shift($order, 'Zulu', ['starts_at' => '2027-05-12 06:00:00', 'status' => 'open']);
        $middle = $this->shift($other, 'Bravo', ['starts_at' => '2027-05-12 08:00:00', 'status' => 'draft']);
        $late = $this->shift($order, 'Alpha', ['starts_at' => '2027-05-12 10:00:00', 'status' => 'open']);
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->set('rangeFrom', '2027-05-12')->set('rangeTo', '2027-05-12');
        foreach (['table', 'orders'] as $view) {
            $component->call('setView', $view);
            foreach (['shift' => [$late->id, $middle->id, $early->id], 'customer' => [$middle->id, $early->id, $late->id], 'schedule' => [$early->id, $middle->id, $late->id], 'status' => [$middle->id, $early->id, $late->id]] as $key => $expected) {
                $component->call('tableSort', $key, 'asc')->assertViewHas('shifts', fn ($items) => $items->modelKeys() === $expected);
            }
        }
        $component->call('tableSort', 'shift', 'asc')->call('setView', 'day')
            ->assertViewHas('dailyGroups', fn ($groups) => $groups['2027-05-12']['items']->modelKeys() === [$early->id, $middle->id, $late->id]);
    }

    public function test_staffing_sort_uses_reserved_coverage_not_declines_or_confirmations_alone(): void
    {
        $order = $this->order('Coverage');
        $half = $this->shift($order, 'Half', ['required_staff' => 2]);
        $quarter = $this->shift($order, 'Quarter', ['required_staff' => 4]);
        $empty = $this->shift($order, 'Empty');
        $this->assignment($half, 'requested');
        $employee = $this->assignment($quarter, 'confirmed');
        $this->assignment($empty, 'declined');
        $this->assignment($empty, 'cancelled');
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'table')
            ->call('tableSort', 'staffing', 'asc')->assertViewHas('shifts', fn ($items) => $items->modelKeys() === [$empty->id, $quarter->id, $half->id])
            ->call('tableSort', 'staffing', 'desc')->assertViewHas('shifts', fn ($items) => $items->modelKeys() === [$half->id, $quarter->id, $empty->id])
            ->call('openDetails', $quarter->id)->assertViewHas('selectedShift', fn ($shift) => $shift->assignments->first()->user->profile_photo_path === 'profile-photos/synthetic.jpg' && $shift->assignments->first()->user->relationLoaded('profile') && $shift->assignments->first()->user->relationLoaded('currentTeam'))
            ->assertViewHas('candidates', fn ($items) => $items->firstWhere('id', $employee->id)?->profile_photo_path === 'profile-photos/synthetic.jpg' && $items->firstWhere('id', $employee->id)?->email === $employee->email && $items->firstWhere('id', $employee->id)?->relationLoaded('profile'));
    }

    public function test_sort_actions_reject_invalid_column_direction_and_unauthorized_users(): void
    {
        foreach ([InquiryInbox::class, Orders::class, ShiftManagement::class] as $class) {
            Livewire::actingAs($this->admin)->test($class)->call('tableSort', 'title desc; drop table users', 'asc')->assertStatus(422);
            $key = $class === ShiftManagement::class ? 'shift' : 'title';
            Livewire::actingAs($this->admin)->test($class)->call('tableSort', $key, 'evil')->assertStatus(422);
            $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
            Livewire::actingAs($employee)->test($class)->assertForbidden();
        }
        $this->assertGreaterThan(0, User::count());
    }

    public function test_sort_state_is_locked_against_direct_client_changes(): void
    {
        foreach ([InquiryInbox::class, Orders::class, ShiftManagement::class] as $class) {
            foreach (['sortBy', 'sortDir'] as $property) {
                try {
                    Livewire::actingAs($this->admin)->test($class)->set($property, 'injected');
                    $this->fail('Expected a locked property exception.');
                } catch (CannotUpdateLockedPropertyException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_sort_without_direction_toggles_only_the_current_column(): void
    {
        foreach ([InquiryInbox::class, Orders::class, ShiftManagement::class] as $class) {
            $key = $class === ShiftManagement::class ? 'shift' : 'title';
            Livewire::actingAs($this->admin)->test($class)
                ->call('tableSort', $key)->assertSet('sortDir', 'asc')
                ->call('tableSort', $key)->assertSet('sortDir', 'desc')
                ->call('tableSort', 'status')->assertSet('sortDir', 'asc');
        }
    }

    public function test_sort_rechecks_permissions_after_component_mount(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        foreach ([InquiryInbox::class, Orders::class, ShiftManagement::class] as $class) {
            $component = Livewire::actingAs($this->admin)->test($class);
            Livewire::actingAs($employee);
            $component->call('tableSort', 'status', 'asc')->assertForbidden();
        }
    }
}
