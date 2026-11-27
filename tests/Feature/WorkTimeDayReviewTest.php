<?php

namespace Tests\Feature;

use App\Livewire\Operations\WorkTimeDayReview;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\WorkTimeDayReviewService;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkTimeDayReviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;
    private WorkTimeEntry $entry;
    private Order $order;
    private WorkTimeDayReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_123000_extend_work_time_contexts.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->travelTo($this->at('16:31'));
        $customer = Customer::create(['company_name' => 'Test', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Tagesprüfung', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => $this->at('08:00'), 'ends_at' => $this->at('16:30'), 'required_staff' => 1]);
        $this->entry = WorkTimeEntry::create([
            'user_id' => $this->employee->id, 'work_context' => 'internal', 'status' => 'completed',
            'starts_at' => $this->at('08:00'), 'ends_at' => $this->at('16:30'), 'timezone' => 'Europe/Berlin',
            'pause_seconds' => 1800, 'plan_snapshot' => ['day_clock' => true, 'title' => 'Arbeitszeit'],
        ]);
        $this->entry->activities()->create(['kind' => 'break', 'starts_at' => $this->at('12:00'), 'ends_at' => $this->at('12:30')]);
        $this->service = app(WorkTimeDayReviewService::class);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-27 '.$time, 'Europe/Berlin');
    }

    private function duty(string $title = 'Dienst West', string $start = '10:00', string $end = '14:00'): ShiftAssignment
    {
        $shift = Shift::forceCreate(['order_id' => $this->order->id, 'title' => $title, 'role_name' => 'Betrieb',
            'starts_at' => $this->at($start), 'ends_at' => $this->at($end), 'timezone' => 'Europe/Berlin',
            'required_staff' => 1, 'status' => 'open', 'revision' => 1, 'published_revision' => 1]);

        return ShiftAssignment::forceCreate(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'plan_revision' => 1]);
    }

    public function test_plan_overlap_excludes_breaks_and_edited_allocation_submits_one_daily_entry(): void
    {
        $duty = $this->duty();
        $pending = app(WorkTimeExtensionService::class)->completeness($this->employee, '2026-11-27', '2026-11-27', $this->employee);
        $this->assertSame([], $pending['missing_shift_times']);
        $this->assertSame([$this->entry->id], array_column($pending['unfinished_times'], 'id'));
        Livewire::actingAs($this->employee)->test(WorkTimeDayReview::class, ['entryId' => $this->entry->id])
            ->assertSee('Tagesbeleg prüfen')->assertSee('8:00 h')->assertSee('4:30 h')->assertSee('3:30 h')
            ->call('edit')->set('fields.'.$duty->id.'.starts_at', '2026-11-27T10:30')
            ->call('apply')->assertHasNoErrors()->assertSee('5:00 h')->assertSee('3:00 h')
            ->call('confirm')->assertHasNoErrors()->assertDispatched('work-time-day-confirmed');
        $entry = $this->entry->fresh();
        $this->assertSame('submitted', $entry->status);
        $review = $entry->plan_snapshot['day_review'];
        $this->assertSame(28800, $review['net_seconds']);
        $this->assertSame(10800, $review['duties'][0]['seconds']);
        $this->assertSame(18000, $review['standard_seconds']);
        $this->assertDatabaseCount('work_time_entries', 1);
        $this->assertSame($review, $entry->revisions()->firstOrFail()->snapshot['plan_snapshot']['day_review']);
        $completeness = app(WorkTimeExtensionService::class)->completeness($this->employee, '2026-11-27', '2026-11-27', $this->employee);
        $this->assertSame([], $completeness['missing_shift_times']);
    }

    public function test_actual_mark_and_absent_duty_can_be_confirmed_once(): void
    {
        $duty = $this->duty();
        $this->entry->events()->create(['kind' => 'duty_start', 'actor_id' => $this->employee->id, 'event_key' => (string) Str::uuid(),
            'occurred_at' => $this->at('10:30'), 'received_at' => $this->at('10:30'), 'data' => [
                'assignment_id' => $duty->id, 'title' => 'Dienst West', 'planned_starts_at' => $this->at('10:00')->toIso8601String(), 'planned_ends_at' => $this->at('14:00')->toIso8601String(),
            ]]);
        $unused = $this->duty('Dienst Süd', '13:30', '14:30');
        $draft = $this->service->draft($this->entry);
        $this->assertSame('2026-11-27T10:30:00', $draft['fields'][$duty->id]['starts_at']);
        $this->assertStringContainsString('überschneiden', $this->service->calculate($this->entry, $draft, $draft['fields'])['issue']);
        $fields = $draft['fields'];
        $fields[$unused->id]['performed'] = false;
        $key = (string) Str::uuid();
        $this->service->confirm($this->entry->id, 1, $draft['basis'], $fields, $key, $this->employee);
        $entry = $this->service->confirm($this->entry->id, 1, $draft['basis'], $fields, $key, $this->employee);
        $this->assertSame(10800, $entry->plan_snapshot['day_review']['duties'][0]['seconds']);
        $this->assertSame(18000, $entry->plan_snapshot['day_review']['standard_seconds']);
        $this->assertDatabaseCount('work_time_revisions', 1);
        $this->assertSame([$duty->id, $unused->id], $this->service->confirmedAssignmentIds($this->employee->id, [$duty->id, $unused->id]));
    }

    public function test_standard_work_only_uses_the_existing_submission_workflow(): void
    {
        $draft = $this->service->draft($this->entry);
        $entry = $this->service->confirm($this->entry->id, 1, $draft['basis'], [], (string) Str::uuid(), $this->employee);
        $this->assertSame('submitted', $entry->status);
        $this->assertSame(28800, $entry->plan_snapshot['day_review']['standard_seconds']);
        $this->assertSame([], $entry->plan_snapshot['day_review']['duties']);
        $this->assertDatabaseCount('work_time_entries', 1);
    }

    public function test_unknown_pause_positions_stay_unassigned(): void
    {
        $draft = $this->service->draft($this->entry);
        $this->assertSame(28800, $this->service->calculate($this->entry, $draft, [])['standard_seconds']);
        $this->entry->activities()->delete();
        $draft = $this->service->draft($this->entry);
        $this->assertNotNull($draft['issue']);
        $this->expectException(ValidationException::class);
        $this->service->confirm($this->entry->id, 1, $draft['basis'], [], (string) Str::uuid(), $this->employee);
    }

    public function test_returned_day_allows_duty_only_correction_without_editing_attendance(): void
    {
        $duty = $this->duty();
        $draft = $this->service->draft($this->entry);
        $fields = $draft['fields'];
        $fields[$duty->id]['starts_at'] = '2026-11-27T10:30';
        $entry = $this->service->confirm($this->entry->id, 1, $draft['basis'], $fields, (string) Str::uuid(), $this->employee);
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        app(WorkTimeService::class)->review($entry->id, $entry->revision, false, 'Bitte den Dienstbeginn berichtigen.', $admin);
        Livewire::actingAs($this->employee)->test(WorkTimeDayReview::class, ['entryId' => $entry->id])
            ->assertSee('Bitte den Dienstbeginn berichtigen.')->assertSee('3:00 h')
            ->call('edit')->set('fields.'.$duty->id.'.starts_at', '2026-11-27T10:45')
            ->call('apply')->call('confirm')->assertHasNoErrors();
        $entry->refresh();
        $this->assertSame('submitted', $entry->status);
        $this->assertSame(2 * 3600 + 45 * 60, $entry->plan_snapshot['day_review']['duties'][0]['seconds']);
        $this->assertSame(28800, $entry->netSeconds());
        $this->assertDatabaseCount('work_time_entries', 1);
        $this->assertSame(['submitted', 'returned', 'submitted'], $entry->revisions()->orderBy('id')->pluck('action')->all());
    }

    public function test_foreign_assignment_payload_and_stale_proposal_do_not_book_any_time(): void
    {
        $duty = $this->duty();
        $draft = $this->service->draft($this->entry);
        $fields = $draft['fields'];
        $fields[9999] = $fields[$duty->id];
        try {
            $this->service->confirm($this->entry->id, 1, $draft['basis'], $fields, (string) Str::uuid(), $this->employee);
            $this->fail('Unexpected assignment accepted.');
        } catch (ValidationException) {
            $this->assertSame('completed', $this->entry->fresh()->status);
        }
        $this->duty('Neuer Dienst', '15:00', '16:00');
        $this->expectException(ValidationException::class);
        $this->service->confirm($this->entry->id, 1, $draft['basis'], $draft['fields'], (string) Str::uuid(), $this->employee);
    }

    public function test_foreign_user_cannot_open_the_day_review(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($other)->test(WorkTimeDayReview::class, ['entryId' => $this->entry->id]);
    }

    public function test_day_clock_cannot_be_submitted_without_confirming_the_current_allocation(): void
    {
        $this->expectException(ValidationException::class);
        app(WorkTimeService::class)->clock($this->entry->id, 1, 'submit', (string) Str::uuid(), $this->employee);
    }

    public function test_existing_rest_v2_client_can_start_stop_and_submit_an_ordinary_entry(): void
    {
        (require database_path('migrations/2019_12_14_000001_create_personal_access_tokens_table.php'))->up();
        config(['operations.api_enabled' => true]);
        $token = $this->employee->createToken('Work time compatibility test', ['operations:own:write'], now()->addDay());
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
        $base = '/api/v2/operations/me/work-times';
        $id = $this->postJson($base.'/start', ['work_context' => 'internal', 'title' => 'Bürozeit', 'timezone' => 'Europe/Berlin', 'event_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('schema_version', 2)->json('data.id');
        $this->travel(30)->minutes();
        $this->postJson($base.'/'.$id.'/events', ['revision' => 1, 'action' => 'stop', 'event_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson($base.'/'.$id.'/events', ['revision' => 2, 'action' => 'submit', 'event_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertArrayNotHasKey('day_clock', WorkTimeEntry::findOrFail($id)->plan_snapshot);
    }
}
