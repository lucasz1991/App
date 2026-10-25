<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeMailService;
use App\Services\Operations\AiIntakeManualService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\Operations\AiDispositionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeFormProcessingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Customer $customer;

    private AiIntakeManualService $manual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php',
            '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_09_090000_create_staffing_automation_runs.php',
            '2026_10_09_100000_add_ai_customer_communication_controls.php', '2026_10_11_120000_add_confirmed_routing_locations.php',
            '2026_10_25_110000_add_manual_processing_mode_to_ai_intakes.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00Z'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        $this->customer = Customer::create(['company_name' => 'Synthetic form customer', 'email' => 'source@example.test', 'is_active' => true]);
        OperationsRuleProfile::create(['name' => 'Synthetic rules', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720,
            'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()]);
        $this->settings(false);
        config(['view.compiled' => sys_get_temp_dir().'/railtime-form-processing-'.getmypid()]);
        Storage::set('local', Storage::fake('ai-intake-form-'.getmypid()));
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->manual = app(AiIntakeManualService::class);
    }

    private function settings(bool $enabled): void
    {
        Setting::setValue('operations', 'ai_disposition', array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => $enabled, 'supervisor_id' => $this->actor->id, 'revision' => 4, 'customer_receipt_mode' => 'automatic', 'from_address' => 'dispatch@example.test']));
    }

    private function intake(array $changes = []): AiIntake
    {
        $intake = AiIntake::create(array_replace(['source_type' => 'email', 'title' => 'Synthetic unanalysed source', 'status' => 'failed',
            'customer_id' => $this->customer->id, 'revision' => 1, 'source_revision' => 1, 'created_by' => $this->actor->id, 'error_code' => 'processing_failed'], $changes));
        $message = $intake->messages()->create(['direction' => 'inbound', 'sender_email' => 'source@example.test', 'external_message_id' => 'form-'.$intake->id.'@example.test',
            'body' => 'Synthetic original preserved.', 'metadata' => ['mailbox_id' => 'synthetic-mailbox']]);
        $intake->update(['latest_inbound_message_id' => $message->id]);

        return $intake->fresh();
    }

    private function payload(array $changes = []): array
    {
        return ['demand' => array_replace(['title' => 'Synthetic manual service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00',
            'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2, 'qualification_ids' => []], $changes), 'segments' => [], 'business_path' => 'inquiry'];
    }

    private function saved(?AiIntake $intake = null): array
    {
        $intake ??= $this->intake();
        $intake = $this->manual->claimManual($intake, $this->actor, $intake->revision);
        $proposal = $this->manual->savePosition($intake, $this->actor, $intake->revision, $intake->source_revision, $this->payload());

        return [$intake->fresh(), $proposal];
    }

    private function confirm(AiIntake $intake): AiIntake
    {
        return $this->manual->confirm($intake, $this->actor, $intake->revision, $intake->source_revision,
            $intake->proposals()->where('source_revision', $intake->source_revision)->pluck('revision', 'id')->all());
    }

    private function rejected(callable $callback, int $status = 409): void
    {
        try {
            $callback();
            $this->fail('Expected a rejected stale or invalid command.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function noExternalEffects(): void
    {
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_raw_failed_source_can_be_saved_and_confirmed_without_provider_analysis_or_settings(): void
    {
        $this->mock(AiIntakeExtractionService::class)->shouldNotReceive('extract');
        [$intake, $proposal] = $this->saved();
        $confirmed = $this->confirm($intake);
        $inquiry = OperationInquiry::sole();
        $proposal->refresh();
        $this->assertSame('manual', $confirmed->processing_mode);
        $this->assertNull($confirmed->analysis);
        $this->assertNull($confirmed->processed_source_revision);
        $this->assertSame('verified', $inquiry->status);
        $this->assertSame($inquiry->id, $proposal->inquiry_id);
        $this->assertSame($this->customer->id, $inquiry->customer_id);
        $this->assertSame('manual', $proposal->payload['provenance']['kind']);
        $this->assertSame('form', $proposal->payload['review_confirmation']['method']);
        $this->assertSame('off', $proposal->payload['review_confirmation']['customer_communication']);
        $this->assertSame('manual', $proposal->payload['review_confirmation']['planning_mode']);
        $this->assertSame(0, $intake->runs()->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Shift::count());
        $this->assertSame('Synthetic original preserved.', $intake->messages()->sole()->body);
        $this->noExternalEffects();
    }

    public function test_confirmation_and_native_assist_bridge_are_idempotent_and_keep_later_form_corrections(): void
    {
        [$intake, $proposal] = $this->saved();
        $confirmed = $this->confirm($intake);
        $again = $this->confirm($confirmed);
        $this->assertSame($confirmed->revision, $again->revision);
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(1, OperationAudit::where('action', 'intake.manual_confirmed')->count());
        $proposal->refresh();
        $inquiry = $proposal->inquiry;
        $bridge = $this->manual->forInquiryAssist($inquiry, $this->actor, $inquiry->revision);
        $this->assertSame($intake->id, $bridge->id);
        $this->assertSame($proposal->payload, $proposal->fresh()->payload);
        $this->assertSame(1, AiIntake::count());
        $this->noExternalEffects();
    }

    public function test_native_inquiry_can_request_assist_without_queuing_extraction_or_changing_its_demand(): void
    {
        $input = $this->payload()['demand'] + ['channel' => 'manual', 'customer_id' => $this->customer->id, 'original' => 'Synthetic phone note.'];
        $inquiry = app(InquiryWorkflowService::class)->save(null, $input, $this->actor);
        $source = $this->manual->forInquiryAssist($inquiry, $this->actor, $inquiry->revision);
        $again = $this->manual->forInquiryAssist($inquiry, $this->actor, $inquiry->revision);
        $this->assertSame($source->id, $again->id);
        $this->assertSame('manual', $source->processing_mode);
        $this->assertSame('Synthetic phone note.', $source->messages()->sole()->body);
        $this->assertSame($inquiry->id, $source->proposals()->sole()->inquiry_id);
        $this->assertSame('2027-05-13T08:00', $source->proposals()->sole()->payload['demand']['starts_at']);
        $this->assertSame('new', $inquiry->fresh()->status);
        $this->assertNull($source->analysis);
        $this->assertNull($source->processed_source_revision);
        $this->assertSame(1, OperationInquiry::count());
        $this->noExternalEffects();
    }

    public function test_incomplete_manual_draft_is_saved_but_cannot_create_a_native_inquiry(): void
    {
        $intake = $this->manual->claimManual($this->intake(), $this->actor, 1);
        $proposal = $this->manual->savePosition($intake, $this->actor, $intake->revision, 1, $this->payload(['starts_at' => null, 'required_staff' => null]));
        $this->assertContains('starts_at', $proposal->payload['missing_fields']);
        $this->rejected(fn () => $this->confirm($intake->fresh()), 422);
        $this->assertSame(0, OperationInquiry::count());
        $this->noExternalEffects();
    }

    public function test_claim_invalidates_running_ai_and_cancels_drafts_before_any_position_exists(): void
    {
        $intake = $this->intake(['status' => 'analyzing']);
        $run = $intake->runs()->create(['kind' => 'analysis', 'status' => 'running', 'source_revision' => 1, 'settings_revision' => 4,
            'supervising_user_id' => $this->actor->id, 'input_hash' => str_repeat('a', 64), 'started_at' => now()]);
        $delivery = $intake->deliveries()->create(['type' => 'receipt', 'status' => 'pending', 'recipient_email' => 'source@example.test', 'subject' => 'Synthetic',
            'body' => 'Synthetic', 'dedup_key' => str_repeat('a', 64), 'source_revision' => 1, 'settings_revision' => 4, 'intake_revision' => 1]);
        $current = $this->manual->claimManual($intake, $this->actor, 1);
        $this->assertSame('manual', $current->processing_mode);
        $this->assertSame('stale', $run->fresh()->status);
        $this->assertSame('canceled', $delivery->fresh()->status);
        $this->settings(true);
        $this->assertNull(app(AiIntakeMailService::class)->enqueueReceipt($current));
        $this->mock(AiIntakeExtractionService::class)->shouldNotReceive('extract');
        app(AiIntakeService::class)->analyze($current->id);
        $this->assertSame(1, $current->runs()->count());
        $this->noExternalEffects();
    }

    public function test_takeover_during_extraction_rejects_its_inflight_result(): void
    {
        $this->settings(true);
        $intake = $this->intake(['status' => 'received']);
        $this->mock(AiIntakeExtractionService::class)->shouldReceive('extract')->once()->andReturnUsing(function (AiIntake $analyzing): array {
            $this->manual->claimManual($analyzing->fresh(), $this->actor, $analyzing->fresh()->revision);

            return ['result' => ['intent' => 'other', 'classification' => 'unrelated', 'extraction_complete' => true, 'overflow' => false,
                'title' => 'Must not replace title', 'summary' => 'Synthetic stale result', 'confidence' => 0.9, 'positions' => [], 'questions' => []]];
        });
        app(AiIntakeService::class)->analyze($intake->id);
        $this->assertSame('manual', $intake->fresh()->processing_mode);
        $this->assertNull($intake->fresh()->analysis);
        $this->assertNull($intake->fresh()->processed_source_revision);
        $this->assertSame('Synthetic unanalysed source', $intake->fresh()->title);
        $this->assertSame('stale', $intake->runs()->sole()->status);
        $this->noExternalEffects();
    }

    public function test_new_reply_keeps_manual_ownership_and_invalidates_review_without_queuing_automation(): void
    {
        [$intake, $proposal] = $this->saved();
        $intake = $this->confirm($intake);
        $this->settings(true);
        $reply = app(AiIntakeService::class)->receiveEmail(['mailbox_id' => 'synthetic-mailbox', 'uid_validity' => 100, 'uid' => 21,
            'from' => 'source@example.test', 'reply_to' => 'source@example.test', 'message_id' => 'reply-form@example.test',
            'in_reply_to' => 'form-'.$intake->id.'@example.test', 'text' => 'Synthetic amended request.', 'subject' => 'Reply']);
        $this->assertSame($intake->id, $reply->id);
        $this->assertSame('manual', $reply->processing_mode);
        $this->assertSame(2, $reply->source_revision);
        $this->assertSame('review', $reply->status);
        $this->assertNull($reply->processed_source_revision);
        $this->assertSame('stale', $proposal->fresh()->status);
        $this->assertSame(1, OperationInquiry::count());
        $this->rejected(fn () => $this->confirm($intake));
        $this->noExternalEffects();
    }

    public function test_stale_intake_source_and_proposal_versions_are_rejected_atomically(): void
    {
        [$intake, $proposal] = $this->saved();
        $this->rejected(fn () => $this->manual->savePosition($intake, $this->actor, $intake->revision - 1, 1, $this->payload(), $proposal->id, $proposal->revision));
        $this->rejected(fn () => $this->manual->savePosition($intake, $this->actor, $intake->revision, 2, $this->payload(), $proposal->id, $proposal->revision));
        $this->rejected(fn () => $this->manual->savePosition($intake, $this->actor, $intake->revision, 1, $this->payload(), $proposal->id, 0));
        $this->assertSame(1, AiIntakeProposal::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->noExternalEffects();
    }

    public function test_direct_order_proof_allows_native_draft_preparation_without_offer_or_acceptance(): void
    {
        [$intake, $proposal] = $this->saved();
        $this->confirm($intake);
        $inquiry = $proposal->fresh()->inquiry;
        $converted = app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $this->assertNull($converted->offer);
        $this->assertNull($converted->accepted_revision);
        $proposal->refresh();
        $ids = app(AiIntakeService::class)->prepareProposalShifts($proposal, $this->actor, $proposal->revision,
            [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]]);
        $this->assertCount(1, $ids);
        $this->assertSame('draft', Shift::sole()->status->value);
        $this->assertNull(Shift::sole()->published_at);
        $this->assertSame(1, Order::count());
        $this->assertSame($ids, app(AiIntakeService::class)->prepareProposalShifts($proposal->fresh(), $this->actor, $proposal->fresh()->revision, []));
        $this->assertSame(1, Shift::count());
        $this->noExternalEffects();
    }

    public function test_native_direct_order_can_gain_optional_assist_and_prepare_from_its_audited_bridge(): void
    {
        $input = $this->payload()['demand'] + ['channel' => 'manual', 'customer_id' => $this->customer->id, 'original' => 'Synthetic direct phone order.'];
        $inquiry = app(InquiryWorkflowService::class)->save(null, $input, $this->actor);
        $converted = app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $conversion = OperationAudit::where('action', 'inquiry.direct_order')->sole();
        $this->assertSame([], $conversion->data['source_context']);
        $source = $this->manual->forInquiryAssist($converted, $this->actor, $converted->revision);
        $proposal = $source->proposals()->sole();
        $bridge = OperationAudit::where('action', 'intake.native_assist_opened')->sole();
        $this->assertSame($conversion->id, $bridge->data['conversion_audit_id']);
        $this->assertSame($proposal->id, $bridge->data['proposal_id']);
        $ids = app(AiIntakeService::class)->prepareProposalShifts($proposal, $this->actor, $proposal->revision,
            [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]]);
        $this->assertCount(1, $ids);
        $this->assertSame([], $conversion->fresh()->data['source_context']);
        $this->assertNull($converted->fresh()->offer);
        $this->assertNull($converted->fresh()->accepted_revision);
        $this->assertSame(1, Order::count());
        $this->noExternalEffects();
    }

    public function test_changed_bridge_cannot_authorize_planning_for_a_native_direct_order(): void
    {
        $input = $this->payload()['demand'] + ['channel' => 'manual', 'customer_id' => $this->customer->id, 'original' => 'Synthetic direct order.'];
        $inquiry = app(InquiryWorkflowService::class)->save(null, $input, $this->actor);
        $converted = app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $source = $this->manual->forInquiryAssist($converted, $this->actor, $converted->revision);
        $proposal = $source->proposals()->sole();
        $bridge = OperationAudit::where('action', 'intake.native_assist_opened')->sole();
        $data = $bridge->data;
        $data['proposal_id']++;
        $bridge->update(['data' => $data]);
        $this->rejected(fn () => app(AiIntakeService::class)->prepareProposalShifts($proposal, $this->actor, $proposal->revision,
            [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]]));
        $this->assertSame(0, Shift::count());
        $this->noExternalEffects();
    }

    public function test_later_invalid_position_rolls_back_all_native_records_and_confirmation_proofs(): void
    {
        [$intake, $proposal] = $this->saved();
        $this->manual->savePosition($intake, $this->actor, $intake->revision, 1, $this->payload(['ends_at' => null]));
        $this->rejected(fn () => $this->confirm($intake->fresh()), 422);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertNull($proposal->fresh()->inquiry_id);
        $this->assertArrayNotHasKey('actor_id', $proposal->fresh()->payload['review_confirmation']);
        $this->assertSame(0, OperationAudit::where('action', 'intake.manual_confirmed')->count());
        $this->noExternalEffects();
    }

    public function test_deactivated_actor_cannot_claim_or_save_using_a_stale_authenticated_model(): void
    {
        $intake = $this->intake();
        User::whereKey($this->actor->id)->update(['status' => false]);
        $this->rejected(fn () => $this->manual->claimManual($intake, $this->actor, 1), 403);
        $this->assertSame('assisted', $intake->fresh()->processing_mode);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->noExternalEffects();
    }

    public function test_sending_or_unknown_delivery_blocks_manual_claim_without_changing_ownership(): void
    {
        foreach (['sending', 'unknown'] as $status) {
            $intake = $this->intake();
            $intake->deliveries()->create(['type' => 'receipt', 'status' => $status, 'recipient_email' => 'source@example.test', 'subject' => 'Synthetic',
                'body' => 'Synthetic', 'dedup_key' => hash('sha256', $status), 'source_revision' => 1, 'settings_revision' => 4, 'intake_revision' => 1]);
            $this->rejected(fn () => $this->manual->claimManual($intake, $this->actor, 1));
            $this->assertSame('assisted', $intake->fresh()->processing_mode);
            $this->assertSame(1, $intake->fresh()->revision);
        }
        $this->noExternalEffects();
    }

    public function test_explicit_legacy_manual_continuation_claims_durable_source_ownership(): void
    {
        [$intake, $proposal] = $this->saved();
        $this->confirm($intake);
        $intake->refresh()->update(['processing_mode' => 'assisted']);
        $proposal->refresh();
        $id = app(AiIntakeService::class)->continueProposalManually($proposal, $this->actor, $proposal->revision);
        $this->assertSame($proposal->inquiry_id, $id);
        $this->assertSame('manual', $intake->fresh()->processing_mode);
        $this->settings(true);
        $this->mock(AiIntakeExtractionService::class)->shouldNotReceive('extract');
        app(AiIntakeService::class)->analyze($intake->id);
        $this->noExternalEffects();
    }

    public function test_new_position_can_be_confirmed_while_an_existing_position_keeps_its_prepared_shifts(): void
    {
        [$intake, $proposal] = $this->saved();
        $this->confirm($intake);
        $inquiry = $proposal->fresh()->inquiry;
        app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $proposal->refresh();
        app(AiIntakeService::class)->prepareProposalShifts($proposal, $this->actor, $proposal->revision,
            [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]]);
        $intake->refresh();
        $extra = $this->manual->savePosition($intake, $this->actor, $intake->revision, 1, $this->payload(['title' => 'Synthetic added service']));
        $confirmed = $this->confirm($intake->fresh());
        $this->assertSame('ready', $confirmed->status);
        $this->assertSame('applied', $proposal->fresh()->status);
        $this->assertNotNull($extra->fresh()->inquiry_id);
        $this->assertSame(2, OperationInquiry::count());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Shift::count());
        $this->noExternalEffects();
    }

    public function test_reply_position_exposes_explicit_mapping_and_reuses_the_native_inquiry_only_after_confirmation(): void
    {
        [$intake, $original] = $this->saved();
        $this->confirm($intake);
        $inquiryId = $original->fresh()->inquiry_id;
        $this->settings(true);
        $reply = app(AiIntakeService::class)->receiveEmail(['mailbox_id' => 'synthetic-mailbox', 'uid_validity' => 100, 'uid' => 91,
            'from' => 'source@example.test', 'reply_to' => 'source@example.test', 'message_id' => 'mapping-reply-form@example.test',
            'in_reply_to' => 'form-'.$intake->id.'@example.test', 'text' => 'Synthetic amended request.', 'subject' => 'Reply']);
        $position = $this->manual->savePosition($reply, $this->actor, $reply->revision, 2, $this->payload(['title' => 'Synthetic amended service']));
        $this->assertSame('reply_position_review', $position->error_code);
        $this->assertSame([$inquiryId], $position->payload['candidate_inquiry_ids']);
        $this->rejected(fn () => $this->confirm($reply->fresh()));
        $mapped = app(AiIntakeService::class)->mapProposalInquiry($position, $this->actor, $inquiryId, $position->revision);
        $this->confirm($reply->fresh());
        $this->assertSame($inquiryId, $mapped->fresh()->inquiry_id);
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame('Synthetic amended service', OperationInquiry::sole()->title);
        $this->assertSame('stale', $original->fresh()->status);
        $this->noExternalEffects();
    }

    public function test_additive_migration_is_idempotent_and_cannot_rollback_durable_manual_ownership(): void
    {
        $migration = require database_path('migrations/2026_10_25_110000_add_manual_processing_mode_to_ai_intakes.php');
        $intake = $this->intake();
        $migration->up();
        $migration->up();
        $this->assertSame('assisted', $intake->fresh()->processing_mode);
        $this->manual->claimManual($intake, $this->actor, 1);
        try {
            $migration->down();
            $this->fail('Rollback erased durable source ownership.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Bearbeitungsverantwortung', $exception->getMessage());
        }
        $this->assertSame('manual', $intake->fresh()->processing_mode);
        $this->noExternalEffects();
    }
}
