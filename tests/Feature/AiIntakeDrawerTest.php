<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeAssistant;
use App\Livewire\Operations\AiIntakeInbox;
use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeDrawerTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_07_18_000001_create_activity_log_table.php',
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_06_100000_create_planning_enhancements.php',
            '2026_10_06_103000_create_operations_attention_tables.php',
            '2026_10_07_010000_create_ai_disposition_intake.php',
            '2026_10_09_090000_create_staffing_automation_runs.php',
            '2026_10_09_100000_add_ai_customer_communication_controls.php',
            '2026_10_25_110000_add_manual_processing_mode_to_ai_intakes.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic drawer customer', 'is_active' => true]);
        $this->actingAs($this->actor);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['enabled' => false, 'revision' => 5]));
        config(['view.compiled' => sys_get_temp_dir().'/railtime-intake-drawer-'.getmypid()]);
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
    }

    private function source(array $changes = []): AiIntake
    {
        $source = AiIntake::create(array_replace([
            'source_type' => 'email', 'title' => 'Synthetic drawer source', 'customer_id' => $this->customer->id,
            'status' => 'received', 'source_revision' => 1, 'revision' => 1, 'supervising_user_id' => $this->actor->id,
        ], $changes));
        $source->messages()->create(['direction' => 'inbound', 'body' => 'Original synthetic drawer message', 'metadata' => ['subject' => 'Synthetic original']]);

        return $source->fresh();
    }

    private function demand(): array
    {
        return ['title' => 'Synthetic drawer service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00',
            'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'WU', 'required_staff' => 2, 'qualification_ids' => []];
    }

    private function drawer(AiIntake $source): Testable
    {
        return Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $source->id, 'modalOnly' => true]);
    }

    private function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function assertActiveTab(Testable $component, string $name): DOMElement
    {
        $dom = $this->dom($component->html());
        $tabs = $dom->query('//*[@role="tab" and @aria-selected="true"]');
        $this->assertSame(1, $tabs->length, 'Only the selected native tab is active.');
        $tab = $tabs->item(0);
        $this->assertSame($name, trim($tab->textContent));
        $this->assertSame('0', $tab->getAttribute('tabindex'));
        $panelId = $tab->getAttribute('aria-controls');
        $this->assertNotSame('', $panelId);
        $panel = $dom->query('//*[@id="'.$panelId.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel);
        $this->assertSame('tabpanel', $panel->getAttribute('role'));
        $this->assertFalse($panel->hasAttribute('hidden'), 'The active tab must reveal its panel.');
        $this->assertSame($tab->getAttribute('id'), $panel->getAttribute('aria-labelledby'));

        return $panel;
    }

    private function assertNoExternalEffects(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_native_drawer_has_three_accessible_tabs_without_an_embedded_assistant(): void
    {
        $source = $this->source();
        $component = $this->drawer($source)->assertDontSeeLivewire(AiIntakeAssistant::class);
        $dom = $this->dom($component->html());
        $tabs = $dom->query('//*[@role="tab"]');
        $this->assertSame(['Bearbeiten', 'Original & Anlagen', 'Verlauf'], array_map(fn ($tab) => trim($tab->textContent), iterator_to_array($tabs)));
        $this->assertActiveTab($component, 'Bearbeiten');
        $this->assertSame('assisted', $source->fresh()->processing_mode);
        $this->assertNoExternalEffects();
    }

    public function test_original_and_customer_message_tabs_are_read_only_and_do_not_claim_or_cancel_a_source(): void
    {
        $source = $this->source();
        $delivery = $source->deliveries()->create(['recipient_email' => 'qa@example.test', 'subject' => 'Synthetic draft message',
            'body' => 'Synthetic unsent customer message', 'status' => 'draft', 'dedup_key' => str_repeat('d', 64),
            'settings_revision' => 5, 'intake_revision' => 1, 'source_revision' => 1, 'message_type' => 'clarification']);
        $before = $source->getRawOriginal();
        $messageBefore = $source->messages()->sole()->getRawOriginal();
        $deliveryBefore = $delivery->fresh()->getRawOriginal();
        $component = $this->drawer($source)->call('setDetailTab', 'sources')->assertSet('detailTab', 'sources');
        $this->assertStringContainsString('Original synthetic drawer message', $this->assertActiveTab($component, 'Original & Anlagen')->textContent);
        $component->call('setDetailTab', 'communication')->assertSet('detailTab', 'communication');
        $this->assertStringContainsString('Synthetic unsent customer message', $this->assertActiveTab($component, 'Verlauf')->textContent);
        $component->call('setDetailTab', 'review');
        $this->assertActiveTab($component, 'Bearbeiten');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame($messageBefore, $source->messages()->sole()->getRawOriginal());
        $this->assertSame($deliveryBefore, $delivery->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertDatabaseCount('ai_intake_proposals', 0);
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_manual_editor_and_validation_survive_tab_switches_while_assist_respects_unsaved_changes(): void
    {
        $source = $this->source();
        $component = $this->drawer($source)->call('addManualPosition')->assertSet('manualEditorOpen', true)
            ->set('proposalForm.demand.title', 'Unsaved drawer position')->set('proposalForm.demand.required_staff', 0)
            ->call('saveManualPosition')->assertHasErrors('proposalForm.demand.required_staff');
        $before = $source->fresh()->getRawOriginal();
        $component->call('setDetailTab', 'sources')->call('setDetailTab', 'communication')->call('setDetailTab', 'review')
            ->assertSet('manualEditorOpen', true)->assertSet('proposalForm.demand.title', 'Unsaved drawer position')
            ->assertSet('proposalForm.demand.required_staff', 0)->assertHasErrors('proposalForm.demand.required_staff')
            ->call('openAssist')->assertHasErrors('intake')->assertNotDispatched('open-ai-intake-panel')->assertSet('detailOpen', true);
        $component->call('discuss', $source->id)->assertHasErrors('intake')->assertNotDispatched('open-ai-intake-panel')
            ->assertSet('manualEditorOpen', true)->assertSet('proposalForm.demand.title', 'Unsaved drawer position');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $component->call('cancelPositionEdit')->call('openAssist')
            ->assertDispatched('open-ai-intake-panel', intakeId: $source->id, create: false, customerId: $this->customer->id)
            ->assertSet('detailOpen', false);
        $this->assertDatabaseCount('ai_intakes', 1);
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_failed_source_can_be_processed_as_a_direct_order_but_saving_never_accepts_it(): void
    {
        $source = $this->source(['status' => 'failed', 'error_code' => 'provider_unavailable']);
        $component = $this->drawer($source)->call('addManualPosition')->set('proposalForm.demand', $this->demand())
            ->set('proposalForm.business_path', 'direct_order')->call('saveManualPosition')->assertHasNoErrors();
        $this->assertSame('manual', $source->fresh()->processing_mode);
        $this->assertNull($source->fresh()->processed_source_revision);
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('direct_order', $source->proposals()->sole()->payload['business_path']);
        $component->call('confirmManual')->assertHasNoErrors();
        $inquiry = OperationInquiry::sole();
        $this->assertSame('converted', $inquiry->status);
        $this->assertNotNull($inquiry->order_id);
        $this->assertNull($inquiry->offer);
        $this->assertNull($inquiry->accepted_revision);
        $this->assertSame('confirmed', Order::sole()->status->value);
        $this->assertSame(0, OperationAudit::whereIn('action', ['inquiry.offer', 'inquiry.accept'])->count());
        $component->call('confirmManual')->assertHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertNoExternalEffects();
    }

    public function test_incomplete_manual_draft_stays_editable_and_cannot_be_confirmed_as_an_order(): void
    {
        $source = $this->source(['status' => 'failed']);
        $component = $this->drawer($source)->call('addManualPosition')
            ->set('proposalForm.demand.title', 'Incomplete synthetic service')
            ->set('proposalForm.business_path', 'direct_order')->call('saveManualPosition')->assertHasNoErrors();
        $proposal = $source->proposals()->sole();
        $component->call('confirmManual')->assertHasErrors('intake');
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $component->call('reviewNextPosition')->assertHasNoErrors()->assertSet('editingProposalId', $proposal->id)
            ->assertSet('proposalForm.demand.title', 'Incomplete synthetic service');
        $this->assertNoExternalEffects();
    }

    public function test_open_shift_draft_survives_tabs_and_blocks_assist_until_explicitly_closed(): void
    {
        $source = $this->source();
        $component = $this->drawer($source)->call('addManualPosition')->set('proposalForm.demand', $this->demand())
            ->set('proposalForm.business_path', 'direct_order')->call('saveManualPosition')->call('confirmManual')->assertHasNoErrors();
        $proposal = $source->proposals()->sole();
        $component->call('openPlanning', $proposal->id, $proposal->revision)->assertHasNoErrors()
            ->set('planningSegments.0.planned_break_minutes', 45)->call('setDetailTab', 'sources')
            ->call('setDetailTab', 'review')->assertSet('planningProposalId', $proposal->id)
            ->assertSet('planningSegments.0.planned_break_minutes', 45)
            ->call('openAssist')->assertHasErrors('intake')->assertNotDispatched('open-ai-intake-panel')
            ->assertSet('detailOpen', true)->assertSet('planningSegments.0.planned_break_minutes', 45);
        $component->call('closePlanning')->call('openAssist')->assertSet('detailOpen', false)
            ->assertDispatched('open-ai-intake-panel', intakeId: $source->id, create: false, customerId: $this->customer->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertNoExternalEffects();
    }

    public function test_default_staff_proposal_is_visibly_unverified_and_opening_it_does_not_confirm_it(): void
    {
        $source = $this->source(['status' => 'ready']);
        $source->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1, 'status' => 'proposed',
            'payload' => ['demand' => array_replace($this->demand(), ['required_staff' => 1]), 'segments' => [],
                'assumptions' => ['required_staff' => ['basis' => 'default', 'value' => 1]], 'business_path' => 'inquiry']]);
        $before = $source->getRawOriginal();
        $component = $this->drawer($source);
        $dom = $this->dom($component->html());
        $staff = $dom->query('//*[@data-field="required_staff"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $staff);
        $this->assertMatchesRegularExpression('/Bitte|Vorschlag|ergänzen|prüfen/u', $staff->textContent);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_customer_editor_is_view_only_and_changed_assignment_blocks_assist_switch(): void
    {
        $source = $this->source();
        $before = $source->getRawOriginal();
        $component = $this->drawer($source)->call('editCustomerAssignment')->assertSet('customerEditorOpen', true);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $other = Customer::create(['company_name' => 'Other synthetic drawer customer', 'is_active' => true]);
        $component->set('assignCustomerId', (string) $other->id)->call('setDetailTab', 'sources')
            ->call('openAssist')->assertHasErrors('intake')->assertNotDispatched('open-ai-intake-panel')
            ->assertSet('assignCustomerId', (string) $other->id);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertNoExternalEffects();
    }

    public function test_customer_and_position_editors_cannot_replace_each_others_unsaved_values(): void
    {
        $source = $this->source();
        $proposal = $source->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1,
            'status' => 'proposed', 'payload' => ['demand' => $this->demand(), 'segments' => [], 'business_path' => 'inquiry']]);
        $before = $source->getRawOriginal();
        $component = $this->drawer($source)->call('editCustomerAssignment')->assertSet('customerEditorOpen', true);
        $component->call('addManualPosition')->assertHasErrors('intake')->assertSet('manualEditorOpen', false)
            ->assertSet('customerEditorOpen', true);
        $component->call('editProposal', $proposal->id)->assertHasErrors('intake')->assertSet('editingProposalId', null)
            ->assertSet('customerEditorOpen', true);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $component->call('cancelCustomerAssignment')->call('editProposal', $proposal->id)->assertHasNoErrors()
            ->set('proposalForm.demand.title', 'Unchanged existing position draft');
        $component->call('editCustomerAssignment')->assertHasErrors('intake')->assertSet('customerEditorOpen', false)
            ->assertSet('editingProposalId', $proposal->id)->assertSet('proposalForm.demand.title', 'Unchanged existing position draft');
        $component->call('cancelPositionEdit')->call('addManualPosition')->assertHasNoErrors()
            ->set('proposalForm.demand.title', 'Unchanged new position draft');
        $component->call('editCustomerAssignment')->assertHasErrors('intake')->assertSet('customerEditorOpen', false)
            ->assertSet('manualEditorOpen', true)->assertSet('proposalForm.demand.title', 'Unchanged new position draft');
        $this->assertDatabaseCount('ai_intake_proposals', 1);
        $this->assertSame('Synthetic drawer service', $proposal->fresh()->payload['demand']['title']);
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_customer_and_planning_editors_cannot_replace_each_others_unsaved_values(): void
    {
        $source = $this->source();
        $component = $this->drawer($source)->call('addManualPosition')->set('proposalForm.demand', $this->demand())
            ->set('proposalForm.business_path', 'direct_order')->call('saveManualPosition')->call('confirmManual')->assertHasNoErrors();
        $proposal = $source->proposals()->sole();
        $before = $source->fresh()->getRawOriginal();
        $component->call('editCustomerAssignment')->assertSet('customerEditorOpen', true);
        $component->call('openPlanning', $proposal->id, $proposal->revision)->assertHasErrors('intake')
            ->assertSet('customerEditorOpen', true)->assertSet('planningProposalId', null);
        $component->call('cancelCustomerAssignment')->call('openPlanning', $proposal->id, $proposal->revision)
            ->assertHasNoErrors()->set('planningSegments.0.planned_break_minutes', 45);
        $component->call('editCustomerAssignment')->assertHasErrors('intake')->assertSet('customerEditorOpen', false)
            ->assertSet('planningProposalId', $proposal->id)->assertSet('planningSegments.0.planned_break_minutes', 45);
        $component->call('addManualPosition')->assertHasErrors('intake')->assertSet('manualEditorOpen', false)
            ->assertSet('planningProposalId', $proposal->id)->assertSet('planningSegments.0.planned_break_minutes', 45);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('orders', 1);
        $this->assertNoExternalEffects();
    }

    public function test_outbox_handoff_preserves_drafts_and_closes_the_drawer_only_after_dirty_values_are_resolved(): void
    {
        $source = $this->source();
        $delivery = $source->deliveries()->create(['recipient_email' => 'qa@example.test', 'subject' => 'Synthetic outbox draft',
            'body' => 'Synthetic customer text awaiting approval', 'status' => 'draft', 'dedup_key' => str_repeat('e', 64),
            'settings_revision' => 5, 'intake_revision' => 1, 'source_revision' => 1, 'message_type' => 'clarification']);
        $before = $source->getRawOriginal();
        $deliveryBefore = $delivery->fresh()->getRawOriginal();
        $component = $this->drawer($source)->call('setDetailTab', 'communication')
            ->assertSee('Kundennachrichten im AI-Assistenten prüfen');
        $component->set('assignCustomerId', '')->call('openOutbox')->assertHasErrors('intake')
            ->assertNotDispatched('railtime-assistant-open')->assertNotDispatched('operations-assistant-action')
            ->assertSet('detailOpen', true);
        $component->set('assignCustomerId', (string) $this->customer->id)->call('openOutbox')->assertHasNoErrors()
            ->assertSet('detailOpen', false)->assertDispatched('railtime-assistant-open')
            ->assertDispatched('operations-assistant-action', action: 'outbox');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame($deliveryBefore, $delivery->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertNoExternalEffects();
    }

    public function test_standalone_assistant_tab_is_rejected_instead_of_showing_an_empty_panel(): void
    {
        $source = $this->source();
        $before = $source->getRawOriginal();
        $this->drawer($source)->call('setDetailTab', 'assistant')->assertStatus(422);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertNoExternalEffects();
    }

    public function test_next_correction_action_rejects_a_new_source_revision_without_claiming_manual_ownership(): void
    {
        $source = $this->source();
        $component = $this->drawer($source);
        $source->update(['source_revision' => 2, 'revision' => 2]);
        $before = $source->fresh()->getRawOriginal();
        $component->call('reviewNextPosition')->assertHasErrors('intake')->assertSet('manualEditorOpen', false);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame('assisted', $source->fresh()->processing_mode);
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertDatabaseCount('ai_intake_proposals', 0);
        $this->assertNoExternalEffects();
    }

    public function test_next_correction_action_rechecks_actor_deactivation_before_opening_an_editor(): void
    {
        $source = $this->source();
        $component = $this->drawer($source);
        $before = $source->getRawOriginal();
        $this->actor->update(['status' => false]);
        $component->call('reviewNextPosition')->assertStatus(403);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertDatabaseCount('ai_intake_proposals', 0);
        $this->assertNoExternalEffects();
    }

    public function test_embedded_assist_retains_its_four_tabs_and_own_conversation(): void
    {
        $source = $this->source();
        $component = Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $source->id, 'embedded' => true])
            ->assertSeeLivewire(AiIntakeAssistant::class);
        $dom = $this->dom($component->html());
        $tabs = $dom->query('//*[@role="tab"]');
        $this->assertSame(['Prüfen', 'Original', 'Postausgang', 'AI-Fragen'], array_map(fn ($tab) => trim($tab->textContent), iterator_to_array($tabs)));
        $component->call('setDetailTab', 'assistant')->assertSet('detailTab', 'assistant')
            ->assertDispatched('ai-intake-discussion-open');
        $this->assertSame('assisted', $source->fresh()->processing_mode);
        $this->assertNoExternalEffects();
    }

    private function twoPositionSource(array $changes = []): AiIntake
    {
        $source = $this->source(array_replace(['processing_mode' => 'manual', 'status' => 'review'], $changes));
        foreach (['inquiry', 'direct_order'] as $index => $path) {
            $source->proposals()->create(['source_revision' => 1, 'position_index' => $index, 'revision' => 1,
                'status' => 'proposed', 'payload' => ['demand' => array_replace($this->demand(), ['title' => 'Synthetic service '.($index + 1)]),
                    'segments' => [], 'business_path' => $path]]);
        }

        return $source->fresh();
    }

    public function test_position_selection_is_read_only_and_footer_actions_have_an_explicit_scope(): void
    {
        $source = $this->twoPositionSource();
        $proposals = $source->proposals()->orderBy('position_index')->get();
        $before = $source->getRawOriginal();
        $component = $this->drawer($source)->assertSet('drawerProposalId', $proposals[0]->id);
        $component->assertViewHas('drawerState', fn ($state) => $state['activeProposalId'] === $proposals[0]->id
            && $state['primary']['action'] === "confirmDrawerPosition('inquiry')"
            && $state['secondary']['action'] === "confirmDrawerPosition('direct_order')");
        $dom = $this->dom($component->html());
        foreach ($dom->query('//*[@data-intake-drawer]//button') as $button) {
            $command = $button->getAttribute('wire:click');
            $this->assertFalse((bool) preg_match('/^(confirm|takeDirectOrder|publishPlanning|openPlanning|openAssist|editCustomerAssignment|pause|reanalyze)/', $command), 'Workflow controls belong in the footer.');
        }
        $footer = $dom->query('//*[@data-intake-drawer-footer]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $footer);
        $commands = array_map(fn ($button) => $button->getAttribute('wire:click'), iterator_to_array($dom->query('.//button', $footer)));
        $this->assertContains("confirmDrawerPosition('inquiry')", $commands);
        $this->assertContains("confirmDrawerPosition('direct_order')", $commands);
        $this->assertContains('openAssist', $commands);
        $component->call('selectDrawerPosition', $proposals[1]->id)->assertHasNoErrors()
            ->assertSet('drawerProposalId', $proposals[1]->id)
            ->assertViewHas('drawerState', fn ($state) => $state['activeProposalId'] === $proposals[1]->id);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertNoExternalEffects();
    }

    public function test_saving_a_new_position_moves_the_display_and_acceptance_to_that_position(): void
    {
        $source = $this->source();
        $component = $this->drawer($source)->call('addManualPosition')->set('proposalForm.demand', $this->demand())
            ->call('saveManualPosition')->assertHasNoErrors();
        $first = $source->proposals()->sole();
        $component->assertSet('drawerProposalId', $first->id)->call('addManualPosition')
            ->set('proposalForm.demand', array_replace($this->demand(), ['title' => 'Newly saved second service']))
            ->call('saveManualPosition')->assertHasNoErrors();
        $second = $source->proposals()->where('id', '!=', $first->id)->sole();
        $component->assertSet('drawerProposalId', $second->id)->assertSee('Newly saved second service')
            ->assertViewHas('drawerState', fn ($state) => $state['activeProposalId'] === $second->id)
            ->call('confirmDrawerPosition', 'inquiry')->assertHasNoErrors();
        $this->assertNull($first->fresh()->inquiry_id);
        $this->assertNotNull($second->fresh()->inquiry_id);
        $this->assertDatabaseCount('operation_inquiries', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_accepting_one_inquiry_does_not_accept_the_other_direct_order_position(): void
    {
        $source = $this->twoPositionSource();
        [$first, $second] = $source->proposals()->orderBy('position_index')->get()->all();
        $secondBefore = $second->getRawOriginal();
        $this->drawer($source)->call('confirmDrawerPosition', 'inquiry')->assertHasNoErrors()
            ->assertViewHas('drawerState', fn ($state) => $state['primary']['key'] === 'inquiry' && $state['secondary']['key'] === 'direct');
        $this->assertDatabaseCount('operation_inquiries', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNotNull($first->fresh()->inquiry_id);
        $this->assertSame($secondBefore, $second->fresh()->getRawOriginal());
        $this->assertSame('review', $source->fresh()->status);
        $this->assertNoExternalEffects();
    }

    public function test_accepting_the_selected_direct_order_leaves_the_other_position_unconfirmed(): void
    {
        $source = $this->twoPositionSource();
        [$first, $second] = $source->proposals()->orderBy('position_index')->get()->all();
        $firstBefore = $first->getRawOriginal();
        $this->drawer($source)->call('selectDrawerPosition', $second->id)
            ->call('confirmDrawerPosition', 'direct_order')->assertHasNoErrors()
            ->assertSet('drawerProposalId', $second->id)
            ->assertViewHas('drawerState', fn ($state) => $state['primary']['action'] === 'openPlanning('.$second->id.','.$second->fresh()->revision.')'
                && $state['secondary']['key'] === 'order');
        $this->assertDatabaseCount('operation_inquiries', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertNotNull($second->fresh()->inquiry->order_id);
        $this->assertNull($second->fresh()->inquiry->offer);
        $this->assertSame($firstBefore, $first->fresh()->getRawOriginal());
        $this->assertSame(0, OperationAudit::whereIn('action', ['inquiry.offer', 'inquiry.accept'])->count());
        $this->assertNoExternalEffects();
    }

    public function test_inquiry_choice_overrides_the_selected_direct_draft_without_accepting_an_order(): void
    {
        $source = $this->twoPositionSource();
        $second = $source->proposals()->orderBy('position_index')->get()[1];
        $this->drawer($source)->call('selectDrawerPosition', $second->id)
            ->call('confirmDrawerPosition', 'inquiry')->assertHasNoErrors();
        $this->assertSame('inquiry', $second->fresh()->payload['business_path']);
        $this->assertSame('verified', $second->fresh()->inquiry->status);
        $this->assertDatabaseCount('operation_inquiries', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_direct_choice_is_explicit_even_when_the_original_proposal_was_an_inquiry(): void
    {
        $source = $this->twoPositionSource(['processing_mode' => 'assisted']);
        [$first, $second] = $source->proposals()->orderBy('position_index')->get()->all();
        $this->drawer($source)->call('confirmDrawerPosition', 'direct_order')->assertHasNoErrors();
        $this->assertSame('manual', $source->fresh()->processing_mode);
        $this->assertSame('direct_order', $first->fresh()->payload['business_path']);
        $this->assertNotNull($first->fresh()->inquiry->order_id);
        $this->assertNull($second->fresh()->inquiry_id);
        $this->assertDatabaseCount('operation_inquiries', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertNoExternalEffects();
    }

    public function test_direct_choice_rechecks_permission_before_claiming_or_saving_any_source(): void
    {
        $source = $this->twoPositionSource(['processing_mode' => 'assisted']);
        $before = $source->getRawOriginal();
        $proposalsBefore = $source->proposals()->get()->map->getRawOriginal()->all();
        $limited = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn () => true);
        Gate::define('operations.manage', fn () => false);
        $this->actingAs($limited);
        $this->drawer($source)->call('confirmDrawerPosition', 'direct_order')->assertStatus(403);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame($proposalsBefore, $source->proposals()->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertNoExternalEffects();
    }

    public function test_selected_confirmation_rejects_a_changed_sibling_before_manual_takeover(): void
    {
        $source = $this->twoPositionSource(['processing_mode' => 'assisted']);
        $component = $this->drawer($source);
        $source->proposals()->orderByDesc('position_index')->first()->increment('revision');
        $before = $source->fresh()->getRawOriginal();
        $component->call('confirmDrawerPosition', 'inquiry')->assertHasErrors('intake');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertNoExternalEffects();
    }

    public function test_rejected_selected_confirmation_rolls_back_takeover_without_staling_the_drawer_snapshot(): void
    {
        $source = $this->twoPositionSource(['processing_mode' => 'assisted']);
        $proposal = $source->proposals()->orderBy('position_index')->first();
        $payload = $proposal->payload;
        $payload['demand']['ends_at'] = '2027-05-12T16:00';
        $proposal->update(['payload' => $payload]);
        $before = $source->getRawOriginal();
        $proposalsBefore = $source->proposals()->get()->map->getRawOriginal()->all();
        $this->drawer($source)->call('confirmDrawerPosition', 'direct_order')->assertHasErrors('intake')
            ->assertSet('selectedRevision', $source->revision)
            ->assertViewHas('drawerState', fn ($state) => $state['stale'] === false);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame($proposalsBefore, $source->proposals()->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('operation_audits', 0);
        $this->assertNoExternalEffects();
    }

    public function test_new_footer_actions_and_position_switch_preserve_an_unsaved_form(): void
    {
        $source = $this->twoPositionSource();
        [$first, $second] = $source->proposals()->orderBy('position_index')->get()->all();
        $component = $this->drawer($source)->call('editProposal', $first->id)
            ->set('proposalForm.demand.title', 'Unsaved selected position');
        $before = $source->fresh()->getRawOriginal();
        $component->call('selectDrawerPosition', $second->id)->assertHasErrors('intake')
            ->assertSet('drawerProposalId', $first->id)->assertSet('proposalForm.demand.title', 'Unsaved selected position');
        $component->call('confirmDrawerPosition', 'direct_order')->assertHasErrors('intake')
            ->call('pause')->assertHasErrors('intake')
            ->assertSet('proposalForm.demand.title', 'Unsaved selected position');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_paused_source_cannot_be_accepted_through_a_new_footer_action(): void
    {
        $source = $this->twoPositionSource(['status' => 'paused', 'paused_at' => now()]);
        $before = $source->getRawOriginal();
        $this->drawer($source)->assertViewHas('drawerState', fn ($state) => $state['primary'] === null && $state['secondary'] === null)
            ->call('confirmDrawerPosition', 'direct_order')->assertHasErrors('intake');
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('operation_inquiries', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNoExternalEffects();
    }

    public function test_existing_bulk_confirmation_still_uses_each_positions_saved_path(): void
    {
        $source = $this->twoPositionSource();
        $this->drawer($source)->call('confirmManual')->assertHasNoErrors();
        $this->assertDatabaseCount('operation_inquiries', 2);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('ready', $source->fresh()->status);
        $this->assertNoExternalEffects();
    }
}
