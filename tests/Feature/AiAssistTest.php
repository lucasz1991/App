<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiAssist;
use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeQualification;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftOffer;
use App\Models\StaffingAutomationRun;
use App\Models\User;
use App\Services\Operations\AiAssistService;
use App\Support\Operations\AiDispositionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiAssistTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $root;

    private User $manager;

    private User $ben;

    private Customer $customer;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            if ($migration === '2026_10_04_120000_create_workforce_personnel_foundations.php' && ! Schema::hasColumn('shifts', 'disposition_details')) {
                Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
            }
            (require database_path('migrations/'.$migration))->up();
        }
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        Http::fake();
        // ID 1 ist die Systemverwaltung; die Disposition prüft ohne Sonderrechte.
        $this->root = User::factory()->create(['name' => 'Systemverwaltung', 'role' => 'admin', 'status' => true]);
        $this->manager = User::factory()->create(['name' => 'Disposition QA', 'role' => 'admin', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Gepflegte Regeln', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $this->customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Kundenleistung', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-10T00:00', 'ends_at' => '2027-05-17T00:00', 'required_staff' => 2, 'created_by' => $this->manager->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'Offener Donnerstag', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id, 'revision' => 1, 'published_revision' => 0]);
        EmployeeAvailability::create(['user_id' => $this->ben->id, 'kind' => 'preferred', 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
    }

    private function assist(array $params = ['page' => 'cases', 'view' => 'shifts', 'section' => 'plan'])
    {
        return Livewire::actingAs($this->manager)->test(AiAssist::class, $params);
    }

    public function test_launcher_and_panel_render_page_context_actions_and_safe_defaults(): void
    {
        $component = $this->assist()->assertSet('page', 'shifts')->assertSet('pageLabel', 'Schichtplan')
            ->assertSet('from', '2027-05-10')->assertSet('until', '2027-05-16');
        $html = $component->html();
        foreach (['rt-ai-assist__launcher', 'AI-Assist (Strg J)', 'data-assistant-cloud-slot="launcher"', 'data-assistant-cloud-slot="header"',
            'Besetzung für den Zeitraum vorschlagen', 'Was steht als Nächstes an?', 'role="tablist"', 'Disposition · AI-Eingang aus'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Ohne Verwaltung keine Einstellungsformulare, nur Anzeige.
        $this->assertStringNotContainsString('wire:click="saveSettings"', $html);
        $this->assertStringContainsString('Ändern nur durch die Systemverwaltung', $html);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_period_action_builds_a_real_proposal_and_apply_assigns_requested_with_undo(): void
    {
        $component = $this->assist()->call('run', 'period');
        $messages = session('operations.ai_assist.messages');
        $this->assertCount(2, $messages);
        $this->assertSame('Besetzung für den Zeitraum vorschlagen', $messages[0]['text']);
        $answer = $messages[1];
        $this->assertSame([[$this->shift->id, $this->ben->id, 1]], $answer['card']['payload']);
        $this->assertSame('Ben Beta', $answer['card']['rows'][0]['value']);
        $component->assertSee('Offener Donnerstag')->assertSee('Vorschlag übernehmen');
        $this->assertSame(0, ShiftAssignment::count());

        $component->call('act', $answer['id'], 'apply')->assertDispatched('operations-plan-changed');
        $assignment = ShiftAssignment::sole();
        $this->assertSame([$this->ben->id, 'requested', AiAssistService::NOTE], [$assignment->user_id, $assignment->status->value, $assignment->note]);
        $this->assertSame(1, OperationAudit::where('action', 'ai_assist.requested')->count());
        $card = session('operations.ai_assist.messages')[1]['card'];
        $this->assertSame('done', $card['state']);
        $this->assertSame([$assignment->id], $card['undo']);
        // Ein zweites Übernehmen derselben Karte ist ausgeschlossen.
        $this->assist()->call('act', $answer['id'], 'apply')->assertStatus(409);
        $this->assertSame(1, ShiftAssignment::count());

        $component = $this->assist()->call('act', $answer['id'], 'undo')->assertDispatched('operations-plan-changed');
        $this->assertSame('cancelled', $assignment->fresh()->status->value);
        $this->assertSame('undone', session('operations.ai_assist.messages')[1]['card']['state']);
        $component->call('setTab', 'activity')->assertSee('Einteilung zurückgenommen')->assertSee('Als Angefragt eingeteilt');
        $this->assist()->call('act', $answer['id'], 'undo')->assertStatus(409);
    }

    public function test_stale_revision_or_new_conflict_is_reported_and_nothing_is_assigned(): void
    {
        $component = $this->assist()->call('run', 'period');
        $id = session('operations.ai_assist.messages')[1]['id'];
        $this->shift->forceFill(['revision' => 2])->save();
        $component->call('act', $id, 'apply')->assertDispatched('swal:toast', type: 'warning');
        $this->assertSame(0, ShiftAssignment::count());
        $card = session('operations.ai_assist.messages')[1]['card'];
        $this->assertNull($card['state'] ?? null);
        $this->assertStringContainsString('Offener Donnerstag · Ben Beta', $card['failed'][0]);
        $component->assertSee('Nicht übernommen');
    }

    public function test_free_text_routes_to_real_answers_and_falls_back_to_help(): void
    {
        $component = $this->assist()->call('ask', 'Welche Schichten sind diese Woche noch offen?');
        $this->assertSame('period', session('operations.ai_assist.messages')[1]['action']);
        $component->call('ask', 'Erzähl einen Witz');
        $help = session('operations.ai_assist.messages')[3];
        $this->assertNull($help['action']);
        $this->assertStringContainsString('Dabei helfe ich in der Disposition', $help['lead']);
        $component->call('ask', '   ')->call('resetConversation');
        $this->assertNull(session('operations.ai_assist.messages'));
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_cards_cannot_be_forged_and_unknown_actions_are_rejected(): void
    {
        $this->assist()->call('act', 99, 'apply')->assertStatus(404);
        $this->assist()->call('run', 'unknown')->assertStatus(403);
        $this->assist()->call('setTab', 'secret')->assertStatus(422);
        // Abgelehnte Aktionen hinterlassen keinen Verlauf.
        $this->assertNull(session('operations.ai_assist.messages'));
        $this->assist()->call('run', 'period');
        $id = session('operations.ai_assist.messages')[1]['id'];
        $this->assist()->call('act', $id, 'delete-everything')->assertStatus(422);
        // Nutzernachrichten tragen keine Karten.
        $this->assist()->call('act', $id - 1, 'apply')->assertStatus(404);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_intake_tab_lists_real_intakes_and_hints_count_reviews(): void
    {
        AiIntake::create(['source_type' => 'email', 'status' => 'review', 'title' => 'Zusatzbedarf Fulda', 'customer_id' => $this->customer->id, 'missing_fields' => ['starts_at']]);
        AiIntake::create(['source_type' => 'email', 'status' => 'completed', 'title' => 'Übernommene Anfrage']);
        $component = $this->assist(['page' => 'cases', 'view' => 'inbox'])->assertSet('pageLabel', 'Eingang');
        $this->assertStringContainsString('Ein AI-Eingang wartet', json_encode(app(AiAssistService::class)->hints($this->manager), JSON_UNESCAPED_UNICODE) ?: '');
        $component->call('setTab', 'intake')->assertSee('Zusatzbedarf Fulda')->assertSee('Übernommene Anfrage')->assertSee('Prüfung nötig');
        $component->set('intakeStatus', 'review')->assertSee('Zusatzbedarf Fulda')->assertDontSee('Übernommene Anfrage');
        $component->set('intakeSearch', 'Übernommen')->assertDontSee('Zusatzbedarf Fulda');
        $this->assist(['page' => 'cases', 'view' => 'inbox'])->call('setTab', 'intake')->set('intakeStatus', 'deleted')->assertStatus(422);
        $component = $this->assist(['page' => 'cases', 'view' => 'inbox'])->call('run', 'intake');
        $this->assertSame('Zusatzbedarf Fulda', session('operations.ai_assist.messages')[1]['card']['rows'][0]['label']);
        $this->assertSame('offen: Beginn', session('operations.ai_assist.messages')[1]['card']['rows'][0]['small']);
    }

    public function test_access_requires_disposition_rights_and_settings_stay_superadmin_only(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.manage', fn (User $user) => $user->is($this->manager));
        Gate::define('operations.inquiries.manage', fn (User $user) => $user->is($this->manager));
        Livewire::actingAs($staff)->test(AiAssist::class, ['page' => 'cases'])->assertForbidden();
        $this->assist()->call('openSettings')->assertForbidden();
        $this->assist()->call('saveSettings')->assertForbidden();
    }

    public function test_system_administration_changes_ai_intake_settings_with_revision_guard(): void
    {
        $component = Livewire::actingAs($this->root)->test(AiAssist::class, ['page' => 'cases'])
            ->assertSee('wire:click="saveSettings"', false)
            ->call('openSettings')->assertSet('config.automation_mode', 'automatic')->assertSet('config.expected_revision', 1)
            ->set('config.automation_mode', 'assisted')->set('config.max_rounds', 1)->call('saveSettings')->assertHasNoErrors();
        $this->assertSame(['assisted', 1, 2], [AiDispositionSettings::all()['automation_mode'], AiDispositionSettings::all()['max_rounds'], AiDispositionSettings::all()['revision']]);
        $component->assertSet('config.expected_revision', 2)->assertSee('Disposition · AI-Eingang aus');
        // Veralteter Stand aus einem zweiten Fenster wird abgewiesen.
        Livewire::actingAs($this->root)->test(AiAssist::class, ['page' => 'cases'])->call('openSettings')->set('config.expected_revision', 1)
            ->set('config.max_ai_calls_per_hour', 250)->call('saveSettings')->assertHasErrors('config');
        $this->assertSame(100, AiDispositionSettings::all()['max_ai_calls_per_hour']);
        $component->set('config.max_rounds', 5)->call('saveSettings')->assertHasErrors('config.max_rounds');
    }

    public function test_remaining_actions_answer_from_live_data_without_writing(): void
    {
        $order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Leistung ohne Schicht', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T06:00', 'ends_at' => '2027-05-14T14:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
        $service = app(AiAssistService::class);
        $context = $service->context('cases', 'shifts', 'plan');
        $without = $service->answer('without', $this->manager, $context);
        $this->assertSame([$order->title], array_column($without['card']['rows'], 'label'));
        $today = $service->answer('open-today', $this->manager, $context);
        $this->assertStringContainsString('jede Schicht besetzt', $today['lead']);
        $this->travelTo(CarbonImmutable::parse('2027-05-13T06:00:00+02:00'));
        $today = $service->answer('open-today', $this->manager, $context);
        $this->assertStringContainsString('Offener Donnerstag', $today['lead']);
        $this->assertSame('Ben Beta', $today['card']['rows'][0]['label']);
        $this->assertSame([[$this->shift->id, $this->ben->id, 1]], $today['card']['payload']);
        $workload = $service->answer('workload', $this->manager, $context);
        $this->assertStringContainsString('ohne gepflegtes Soll', $workload['lead'].' ohne gepflegtes Soll');
        $day = $service->answer('day', $this->manager, $context);
        $this->assertStringContainsString('1 Dienst heute offen', $day['lead']);
        $this->assertStringContainsString('0 neue Anfragen', $day['lead']);
        $this->assertSame(['open-today', 'workload', 'day'], [$service->route('Wer kann heute übernehmen?'), $service->route('Wer liegt über Soll?'), $service->route('Tageslage bitte')]);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_period_follows_the_shift_plan_and_rejects_oversized_ranges(): void
    {
        $component = $this->assist()->dispatch('operations-period-changed', from: '2027-05-17', until: '2027-05-23')
            ->assertSet('from', '2027-05-17')->assertSet('until', '2027-05-23');
        $component->dispatch('operations-period-changed', from: '2027-01-01', until: '2027-12-31')
            ->assertSet('from', '2027-05-10')->assertSet('until', '2027-05-16');
        $component->call('run', 'period');
        $this->assertStringContainsString('10.05. – 16.05.2027', session('operations.ai_assist.messages')[1]['lead']);
    }

    public function test_fallback_context_follows_the_case_workspace_without_clearing_chat(): void
    {
        $component = $this->assist()->call('run', 'period');
        $history = session('operations.ai_assist.messages');
        $component->dispatch('operations-assistant-context-changed', page: 'cases', view: 'offers', section: '')
            ->assertSet('page', 'offers')->assertSet('pageLabel', 'Angebote');
        $this->assertSame($history, session('operations.ai_assist.messages'));
        $component->dispatch('operations-assistant-context-changed', page: 'cases', view: 'shifts', section: 'calendar')
            ->assertSet('page', 'calendar')->assertSet('pageLabel', 'Kalender');
    }

    public function test_exception_help_explains_missing_source_data_and_safe_alternatives_without_writes(): void
    {
        $intake = AiIntake::create(['source_type' => 'email', 'status' => 'review', 'title' => 'Synthetic incomplete service', 'missing_fields' => ['starts_at', 'qualification_ids'], 'error_code' => 'recipient_not_verified']);
        $service = app(AiAssistService::class);
        $answer = $service->answer('exceptions', $this->manager, $service->context('cases', 'shifts'));
        $row = collect($answer['card']['rows'])->firstWhere('label', $intake->title);
        $this->assertStringContainsString('Beginn', $row['value']);
        $this->assertStringContainsString('exakt prüfen', implode(' ', $row['why']));
        $this->assertStringContainsString('keine Kundenbindung', implode(' ', $row['why']));
        $this->assertStringContainsString('Sichere Alternativen', json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('payload', $answer['card']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(1, AiIntake::count());
        Http::assertNothingSent();
    }

    public function test_exception_diagnosis_keeps_missing_qualification_as_blocking(): void
    {
        $qualification = QualificationType::create(['name' => 'Synthetic mandatory qualification', 'is_active' => true]);
        $this->shift->qualifications()->attach($qualification->id);
        $service = app(AiAssistService::class);
        $answer = $service->answer('exceptions', $this->manager, $service->context('cases', 'shifts'));
        $row = collect($answer['card']['rows'])->first(fn ($row) => str_contains($row['label'], $this->shift->title));
        $this->assertSame('Keine konfliktfreie Besetzung gefunden.', $row['value']);
        $this->assertStringContainsString('Gültiger Nachweis fehlt', implode(' ', $row['why']));
        $this->assertStringContainsString('keine Sperre wird aufgehoben', implode(' ', $row['why']));
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, EmployeeQualification::count());
    }

    public function test_combined_exception_history_disappears_after_one_used_permission_is_revoked(): void
    {
        $this->manager->forceFill(['role' => 'staff'])->save();
        Gate::define('operations.manage', fn ($user) => $user->is($this->manager));
        Gate::define('operations.inquiries.manage', fn ($user) => $user->is($this->manager));
        $component = $this->assist()->dispatch('operations-assistant-action', action: 'exceptions')->assertSet('loaded', true);
        $this->assertSame(['operations.manage', 'operations.inquiries.manage'], session('operations.ai_assist.messages')[1]['abilities']);
        Gate::define('operations.inquiries.manage', fn () => false);
        $component->call('load')->assertDontSee('Unklare Fälle und sichere Alternativen');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_assisted_staffing_preparation_is_explicit_and_creates_no_offer_or_assignment(): void
    {
        foreach (['2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        (require database_path('migrations/2026_10_09_090000_create_staffing_automation_runs.php'))->up();
        AiDispositionSettings::save(['staffing_request_mode' => 'assisted', 'supervisor_id' => $this->manager->id, 'expected_revision' => 1], $this->root);
        $this->shift->forceFill(['status' => 'open', 'published_revision' => 1])->save();
        $component = $this->assist()->dispatch('operations-assistant-action', action: 'automation');
        $card = session('operations.ai_assist.messages')[1]['card'];
        $this->assertSame('staffing_prepare', $card['command']['kind']);
        $this->assertSame($this->shift->id, $card['command']['id']);
        $this->assertContains('prepare', array_column($card['buttons'], 'act'));
        $this->assertSame(0, StaffingAutomationRun::count());
        $this->assertSame(0, ShiftOffer::count());
        $component->call('act', 2, 'prepare');
        $this->assertSame(1, StaffingAutomationRun::count());
        $this->assertSame('review', StaffingAutomationRun::sole()->state);
        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame('open', $this->shift->fresh()->status->value);
        $activity = app(AiAssistService::class)->activity($this->manager, 'automation');
        $this->assertNotEmpty($activity);
        $this->assertSame(['staffing_request'], $activity->pluck('phase')->unique()->all());
        $this->assertStringContainsString('reserviert keinen', $activity->first()['detail']);
        Http::assertNothingSent();
    }

    public function test_changed_staffing_mode_blocks_a_private_preparation_card_before_any_request(): void
    {
        foreach (['2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        (require database_path('migrations/2026_10_09_090000_create_staffing_automation_runs.php'))->up();
        AiDispositionSettings::save(['staffing_request_mode' => 'assisted', 'supervisor_id' => $this->manager->id, 'expected_revision' => 1], $this->root);
        $this->shift->forceFill(['status' => 'open', 'published_revision' => 1])->save();
        $component = $this->assist()->call('run', 'automation');
        AiDispositionSettings::save(['staffing_request_mode' => 'off', 'expected_revision' => 2], $this->root);
        $component->call('act', 2, 'prepare')->assertSee('wurde geändert');
        $this->assertSame(0, StaffingAutomationRun::count());
        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_staffing_status_reports_a_revoked_supervisor_without_claiming_running_automation(): void
    {
        foreach (['2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php', '2026_10_09_090000_create_staffing_automation_runs.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        AiDispositionSettings::save(['staffing_request_mode' => 'automatic', 'supervisor_id' => $this->manager->id, 'expected_revision' => 1], $this->root);
        $this->manager->forceFill(['status' => false])->save();
        Livewire::actingAs($this->root)->test(AiAssist::class)->call('setTab', 'actions')->assertSee('Verantwortliche Disposition nicht berechtigt')->assertDontSee('automatische Anfragen aktiv');
        $this->assertSame('supervisor_not_authorized', app(AiAssistService::class)->automationOverview($this->root)['staffing']['status']);
        $this->assertSame(0, ShiftAssignment::count());
    }
}
