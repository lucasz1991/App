<?php

namespace Tests\Feature;

use App\Livewire\Operations\MyWork;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\Order;
use App\Models\PersonnelTraining;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftSection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonalCalendarTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = sys_get_temp_dir().'/railtime-calendar-dialog-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 08:00', 'Europe/Berlin'));
        config(['operations.display_timezone' => 'Europe/Berlin']);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            if (str_starts_with($this->compiledViews, sys_get_temp_dir().'/railtime-calendar-dialog-')) {
                File::deleteDirectory($this->compiledViews);
            }
            parent::tearDown();
            putenv($this->previousCompiledEnvironment[0] === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$this->previousCompiledEnvironment[0]);
            foreach (['_ENV', '_SERVER'] as $index => $environment) {
                if ($this->previousCompiledEnvironment[$index + 1] === null) {
                    unset($GLOBALS[$environment]['VIEW_COMPILED_PATH']);
                } else {
                    $GLOBALS[$environment]['VIEW_COMPILED_PATH'] = $this->previousCompiledEnvironment[$index + 1];
                }
            }
        }
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

    public function test_today_overview_shows_own_mini_calendar_and_duty_map_and_jumps_to_the_day(): void
    {
        $this->assignment();
        $other = User::factory()->create(['role' => 'staff']);
        $this->assignment($other, ['title' => 'Fremder Dienst', 'location_name' => 'München Nord']);
        $test = Livewire::actingAs($this->employee)->test(MyWork::class)->assertSet('tab', 'today')
            ->assertSee('Einsatzorte')->assertSee('Hamburg')->assertDontSee('Fremder Dienst')->assertDontSee('München Nord');
        $calendar = $test->viewData('miniCalendar');
        $this->assertSame('September 2026', $calendar['label']);
        $day = collect($calendar['days'])->first(fn ($day) => $day['date']->toDateString() === '2026-09-17');
        $this->assertSame([1, true, true], [$day['duties'], $day['open'], $day['is_today']]);
        $this->assertSame(['Hamburg'], array_column($test->viewData('dutyMap')['points'], 'label'));
        $test->call('shiftMiniMonth', 1)->assertSet('miniMonth', 1);
        $this->assertSame('Oktober 2026', $test->viewData('miniCalendar')['label']);
        $test->call('shiftMiniMonth', 0)->assertSet('miniMonth', 0)
            ->call('openDay', '2026-09-18')->assertSet('tab', 'schedule')->assertSet('viewMode', 'day')->assertSet('anchorDate', '2026-09-18');
        $test->call('openDay', 'kein-datum')->assertNotFound();
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

    public function test_calendar_shift_uses_the_shared_dialog_with_native_actions_and_one_opening_audit(): void
    {
        $assignment = $this->assignment();
        $this->absence();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')->call('openCalendarEvent', 'shift-'.$assignment->id)
            ->assertSeeHtml('data-personal-request-dialog')->assertSeeHtml('data-personal-request-map')->assertSeeHtml('data-request-calendar')
            ->assertSeeHtml('wire:key="personal-calendar-shift-dialog-')->assertDontSeeHtml('wire:key="personal-calendar-other-dialog-')
            ->assertSeeHtml('data-has-activity="false"')->assertSee('Dein Dienst')->assertSee('Dienst bestätigen')->assertDontSeeHtml('wire:click="respondToRequest(true)"');
        $this->assertStringContainsString(".entangle('calendarEventOpen').live", html_entity_decode($ui->html(), ENT_QUOTES));
        $selected = $ui->viewData('selectedCalendarRequest');
        $this->assertFalse($selected['can_respond']);
        $this->assertSame('', $selected['notice']);
        $this->assertSame($assignment->id, $selected['row']['id']);
        $this->assertSame('requested', $selected['row']['status']);
        $this->assertSame('Hamburg', $selected['row']['location_preview']['place']);
        $this->assertCount(2, $ui->viewData('selectedCalendarContext')['events']);
        $this->assertSame(1, OperationAudit::where('action', 'assignment.opened')->where('subject_id', $assignment->id)->count());
        $assignment->forceFill(['status' => 'confirmed'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 10:15', 'Europe/Berlin'));
        $ui->call('$refresh')->assertSee('Bestätigt')->assertSee('Dienst starten')->assertDontSee('Dienst bestätigen');
        $this->assertSame('Dienst', $ui->viewData('selectedCalendarRequest')['row']['label']);
        $this->assertSame('confirmed', $ui->viewData('selectedCalendarRequest')['row']['status']);
        $this->assertSame(1, OperationAudit::where('action', 'assignment.opened')->where('subject_id', $assignment->id)->count());
        $ui->call('closeCalendarEvent')->assertSet('calendarEventOpen', false)->assertSet('selectedCalendarEventId', null)
            ->assertSeeHtml('wire:key="personal-calendar-other-dialog-')->assertDontSeeHtml('wire:key="personal-calendar-shift-dialog-');
        $this->assertNull($ui->viewData('selectedCalendarRequest'));
        $this->assertNull($ui->viewData('selectedCalendarContext'));
    }

    public function test_calendar_shift_changes_come_from_its_owned_published_revision(): void
    {
        $assignment = $this->assignment(null, ['revision' => 2, 'published_revision' => 2]);
        $assignment->forceFill(['plan_revision' => 2])->save();
        $change = ['label' => 'Einsatzort', 'before' => 'Public previous location', 'after' => 'Public released location'];
        foreach ([[1, []], [2, ['changes' => [$change]]]] as [$revision, $data]) {
            OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => $assignment->shift_id, 'actor_id' => $this->employee->id, 'action' => 'shift.published', 'revision' => $revision, 'data' => $data, 'created_at' => now()]);
        }
        OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => $assignment->shift_id, 'actor_id' => $this->employee->id, 'action' => 'shift.changed', 'revision' => 3, 'data' => ['changes' => [['label' => 'Private draft difference', 'before' => '', 'after' => 'Secret']]], 'created_at' => now()]);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')->call('openCalendarEvent', 'shift-'.$assignment->id)
            ->assertSee('Was sich geändert hat')->assertSee('Public previous location')->assertSee('Public released location')->assertDontSee('Private draft difference');
        $this->assertSame([$change], $ui->viewData('selectedCalendarRequest')['changes']);
        $this->assertSame(2, $ui->viewData('selectedCalendarRequest')['row']['revision']);
    }

    public function test_calendar_service_tab_preserves_only_the_published_employee_activity(): void
    {
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        $assignment = $this->assignment();
        $shift = $assignment->shift;
        $publishedSection = ['id' => 9, 'kind' => 'preparation', 'label' => 'Own published preparation', 'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->starts_at->addHour()->toIso8601String(), 'timezone' => 'Europe/Berlin'];
        $shift->forceFill(['published_snapshot' => $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']) + ['sections' => [$publishedSection]], 'revision' => 2])->save();
        ShiftSection::create(['shift_id' => $shift->id, 'kind' => 'travel', 'label' => 'Private current draft activity', 'starts_at' => $shift->starts_at, 'ends_at' => $shift->starts_at->addHour(), 'timezone' => 'Europe/Berlin']);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')->call('openCalendarEvent', 'shift-'.$assignment->id)
            ->assertSeeHtml('data-has-activity="true"')->assertSee('Own published preparation')->assertDontSee('Private current draft activity')
            ->assertSee('Planänderung in Prüfung')->assertDontSee('Dienst bestätigen')->assertDontSee('Dienst starten');
        $this->assertFalse($ui->viewData('selectedCalendarRequest')['can_respond']);
        $this->assertSame(1, $ui->viewData('selectedCalendarRequest')['row']['revision']);
        $this->assertNotSame('', $ui->viewData('selectedCalendarRequest')['notice']);
        $this->assertStringContainsString('-activity', $ui->html());
    }

    public function test_absence_and_training_keep_their_existing_modal_without_shift_adapters(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $absence = $this->absence();
        $training = PersonnelTraining::create(['title' => 'Own personal training', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(4), 'timezone' => 'Europe/Berlin', 'capacity' => 5, 'status' => 'scheduled', 'created_by' => $this->employee->id]);
        $training->participants()->create(['user_id' => $this->employee->id, 'status' => 'confirmed', 'created_by' => $this->employee->id]);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')->call('openCalendarEvent', 'absence-'.$absence->id)
            ->assertSee('Meine persönliche Notiz')->assertSee('Antrag zurückziehen')->assertDontSeeHtml('data-personal-request-map');
        $this->assertNull($ui->viewData('selectedCalendarRequest'));
        $this->assertNull($ui->viewData('selectedCalendarContext'));
        $ui->call('openCalendarEvent', 'training-'.$training->id)->assertSee('Own personal training')->assertDontSee('Antrag zurückziehen')->assertDontSeeHtml('data-personal-request-map');
        $this->assertNull($ui->viewData('selectedCalendarRequest'));
        $this->assertNull($ui->viewData('selectedCalendarContext'));
        $this->assertSame(0, OperationAudit::where('action', 'assignment.opened')->count());
    }
}
