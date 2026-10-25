<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeAssistant;
use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\InquiryInbox;
use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class IndependentIntakeFormUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_09_090000_create_staffing_automation_runs.php', '2026_10_09_100000_add_ai_customer_communication_controls.php', '2026_10_25_110000_add_manual_processing_mode_to_ai_intakes.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic form customer', 'is_active' => true]);
        $this->actingAs($this->actor);
        config(['view.compiled' => sys_get_temp_dir().'/railtime-independent-form-ui-'.getmypid()]);
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
    }

    private function source(): AiIntake
    {
        return AiIntake::create(['source_type' => 'email', 'title' => 'Synthetic unanalysed source', 'customer_id' => $this->customer->id, 'status' => 'received', 'source_revision' => 1, 'revision' => 1, 'supervising_user_id' => $this->actor->id]);
    }

    private function demand(): array
    {
        return ['title' => 'Synthetic service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2, 'qualification_ids' => []];
    }

    private function assertUsableButton(string $html, string $action): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        foreach ($document->getElementsByTagName('button') as $button) {
            if ($button->getAttribute('wire:click') === $action) {
                $this->assertFalse($button->hasAttribute('disabled'), $action.' must be usable in the rendered UI.');

                return;
            }
        }
        $this->fail('Usable '.$action.' button is missing from the rendered UI.');
    }

    public function test_opening_source_stays_read_only_and_assist_is_explicit(): void
    {
        $source = $this->source();
        $component = Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $source->id, 'modalOnly' => true])
            ->assertSee('Position per Formular erfassen')->assertSee('Mit AI-Assist öffnen')
            ->assertDontSeeLivewire(AiIntakeAssistant::class);
        $this->assertSame('assisted', $source->fresh()->processing_mode);
        $this->assertSame(1, $source->fresh()->revision);
        $this->assertUsableButton($component->html(), 'addManualPosition');
        $this->assertUsableButton($component->html(), 'openAssist');
        $component->call('openAssist')->assertDispatched('open-ai-intake-panel', intakeId: $source->id, create: false, customerId: $this->customer->id)->assertSet('detailOpen', false);
        $this->assertSame('assisted', $source->fresh()->processing_mode);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_raw_source_can_be_saved_and_confirmed_as_native_inquiry_without_ai(): void
    {
        $source = $this->source();
        $component = Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $source->id, 'modalOnly' => true])
            ->call('addManualPosition')->assertSet('manualEditorOpen', true)
            ->set('proposalForm.demand', $this->demand())->call('saveManualPosition')->assertHasNoErrors()
            ->assertSee('Synthetic service');
        $this->assertUsableButton($component->html(), 'confirmManual');
        $component->call('confirmManual')->assertHasNoErrors();
        $source->refresh();
        $this->assertSame('manual', $source->processing_mode);
        $this->assertNull($source->processed_source_revision);
        $this->assertDatabaseCount('operation_inquiries', 1);
        $inquiry = OperationInquiry::firstOrFail();
        $this->assertSame('verified', $inquiry->status);
        $this->assertNull($inquiry->order_id);
        $component->call('confirmManual')->assertHasNoErrors();
        $this->assertDatabaseCount('operation_inquiries', 1);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_raw_source_direct_path_creates_order_without_offer_or_customer_acceptance(): void
    {
        $source = $this->source();
        $component = Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $source->id, 'modalOnly' => true])
            ->call('addManualPosition')->set('proposalForm.demand', $this->demand())
            ->set('proposalForm.business_path', 'direct_order')->call('saveManualPosition')->assertHasNoErrors()
            ->assertSee('Bestätigen und Direktaufträge annehmen')->call('confirmManual')->assertHasNoErrors();
        $inquiry = OperationInquiry::firstOrFail();
        $this->assertSame('converted', $inquiry->status);
        $this->assertNotNull($inquiry->order_id);
        $this->assertNull($inquiry->offer);
        $component->call('confirmManual')->assertHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unsaved_source_form_blocks_assist_switch(): void
    {
        Livewire::test(AiIntakeInbox::class, ['initialIntakeId' => $this->source()->id, 'modalOnly' => true])
            ->call('addManualPosition')->set('proposalForm.demand.title', 'Unsaved position')
            ->call('openAssist')->assertHasErrors('intake')->assertNotDispatched('open-ai-intake-panel')
            ->assertSet('proposalForm.demand.title', 'Unsaved position');
    }

    public function test_native_inquiry_remains_provider_free_and_has_direct_order_action(): void
    {
        $form = $this->demand() + ['customer_id' => $this->customer->id, 'channel' => 'manual', 'original' => 'Synthetic direct instruction'];
        Livewire::test(InquiryInbox::class, ['modalOnly' => true])->set('form', $form)
            ->call('saveAndTakeDirectOrder')->assertHasNoErrors();
        $record = OperationInquiry::firstOrFail();
        $this->assertSame('converted', $record->status);
        $this->assertNotNull($record->order_id);
        $this->assertDatabaseCount('ai_intakes', 0);
        $this->assertNull($record->offer);
        $this->assertSame(0, OperationAudit::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)->whereIn('action', ['inquiry.offer', 'inquiry.accept'])->count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_native_inquiry_assist_uses_saved_context_and_neutral_form_links(): void
    {
        $record = app(InquiryWorkflowService::class)->save(null, $this->demand() + ['customer_id' => $this->customer->id, 'channel' => 'manual', 'original' => 'Synthetic instruction'], $this->actor, null);
        Livewire::test(InquiryInbox::class, ['initialInquiryId' => $record->id, 'modalOnly' => true])
            ->call('openAssist')->assertDispatched('open-inquiry-assist', inquiryId: $record->id, revision: $record->revision, customerId: $this->customer->id);
        $this->assertStringNotContainsString('assistant=', OperationsPages::intakeFormUrl(42, $this->customer->id));
        $this->assertStringContainsString('assistant=intake', OperationsPages::intakePanelUrl(42, $this->customer->id));
        $this->assertDatabaseCount('ai_intakes', 0);
    }
}
