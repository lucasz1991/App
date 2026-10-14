<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Customers;
use App\Livewire\Operations\AiIntakeAssistant;
use App\Livewire\Operations\AiIntakeInbox;
use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeDelivery;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeInboxEmbeddedTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php',
            '2026_10_09_090000_create_staffing_automation_runs.php', '2026_10_09_100000_add_ai_customer_communication_controls.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        if (! Schema::hasColumn('shifts', 'disposition_details')) {
            Schema::table('shifts', fn ($table) => $table->json('disposition_details')->nullable());
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        config(['view.compiled' => sys_get_temp_dir().'/railtime-intake-embedded-'.getmypid()]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['enabled' => false, 'revision' => 5]));
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('intake-embedded-'.getmypid()));
        $this->withoutVite();
    }

    private function intake(array $changes = []): AiIntake
    {
        $intake = AiIntake::create(array_replace(['source_type' => 'manual', 'status' => 'review', 'title' => 'Synthetic panel intake', 'revision' => 1, 'source_revision' => 1,
            'analysis' => ['intent' => 'inquiry'], 'missing_fields' => [], 'created_by' => $this->actor->id], $changes));
        $source = $intake->messages()->create(['direction' => 'inbound', 'body' => 'Original synthetic panel source', 'metadata' => ['subject' => 'Synthetic source']]);
        $intake->update(['latest_inbound_message_id' => $source->id]);

        return $intake;
    }

    private function payload(): array
    {
        return ['demand' => ['title' => 'Synthetic proposal', 'role_name' => 'Tf', 'location_name' => 'Hamburg', 'starts_at' => '2027-05-13T08:00',
            'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'qualification_ids' => []],
            'segments' => [], 'evidence' => ['location_name' => ['quote' => 'Original Hamburg', 'message_id' => 1]], 'missing_fields' => []];
    }

    private function noSideEffects(): void
    {
        $this->assertSame(0, OperationInquiry::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
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

    public function test_compact_list_has_native_icon_filter_and_icon_only_contextual_discussion(): void
    {
        $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true])->assertSeeLivewire(AiIntakeAssistant::class);
        $dom = $this->dom($component->html());
        $rows = $dom->query('//li[contains(@class,"ai-intake-panel__row")]');
        $this->assertSame(1, $rows->length);
        $icons = $dom->query('//button[contains(@class,"ai-intake-panel__discuss")]');
        $this->assertSame(1, $icons->length);
        $this->assertSame('', trim($icons->item(0)->textContent));
        $this->assertStringContainsString('mit AI besprechen', $icons->item(0)->getAttribute('aria-label'));
        $filter = $dom->query('//*[@data-rt-custom-select and @data-autosave-model="statusFilter"]');
        $this->assertSame(1, $filter->length);
        foreach (json_decode($filter->item(0)->getAttribute('data-rt-select-options'), true, 32, JSON_THROW_ON_ERROR) as $option) {
            $this->assertNull($option['icon']);
            $this->assertStringStartsWith('far fa-', $option['iconClass']);
        }
        $this->assertSame(0, $dom->query('//*[@role="dialog"]')->length);
        $this->assertSame(1, $dom->query('//*[contains(@class,"ai-intake-panel__composer")]')->length);
        $this->noSideEffects();
    }

    public function test_recognized_customer_is_shown_as_assigned_and_can_still_be_corrected(): void
    {
        $customer = Customer::create(['company_name' => 'Recognized Rail Customer', 'is_active' => true]);
        $replacement = Customer::create(['company_name' => 'Personally Corrected Customer', 'is_active' => true]);
        $intake = $this->intake(['customer_id' => $customer->id, 'match_method' => 'source_context',
            'analysis' => ['intent' => 'inquiry', 'customer_draft' => ['company_name' => 'Obsolete new-customer draft']]]);

        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])
            ->assertSet('assignCustomerId', (string) $customer->id)
            ->assertSee('Automatisch erkannt und zugeordnet')->assertSee('Kundenzuordnung ändern')
            ->assertDontSee('Zuordnung bestätigen')->assertDontSee('Obsolete new-customer draft')
            ->assertDontSee('Neuen Kunden prüfen und anlegen');
        $form = $this->dom($component->html())->query('//form[@*[name()="wire:submit"]="assignCustomer"]')->item(0);
        $this->assertSame('editingCustomer', $form->getAttribute('x-show'));
        $this->assertTrue($form->hasAttribute('x-cloak'));
        $this->assertSame($customer->id, $intake->fresh()->customer_id);

        $component->set('assignCustomerId', (string) $replacement->id)->call('assignCustomer')->assertHasNoErrors()
            ->assertSee('Kunde zugeordnet')->assertDontSee('Automatisch erkannt und zugeordnet');
        $this->assertSame($replacement->id, $intake->fresh()->customer_id);
        $this->assertSame('manual', $intake->fresh()->match_method);
        $this->noSideEffects();
    }

    public function test_review_actions_remain_outside_the_scroll_area_and_disclosures_preserve_evidence(): void
    {
        $intake = $this->intake(['source_type' => 'email']);
        $proposal = $intake->proposals()->create(['revision' => 1, 'source_revision' => 1, 'position_index' => 0, 'status' => 'proposed', 'payload' => $this->payload()]);
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->assertSee('Erneut prüfen')->assertSee('Anfragen übernehmen');
        $dom = $this->dom($component->html());
        $dock = $dom->query('//*[@role="group" and @aria-label="Eingang bearbeiten"]')->item(0);
        $this->assertNotNull($dock);
        $this->assertSame(0, $dom->query('ancestor::*[@role="tabpanel"]', $dock)->length);
        $this->assertSame(1, $dom->query('.//button[@*[name()="wire:click"]="reanalyze"]', $dock)->length);
        $this->assertSame(1, $dom->query('.//button[@*[name()="wire:click"]="createInquiries"]', $dock)->length);
        $this->assertSame(6, $dom->query('//*[contains(@class,"rt-intake-facts__item")]')->length);
        $component->assertSee('13.05.2027 · 08:00')->assertSee('2 Personen')->assertSee('Original Hamburg');
        $this->assertSame(1, $dom->query('//*[@data-rt-accordion-section="position-'.$proposal->id.'"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-rt-accordion-section="evidence"]')->length);
        $component->call('setDetailTab', 'sources');
        $this->assertSame(0, $this->dom($component->html())->query('//*[@role="group" and @aria-label="Eingang bearbeiten"]')->length);
        $component->call('setDetailTab', 'review')->call('editProposal', $proposal->id)->assertSet('editingProposalId', $proposal->id)->assertSee('Korrektur speichern');
        $this->noSideEffects();
    }

    public function test_non_inquiry_keeps_original_access_without_active_review_dock(): void
    {
        $intake = $this->intake(['status' => 'not_inquiry']);
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->assertSee('Keine Anfrage')->assertDontSee('Anfragen übernehmen');
        $this->assertSame(0, $this->dom($component->html())->query('//*[@role="group" and @aria-label="Eingang bearbeiten"]')->length);
        $component->call('setDetailTab', 'sources')->assertSee('Original synthetic panel source');
        $this->noSideEffects();
    }

    public function test_default_staff_and_historical_values_stay_marked_outside_collapsed_evidence(): void
    {
        $intake = $this->intake();
        $payload = $this->payload();
        $payload['demand']['required_staff'] = 1;
        $payload['assumptions'] = ['required_staff' => ['basis' => 'default'], 'history_review' => true];
        $intake->proposals()->create(['revision' => 1, 'source_revision' => 1, 'position_index' => 0, 'status' => 'proposed', 'payload' => $payload]);
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id]);
        $dom = $this->dom($component->html());
        foreach (['Standardvorschlag: 1 Person', 'Historische Vergleichsdaten verwendet'] as $notice) {
            $item = $dom->query('//p[contains(text(),"'.$notice.'")]')->item(0);
            $this->assertNotNull($item);
            $this->assertSame(0, $dom->query('ancestor::*[@data-rt-accordion-section="evidence"]', $item)->length);
        }
        $this->noSideEffects();
    }

    public function test_search_status_and_pagination_keep_the_complete_scoped_inbox_browsable(): void
    {
        for ($index = 1; $index <= 14; $index++) {
            $this->intake(['title' => 'Browsable intake '.$index]);
        }
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true]);
        $this->assertSame(12, $this->dom($component->html())->query('//li[contains(@class,"ai-intake-panel__row")]')->length);
        $component->call('gotoPage', 2, 'aiIntakePage');
        $this->assertSame(2, $this->dom($component->html())->query('//li[contains(@class,"ai-intake-panel__row")]')->length);
        $component->set('search', 'Browsable intake 14')->assertSet('paginators.aiIntakePage', 1)->assertSee('Browsable intake 14')->assertDontSee('Browsable intake 13')
            ->set('statusFilter', 'paused')->assertSee('Noch keine AI-Eingänge für diese Auswahl.');
        $this->noSideEffects();
    }

    public function test_parent_scoped_open_event_ignores_other_hosts_and_keeps_capture_drafts(): void
    {
        $intake = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'parentComponentId' => 'owned-parent'])
            ->set('text', 'Capture draft remains in its own host')
            ->dispatch('open-ai-intake', intakeId: PHP_INT_MAX, create: false, parentComponentId: 'other-parent')
            ->assertSet('selectedId', null)->assertSet('detailOpen', false)
            ->assertSet('text', 'Capture draft remains in its own host')
            ->dispatch('open-ai-intake', intakeId: $intake->id, create: false, parentComponentId: 'owned-parent')
            ->assertSet('selectedId', $intake->id)->assertSet('detailOpen', true);
        $this->noSideEffects();
    }

    public function test_select_discuss_and_back_use_inline_views_and_host_scoped_composer_events(): void
    {
        $intake = $this->intake();
        Livewire::test(AiIntakeInbox::class, ['embedded' => true])->call('select', $intake->id)->assertSet('selectedRevision', 1)->assertSet('selectedSettingsRevision', 5)
            ->assertSet('detailTab', 'review')->assertSet('detailOpen', true)->assertSee('Kundenzuordnung')->assertSee('Nachrichten und Anlagen')
            ->assertDispatched('ai-intake-selected', intakeId: $intake->id, customerId: null)->assertDispatched('select-ai-intake-assistant', intakeId: $intake->id)
            ->call('discuss', $intake->id)->assertSet('detailTab', 'assistant')->assertDispatched('ai-intake-discussion-open')
            ->call('closeDetail')->assertSet('selectedId', null)->assertSet('detailOpen', false)->assertDispatched('ai-intake-assistant-overview', customerId: null);
        $this->noSideEffects();
    }

    public function test_answer_opens_only_the_current_host_discussion_without_resetting_selected_state(): void
    {
        $intake = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id]);
        $component->dispatch('ai-intake-question-answered', hostComponentId: 'another-host', intakeId: $intake->id)->assertSet('detailTab', 'review');
        $component->dispatch('ai-intake-question-answered', hostComponentId: $component->instance()->getId(), intakeId: $intake->id + 1)->assertSet('detailTab', 'review');
        $component->dispatch('ai-intake-question-answered', hostComponentId: $component->instance()->getId(), intakeId: $intake->id)
            ->assertSet('detailTab', 'assistant')->assertSet('selectedId', $intake->id)->assertSet('selectedRevision', 1)
            ->assertDispatched('ai-intake-discussion-open');
        $this->noSideEffects();
    }

    public function test_initial_capture_and_explicit_text_file_capture_preserve_the_native_ingestion_path(): void
    {
        $file = UploadedFile::fake()->createWithContent('native-note.txt', 'Attached original intake note.');
        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialCapture' => true])->assertSet('captureOpen', true)->assertSee('Eingang erfassen')
            ->assertSee('Dateien anhängen')->assertSee('Sprache aufnehmen')->set('text', 'Human captured native request')->set('uploads', [$file])
            ->call('submit')->assertHasNoErrors()->assertSet('captureOpen', false)->assertSet('detailOpen', true)->assertDispatched('ai-intake-submitted');
        $this->assertSame(1, AiIntake::count());
        $this->assertSame('Human captured native request', AiIntake::firstOrFail()->messages()->firstOrFail()->body);
        $this->assertSame(1, AiIntakeAttachment::count());
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->noSideEffects();
    }

    public function test_closing_capture_keeps_text_and_finished_file_drafts_for_reopen(): void
    {
        $file = UploadedFile::fake()->createWithContent('draft.txt', 'Temporary captured draft.');
        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialCapture' => true])->set('text', 'Unsent text')->set('contactEmail', 'draft@example.test')->set('uploads', [$file])
            ->call('closeCapture')->assertSet('captureOpen', false)->assertSet('text', 'Unsent text')->assertSet('contactEmail', 'draft@example.test')->assertCount('uploads', 1)
            ->call('openCapture')->assertSet('captureOpen', true)->assertCount('uploads', 1)->call('removeUpload', 0)->assertCount('uploads', 0)->assertSet('text', 'Unsent text');
        $this->assertSame(0, AiIntake::count());
        $this->noSideEffects();
    }

    public function test_customer_scope_and_modal_only_compatibility_are_preserved(): void
    {
        $customer = Customer::create(['company_name' => 'Scoped customer', 'is_active' => true]);
        $own = $this->intake(['customer_id' => $customer->id]);
        $other = $this->intake(['title' => 'Foreign customer private intake']);
        Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $own->id, 'modalOnly' => true])->assertSet('detailOpen', true)->assertSet('embedded', false);
        $component = Livewire::test(AiIntakeInbox::class, ['customerId' => $customer->id, 'embedded' => true])->assertDontSee('Foreign customer private intake');
        $this->expectException(ModelNotFoundException::class);
        $component->dispatch('open-ai-intake', intakeId: $other->id, create: false);
    }

    public function test_embedded_event_does_not_open_a_standard_modal_instance(): void
    {
        $intake = $this->intake();
        Livewire::test(AiIntakeInbox::class)->dispatch('open-ai-intake', intakeId: $intake->id, create: false)->assertSet('detailOpen', false)->assertSet('selectedId', null);
        $this->noSideEffects();
    }

    public function test_email_recognition_uses_locked_revisions_when_automation_is_off(): void
    {
        $intake = $this->intake(['source_type' => 'email']);
        $service = $this->mock(AiIntakeService::class);
        $service->shouldReceive('analyzeManually')->once()->with(Mockery::on(fn ($value) => $value->id === $intake->id), Mockery::on(fn ($value) => $value->id === $this->actor->id), 1, 5)->andReturn($intake);
        $service->shouldNotReceive('reanalyze');

        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->assertSee('Erneut prüfen')->call('reanalyze')->assertHasNoErrors();
        $this->noSideEffects();
    }

    public function test_provider_failure_is_safe_and_keeps_the_selected_intake_available(): void
    {
        $intake = $this->intake(['source_type' => 'email']);
        $service = $this->mock(AiIntakeService::class);
        $service->shouldReceive('analyzeManually')->once()->andThrow(new OpenRouterChatException('upstream_http_error', 404, new \RuntimeException('synthetic-private-provider-body')));

        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->call('reanalyze')->assertHasErrors('intake')->assertSet('selectedId', $intake->id)
            ->assertSee('Der Anbieter unterstützt das eingestellte AI-Modell oder die angeforderten Funktionen derzeit nicht.')->assertDontSee('synthetic-private-provider-body');
        $this->noSideEffects();
    }

    public function test_proposal_editor_keeps_native_fields_segments_qualifications_and_version_checks(): void
    {
        $intake = $this->intake();
        $proposal = $intake->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1, 'status' => 'proposed', 'payload' => $this->payload()]);
        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->call('editProposal', $proposal->id)->assertSet('proposalRevision', 1)
            ->assertSee('Korrektur speichern')->assertSee('Erforderliche Qualifikationen')->call('addSegment')->assertCount('proposalForm.segments', 1)
            ->call('removeSegment', 0)->assertCount('proposalForm.segments', 0)->set('proposalForm.demand.title', 'Personally corrected native proposal')->call('saveProposal')->assertHasNoErrors();
        $this->assertSame('Personally corrected native proposal', $proposal->fresh()->payload['demand']['title']);
        $this->assertSame(2, $proposal->fresh()->revision);
        $this->noSideEffects();
    }

    public function test_stale_proposals_do_not_hide_current_amendment_analysis_or_offer_old_edit_controls(): void
    {
        $intake = $this->intake(['source_type' => 'email', 'processed_source_revision' => 1, 'analysis' => ['intent' => 'amendment', 'classification' => 'reply',
            'positions' => [['title' => 'Fresh amendment preview', 'role_name' => 'WU', 'location_name' => 'Hamburg Hohe Schaar',
                'starts_at' => '2027-05-13T16:00', 'ends_at' => '2027-05-13T18:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 1,
                'missing_fields' => ['required_staff', 'qualification_ids', 'unknown_private_key'],
                'assumptions' => ['required_staff' => ['basis' => 'default'], 'history_review' => true]]]]]);
        $oldPayload = $this->payload();
        $oldPayload['demand']['title'] = 'Obsolete proposal without times';
        $oldPayload['demand']['starts_at'] = $oldPayload['demand']['ends_at'] = null;
        $proposal = $intake->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1, 'status' => 'stale', 'payload' => $oldPayload]);
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])
            ->assertSee('Fresh amendment preview')->assertSee('13.05.2027 · 16:00')->assertSee('13.05.2027 · 18:00')->assertSee('Hamburg Hohe Schaar')
            ->assertSee('Standardvorschlag: 1 Person')->assertSee('Historische Vergleichsdaten verwendet')
            ->assertSee('Noch zu prüfen: Personalbedarf · Qualifikationen')->assertDontSee('unknown_private_key')->assertDontSee('Obsolete proposal without times')
            ->assertDontSeeHtml('wire:click="editProposal('.$proposal->id.')"')->assertDontSeeHtml('wire:click="createInquiries"');
        $preview = $this->dom($component->html())->query('//*[@data-ai-intake-analysis-preview]');
        $this->assertSame(1, $preview->length);
        $this->assertSame(1, $this->dom($component->html())->query('//*[@data-ai-intake-analysis-preview]//button[@aria-controls]')->length);
        $this->assertSame(0, $this->dom($component->html())->query('//*[@data-ai-intake-analysis-preview]//button[@*[name()="wire:click"]]')->length);
        $this->assertSame('stale', $proposal->fresh()->status);
        $this->assertSame($oldPayload, $proposal->fresh()->payload);
        $this->noSideEffects();
    }

    public function test_current_native_proposal_remains_primary_and_older_sources_are_not_editable(): void
    {
        $intake = $this->intake(['source_revision' => 2, 'processed_source_revision' => 2, 'analysis' => ['positions' => [['title' => 'Analysis should not replace corrected proposal']]]]);
        $oldPayload = $this->payload();
        $oldPayload['demand']['title'] = 'Obsolete source position';
        $old = $intake->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1, 'status' => 'proposed', 'payload' => $oldPayload]);
        $currentPayload = $this->payload();
        $currentPayload['demand']['title'] = 'Current personally corrected position';
        $current = $intake->proposals()->create(['source_revision' => 2, 'position_index' => 0, 'revision' => 2, 'status' => 'proposed', 'payload' => $currentPayload]);
        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])
            ->assertSee('Current personally corrected position')->assertDontSee('Obsolete source position')->assertDontSee('Analysis should not replace corrected proposal')
            ->assertSeeHtml('wire:click="editProposal('.$current->id.')"')->assertDontSeeHtml('wire:click="editProposal('.$old->id.')"')
            ->assertDontSeeHtml('data-ai-intake-analysis-preview');
        $this->noSideEffects();
    }

    public function test_unprocessed_analysis_is_not_previewed_but_historical_native_links_remain_readonly(): void
    {
        $intake = $this->intake(['source_revision' => 2, 'processed_source_revision' => 1, 'analysis' => ['positions' => [['title' => 'Outdated analysis facts']]]]);
        $inquiry = OperationInquiry::create(['channel' => 'manual', 'title' => 'Previously captured native inquiry', 'status' => 'new', 'timezone' => 'Europe/Berlin',
            'original' => 'Synthetic prior native source', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $payload = $this->payload();
        $payload['demand']['title'] = 'Obsolete linked position facts';
        $proposal = $intake->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1, 'status' => 'stale', 'inquiry_id' => $inquiry->id, 'payload' => $payload]);
        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])
            ->assertSee('Noch keine Positionen ausgewertet.')->assertDontSee('Outdated analysis facts')->assertDontSee('Obsolete linked position facts')
            ->assertSee('Frühere Anfragen')->assertSee('Native Anfrage '.$inquiry->number.' öffnen')
            ->assertDontSeeHtml('wire:click="editProposal('.$proposal->id.')"')->assertDontSeeHtml('wire:click="createInquiries"');
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(1, $inquiry->fresh()->revision);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_new_customer_form_handoff_and_source_accordions_remain_native_and_escaped(): void
    {
        $intake = $this->intake(['title' => '<script>private title</script>']);
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->call('setDetailTab', 'sources')
            ->assertSee('Original synthetic panel source')->assertDontSeeHtml('<script>private title</script>')->assertSeeHtml('data-rt-accordion-section="message-');
        $component->call('setDetailTab', 'review')->call('prepareCustomer')->assertSet('customerDraftOpen', true)->assertSeeLivewire(Customers::class);
        $this->assertSame(0, Customer::count());
        $this->noSideEffects();
    }

    public function test_delivery_preview_retains_outbox_handoff_and_private_test_unknown_contracts(): void
    {
        $intake = $this->intake();
        foreach (['draft', 'unknown'] as $status) {
            $intake->deliveries()->create(['recipient_email' => 'synthetic@example.test', 'subject' => 'Synthetic delivery', 'body' => 'Human preview '.$status, 'status' => $status,
                'dedup_key' => hash('sha256', $status), 'source_revision' => 1, 'settings_revision' => 5, 'intake_revision' => 1]);
        }
        $intake->deliveries()->create(['recipient_email' => 'synthetic@example.test', 'subject' => 'Private test', 'body' => 'Test only message', 'status' => 'draft',
            'dedup_key' => hash('sha256', 'test'), 'source_revision' => 1, 'settings_revision' => 5, 'intake_revision' => 1, 'metadata' => ['manual_mailbox_test' => true]]);

        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id])->call('setDetailTab', 'communication')->assertSee('Testentwurf · ohne Versand')
            ->assertSee('Der Versand wird nicht automatisch wiederholt.')->assertSee('Kundennachrichten im AI-Assistenten prüfen')->assertSeeHtml('operations-assistant-action');
        $this->assertSame(3, AiIntakeDelivery::count());
        $this->noSideEffects();
    }

    public function test_revoked_actor_and_forged_embedded_context_remain_rejected(): void
    {
        $intake = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $intake->id]);
        User::whereKey($this->actor->id)->update(['status' => false]);
        $component->call('openCapture')->assertForbidden();
        User::whereKey($this->actor->id)->update(['status' => true]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(AiIntakeInbox::class, ['embedded' => true])->set('embedded', false);
    }
}
