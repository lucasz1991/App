<?php

namespace Tests\Feature;

use App\Livewire\Operations\MyWork;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonalCalendarTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 08:00', 'Europe/Berlin'));
        config(['operations.display_timezone' => 'Europe/Berlin']);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    private function assignment(?User $user = null, array $values = []): ShiftAssignment
    {
        $customer = Customer::create(['company_name' => 'Persönlicher Kunde', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Leistung', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDays(30), 'required_staff' => 1]);
        $shift = Shift::forceCreate(array_merge(['order_id' => $order->id, 'title' => 'Mein veröffentlichter Dienst', 'role_name' => 'Tf', 'location_name' => 'Hamburg', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-09-17 10:00', 'ends_at' => '2026-09-17 18:00', 'status' => 'open', 'required_staff' => 1, 'planned_break_minutes' => 30, 'revision' => 1, 'published_revision' => 1], $values));

        return ShiftAssignment::forceCreate(['user_id' => ($user ?? $this->employee)->id, 'shift_id' => $shift->id, 'status' => 'requested', 'plan_revision' => 1]);
    }

    private function absence(?User $user = null, array $values = []): AbsenceRequest
    {
        return AbsenceRequest::create(array_merge(['user_id' => ($user ?? $this->employee)->id, 'kind' => 'vacation', 'status' => 'pending', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-09-18 00:00', 'ends_at' => '2026-09-20 00:00', 'note' => 'Meine persönliche Notiz'], $values));
    }

    public function test_calendar_views_contain_only_own_visible_events_with_shared_controls(): void
    {
        $this->assignment();
        $ownAbsence = $this->absence();
        $other = User::factory()->create(['role' => 'staff']);
        $this->assignment($other, ['title' => 'Fremder Dienst']);
        $this->absence($other, ['note' => 'Fremde Notiz']);
        $this->assignment(null, ['title' => 'Unveröffentlicht', 'published_revision' => 0]);
        $this->absence(null, ['status' => 'rejected']);
        $test = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule');
        foreach (['day', 'week', 'month', 'list'] as $view) {
            $test->call('switchView', $view)->assertSee('Mein veröffentlichter Dienst')
                ->assertDontSee('Fremder Dienst')->assertDontSee('Fremde Notiz')->assertDontSee('Unveröffentlicht')
                ->assertSeeHtml('data-calendar-view="'.$view.'"')
                ->assertSeeHtml('personal-calendar-view-toggle')->assertSeeHtml('personal-calendar-anchor-date');
            $this->assertCount($view === 'day' ? 1 : 2, $test->viewData('calendarEvents'));
        }
        $test->call('openCalendarEvent', 'absence-'.$ownAbsence->id)->assertSet('calendarEventOpen', true)
            ->assertSee('Meine persönliche Notiz')->assertSeeHtml('role="dialog"');
    }

    public function test_navigation_date_validation_and_deep_link(): void
    {
        $test = Livewire::withQueryParams(['tab' => 'schedule'])->actingAs($this->employee)->test(MyWork::class)
            ->assertSet('tab', 'schedule')->call('switchView', 'month')->set('anchorDate', '2027-01-31')
            ->call('nextPeriod')->assertSet('anchorDate', '2027-02-28')
            ->call('previousPeriod')->assertSet('anchorDate', '2027-01-28')
            ->call('showDay', '2026-09-18')->assertSet('viewMode', 'day')
            ->call('previousPeriod')->assertSet('anchorDate', '2026-09-17')
            ->call('switchView', 'week')->call('nextPeriod')->assertSet('anchorDate', '2026-09-24')
            ->call('today')->assertSet('anchorDate', '2026-09-17');
        $test->set('anchorDate', '2026-02-31')->assertHasErrors('anchorDate');
        $test->call('today')->assertHasNoErrors('anchorDate');
        $test->call('switchView', 'bad')->assertNotFound();
    }

    public function test_details_reauthorize_own_shift_and_close_after_decline_or_withdrawal(): void
    {
        $assignment = $this->assignment();
        $absence = $this->absence();
        $test = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')
            ->call('openCalendarEvent', 'shift-'.$assignment->id)->assertSet('calendarEventOpen', true)
            ->assertSee('Dienst bestätigen')->call('respond', $assignment->id, 1, false)
            ->assertSet('calendarEventOpen', false)->assertDontSee('Mein veröffentlichter Dienst')
            ->call('openCalendarEvent', 'absence-'.$absence->id)->call('withdraw', $absence->id, 1)
            ->assertSet('calendarEventOpen', false);
        $this->assertCount(0, $test->viewData('calendarEvents'));
        $other = User::factory()->create(['role' => 'staff']);
        $foreign = $this->assignment($other);
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openCalendarEvent', 'shift-'.$foreign->id)->assertNotFound();
        $foreignAbsence = $this->absence($other);
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openCalendarEvent', 'absence-'.$foreignAbsence->id)->assertNotFound();
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openCalendarEvent', 'invalid')->assertNotFound();
    }

    public function test_stale_plan_details_keep_local_time_and_published_fields_without_actions(): void
    {
        $assignment = $this->assignment();
        $shift = $assignment->shift;
        $snapshot = $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']);
        $shift->forceFill(['published_snapshot' => $snapshot, 'revision' => 2, 'title' => 'Geheimer Entwurf', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(8)])->save();
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')
            ->call('openCalendarEvent', 'shift-'.$assignment->id)->assertSee('Mein veröffentlichter Dienst')
            ->assertSee('10:00')->assertSee('Planänderung in Prüfung')->assertDontSee('Geheimer Entwurf')
            ->assertDontSee('Dienst bestätigen')->assertDontSee('Dienst starten')
            ->call('nextPeriod')->assertSet('calendarEventOpen', false);
        $this->assertSame(1, $shift->fresh()->published_revision);
    }

    public function test_day_ranges_cover_dst_and_exclude_exact_end_boundary(): void
    {
        foreach (['2026-03-29', '2026-10-25'] as $day) {
            $from = CarbonImmutable::parse($day, 'Europe/Berlin');
            $assignment = $this->assignment(null, ['starts_at' => $from->subHour(), 'ends_at' => $from->addDay()]);
            $test = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')->call('showDay', $day);
            $this->assertSame(['shift-'.$assignment->id], $test->viewData('calendarEvents')->pluck('id')->all());
            $this->assertCount(1, $test->viewData('calendarDays'));
            $test->call('nextPeriod');
            $this->assertCount(0, $test->viewData('calendarEvents'));
        }
    }

    public function test_inactive_staff_and_administrators_cannot_use_personal_scope(): void
    {
        foreach ([['role' => 'staff', 'status' => false], ['role' => 'admin', 'status' => true]] as $values) {
            Livewire::actingAs(User::factory()->create($values))->test(MyWork::class)->assertForbidden();
        }
    }
}
