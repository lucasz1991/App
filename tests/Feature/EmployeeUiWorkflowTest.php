<?php

namespace Tests\Feature;

use App\Livewire\Operations\MyWork;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeUiWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_123000_extend_work_time_contexts.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->withoutVite();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Bus::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Europe/Berlin'));
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    private function assignment(string $status, string $title, string $starts, ?User $owner = null): ShiftAssignment
    {
        $customer = Customer::create(['company_name' => 'Synthetic employee UI', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic order', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDays(60), 'required_staff' => 1]);
        $shift = Shift::forceCreate(['order_id' => $order->id, 'title' => $title, 'role_name' => 'Wagenmeister', 'location_name' => 'Maschen', 'timezone' => 'Europe/Berlin', 'starts_at' => $starts, 'ends_at' => CarbonImmutable::parse($starts, 'Europe/Berlin')->addHours(4), 'status' => 'open', 'required_staff' => 1, 'planned_break_minutes' => 0, 'revision' => 1, 'published_revision' => 1]);

        return ShiftAssignment::forceCreate(['user_id' => ($owner ?? $this->employee)->id, 'shift_id' => $shift->id, 'status' => $status, 'plan_revision' => 1]);
    }

    public function test_overview_and_time_page_agree_on_missing_actual_times(): void
    {
        $this->assignment('confirmed', 'Zeitmeldung fehlt', '2026-11-05 08:00');
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class);
        $this->assertFalse($ui->viewData('glance')['time_complete']);
        $this->assertSame(1, $ui->viewData('glance')['time_issue_count']);
        $ui->call('showTab', 'time')->assertSee('Zeitmeldung fehlt');
        $this->assertFalse($ui->viewData('timeCompleteness')['complete']);
        $this->assertDatabaseCount('work_time_entries', 0);
    }

    public function test_request_filters_preserve_open_badge_and_own_published_history(): void
    {
        $open = $this->assignment('requested', 'Offene Anfrage', '2026-12-10 08:00');
        $accepted = $this->assignment('confirmed', 'Bestätigter Dienst', '2026-11-05 08:00');
        $declined = $this->assignment('declined', 'Abgelehnter Dienst', '2026-11-08 08:00');
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->assignment('confirmed', 'Fremder Dienst', '2026-11-08 08:00', $other);
        $this->assignment('confirmed', 'Alte Historie', '2026-07-01 08:00');
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class, ['initialTab' => 'requests'])
            ->assertSee('Offene Anfrage')->assertSee('Bestätigter Dienst')->assertSee('Abgelehnter Dienst')->assertDontSee('Fremder Dienst')->assertDontSee('Alte Historie');
        $this->assertSame(['all' => 3, 'open' => 1, 'answered' => 2], $ui->viewData('requestCounts'));
        $ui->assertViewHas('requestCount', 1)->call('showRequestFilter', 'answered')->assertDontSee('Offene Anfrage')->assertSee('Abgelehnter Dienst')->assertViewHas('requestCount', 1);
        $ui->call('openConsentRequest', 'shift', $declined->id, 1)->assertSee('Dienst abgelehnt.');
        $this->assertFalse($ui->viewData('selectedRequest')['can_respond']);
        $this->assertSame('requested', $open->fresh()->status->value);
        $this->assertSame('confirmed', $accepted->fresh()->status->value);
        $this->assertSame('declined', $declined->fresh()->status->value);
    }

    public function test_whole_day_absence_preserves_inclusive_last_day_across_dst(): void
    {
        Livewire::actingAs($this->employee)->test(MyWork::class, ['focus' => 'absences'])
            ->set('absence.kind', 'unavailable')->set('absenceFirstDay', '2026-10-25')->set('absenceLastDay', '2026-10-25')
            ->call('requestAbsence')->assertHasNoErrors()->assertSet('absenceOpen', false);
        $absence = AbsenceRequest::sole();
        $this->assertSame('2026-10-25 00:00', $absence->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 00:00', $absence->ends_at->format('Y-m-d H:i'));
        $this->assertSame(25 * 3600, $absence->ends_at->getTimestamp() - $absence->starts_at->getTimestamp());
        $this->assertSame('pending', $absence->status);
    }

    public function test_exact_time_absence_remains_available_and_invalid_dates_do_not_write(): void
    {
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class, ['focus' => 'absences']);
        $ui->set('absenceFirstDay', '2026-02-31')->set('absenceLastDay', '2026-02-31')->call('requestAbsence')->assertHasErrors('absenceFirstDay');
        $this->assertDatabaseCount('absence_requests', 0);
        $ui->set('absenceWholeDays', false)->set('absence', ['kind' => 'unavailable', 'starts_at' => '2026-11-12T12:00', 'ends_at' => '2026-11-12T15:30', 'timezone' => 'Europe/Berlin', 'note' => ''])
            ->call('requestAbsence')->assertHasNoErrors();
        $this->assertSame('15:30', AbsenceRequest::sole()->ends_at->format('H:i'));
    }

    public function test_manual_errors_are_bound_to_visible_fields_and_no_time_is_created(): void
    {
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openGeneralManual')
            ->set('generalManual.title', 'x')->set('generalManual.note', 'x')->call('saveGeneralManual')
            ->assertHasErrors(['generalManual.title', 'generalManual.note', 'generalManual.starts_at', 'generalManual.ends_at'])
            ->assertSeeHtml('aria-invalid="true"')->assertSeeHtml(Str::slug('generalManual.title').'-error');
        $this->assertDatabaseCount('work_time_entries', 0);
    }

    public function test_foreign_manual_shortcut_cannot_open_another_employees_assignment(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $assignment = $this->assignment('confirmed', 'Foreign', '2026-11-05 08:00', $other);
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openManualAssignment', $assignment->id)->assertNotFound();
        $this->assertDatabaseCount('work_time_entries', 0);
    }

    public function test_nonexistent_civil_day_is_not_silently_normalized(): void
    {
        Livewire::actingAs($this->employee)->test(MyWork::class, ['focus' => 'absences'])
            ->set('absence.kind', 'unavailable')->set('absence.timezone', 'Pacific/Apia')
            ->set('absenceFirstDay', '2011-12-30')->set('absenceLastDay', '2011-12-30')
            ->call('requestAbsence')->assertHasErrors('absenceFirstDay');
        $this->assertDatabaseCount('absence_requests', 0);
    }

    public function test_domain_date_errors_are_bound_to_the_form_field(): void
    {
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openGeneralManual')
            ->set('generalManual', ['work_context' => 'internal', 'title' => 'Besprechung', 'starts_at' => '2026-03-29T02:30', 'ends_at' => '2026-03-29T04:00', 'timezone' => 'Europe/Berlin', 'pause_minutes' => 0, 'note' => 'Nachtrag zur Prüfung'])
            ->call('saveGeneralManual')->assertHasErrors('generalManual.starts_at');
        $this->assertDatabaseCount('work_time_entries', 0);
    }

    public function test_stale_missing_duty_does_not_offer_an_unusable_manual_shortcut(): void
    {
        $assignment = $this->assignment('confirmed', 'Dienst in Überarbeitung', '2026-11-05 08:00');
        $assignment->shift->forceFill(['revision' => 2])->save();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class, ['initialTab' => 'time'])
            ->assertSee('Bitte den veröffentlichten Dienststand mit der Disposition prüfen.')
            ->assertDontSeeHtml('wire:click="openManualAssignment('.$assignment->id.')"');
        $ui->call('openManualAssignment', $assignment->id)->assertNotFound();
        $this->assertDatabaseCount('work_time_entries', 0);
    }

    public function test_unapplied_or_invalid_period_keeps_list_tasks_and_csv_on_the_same_period(): void
    {
        $november = $this->timeEntry('Interne Arbeit November', '2026-11-04', 'completed');
        $october = $this->timeEntry('Interne Arbeit Oktober', '2026-10-18', 'returned');
        $oldDuty = $this->assignment('confirmed', 'Alter Dienst', '2026-06-04 08:00');
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class, ['initialTab' => 'time']);
        $ui->set('timePeriod', ['from' => '2026-10-01', 'until' => ''])->call('applyTimePeriod')
            ->assertHasErrors('timePeriod.until')->assertSet('timeFrom', '2026-11-01')
            ->assertViewHas('times', fn ($rows) => $rows->pluck('id')->all() === [$november->id])
            ->assertViewHas('timeCompleteness', fn ($result) => array_column($result['unfinished_times'], 'id') === [$november->id]);
        $response = $ui->instance()->exportOwnWorkTimes(app(OperationsReportService::class));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('Interne Arbeit November', $csv);
        $this->assertStringNotContainsString('Interne Arbeit Oktober', $csv);

        $ui->set('timePeriod.until', '2026-09-30')->call('applyTimePeriod')->assertHasErrors('timePeriod.until');
        $ui->set('timePeriod.until', '2026-10-31')->call('applyTimePeriod')->assertHasNoErrors()
            ->assertDispatched('personal-time-period-applied')
            ->assertViewHas('times', fn ($rows) => $rows->pluck('id')->all() === [$october->id]);
        $ui->set('timePeriod', ['from' => '2026-06-01', 'until' => '2026-06-30'])->call('applyTimePeriod')
            ->call('openManualAssignment', $oldDuty->id)->assertSet('manualAssignmentId', $oldDuty->id);
        $ui->call('openCurrentTimeIssues')->assertSet('timeFrom', '2026-11-01')->assertSet('timeUntil', '2026-11-06')
            ->assertSet('timePeriod.from', '2026-11-01')->assertSet('manualOpen', false)->assertDispatched('personal-time-issues-opened');
    }

    public function test_status_filter_is_exact_and_each_unfinished_entry_has_its_own_action(): void
    {
        $returned = $this->timeEntry('Rückgabe', '2026-11-03', 'returned');
        $completed = $this->timeEntry('Bereit', '2026-11-04', 'completed');
        $submitted = $this->timeEntry('Übergeben', '2026-11-02', 'submitted');
        $this->timeEntry('Freigabe', '2026-11-01', 'approved');
        $this->timeEntry('Export', '2026-11-05', 'exported');
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class, ['initialTab' => 'time'])
            ->call('showTimeFilter', 'submitted')->assertViewHas('times', fn ($rows) => $rows->pluck('id')->all() === [$submitted->id])
            ->assertSeeHtml('editTime('.$returned->id.', 1)')->assertSeeHtml('clock('.$completed->id.', 1,')
            ->assertViewHas('timeCompleteness', fn ($result) => count($result['unfinished_times']) === 2);
        $returned->update(['revision' => 2]);
        $ui->call('editTime', $returned->id, 1)->assertHasErrors('workflow')->assertSet('correctionOpen', false);
        $ui->call('editTime', $returned->id, 2)->assertSet('correctingRevision', 2)
            ->assertViewHas('correctingEntry', fn ($entry) => $entry->is($returned));
    }

    public function test_clock_duty_and_conflicts_are_available_from_both_workday_views(): void
    {
        $this->assignment('requested', 'Offene Anfrage', '2026-11-06 07:00');
        $own = $this->assignment('confirmed', 'Bestätigter Dienst', '2026-11-06 08:00');
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)
            ->assertViewHas('clockDuty', fn ($item) => $item?->id === $own->id)
            ->call('showCaptureConflicts', 'ungültig')->assertSet('tab', 'time')
            ->assertDispatched('personal-time-conflicts-opened', eventKey: null)
            ->assertViewHas('clockDuty', fn ($item) => $item?->id === $own->id);
        $own->shift->update(['revision' => 2]);
        $ui->call('$refresh')->assertViewHas('clockDuty', null);
    }

    private function timeEntry(string $title, string $date, string $status): WorkTimeEntry
    {
        return WorkTimeEntry::create([
            'user_id' => $this->employee->id, 'work_context' => 'internal', 'status' => $status,
            'timezone' => 'Europe/Berlin', 'starts_at' => $date.' 08:00', 'ends_at' => $date.' 12:00',
            'plan_snapshot' => ['title' => $title],
        ]);
    }
}
