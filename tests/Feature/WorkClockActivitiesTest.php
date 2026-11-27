<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\WorkTimeActivityService;
use App\Services\Operations\WorkTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkClockActivitiesTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12T06:00:00Z'));
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Zeitmodell', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->employee->id, 'approved_at' => now()]);
    }

    public function test_a_repeat_and_a_correction_preserve_the_section_start_and_resume_keeps_its_kind(): void
    {
        $clock = app(WorkTimeService::class);
        $activities = app(WorkTimeActivityService::class);
        $entry = $clock->startContext(['work_context' => 'internal', 'title' => 'Vorbereitung', 'timezone' => 'Europe/Berlin'], (string) Str::uuid(), $this->employee);
        $this->assertFalse($activities->pickerState($entry)['can_correct_activity']);
        $this->travel(12)->minutes();
        $firstAt = now();
        $entry = $activities->change($entry->id, $entry->revision, 'driving', (string) Str::uuid(), $this->employee);
        $entry = $activities->change($entry->id, $entry->revision, 'driving', (string) Str::uuid(), $this->employee);
        $this->assertCount(2, $entry->activities()->get());
        $this->assertTrue($activities->pickerState($entry)['can_correct_activity']);
        $this->travel(2)->minutes();
        $entry = $activities->change($entry->id, $entry->revision, 'shunting', (string) Str::uuid(), $this->employee, correctCurrent: true);
        $this->assertCount(2, $entry->activities()->get());
        $current = $entry->activities()->whereNull('ends_at')->sole();
        $this->assertTrue($current->starts_at->equalTo($firstAt));
        $this->assertSame('shunting', $current->kind);
        $this->assertSame('driving', $entry->events()->latest('id')->first()->data['previous_kind']);
        $this->travel(3)->minutes();
        $entry = $clock->clock($entry->id, $entry->revision, 'pause', (string) Str::uuid(), $this->employee);
        $this->assertSame('shunting', $activities->pickerState($entry)['previous_kind']);
        $this->travel(5)->minutes();
        $entry = $clock->clock($entry->id, $entry->revision, 'resume', (string) Str::uuid(), $this->employee);
        $state = $activities->pickerState($entry);
        $this->assertSame('shunting', $state['kind']);
        $this->assertFalse($state['can_correct_activity']);
        $this->assertSame(300, $entry->pause_seconds);
        $this->expectException(HttpException::class);
        $activities->change($entry->id, $entry->revision, 'waiting', (string) Str::uuid(), $this->employee, correctCurrent: true);
    }

    public function test_only_own_history_and_supported_published_duty_sections_are_suggested(): void
    {
        $clock = app(WorkTimeService::class);
        $activities = app(WorkTimeActivityService::class);
        $context = ['work_context' => 'internal', 'title' => 'Standardarbeit', 'timezone' => 'Europe/Berlin'];
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $otherEntry = $clock->startContext($context, (string) Str::uuid(), $other);
        $activities->change($otherEntry->id, $otherEntry->revision, 'training', (string) Str::uuid(), $other);
        $entry = $clock->startContext($context, (string) Str::uuid(), $this->employee);
        $customer = Customer::create(['company_name' => 'Testbetrieb', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Uhr-Test', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'required_staff' => 1]);
        $shift = Shift::forceCreate(['order_id' => $order->id, 'title' => 'Dienst West', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addHours(8), 'role_name' => 'Betrieb', 'status' => 'open', 'required_staff' => 1, 'planned_break_minutes' => 30, 'revision' => 2, 'published_revision' => 2, 'published_snapshot' => ['sections' => [
            ['kind' => 'break'], ['kind' => 'travel'], ['kind' => 'shunting'], ['kind' => 'travel'], ['kind' => 'preparation'], ['kind' => 'handover'],
        ]]]);
        $assignment = ShiftAssignment::forceCreate(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'plan_revision' => 2]);
        $state = $activities->pickerState($entry, $assignment->id);
        $this->assertSame(['driving', 'shunting', 'preparation'], $state['suggested_kinds']);
        $this->assertSame(['internal'], $state['recent_kinds']);
        $this->assertSame([], $activities->pickerState($otherEntry, $assignment->id)['suggested_kinds']);
    }
}
