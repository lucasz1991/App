<?php

namespace Tests\Feature;

use App\Livewire\Operations\DistributionAutoAssignment;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\DistributionAutoAssignmentService;
use App\Services\Routing\TravelAssessmentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DistributionAutoAssignmentTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $anna;

    private User $ben;

    private Customer $customer;

    private Order $order;

    private DistributionAutoAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        // The tested manager is not the historical ID1 superadmin fallback.
        User::factory()->create(['role' => 'guest', 'status' => true]);
        $this->manager = User::factory()->create(['name' => 'Auto Disposition', 'role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Alpha', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Auto Regeln', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $this->customer = Customer::create(['company_name' => 'Auto Kunde', 'is_active' => true]);
        $this->order = $this->orderFor($this->customer);
        $this->service = app(DistributionAutoAssignmentService::class);
        $this->actingAs($this->manager);
        $this->withoutVite();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('distribution-auto-assignment-'.getmypid()));
        Storage::disk('local')->makeDirectory('compiled-views');
        config(['view.compiled' => Storage::disk('local')->path('compiled-views')]);
    }

    private function orderFor(Customer $customer): Order
    {
        return Order::create(['customer_id' => $customer->id, 'title' => 'Auto Leistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-05-01T00:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
    }

    private function shift(array $changes = []): Shift
    {
        return Shift::create(array_merge(['order_id' => $this->order->id, 'title' => 'Auto Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id], $changes))->fresh();
    }

    private function preview(string $customer = 'all'): array
    {
        return $this->service->prepare('2027-05-10', '2027-05-16', $customer, $this->manager);
    }

    private function allocationComponent()
    {
        return Livewire::actingAs($this->manager)->test(DistributionAutoAssignment::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'customerFilter' => 'all']);
    }

    private function invalid(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected a guarded validation failure.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, collect($exception->errors())->flatten()->implode(' '));
        }
    }

    private function wish(User $user, string $kind): void
    {
        EmployeeAvailability::create(['user_id' => $user->id, 'kind' => $kind, 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
    }

    public function test_mount_prepare_and_reset_are_read_only_and_only_explicit_apply_creates_requested_assignments(): void
    {
        $shift = $this->shift(['required_staff' => 2]);
        $component = $this->allocationComponent()->assertSet('preview', [])->assertSet('outcome', [])
            ->assertViewHas('available', true)->assertViewHas('prepared', false)->assertViewHas('canApply', false);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, OperationAudit::count());
        $component->call('prepare')->assertHasNoErrors()->assertViewHas('prepared', true)->assertViewHas('canApply', true);
        $preview = $component->get('preview');
        $this->assertSame(2, $preview['summary']['planned_slots']);
        $this->assertSame([$this->anna->id, $this->ben->id], $preview['entries'][0]['user_ids']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, OperationAudit::count());
        $component->call('resetPreview')->assertSet('preview', [])->assertSet('outcome', []);
        $component->call('apply')->assertHasErrors('autoAssignment');
        $this->assertSame(0, ShiftAssignment::count());
        $component->call('prepare')->call('apply')->assertHasNoErrors()->assertSet('preview', [])
            ->assertSet('outcome.assigned', 2)->assertDispatched('operations-plan-changed');
        $this->assertSame(['requested'], ShiftAssignment::pluck('status')->map(fn ($status) => $status->value)->unique()->all());
        $this->assertSame(2, OperationAudit::where('action', 'assignment.saved')->count());
        $this->assertSame(0, $shift->fresh()->published_revision);
        $component->call('apply')->assertHasErrors('autoAssignment');
        $this->assertSame(2, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_preview_covers_the_complete_period_beyond_sidebar_pagination_and_is_deterministic(): void
    {
        for ($index = 0; $index < 26; $index++) {
            $day = CarbonImmutable::parse('2027-05-13')->addDays($index)->format('Y-m-d');
            $this->shift(['title' => 'Auto Dienst '.$index, 'starts_at' => $day.'T08:00', 'ends_at' => $day.'T10:00', 'planned_break_minutes' => 0]);
        }
        $first = $this->service->prepare('2027-05-12', '2027-06-07', 'all', $this->manager);
        $second = $this->service->prepare('2027-05-12', '2027-06-07', 'all', $this->manager);
        $this->assertTrue($first['valid']);
        $this->assertCount(26, $first['entries']);
        $this->assertSame(26, $first['summary']['shifts']);
        $this->assertSame(26, $first['summary']['planned_slots']);
        $this->assertSame($first['entries'], $second['entries']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_ranking_uses_all_active_staff_not_the_first_visible_block_and_respects_preferences(): void
    {
        for ($index = 0; $index < 24; $index++) {
            User::factory()->create(['name' => 'QA Staff '.$index, 'role' => 'staff', 'status' => true]);
        }
        $last = User::factory()->create(['name' => 'Zeta Preferred', 'role' => 'staff', 'status' => true]);
        $this->wish($last, 'preferred');
        $this->shift();
        $visibleIds = User::where('role', 'staff')->orderBy('name')->orderBy('id')->limit(24)->pluck('id')->all();
        $this->assertNotContains($last->id, $visibleIds);
        $preview = $this->preview();
        $this->assertSame([$last->id], $preview['entries'][0]['user_ids']);
        $this->assertSame('preferred', $preview['rows'][0]['wish']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_existing_requested_confirmed_declined_and_cancelled_rows_are_preserved_and_never_reactivated(): void
    {
        foreach (['requested', 'confirmed', 'declined', 'cancelled'] as $index => $status) {
            $day = '2027-05-'.(13 + $index);
            $shift = $this->shift(['starts_at' => $day.'T08:00', 'ends_at' => $day.'T16:00', 'required_staff' => 2]);
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->anna->id, 'status' => $status, 'assigned_by' => $this->manager->id]);
        }
        $before = ShiftAssignment::orderBy('id')->get()->map(fn ($item) => $item->getAttributes())->all();
        $preview = $this->preview();
        $this->assertTrue($preview['valid']);
        $this->assertCount(4, $preview['entries']);
        foreach ($preview['entries'] as $entry) {
            $this->assertSame([$this->ben->id], $entry['user_ids']);
        }
        $this->service->apply($preview, '2027-05-10', '2027-05-16', 'all', $this->manager);
        $after = ShiftAssignment::whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map(fn ($item) => $item->getAttributes())->all();
        $this->assertSame($before, $after);
        $this->assertSame(8, ShiftAssignment::count());
        $this->assertSame(0, Shift::where('published_revision', '>', 0)->count());
    }

    public function test_virtual_assignments_avoid_overlaps_and_report_unfilled_slots_without_partial_hidden_writes(): void
    {
        $this->shift(['required_staff' => 2]);
        $other = $this->shift();
        $preview = $this->preview();
        $this->assertTrue($preview['valid']);
        $this->assertCount(1, $preview['entries']);
        $this->assertSame(2, $preview['summary']['planned_slots']);
        $this->assertSame(1, $preview['summary']['remaining_slots']);
        $this->assertSame($other->id, $preview['remaining'][0]['shift_id']);
        $this->assertSame(1, $preview['remaining'][0]['slots']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_each_new_seat_checks_the_cumulative_shared_demand_capacity(): void
    {
        $demand = OrderDemand::create(['order_id' => $this->order->id, 'title' => 'Begrenzter Bedarf', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'maximum_staff' => 1, 'staffing_mode' => 'range', 'qualification_ids' => [], 'created_by' => $this->manager->id]);
        $shift = $this->shift(['required_staff' => 2]);
        $shift->forceFill(['order_demand_id' => $demand->id])->save();
        $this->assertSame($demand->id, $shift->fresh()->order_demand_id);
        $preview = $this->preview();
        $this->assertTrue($preview['valid']);
        $this->assertCount(1, $preview['entries'][0]['user_ids']);
        $this->assertSame(1, $preview['summary']['remaining_slots']);
        $this->assertStringContainsString('Höchstbedarf', $preview['remaining'][0]['reason']);
        $this->service->apply($preview, '2027-05-10', '2027-05-16', 'all', $this->manager);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_free_wishes_and_no_fit_or_empty_periods_remain_read_only_and_explain_remaining_places(): void
    {
        $empty = $this->preview();
        $this->assertSame([], $empty['entries']);
        $this->assertFalse($empty['valid']);
        $this->shift(['required_staff' => 2]);
        $this->wish($this->anna, 'unavailable');
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin']);
        $preview = $this->preview();
        $this->assertFalse($preview['valid']);
        $this->assertSame([], $preview['entries']);
        $this->assertSame(2, $preview['summary']['remaining_slots']);
        $this->assertStringContainsString('Freiwünsche', $preview['remaining'][0]['reason']);
        $this->invalid(fn () => $this->service->apply($preview, '2027-05-10', '2027-05-16', 'all', $this->manager), 'gültige Verteilung');
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_started_closed_and_other_customer_shifts_are_omitted_and_counted_without_touching_them(): void
    {
        $wanted = $this->shift();
        $this->shift(['starts_at' => '2027-05-12T06:00', 'ends_at' => '2027-05-12T14:00']);
        $this->shift(['status' => 'completed']);
        $closedOrder = $this->orderFor($this->customer);
        $closedOrder->status = 'cancelled';
        $closedOrder->save();
        $this->shift(['order_id' => $closedOrder->id]);
        $otherCustomer = Customer::create(['company_name' => 'Anderer Kunde', 'is_active' => true]);
        $this->shift(['order_id' => $this->orderFor($otherCustomer)->id]);
        $preview = $this->preview((string) $this->customer->id);
        $this->assertSame([$wanted->id], array_column($preview['entries'], 'shift_id'));
        $this->assertSame(1, $preview['summary']['omitted_started']);
        $this->assertSame(2, $preview['summary']['omitted_closed']);
        $this->assertSame(3, $preview['summary']['omitted_shifts']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_large_workloads_fail_clearly_before_candidate_ranking_instead_of_silently_truncating(): void
    {
        for ($index = 0; $index < 101; $index++) {
            $this->shift();
        }
        $this->invalid(fn () => $this->preview(), '100 offene Schichten');
        Shift::query()->delete();
        $this->shift(['required_staff' => 201]);
        $this->invalid(fn () => $this->preview(), '200 neue Einsatzplätze');
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, OperationAudit::count());
        Http::assertNothingSent();
    }

    public function test_range_and_customer_scope_changes_invalidate_the_prepared_distribution(): void
    {
        $this->shift();
        $preview = $this->preview();
        $this->invalid(fn () => $this->service->apply($preview, '2027-05-11', '2027-05-16', 'all', $this->manager), 'Zeitraum oder Kunde');
        $this->invalid(fn () => $this->service->apply($preview, '2027-05-10', '2027-05-16', (string) $this->customer->id, $this->manager), 'Zeitraum oder Kunde');
        $this->order->customer_id = Customer::create(['company_name' => 'Neu zugeordnet', 'is_active' => true])->id;
        $this->order->save();
        $scoped = $preview;
        $scoped['scope']['customer_filter'] = (string) $this->customer->id;
        $this->invalid(fn () => $this->service->apply($scoped, '2027-05-10', '2027-05-16', (string) $this->customer->id, $this->manager), 'Kundenbereich');
        $this->assertSame(0, ShiftAssignment::count());
    }

    #[DataProvider('staleChanges')]
    public function test_fresh_revision_capacity_absence_wish_or_existing_response_change_rejects_all_writes(string $change): void
    {
        $shift = $this->shift(['required_staff' => 2]);
        $preview = $this->preview();
        $selected = User::findOrFail($preview['entries'][0]['user_ids'][0]);
        match ($change) {
            'revision' => $shift->forceFill(['revision' => $shift->revision + 1])->save(),
            'capacity' => $shift->forceFill(['required_staff' => 1])->save(),
            'absence' => AbsenceRequest::create(['user_id' => $selected->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin']),
            'wish' => $this->wish($selected, 'unavailable'),
            'decline' => ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $selected->id, 'status' => 'declined', 'assigned_by' => $this->manager->id]),
        };
        $before = ShiftAssignment::count();
        $this->invalid(fn () => $this->service->apply($preview, '2027-05-10', '2027-05-16', 'all', $this->manager), $change === 'wish' ? 'Freiwunsch' : ($change === 'decline' ? 'Antworten' : 'Konflikte'));
        $this->assertSame($before, ShiftAssignment::count());
        $this->assertSame(0, ShiftAssignment::where('status', 'requested')->count());
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($change) => [$change], ['revision', 'capacity', 'absence', 'wish', 'decline']);
    }

    #[DataProvider('preflightChanges')]
    public function test_travel_preflights_are_outside_transactions_and_late_automatic_guard_changes_are_rechecked_under_locks(string $change): void
    {
        $shift = $this->shift();
        $otherCustomer = Customer::create(['company_name' => 'Late Kunde', 'is_active' => true]);
        $state = new \ArrayObject(['calls' => 0, 'levels' => []]);
        $this->app->bind(TravelAssessmentService::class, fn () => new class($state, $shift, $this->anna, $this->manager, $change, $otherCustomer) extends TravelAssessmentService
        {
            public function __construct(private \ArrayObject $state, private Shift $shift, private User $user, private User $actor, private string $change, private Customer $otherCustomer) {}

            public function preflightForEntries(array $entries): array
            {
                $this->state['calls']++;
                $this->state['levels'] = [...$this->state['levels'], DB::transactionLevel()];
                if ($this->state['calls'] === 2) {
                    match ($this->change) {
                        'decline' => ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->user->id, 'status' => 'declined', 'assigned_by' => $this->actor->id]),
                        'wish' => EmployeeAvailability::create(['user_id' => $this->user->id, 'kind' => 'unavailable', 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']),
                        'customer' => $this->shift->order->forceFill(['customer_id' => $this->otherCustomer->id])->save(),
                    };
                }

                return parent::preflightForEntries($entries);
            }
        });
        $preview = $this->preview((string) $this->customer->id);
        $this->invalid(fn () => $this->service->apply($preview, '2027-05-10', '2027-05-16', (string) $this->customer->id, $this->manager), match ($change) {
            'decline' => 'Antworten', 'wish' => 'Freiwunsch', 'customer' => 'Kundenbereich',
        });
        $this->assertSame([0, 0], $state['levels']);
        $this->assertSame($change === 'decline' ? ['declined'] : [], ShiftAssignment::pluck('status')->map(fn ($status) => $status->value)->all());
        $this->assertSame(0, OperationAudit::where('action', 'assignment.saved')->count());
        Http::assertNothingSent();
    }

    public static function preflightChanges(): array
    {
        return [['decline'], ['wish'], ['customer']];
    }

    public function test_reactive_scope_changes_clear_preview_and_an_old_success_outcome(): void
    {
        $this->shift();
        $component = $this->allocationComponent()->call('prepare');
        $instance = $component->instance();
        $instance->from = '2027-05-11';
        $instance->hydrate();
        $this->assertSame([], $instance->preview);
        $this->assertSame([], $instance->outcome);

        $applied = $this->allocationComponent()->call('prepare')->call('apply')->assertSet('outcome.assigned', 1);
        $instance = $applied->instance();
        $instance->customerFilter = (string) $this->customer->id;
        $instance->hydrate();
        $this->assertSame([], $instance->preview);
        $this->assertSame([], $instance->outcome);
    }

    #[DataProvider('lockedProperties')]
    public function test_client_cannot_replace_prepared_payload_or_current_parent_scope(string $property, mixed $value): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->allocationComponent()->set($property, $value);
    }

    public static function lockedProperties(): array
    {
        return [['preview', ['valid' => true, 'entries' => []]], ['outcome', ['assigned' => 12]], ['from', '2027-05-11'], ['until', '2027-05-20'], ['customerFilter', '2']];
    }

    public function test_fresh_revoked_or_inactive_actor_and_non_native_principal_cannot_prepare_or_apply(): void
    {
        $this->shift();
        $component = $this->allocationComponent()->call('prepare');
        $this->manager->role = 'staff';
        $this->manager->save();
        $component->call('apply')->assertForbidden();
        $this->assertSame(0, ShiftAssignment::count());
        $this->manager->forceFill(['role' => 'admin', 'status' => false])->save();
        Livewire::actingAs($this->manager)->test(DistributionAutoAssignment::class, ['from' => '2027-05-10', 'until' => '2027-05-16'])->assertForbidden();
        Livewire::actingAs(new GenericUser(['id' => $this->manager->id]))->test(DistributionAutoAssignment::class, ['from' => '2027-05-10', 'until' => '2027-05-16'])->assertForbidden();
        $this->assertSame(0, ShiftAssignment::count());
    }

    #[DataProvider('missingSchemas')]
    public function test_missing_wish_or_bulk_schema_is_unavailable_instead_of_assuming_wishes_unknown(string $table): void
    {
        Schema::drop($table);
        $this->allocationComponent()->assertViewHas('available', false)->assertViewHas('canApply', false)->call('prepare')->assertStatus(503);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public static function missingSchemas(): array
    {
        return [['employee_availabilities'], ['planning_teams']];
    }
}
