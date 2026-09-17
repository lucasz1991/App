<?php

namespace Tests\Feature\Operations;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Support\Operations\PersonalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonalScheduleTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $admin;

    private Order $order;

    private PersonalSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Released Rail', 'is_active' => true]);
        $this->order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Private current order title', 'notes' => 'Private order note',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2027-01-01T00:00:00Z'), 'required_staff' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        $this->actingAs($this->employee);
        $this->schedule = app(PersonalSchedule::class);
    }

    private function makeAssignment(array $shiftValues = [], array $assignmentValues = []): ShiftAssignment
    {
        $shift = Shift::forceCreate(array_merge([
            'order_id' => $this->order->id, 'title' => 'Published duty', 'role_name' => 'Tf',
            'status' => 'open', 'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg',
            'starts_at' => CarbonImmutable::parse('2026-09-17T06:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-09-17T14:00:00Z'),
            'required_staff' => 1, 'planned_break_minutes' => 30, 'revision' => 1, 'published_revision' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ], $shiftValues));

        return ShiftAssignment::forceCreate(array_merge([
            'shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed',
            'plan_revision' => 1, 'assigned_by' => $this->admin->id,
        ], $assignmentValues));
    }

    private function snapshot(Shift $shift): array
    {
        return [
            'order_id' => $shift->order_id, 'title' => $shift->title, 'role_name' => $shift->role_name,
            'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->ends_at->toISOString(),
            'timezone' => $shift->timezone, 'location_name' => $shift->location_name,
            'planned_break_minutes' => $shift->planned_break_minutes,
        ];
    }

    public function test_only_own_released_requested_or_confirmed_assignment_revisions_are_visible(): void
    {
        $confirmed = $this->makeAssignment();
        $requested = $this->makeAssignment([], ['status' => 'requested']);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $hidden = [
            $this->makeAssignment([], ['user_id' => $other->id]),
            $this->makeAssignment(['published_revision' => 0]),
            $this->makeAssignment(['status' => 'cancelled']),
            $this->makeAssignment([], ['status' => 'cancelled']),
            $this->makeAssignment([], ['status' => 'declined']),
            $this->makeAssignment(['revision' => 2], ['plan_revision' => 2]),
            $this->makeAssignment(['revision' => 1, 'published_revision' => 2], ['plan_revision' => 2]),
        ];
        $deleted = $this->makeAssignment();
        $deleted->shift->delete();
        $hidden[] = $deleted;
        $this->assertSame([$confirmed->id, $requested->id], $this->schedule->assignments($this->employee)->pluck('id')->all());
        foreach ($hidden as $record) {
            $this->assertNotFound($record->id);
        }
        $this->assertNotFound(999999);
    }

    public function test_stale_projection_uses_only_complete_published_values_and_published_order_without_writes(): void
    {
        $assignment = $this->makeAssignment();
        $snapshot = $this->snapshot($assignment->shift);
        $otherCustomer = Customer::create(['company_name' => 'Unreleased customer', 'is_active' => true]);
        $otherOrder = $this->order->replicate(['public_id', 'order_number']);
        $otherOrder->fill(['customer_id' => $otherCustomer->id, 'title' => 'Unreleased order']);
        $otherOrder->save();
        $assignment->shift->forceFill([
            'revision' => 2, 'published_snapshot' => $snapshot + ['notes' => 'Injected extra', 'required_staff' => 999],
            'title' => 'Unreleased title', 'role_name' => 'Unreleased role', 'location_name' => 'Unreleased location',
            'order_id' => $otherOrder->id, 'notes' => 'Unreleased notes', 'required_staff' => 999,
            'starts_at' => CarbonImmutable::parse('2026-11-03T15:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-11-03T20:00:00Z'), 'timezone' => 'America/New_York',
        ])->save();
        $before = (array) DB::table('shifts')->where('id', $assignment->shift_id)->first();
        $record = $this->schedule->assignment($this->employee, $assignment->id);

        $this->assertTrue($record->plan_is_stale);
        $this->assertSame('Published duty', $record->shift->title);
        $this->assertSame('Tf', $record->shift->role_name);
        $this->assertSame('Hamburg', $record->shift->location_name);
        $this->assertSame('Europe/Berlin', $record->shift->timezone);
        $this->assertSame('2026-09-17T06:00:00+00:00', $record->shift->starts_at->utc()->toIso8601String());
        $this->assertSame($this->order->id, $record->shift->order_id);
        $this->assertSame($this->order->id, $record->shift->order->id);
        $this->assertSame('Released Rail', $record->shift->order->customer->company_name);
        $this->assertArrayNotHasKey('notes', $record->shift->getAttributes());
        $this->assertArrayNotHasKey('required_staff', $record->shift->getAttributes());
        $this->assertArrayNotHasKey('published_snapshot', $record->shift->getAttributes());
        $this->assertArrayNotHasKey('title', $record->shift->order->getAttributes());
        $this->assertArrayNotHasKey('notes', $record->shift->order->getAttributes());
        $this->assertTrue($record->relationLoaded('timeEntry'));
        $this->assertSame($before, (array) DB::table('shifts')->where('id', $assignment->shift_id)->first());
        $this->assertDatabaseCount('operation_audits', 0);
    }

    public function test_missing_invalid_and_incomplete_stale_snapshots_fail_closed(): void
    {
        $assignment = $this->makeAssignment();
        $valid = $this->snapshot($assignment->shift);
        $incomplete = $valid;
        unset($incomplete['role_name']);
        foreach ([
            null, [], 'not-an-array', $incomplete,
            array_replace($valid, ['starts_at' => 'tomorrow']),
            array_replace($valid, ['starts_at' => '2026-02-31T12:00:00Z']),
            array_replace($valid, ['ends_at' => $valid['starts_at']]),
            array_replace($valid, ['timezone' => 'Unknown/Zone']),
            array_replace($valid, ['order_id' => 0]),
            array_replace($valid, ['title' => '']),
            array_replace($valid, ['planned_break_minutes' => -1]),
        ] as $snapshot) {
            $assignment->shift->forceFill(['revision' => 2, 'published_snapshot' => $snapshot])->save();
            $this->assertTrue($this->schedule->assignments($this->employee)->isEmpty());
            $this->assertNotFound($assignment->id);
        }
    }

    public function test_range_and_sorting_follow_released_time_not_current_draft_time(): void
    {
        $later = $this->makeAssignment(['starts_at' => CarbonImmutable::parse('2026-09-17T09:00:00Z')]);
        $early = $this->makeAssignment();
        $early->shift->forceFill([
            'revision' => 2, 'published_snapshot' => $this->snapshot($early->shift),
            'starts_at' => CarbonImmutable::parse('2026-12-01T08:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-12-01T12:00:00Z'),
        ])->save();
        $this->assertSame([$early->id, $later->id], $this->schedule->assignments($this->employee,
            CarbonImmutable::parse('2026-09-17T00:00:00Z'), CarbonImmutable::parse('2026-09-18T00:00:00Z'))
            ->pluck('id')->all());
        $this->assertTrue($this->schedule->assignments($this->employee, CarbonImmutable::parse('2026-12-01T00:00:00Z'))->isEmpty());
        $this->assertSame([$early->id], $this->schedule->assignments($this->employee, null, CarbonImmutable::parse('2026-09-17T08:00:00Z'))->pluck('id')->all());
        $this->assertTrue($this->schedule->assignments($this->employee, CarbonImmutable::parse('2026-09-18'), CarbonImmutable::parse('2026-09-17'))->isEmpty());
    }

    public function test_night_and_dst_intervals_use_exclusive_utc_boundaries(): void
    {
        foreach (['2026-03-29', '2026-10-25'] as $date) {
            $from = CarbonImmutable::parse($date, 'Europe/Berlin')->startOfDay();
            $until = $from->addDay();
            $inside = $this->makeAssignment(['starts_at' => $from->subHour(), 'ends_at' => $until]);
            // Use the same Carbon-to-JSON serialization as PlanPublicationService.
            $inside->shift->forceFill([
                'revision' => 2,
                'published_snapshot' => $inside->shift->only([
                    'order_id', 'title', 'role_name', 'starts_at', 'ends_at',
                    'timezone', 'location_name', 'planned_break_minutes',
                ]),
                'starts_at' => $from->addDays(3), 'ends_at' => $until->addDays(3),
            ])->save();
            $endsAtStart = $this->makeAssignment(['starts_at' => $from->subHours(2), 'ends_at' => $from]);
            $startsAtEnd = $this->makeAssignment(['starts_at' => $until, 'ends_at' => $until->addHour()]);
            $items = $this->schedule->assignments($this->employee, $from, $until);
            $this->assertSame([$inside->id], $items->pluck('id')->all());
            $this->assertFalse($items->contains('id', $endsAtStart->id));
            $this->assertFalse($items->contains('id', $startsAtEnd->id));
            $this->assertSame($until->utc()->timestamp, $items->first()->shift->ends_at->utc()->timestamp);
        }
    }

    public function test_scope_cannot_be_switched_to_another_authenticated_user_or_admin(): void
    {
        $this->makeAssignment();
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        foreach ([$other, $this->admin] as $actor) {
            try {
                $this->schedule->assignments($actor);
                $this->fail('Personal scope must remain with the employee.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->employee->forceFill(['status' => false])->save();
        $this->expectException(HttpException::class);
        $this->schedule->assignments($this->employee);
    }

    public function test_time_entry_relation_also_stays_in_the_employee_scope(): void
    {
        $assignment = $this->makeAssignment();
        $entry = WorkTimeEntry::create([
            'shift_assignment_id' => $assignment->id, 'user_id' => $this->employee->id,
            'starts_at' => CarbonImmutable::parse('2026-09-17T06:00:00Z'),
            'timezone' => 'Europe/Berlin', 'plan_snapshot' => $this->snapshot($assignment->shift),
        ]);
        $this->assertSame($entry->id, $this->schedule->assignment($this->employee, $assignment->id)->timeEntry->id);
        // Defensive protection if an imported legacy record is inconsistent.
        $entry->forceFill(['user_id' => $this->admin->id])->save();
        $this->assertNull($this->schedule->assignment($this->employee, $assignment->id)->timeEntry);
    }

    private function assertNotFound(int $id): void
    {
        try {
            $this->schedule->assignment($this->employee, $id);
            $this->fail('The assignment must not be exposed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }
}
