<?php

namespace Tests\Feature;

use App\Livewire\Operations\AttentionCenter;
use App\Livewire\Operations\MyWork;
use App\Livewire\Operations\PersonalWorkspace;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerCapacityReservation;
use App\Models\CustomerLocation;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PersonnelTraining;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonalRequestDashboardTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $other;

    private User $admin;

    private Customer $customer;

    private CustomerLocation $location;

    private string $compiledViews;

    private array $previousCompiledEnvironment;

    protected function setUp(): void
    {
        $this->previousCompiledEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        $this->compiledViews = sys_get_temp_dir().'/railtime-personal-requests-'.bin2hex(random_bytes(8));
        mkdir($this->compiledViews, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$this->compiledViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->compiledViews;
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-10-13T08:00:00+02:00'));
        config(['app.locale' => 'de', 'app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Request customer fixture', 'is_active' => true]);
        $this->location = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Request yard fixture', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->admin->id]);
        OperationsRuleProfile::create(['name' => 'Request rule fixture', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            if (str_starts_with($this->compiledViews, sys_get_temp_dir().'/railtime-personal-requests-')) {
                Filesystem::deleteDirectory($this->compiledViews);
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

    private function assignment(int $days = 2, array $values = [], ?User $employee = null): ShiftAssignment
    {
        $order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Request order fixture', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(200), 'required_staff' => 1]);
        $shift = Shift::forceCreate(array_replace(['order_id' => $order->id, 'title' => 'Requested duty '.$days, 'role_name' => 'Tf', 'location_name' => 'Request yard fixture', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->addDays($days)->startOfHour(), 'ends_at' => now()->addDays($days)->startOfHour()->addHours(8), 'status' => 'open', 'required_staff' => 1, 'planned_break_minutes' => 30, 'revision' => 1, 'published_revision' => 1], $values));

        return ShiftAssignment::forceCreate(['user_id' => ($employee ?? $this->employee)->id, 'shift_id' => $shift->id, 'status' => 'requested', 'plan_revision' => $shift->published_revision ?: 1]);
    }

    private function capacity(int $days = 1, array $values = []): CustomerCapacityCommitment
    {
        return CustomerCapacityCommitment::create(array_replace(['customer_id' => $this->customer->id, 'user_id' => $this->employee->id, 'location_id' => $this->location->id, 'role_name' => 'Capacity role fixture', 'starts_at' => now()->addDays($days), 'ends_at' => now()->addDays($days)->addHours(8), 'timezone' => 'Europe/Berlin', 'planned_break_minutes' => 30, 'qualification_ids' => [], 'status' => 'requested', 'revision' => 1, 'created_by' => $this->admin->id], $values));
    }

    private function deeplink(string $type, int $id, int $revision = 1): array
    {
        return ['tab' => 'today', 'request_type' => $type, 'request_id' => (string) $id, 'request_revision' => (string) $revision];
    }

    public function test_dashboard_contains_only_own_fresh_answerable_requests_even_beyond_fourteen_days(): void
    {
        $future = $this->assignment(120);
        $capacity = $this->capacity();
        $this->assignment(4, ['title' => 'Foreign confidential duty'], $this->other);
        $this->capacity(2, ['user_id' => $this->other->id, 'role_name' => 'Foreign confidential capacity']);
        $this->assignment(5, ['title' => 'Unpublished confidential draft', 'published_revision' => 0]);
        $this->assignment(-2, ['title' => 'Expired duty']);
        $this->capacity(-1, ['role_name' => 'Expired capacity']);
        $this->capacity(6, ['status' => 'consented', 'role_name' => 'Already answered capacity']);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->assertSet('tab', 'today')
            ->assertSeeHtml('data-workday-kpis')->assertDontSeeHtml('data-personal-requests')
            ->assertDontSee('Foreign confidential duty')->assertDontSee('Foreign confidential capacity')->assertDontSee('Unpublished confidential draft')
            ->assertSee('Dein Dienstplan')->assertSeeHtml('data-personal-workspace');
        $requests = $ui->viewData('pendingRequests');
        $this->assertSame(2, $requests->total());
        $this->assertSame([['capacity', $capacity->id], ['shift', $future->id]], $requests->getCollection()->map(fn ($row) => [$row['type'], $row['id']])->all());
        $this->assertCount(0, $ui->viewData('assignments'));
        $this->assertNotNull($ui->viewData('miniCalendar'));
        $ui->call('showTab', 'requests')->assertSeeHtml('data-personal-requests')
            ->assertSee('Requested duty 120')->assertSee('Capacity role fixture')
            ->assertDontSee('Foreign confidential duty')->assertDontSee('Foreign confidential capacity')->assertDontSee('Unpublished confidential draft');
    }

    public function test_today_combines_real_kpis_and_slim_actions_without_duplicate_navigation(): void
    {
        $this->assignment();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)
            ->assertSeeHtml('data-workday-kpis')->assertSee('Dienste')->assertSee('Zeiten')
            ->assertSee('Aufgaben')->assertSee('Anfragen')->assertSee('Dein Dienstplan');
        $html = $ui->html();
        $this->assertSame(1, substr_count($html, 'data-workday-kpis'));
        $this->assertStringNotContainsString('data-workday-shortcuts', $html);
        $this->assertStringNotContainsString('class="rt-today-stats', $html);
        $this->assertStringNotContainsString('class="rt-today-quick"', $html);
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $actions = (new DOMXPath($document))->query('//button[contains(concat(" ", normalize-space(@class), " "), " rt-workday-kpi ")]');
        $this->assertSame(4, $actions->length);
        foreach ($actions as $action) {
            $this->assertFalse($action->hasAttribute('disabled'));
        }
        $ui->call('showTab', 'schedule')->assertSet('tab', 'schedule')->assertSee('Mein Kalender');
        $ui->call('showTab', 'today')->assertSet('tab', 'today')->assertSeeHtml('data-workday-kpis');
        $this->assertSame('requested', ShiftAssignment::first()->status->value);
    }

    public function test_work_overview_omits_back_info_row_but_retains_topbar_title(): void
    {
        Livewire::actingAs($this->employee)->test(PersonalWorkspace::class)
            ->assertSeeHtml('data-workday-title')->assertSeeHtml('rt-shell-page-title')
            ->assertDontSeeHtml('data-page-header')->assertSee('Übersicht');
        Livewire::withQueryParams(['area' => 'absences'])->actingAs($this->employee)->test(PersonalWorkspace::class)
            ->assertSeeHtml('data-page-header')->assertDontSeeHtml('data-workday-title');
    }

    public function test_request_pagination_keeps_all_later_requests_reachable(): void
    {
        $ids = collect(range(50, 57))->map(fn ($day) => $this->assignment($day)->id);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class);
        $this->assertSame(8, $ui->viewData('pendingRequests')->total());
        $this->assertSame($ids->take(6)->all(), $ui->viewData('pendingRequests')->getCollection()->pluck('id')->all());
        $ui->call('setPage', 2, 'requestsPage');
        $this->assertSame($ids->slice(6)->values()->all(), $ui->viewData('pendingRequests')->getCollection()->pluck('id')->all());
        $ui->call('openConsentRequest', 'shift', $ids->last(), 1)->assertSet('requestOpen', true);
    }

    public function test_notification_get_only_opens_the_exact_request_without_any_acceptance_or_open_audit(): void
    {
        $assignment = $this->assignment();
        $auditCount = OperationAudit::count();
        $ui = Livewire::withQueryParams($this->deeplink('shift', $assignment->id))->actingAs($this->employee)->test(MyWork::class)
            ->assertSet('requestOpen', true)->assertSet('requestType', 'shift')->assertSet('requestId', $assignment->id)->assertSet('requestRevision', 1)
            ->assertSeeHtml('data-personal-request-dialog')->assertSee('Dienst bestätigen');
        $this->assertTrue($ui->viewData('selectedRequest')['can_respond']);
        $this->assertStringContainsString(".entangle('requestOpen')", html_entity_decode($ui->html(), ENT_QUOTES));
        $this->assertStringNotContainsString(".entangle('')", html_entity_decode($ui->html(), ENT_QUOTES));
        $this->assertSame('requested', $assignment->fresh()->status->value);
        $this->assertNull($assignment->fresh()->responded_at);
        $this->assertSame($auditCount, OperationAudit::count());
    }

    public function test_workspace_forwards_only_request_parameters_and_preserves_today_default(): void
    {
        $assignment = $this->assignment();
        $query = $this->deeplink('shift', $assignment->id);
        Livewire::withQueryParams($query + ['accept' => 'true'])->actingAs($this->employee)->test(PersonalWorkspace::class)
            ->assertSet('area', 'work')->assertSet('initialTab', 'today')->assertSet('initialRequest', array_diff_key($query, ['tab' => true]))
            ->assertSeeHtml('data-personal-request-dialog');
        $this->assertSame('requested', $assignment->fresh()->status->value);
        Livewire::withQueryParams([])->actingAs($this->employee)->test(MyWork::class)->assertSet('tab', 'today')->assertSet('requestOpen', false);
    }

    public function test_changed_published_revision_requires_explicit_reopening_before_response(): void
    {
        $assignment = $this->assignment(2, ['revision' => 2, 'published_revision' => 2, 'title' => 'Updated published duty']);
        OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => $assignment->shift_id, 'action' => 'shift.published', 'revision' => 1, 'actor_id' => $this->admin->id, 'created_at' => now()->utc(), 'data' => []]);
        OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => $assignment->shift_id, 'action' => 'shift.published', 'revision' => 2, 'actor_id' => $this->admin->id, 'created_at' => now()->utc(), 'data' => ['changes' => [['field' => 'title', 'label' => 'Titel', 'before' => 'Earlier published duty', 'after' => 'Updated published duty']]]]);
        $ui = Livewire::withQueryParams($this->deeplink('shift', $assignment->id))->actingAs($this->employee)->test(MyWork::class)
            ->assertSee('Diese Anfrage hat sich geändert')->assertSee('Aktuelle Anfrage prüfen')->assertSee('Earlier published duty');
        $this->assertFalse($ui->viewData('selectedRequest')['can_respond']);
        $ui->call('respondToRequest', true)->assertHasErrors('request');
        $this->assertSame('requested', $assignment->fresh()->status->value);
        $ui->call('openConsentRequest', 'shift', $assignment->id, 2)->assertHasNoErrors('request')->assertSet('requestRevision', 2)->assertSee('Änderung bestätigen')
            ->call('respondToRequest', false)->assertSet('requestOpen', false);
        $this->assertSame('declined', $assignment->fresh()->status->value);
        $this->assertNotNull($assignment->fresh()->responded_at);
    }

    public function test_unpublished_change_only_exposes_previous_published_details_and_blocks_response(): void
    {
        $assignment = $this->assignment();
        $shift = $assignment->shift;
        $snapshot = $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']);
        $shift->forceFill(['published_snapshot' => $snapshot, 'revision' => 2, 'title' => 'Private unpublished change'])->save();
        $ui = Livewire::withQueryParams($this->deeplink('shift', $assignment->id))->actingAs($this->employee)->test(MyWork::class)
            ->assertSee('Der Dienst wird gerade überarbeitet')->assertDontSee('Private unpublished change')->call('respondToRequest', true)->assertHasErrors('request');
        $this->assertSame(0, $ui->viewData('pendingRequests')->total());
        $this->assertFalse($ui->viewData('selectedRequest')['can_respond']);
        $this->assertSame('requested', $assignment->fresh()->status->value);
    }

    public function test_notification_links_guard_foreign_input_and_keep_answered_own_requests_read_only(): void
    {
        $foreign = $this->assignment(3, ['title' => 'Private foreign request'], $this->other);
        foreach ([$this->deeplink('shift', $foreign->id), $this->deeplink('shift', 999999), ['request_type' => ['shift'], 'request_id' => ['1'], 'request_revision' => '1'], ['request_type' => 'shift', 'request_id' => '1e3', 'request_revision' => '-1']] as $query) {
            Livewire::withQueryParams($query)->actingAs($this->employee)->test(MyWork::class)->assertSet('requestOpen', true)
                ->assertSee('Diese Anfrage ist nicht verfügbar')->assertDontSee('Private foreign request')->assertSet('requestId', null);
        }
        $done = $this->assignment();
        $done->forceFill(['status' => 'declined'])->save();
        $auditsBefore = OperationAudit::count();
        $answered = Livewire::withQueryParams($this->deeplink('shift', $done->id))->actingAs($this->employee)->test(MyWork::class)
            ->assertSee('Dienst abgelehnt.')->assertSet('requestOpen', true);
        $this->assertFalse($answered->viewData('selectedRequest')['can_respond']);
        $answered->call('respondToRequest', true)->assertHasErrors('request')->assertNotDispatched('personal-request-answered');
        $this->assertSame('declined', $done->fresh()->status->value);
        $this->assertSame($auditsBefore, OperationAudit::count());
        Livewire::withQueryParams([])->actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $foreign->id, 1)->assertNotFound();
    }

    public function test_capacity_requires_explicit_window_confirmation_and_never_creates_binding_or_times(): void
    {
        $capacity = $this->capacity();
        $ui = Livewire::withQueryParams($this->deeplink('capacity', $capacity->id))->actingAs($this->employee)->test(MyWork::class)
            ->assertSee('Die Kapazität wird anschließend separat freigegeben')->assertSet('requestConfirmed', false)
            ->call('respondToRequest', true)->assertHasErrors('requestConfirmed')->assertNotDispatched('personal-request-answered');
        $this->assertSame('requested', $capacity->fresh()->status);
        $ui->set('requestConfirmed', true)->call('respondToRequest', true)->assertHasNoErrors()->assertSet('requestOpen', false)
            ->assertDispatchedTo(AttentionCenter::class, 'personal-request-answered');
        $this->assertSame('consented', $capacity->fresh()->status);
        $this->assertSame(2, $capacity->fresh()->revision);
        $this->assertNull($capacity->fresh()->approved_at);
        $this->assertSame(0, CustomerCapacityReservation::count());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_answered_capacity_disappears_from_the_mounted_personal_tasks_without_a_reload(): void
    {
        (require database_path('migrations/2026_10_06_103000_create_operations_attention_tables.php'))->up();
        $capacity = $this->capacity();
        $remaining = $this->capacity(3, ['role_name' => 'Still pending own capacity']);
        $this->capacity(2, ['user_id' => $this->other->id, 'role_name' => 'Foreign private capacity']);
        $tasks = Livewire::actingAs($this->employee)->test(AttentionCenter::class, ['personal' => true])
            ->assertSee('Capacity role fixture')->assertSee('Still pending own capacity')->assertDontSee('Foreign private capacity')
            ->call('setPage', 2);
        $remindersBefore = DB::table('operations_attention_items')->count();

        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1)
            ->set('requestConfirmed', true)->call('respondToRequest', true)->assertHasNoErrors()
            ->assertDispatchedTo(AttentionCenter::class, 'personal-request-answered');
        $tasks->dispatch('personal-request-answered')->assertSet('paginators.page', 1)
            ->assertDontSee('Capacity role fixture')->assertSee('Still pending own capacity')->assertDontSee('Foreign private capacity');
        $this->assertSame(['portal-capacity-'.$remaining->id], $tasks->viewData('items')->getCollection()->pluck('id')->all());
        $this->assertSame($remindersBefore, DB::table('operations_attention_items')->count());
        $this->assertSame('consented', $capacity->fresh()->status);
    }

    public function test_personal_task_refresh_rechecks_fresh_actor_access(): void
    {
        $this->capacity();
        $tasks = Livewire::actingAs($this->employee)->test(AttentionCenter::class, ['personal' => true])->assertSee('Capacity role fixture');
        $this->employee->forceFill(['status' => false])->save();
        $tasks->dispatch('personal-request-answered')->assertForbidden();
    }

    public function test_capacity_revision_change_resets_confirmation_and_requires_new_review(): void
    {
        $capacity = $this->capacity();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1)->set('requestConfirmed', true);
        $capacity->forceFill(['revision' => 2, 'ends_at' => $capacity->ends_at->addHour()])->save();
        $ui->call('respondToRequest', true)->assertHasErrors('request')->assertSet('requestConfirmed', false)->assertSee('Aktuelle Anfrage prüfen');
        $this->assertSame('requested', $capacity->fresh()->status);
        $ui->call('openConsentRequest', 'capacity', $capacity->id, 2)->set('requestConfirmed', true)->call('respondToRequest', false)->assertSet('requestOpen', false);
        $this->assertSame('declined', $capacity->fresh()->status);
        $this->assertNull($capacity->fresh()->consented_at);
    }

    public function test_native_capacity_conflict_between_review_and_lock_stays_recoverable_in_the_dialog(): void
    {
        $capacity = $this->capacity();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1)->set('requestConfirmed', true);
        $interleaved = false;
        // Simulate a revision change at the native service's first lock, after
        // the dashboard read. The real service rejects it and rolls back.
        DB::listen(function (QueryExecuted $query) use ($capacity, &$interleaved): void {
            if (! $interleaved && DB::transactionLevel() > 0 && str_contains($query->sql, 'select') && str_contains($query->sql, '"users"')) {
                $interleaved = true;
                CustomerCapacityCommitment::whereKey($capacity->id)->update(['revision' => 2]);
            }
        });
        $ui->call('respondToRequest', true)->assertHasErrors('request')->assertSet('requestOpen', true)->assertSet('requestConfirmed', false)
            ->assertSee('zwischenzeitlich geändert oder beantwortet')->assertNotDispatched('personal-request-answered');
        $this->assertTrue($interleaved);
        $this->assertSame('requested', $capacity->fresh()->status);
        $this->assertNull($capacity->fresh()->consented_at);
        $this->assertSame(0, CustomerCapacityReservation::count());
    }

    public function test_shift_acceptance_still_uses_native_overlap_and_absence_guards(): void
    {
        $assignment = $this->assignment();
        AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4), 'timezone' => 'Europe/Berlin', 'status' => 'approved']);
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $assignment->id, 1)->call('respondToRequest', true)->assertHasErrors('workflow');
        $this->assertSame('requested', $assignment->fresh()->status->value);
        $this->assertNull($assignment->fresh()->responded_at);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_a_fresh_shift_can_be_confirmed_and_still_records_the_native_response_audit(): void
    {
        $assignment = $this->assignment();
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $assignment->id, 1)
            ->call('respondToRequest', true)->assertHasNoErrors()->assertSet('requestOpen', false)->assertSet('requestId', null)
            ->assertDispatchedTo(AttentionCenter::class, 'personal-request-answered');
        $this->assertSame('confirmed', $assignment->fresh()->status->value);
        $this->assertNotNull($assignment->fresh()->responded_at);
        $this->assertTrue(OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignment->id)->where('action', 'assignment.accepted')->exists());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_today_to_calendar_morph_has_only_named_modal_and_date_bindings(): void
    {
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openDay', '2026-10-15')->assertSet('tab', 'schedule')->assertSet('anchorDate', '2026-10-15');
        $html = html_entity_decode($ui->html(), ENT_QUOTES);
        $this->assertStringContainsString(".entangle('calendarEventOpen')", $html);
        $this->assertStringContainsString('anchorDate', $html);
        $this->assertStringNotContainsString(".entangle('')", $html);
        $this->assertDoesNotMatchRegularExpression('/(?:wire:model(?:\.[a-z]+)*|x-model)=""/', $html);
    }

    public function test_fresh_actor_deactivation_blocks_a_previously_opened_request(): void
    {
        $capacity = $this->capacity();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1)->set('requestConfirmed', true);
        $this->employee->forceFill(['status' => false])->save();
        $ui->call('respondToRequest', true)->assertForbidden();
        $this->assertSame('requested', $capacity->fresh()->status);
    }

    public function test_request_pointer_is_locked_and_cannot_be_rebound_to_another_employee(): void
    {
        $own = $this->assignment();
        $foreign = $this->assignment(3, [], $this->other);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $own->id, 1);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $ui->set('requestId', $foreign->id);
    }

    public function test_missing_optional_capacity_schema_keeps_shift_dashboard_operational(): void
    {
        Schema::drop('customer_capacity_reservations');
        $assignment = $this->assignment();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->assertSee('Requested duty 2');
        $this->assertSame(1, $ui->viewData('pendingRequests')->total());
        $ui->call('openConsentRequest', 'capacity', 123, 1)->assertSee('Diese Anfrage ist nicht mehr verfügbar.');
        $this->assertSame('requested', $assignment->fresh()->status->value);
    }

    public function test_request_calendar_shows_only_own_published_schedule_and_authorized_personal_events(): void
    {
        $request = $this->assignment();
        $public = $this->assignment(5);
        $shift = $public->shift;
        $shift->forceFill(['published_snapshot' => $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']), 'revision' => 2, 'title' => 'Unpublished private calendar title'])->save();
        $foreign = $this->assignment(3, ['title' => 'Foreign private calendar title'], $this->other);
        $draft = $this->assignment(4, ['title' => 'Unreleased calendar title', 'published_revision' => 0]);
        $ownAbsence = AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(8), 'timezone' => 'Europe/Berlin', 'status' => 'approved']);
        $foreignAbsence = AbsenceRequest::create(['user_id' => $this->other->id, 'kind' => 'sick', 'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(8), 'timezone' => 'Europe/Berlin', 'status' => 'approved']);
        $ownTraining = PersonnelTraining::create(['title' => 'Own confirmed training', 'starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(6)->addHours(4), 'timezone' => 'Europe/Berlin', 'capacity' => 5, 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        $foreignTraining = PersonnelTraining::create(['title' => 'Foreign confidential training', 'starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(6)->addHours(4), 'timezone' => 'Europe/Berlin', 'capacity' => 5, 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        foreach ([[$ownTraining, $this->employee], [$foreignTraining, $this->other]] as [$training, $participant]) {
            $training->participants()->create(['user_id' => $participant->id, 'status' => 'confirmed', 'created_by' => $this->admin->id]);
        }
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $request->id, 1)
            ->assertSeeHtml('data-request-calendar')->assertSee('Own confirmed training')->assertDontSee('Foreign confidential training')
            ->assertDontSee('Foreign private calendar title')->assertDontSee('Unreleased calendar title')->assertDontSee('Unpublished private calendar title');
        $events = collect($ui->viewData('requestCalendar')['events'])->keyBy('id');
        $this->assertTrue($events['shift-'.$request->id]['is_request']);
        $this->assertSame('Requested duty 5', $events['shift-'.$public->id]['title']);
        $this->assertFalse($events['shift-'.$public->id]['is_request']);
        $this->assertTrue($events->has('absence-'.$ownAbsence->id));
        $this->assertTrue($events->has('training-'.$ownTraining->id));
        foreach (['shift-'.$foreign->id, 'shift-'.$draft->id, 'absence-'.$foreignAbsence->id, 'training-'.$foreignTraining->id] as $id) {
            $this->assertFalse($events->has($id));
        }
        $this->assertSame('requested', $request->fresh()->status->value);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_request_calendar_uses_the_native_month_grid_even_for_a_four_week_february(): void
    {
        $start = CarbonImmutable::parse('2027-02-01T23:00:00+01:00');
        $capacity = $this->capacity(1, ['starts_at' => $start, 'ends_at' => $start->addHours(3)]);
        $assignment = $this->assignment(1, ['title' => 'Own fifth-week calendar duty', 'starts_at' => $start->addWeeks(4), 'ends_at' => $start->addWeeks(4)->addHours(3)]);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1)->assertSee('Own fifth-week calendar duty');
        $calendar = $ui->viewData('requestCalendar');
        $this->assertSame('2027-02-01', $calendar['from']->toDateString());
        $this->assertSame('2027-03-08', $calendar['to']->toDateString());
        $this->assertSame(['capacity-'.$capacity->id, 'shift-'.$assignment->id], collect($calendar['events'])->pluck('id')->all());
        $this->assertSame('requested', $capacity->fresh()->status);
        $ui->call('closeConsentRequest');
        $this->assertNull($ui->viewData('requestCalendar'));
    }

    public function test_native_modal_close_clears_request_validation_and_selection_without_answering(): void
    {
        $capacity = $this->capacity();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1);
        $this->assertStringContainsString(".entangle('requestOpen').live", html_entity_decode($ui->html(), ENT_QUOTES));
        $ui->call('respondToRequest', true)->assertHasErrors('requestConfirmed')->assertSet('requestOpen', true)
            ->set('requestOpen', false)->assertHasNoErrors(['request', 'requestConfirmed'])
            ->assertSet('requestOpen', false)->assertSet('requestConfirmed', false)->assertSet('requestId', null)->assertSet('requestType', '')->assertSet('requestRevision', null)
            ->assertDontSee('Bitte prüfe und bestätige den angefragten Zeitraum.')->assertNotDispatched('personal-request-answered');
        $this->assertNull($ui->viewData('requestCalendar'));
        $this->assertSame('requested', $capacity->fresh()->status);
        $this->assertSame(1, $capacity->fresh()->revision);
        $this->assertSame(0, CustomerCapacityReservation::count());
        $this->assertSame(0, WorkTimeEntry::count());
        $ui->call('openConsentRequest', 'capacity', $capacity->id, 1)->assertSet('requestConfirmed', false)->assertHasNoErrors(['request', 'requestConfirmed']);
    }

    public function test_native_modal_close_rechecks_fresh_actor_access(): void
    {
        $capacity = $this->capacity();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1);
        $this->employee->forceFill(['status' => false])->save();
        $ui->set('requestOpen', false)->assertForbidden();
        $this->assertSame('requested', $capacity->fresh()->status);
    }

    public function test_request_details_keep_the_published_order_and_duration_without_any_draft_notes(): void
    {
        $assignment = $this->assignment();
        $shift = $assignment->shift;
        $shift->order->forceFill(['order_number' => 'PUBLIC-ORDER-42'])->save();
        $snapshot = $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']);
        $draftOrder = Order::create(['customer_id' => $this->customer->id, 'order_number' => 'PRIVATE-DRAFT-ORDER', 'title' => 'Private new draft order', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDays(30), 'required_staff' => 1]);
        $shift->forceFill(['published_snapshot' => $snapshot + ['notes' => 'Unapproved snapshot note'], 'revision' => 2, 'order_id' => $draftOrder->id,
            'ends_at' => $shift->ends_at->addHours(3), 'notes' => 'Private unpublished shift note'])->save();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $assignment->id, 1)
            ->assertDontSee('PRIVATE-DRAFT-ORDER')->assertDontSee('Private unpublished shift note')->assertDontSee('Unapproved snapshot note');
        $selected = $ui->viewData('selectedRequest');
        $this->assertSame('PUBLIC-ORDER-42', $selected['row']['order_reference']);
        $this->assertSame(480, $selected['row']['duration_minutes']);
        $this->assertSame(30, $selected['row']['break_minutes']);
        $this->assertNull($selected['row']['notes']);
        $this->assertSame('requested', $selected['row']['status']);
        $this->assertSame(1, $selected['row']['revision']);
        $this->assertFalse($selected['can_respond']);
        $this->assertSame('requested', $assignment->fresh()->status->value);
    }

    public function test_request_duration_is_elapsed_time_across_dst_and_overnight_for_both_request_types(): void
    {
        foreach ([['2027-03-28T01:30:00+01:00', '2027-03-28T03:30:00+02:00', 60],
            ['2026-10-25T01:30:00+02:00', '2026-10-25T03:30:00+01:00', 180],
            ['2027-02-01T23:00:00+01:00', '2027-02-02T02:00:00+01:00', 180]] as [$from, $to, $expected]) {
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);
            $assignment = $this->assignment(1, ['starts_at' => $start, 'ends_at' => $end]);
            $capacity = $this->capacity(1, ['starts_at' => $start, 'ends_at' => $end, 'planned_break_minutes' => 20]);
            $ui = Livewire::actingAs($this->employee)->test(MyWork::class);
            $rows = $ui->viewData('pendingRequests')->getCollection();
            foreach ([['shift', $assignment->id, 30], ['capacity', $capacity->id, 20]] as [$type, $id, $pause]) {
                $row = $rows->first(fn ($row) => $row['type'] === $type && $row['id'] === $id);
                $this->assertNotNull($row);
                $this->assertSame($expected, $row['duration_minutes']);
                $this->assertSame($pause, $row['break_minutes']);
                $this->assertSame('requested', $row['status']);
                $this->assertNull($row['notes']);
                if ($type === 'capacity') {
                    $this->assertNull($row['order_reference']);
                }
            }
            $this->assertSame('requested', $assignment->fresh()->status->value);
            $this->assertSame('requested', $capacity->fresh()->status);
        }
        $this->assertSame(0, CustomerCapacityReservation::count());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_request_map_uses_only_the_owned_published_point_while_a_draft_moves_the_site(): void
    {
        (require database_path('migrations/2026_10_11_120000_add_confirmed_routing_locations.php'))->up();
        $point = ['name' => 'Own published depot', 'latitude' => 53.550001, 'longitude' => 10.000001, 'confirmed' => true, 'revision' => 1,
            'address' => 'Private address not needed by map', 'provider_payload' => ['secret' => 'Never present provider payload']];
        $assignment = $this->assignment(2, ['location_name' => $point['name'], 'location_snapshot' => $point]);
        $shift = $assignment->shift;
        $snapshot = $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes', 'location_snapshot']);
        $draft = ['name' => 'Private draft depot', 'latitude' => 48.13512, 'longitude' => 11.58198, 'confirmed' => true];
        $shift->forceFill(['published_snapshot' => $snapshot, 'revision' => 2, 'location_name' => $draft['name'], 'location_snapshot' => $draft])->save();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $assignment->id, 1)->assertDontSee($draft['name']);
        $selected = $ui->viewData('selectedRequest');
        $preview = $selected['row']['location_preview'];
        $this->assertSame('located', $preview['state']);
        $this->assertSame($point['name'], $preview['place']);
        $this->assertSame($point['latitude'], $preview['latitude']);
        $this->assertSame($point['longitude'], $preview['longitude']);
        $this->assertTrue($preview['confirmed']);
        $this->assertSame('Bestätigter Einsatzstandort', $preview['label']);
        $this->assertArrayNotHasKey('address', $preview);
        $this->assertArrayNotHasKey('provider_payload', $preview);
        $this->assertFalse($selected['can_respond']);
        $this->assertFalse($ui->viewData('pendingRequests')->getCollection()->contains(fn ($row) => array_key_exists('location_preview', $row)));
        $html = Blade::render('<x-operations.personal-request-map id="own-map-fixture" :preview="$preview" :location="$location" />', ['preview' => $preview, 'location' => $selected['row']['location']]);
        $this->assertStringContainsString('data-personal-request-map', $html);
        $this->assertStringContainsString('data-location-marker', $html);
        $this->assertStringContainsString('Own published depot', $html);
        $this->assertStringNotContainsString($draft['name'], $html);
        $this->assertStringNotContainsString('Private address not needed by map', $html);
        $this->assertStringNotContainsString('provider_payload', $html);
        $this->assertSame('requested', $assignment->fresh()->status->value);
    }

    public function test_capacity_map_remains_approximate_and_never_borrows_a_current_confirmed_directory_point(): void
    {
        (require database_path('migrations/2026_10_11_120000_add_confirmed_routing_locations.php'))->up();
        $this->location->forceFill(['name' => 'Hamburg', 'latitude' => 48.13512, 'longitude' => 11.58198, 'coordinates_confirmed_at' => now()])->save();
        $capacity = $this->capacity();
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'capacity', $capacity->id, 1);
        $preview = $ui->viewData('selectedRequest')['row']['location_preview'];
        $this->assertSame('located', $preview['state']);
        $this->assertSame('Hamburg', $preview['place']);
        $this->assertSame('Stadtmitte · ungefähr', $preview['label']);
        $this->assertFalse($preview['confirmed'] ?? false);
        $this->assertNotSame(48.13512, $preview['latitude']);
        $foreignCustomer = Customer::create(['company_name' => 'Foreign private customer', 'is_active' => true]);
        $foreignLocation = CustomerLocation::create(['customer_id' => $foreignCustomer->id, 'name' => 'Foreign private depot', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->admin->id]);
        $capacity->forceFill(['location_id' => $foreignLocation->id])->save();
        $ui->call('openConsentRequest', 'capacity', $capacity->id, 1)->assertDontSee('Foreign private depot')->assertDontSee('Foreign private customer');
        $this->assertSame('unknown', $ui->viewData('selectedRequest')['row']['location_preview']['state']);
        $this->assertSame('requested', $capacity->fresh()->status);
        $this->assertSame(0, CustomerCapacityReservation::count());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_request_map_keeps_multiple_published_places_and_neutral_unknown_locations(): void
    {
        $assignment = $this->assignment(2, ['location_name' => 'Eidelstedt/Maschen']);
        $ui = Livewire::actingAs($this->employee)->test(MyWork::class)->call('openConsentRequest', 'shift', $assignment->id, 1);
        $preview = $ui->viewData('selectedRequest')['row']['location_preview'];
        $this->assertSame('multiple', $preview['state']);
        $this->assertCount(2, $preview['points']);
        $this->assertArrayNotHasKey('latitude', $preview);
        foreach ($preview['points'] as $point) {
            $this->assertArrayNotHasKey('source', $point);
            $this->assertArrayNotHasKey('geonames_id', $point);
        }
        $unknown = $this->assignment(3, ['location_name' => 'Unbekannter Einsatzort']);
        $unknown->shift->order->forceFill(['location_name' => 'Berlin', 'description' => 'Current private order address in Berlin'])->save();
        $ui->call('openConsentRequest', 'shift', $unknown->id, 1);
        $this->assertSame(['state' => 'unknown', 'label' => 'Standort nicht verortet'], $ui->viewData('selectedRequest')['row']['location_preview']);
        $html = Blade::render('<x-operations.personal-request-map id="unknown-map-fixture" />');
        $this->assertStringContainsString('data-map-state="unknown"', $html);
        $this->assertStringContainsString('Standort nicht verortet', $html);
        $this->assertStringNotContainsString('data-location-marker', $html);
        $this->assertStringNotContainsString('Berlin', $html);
    }
}
