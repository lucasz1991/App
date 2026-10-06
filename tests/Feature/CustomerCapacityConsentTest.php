<?php

namespace Tests\Feature;

use App\Livewire\Operations\CustomerCapacityConsent;
use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalSubmission;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerCapacityConsentTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $outside;

    private User $admin;

    private CustomerCapacityCommitment $commitment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Mail::fake();
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->outside = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic capacity rail', 'is_active' => true]);
        $location = CustomerLocation::create(['customer_id' => $customer->id, 'name' => 'Synthetic capacity yard', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->admin->id]);
        $this->commitment = CustomerCapacityCommitment::create(['customer_id' => $customer->id, 'user_id' => $this->employee->id, 'location_id' => $location->id, 'role_name' => 'Tf', 'starts_at' => now()->addDays(3)->startOfHour(), 'ends_at' => now()->addDays(3)->startOfHour()->addHours(8), 'timezone' => 'Europe/Berlin', 'planned_break_minutes' => 30, 'qualification_ids' => [], 'status' => 'requested', 'revision' => 1, 'created_by' => $this->admin->id]);
    }

    public function test_employee_sees_own_capacity_and_responds_without_any_shift_or_time_creation(): void
    {
        $outside = $this->commitment->replicate();
        $outside->forceFill(['user_id' => $this->outside->id, 'role_name' => 'OUTSIDE PRIVATE'])->save();
        Livewire::actingAs($this->employee)->test(CustomerCapacityConsent::class)
            ->assertSee('Synthetic capacity rail')->assertDontSee('OUTSIDE PRIVATE')
            ->call('open', $this->commitment->id)->set('confirmed', true)->call('respond', true)
            ->assertSet('formOpen', false)->assertSet('recordId', null);
        $this->assertSame('consented', $this->commitment->fresh()->status);
        $this->assertSame(2, $this->commitment->fresh()->revision);
        $this->assertNull($this->commitment->fresh()->approved_at);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, WorkTimeEntry::count());
        Mail::assertNothingSent();
    }

    public function test_employee_must_explicitly_confirm_exact_window_before_responding(): void
    {
        Livewire::actingAs($this->employee)->test(CustomerCapacityConsent::class)->call('open', $this->commitment->id)->call('respond', true)->assertHasErrors('confirmed');
        $this->assertSame('requested', $this->commitment->fresh()->status);
    }

    public function test_decline_is_not_an_approval_or_automatic_customer_rejection(): void
    {
        Livewire::actingAs($this->employee)->test(CustomerCapacityConsent::class)->call('open', $this->commitment->id)->set('confirmed', true)->call('respond', false);
        $this->assertSame('declined', $this->commitment->fresh()->status);
        $this->assertNull($this->commitment->fresh()->consented_at);
        $this->assertNull($this->commitment->fresh()->approved_by);
        $this->assertSame(0, CustomerPortalSubmission::count());
    }

    public function test_other_employee_cannot_open_or_change_owned_record(): void
    {
        Livewire::actingAs($this->outside)->test(CustomerCapacityConsent::class)->call('open', $this->commitment->id)->assertStatus(404);
        $this->assertSame('requested', $this->commitment->fresh()->status);
    }

    public function test_changed_revision_requires_reopening_and_never_silently_confirms_new_dates(): void
    {
        $ui = Livewire::actingAs($this->employee)->test(CustomerCapacityConsent::class)->call('open', $this->commitment->id)->set('confirmed', true);
        $this->commitment->forceFill(['revision' => 2, 'ends_at' => $this->commitment->ends_at->addHour()])->save();
        $ui->call('respond', true)->assertStatus(409);
        $this->assertSame('requested', $this->commitment->fresh()->status);
        $this->assertNull($this->commitment->fresh()->consented_at);
    }

    public function test_deactivated_user_and_missing_schema_are_fail_closed(): void
    {
        $ui = Livewire::actingAs($this->employee)->test(CustomerCapacityConsent::class)->call('open', $this->commitment->id)->set('confirmed', true);
        $this->employee->forceFill(['status' => false])->save();
        $ui->call('respond', true)->assertStatus(403);
        $this->assertSame('requested', $this->commitment->fresh()->status);
        Schema::drop('customer_capacity_reservations');
        Livewire::actingAs($this->outside)->test(CustomerCapacityConsent::class)->assertStatus(503);
    }
}
