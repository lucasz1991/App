<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AssistantSpeechRouter;
use App\Services\Ai\Attachments\AssistantAttachment;
use App\Services\Ai\Attachments\AssistantAttachmentBatch;
use App\Services\Ai\Attachments\AssistantAttachmentKind;
use App\Services\Ai\Attachments\AssistantAttachmentProcessor;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterModelProfile;
use App\Services\Operations\AiDispositionClient;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeMessageContent;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsDateTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeManualRecognitionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private MockInterface $provider;

    private const SOURCE = 'Tf Hamburg 2027-05-13T08:00 2027-05-13T16:00 zwei Mitarbeiter, Pause 30 Minuten.';

    private function raw(string $body = self::SOURCE, string $type = 'text/plain'): string
    {
        return "From: customer@example.test\r\nTo: dispatch@example.test\r\nSubject: Synthetic inquiry\r\nMIME-Version: 1.0\r\nContent-Type: {$type}; charset=UTF-8\r\n\r\n".$body;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('manual-recognition-'.getmypid()));
        config(['view.compiled' => sys_get_temp_dir().'/railtime-manual-recognition-'.getmypid()]);
        Cache::flush();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => false, 'supervisor_id' => null, 'revision' => 5, 'imap_host' => 'imap.example.test', 'imap_username' => 'dispatch@example.test', 'from_address' => 'dispatch@example.test']));
        $this->provider = $this->mock(OpenRouterChatClient::class);
        $this->provider->shouldReceive('isConfiguredFor')->andReturn(true);
    }

    private function capture(array $changes = []): AiIntake
    {
        return app(AiIntakeService::class)->receiveEmail(array_replace([
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 401, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test', 'subject' => 'Synthetic inquiry',
            'message_id' => 'synthetic@example.test', 'text' => self::SOURCE, 'raw' => $this->raw(),
        ], $changes), $this->actor);
    }

    private function extractionResult(AiIntake $intake, array $changes = []): array
    {
        $position = ['title' => 'Synthetic service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin',
            'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2,
            'segments' => [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]],
            'qualification_requirements' => [], 'missing_fields' => [], 'evidence' => []];
        foreach (['starts_at', 'ends_at', 'location_name', 'role_name', 'required_staff', 'segments'] as $field) {
            $position['evidence'][] = ['field' => $field, 'message_id' => $intake->latest_inbound_message_id, 'attachment_id' => null, 'quote' => self::SOURCE];
        }

        return array_replace(['intent' => 'inquiry', 'classification' => 'new_inquiry', 'extraction_complete' => true, 'overflow' => false,
            'title' => 'Synthetic inquiry', 'summary' => 'Synthetic demand from a mailbox message', 'confidence' => 0.9,
            'positions' => [$position], 'questions' => [], 'customer_draft' => null], $changes);
    }

    private function expectResult(AiIntake $intake, array $changes = [], ?callable $duringRequest = null): void
    {
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function () use ($intake, $changes, $duringRequest): OpenRouterChatResponse {
            $this->assertSame(0, DB::transactionLevel());
            $duringRequest?->__invoke();

            return new OpenRouterChatResponse(json_encode($this->extractionResult($intake, $changes), JSON_THROW_ON_ERROR), costUsd: 0.001, requestId: 'synthetic-request', model: 'synthetic-model');
        });
    }

    private function recognize(AiIntake $intake, string $prompt = ''): AiIntake
    {
        return app(AiIntakeService::class)->analyzeManually($intake, $this->actor, $intake->revision, (int) AiDispositionSettings::all(true)['revision'], $prompt);
    }

    private function noBindingOrSending(): void
    {
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function rejected(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('The manual context must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        } catch (AuthorizationException $error) {
            $this->assertSame(403, $status);
        }
    }

    public function test_explicit_mailbox_recognition_runs_with_global_automation_off_and_no_supervisor(): void
    {
        $intake = $this->capture();
        $source = $intake->messages()->firstOrFail();
        $this->expectResult($intake);

        $recognized = $this->recognize($intake);

        $this->assertSame('review', $recognized->status);
        $this->assertSame('mailbox_test_review', $recognized->error_code);
        $this->assertSame('inquiry', $recognized->analysis['intent']);
        $this->assertSame($recognized->source_revision, $recognized->processed_source_revision);
        $this->assertSame(1, $recognized->proposals()->count());
        $proposal = $recognized->proposals()->firstOrFail();
        $this->assertSame('proposed', $proposal->status);
        $this->assertNull($proposal->inquiry_id);
        $this->assertNull($proposal->approved_at);
        $this->assertSame('Hamburg', $proposal->payload['demand']['location_name']);
        $run = $recognized->runs()->firstOrFail();
        $this->assertSame('mailbox_test', $run->kind);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame($this->actor->id, $run->supervising_user_id);
        $this->assertSame('manual_review', $run->configuration['mode']);
        $this->assertSame(AiIntakeMessageContent::VERSION, $run->configuration['extraction_version']);
        $this->assertSame('', $run->configuration['review_prompt']);
        $this->assertSame(2, OperationAudit::where('subject_type', 'AiIntake')->where('subject_id', $intake->id)->where('action', 'like', 'mailbox.analysis_%')->count());
        $this->assertSame($this->raw(), app(AiIntakeAttachmentService::class)->bytes($recognized, $source->raw_path, $source->raw_hash));
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->assertNull(AiDispositionSettings::supervisor());
        $this->noBindingOrSending();
    }

    public function test_repeating_a_successful_current_source_reuses_the_same_result_without_provider_call(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake);
        $first = $this->recognize($intake);
        $second = $this->recognize($first);

        $this->assertSame($first->revision, $second->revision);
        $this->assertSame(1, AiIntakeRun::count());
        $this->assertSame(1, AiIntakeProposal::count());
        $this->noBindingOrSending();
    }

    public function test_old_extraction_version_is_reanalyzed_only_after_an_explicit_request(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake);
        $first = $this->recognize($intake);
        $oldRun = $first->runs()->firstOrFail();
        $oldRun->update(['configuration' => array_replace($oldRun->configuration, ['extraction_version' => 'obsolete-fixture'])]);
        $this->expectResult($first, ['summary' => 'Fresh table-aware recognition']);

        $second = $this->recognize($first);

        $this->assertSame($first->source_revision, $second->source_revision);
        $this->assertSame('Fresh table-aware recognition', $second->analysis['summary']);
        $this->assertSame(2, AiIntakeRun::count());
        $this->assertSame(AiIntakeMessageContent::VERSION, $second->runs()->latest('id')->firstOrFail()->configuration['extraction_version']);
        $this->assertSame(1, AiIntakeProposal::count());
        $this->noBindingOrSending();
    }

    public function test_explicit_review_guidance_bypasses_the_current_cache_and_is_stored_encrypted(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake);
        $first = $this->recognize($intake);
        $prompt = 'Bitte die beiden Tabellenzeilen einzeln prüfen.';
        $this->expectResult($first, ['summary' => 'Reviewed with explicit guidance']);

        $second = $this->recognize($first, '  '.$prompt.'  ');

        $run = $second->runs()->latest('id')->firstOrFail();
        $this->assertSame($prompt, $run->configuration['review_prompt']);
        $this->assertStringNotContainsString($prompt, $run->getRawOriginal('configuration'));
        $this->assertSame('Reviewed with explicit guidance', $second->analysis['summary']);
        $this->assertSame($first->source_revision, $second->source_revision);
        $this->assertSame($this->raw(), app(AiIntakeAttachmentService::class)->bytes($second, $second->messages()->firstOrFail()->raw_path, $second->messages()->firstOrFail()->raw_hash));
        $this->assertSame($second->revision, $this->recognize($second, '  ')->revision);
        $this->assertSame(2, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    public function test_overlong_guidance_is_rejected_before_a_run_or_provider_call(): void
    {
        $intake = $this->capture();
        $this->provider->shouldNotReceive('complete');
        try {
            $this->recognize($intake, str_repeat('x', 2001));
            $this->fail('Review guidance must be bounded.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('review_prompt', $error->errors());
        }
        $this->assertSame(0, AiIntakeRun::count());
        $this->assertSame($intake->revision, $intake->fresh()->revision);
        $this->noBindingOrSending();
    }

    public function test_current_cache_never_hides_a_missing_archived_original(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake);
        $first = $this->recognize($intake);
        Storage::disk('local')->delete($first->messages()->firstOrFail()->raw_path);

        $this->rejected(fn () => $this->recognize($first), 404);

        $this->assertSame($first->revision, $first->fresh()->revision);
        $this->assertSame(1, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    public function test_table_quotes_verify_position_and_customer_fields_against_the_same_raw_projection(): void
    {
        $html = '<table><tr><th>Firma</th><th>Kontakt</th></tr><tr><td>Table Customer</td><td>person@example.test</td></tr></table><table><tr><th>Leistung</th><th>Ort</th><th>Beginn</th><th>Ende</th><th>Personal</th></tr><tr><td>Tf</td><td>Hamburg</td><td>2027-05-13T08:00</td><td>2027-05-13T16:00</td><td>2</td></tr></table>';
        $intake = $this->capture(['text' => 'Legacy flattened body without table boundaries', 'raw' => $this->raw($html, 'text/html')]);
        $message = $intake->messages()->firstOrFail();
        $projected = app(AiIntakeMessageContent::class)->forMessage($message);
        $this->assertNotSame($message->body, $projected);
        $result = $this->extractionResult($intake);
        foreach ($result['positions'][0]['evidence'] as &$item) {
            $item['quote'] = $projected;
        }
        unset($item);
        $result['customer_draft'] = ['company_name' => 'Table Customer', 'contact_email' => 'person@example.test', 'evidence' => [
            ['field' => 'company_name', 'message_id' => $message->id, 'quote' => $projected],
            ['field' => 'contact_email', 'message_id' => $message->id, 'quote' => $projected],
        ]];
        $this->expectResult($intake, $result);

        $recognized = $this->recognize($intake);

        $this->assertSame('Hamburg', $recognized->proposals()->firstOrFail()->payload['demand']['location_name']);
        $this->assertSame('Table Customer', $recognized->analysis['customer_draft']['company_name']);
        $this->assertSame('person@example.test', $recognized->analysis['customer_draft']['contact_email']);
        $this->assertSame('Legacy flattened body without table boundaries', $message->fresh()->body);
        $this->assertSame($this->raw($html, 'text/html'), app(AiIntakeAttachmentService::class)->bytes($recognized, $message->raw_path, $message->raw_hash));
        $this->noBindingOrSending();
    }

    public function test_reanalysis_does_not_create_an_inquiry_from_an_obsolete_extra_position(): void
    {
        $intake = $this->capture();
        $position = $this->extractionResult($intake)['positions'][0];
        $this->expectResult($intake, ['positions' => [$position, $position]]);
        $first = $this->recognize($intake);
        $this->expectResult($first);
        $second = $this->recognize($first, 'Nur die aktuelle erste Leistung übernehmen.');

        $this->assertSame('stale', $second->proposals()->where('position_index', 1)->firstOrFail()->status);
        $this->assertSame('manual_recognition_requested', $second->proposals()->where('position_index', 1)->firstOrFail()->error_code);
        app(AiIntakeService::class)->createInquiries($second, $this->actor, $second->revision);

        $this->assertSame(1, OperationInquiry::count());
        $this->assertNull($second->proposals()->where('position_index', 1)->firstOrFail()->inquiry_id);
        $this->assertSame(0, AiIntakeDelivery::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_enabled_automatic_mode_does_not_allow_this_single_run_to_create_or_send_anything(): void
    {
        $intake = $this->capture();
        $this->settings(['enabled' => true, 'supervisor_id' => $this->actor->id, 'automation_mode' => 'automatic', 'customer_clarification_mode' => 'automatic']);
        $this->expectResult($intake);

        $recognized = $this->recognize($intake);
        app(AiIntakeService::class)->analyze($recognized->id);

        $this->assertSame('mailbox_test_review', $recognized->fresh()->error_code);
        $this->assertSame(1, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    private function settings(array $changes): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY,
            array_replace(Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY), $changes));
    }

    public function test_stale_revisions_inactive_actors_and_foreign_sources_fail_before_any_model_call(): void
    {
        $intake = $this->capture();
        $this->provider->shouldNotReceive('complete');
        $service = app(AiIntakeService::class);
        $this->rejected(fn () => $service->analyzeManually($intake, $this->actor, $intake->revision - 1, 5), 409);
        $this->rejected(fn () => $service->analyzeManually($intake, $this->actor, $intake->revision, 4), 409);
        User::whereKey($this->actor->id)->update(['status' => false]);
        $this->rejected(fn () => $this->recognize($intake), 403);
        User::whereKey($this->actor->id)->update(['status' => true]);
        $this->settings(['imap_username' => 'different@example.test']);
        $this->rejected(fn () => $this->recognize($intake), 409);
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    public function test_paused_completed_and_source_error_intakes_are_not_overridden(): void
    {
        $intake = $this->capture();
        $this->provider->shouldNotReceive('complete');
        foreach (['paused', 'completed', 'not_inquiry'] as $status) {
            $intake->update(['status' => $status]);
            $this->rejected(fn () => $this->recognize($intake), 409);
            $this->assertSame($status, $intake->fresh()->status);
        }
        foreach (['mailbox_message_size', 'mailbox_text_limit', 'attachments_review', 'automatic_message_review', 'sender_unverified'] as $code) {
            $intake->update(['status' => 'review', 'error_code' => $code]);
            $this->rejected(fn () => $this->recognize($intake), 422);
            $this->assertSame($code, $intake->fresh()->error_code);
        }
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    public function test_a_running_intake_lock_refuses_a_duplicate_explicit_request(): void
    {
        $intake = $this->capture();
        $lock = Cache::lock('ai-intake:'.$intake->id, 660);
        $this->assertTrue($lock->get());
        $this->provider->shouldNotReceive('complete');
        try {
            $this->rejected(fn () => $this->recognize($intake), 409);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    public function test_existing_native_inquiry_is_never_overwritten_and_current_analysis_can_be_reused(): void
    {
        $intake = $this->capture();
        $inquiry = OperationInquiry::create(['channel' => 'email', 'title' => 'Native current inquiry', 'original' => 'Native original', 'status' => 'new', 'timezone' => 'Europe/Berlin', 'revision' => 1, 'created_by' => $this->actor->id]);
        $proposal = $intake->proposals()->create(['source_revision' => $intake->source_revision, 'position_index' => 0, 'status' => 'proposed', 'revision' => 1, 'inquiry_id' => $inquiry->id, 'payload' => []]);
        $this->provider->shouldNotReceive('complete');
        $this->rejected(fn () => $this->recognize($intake), 409);
        $intake->update(['analysis' => $this->extractionResult($intake), 'processed_source_revision' => $intake->source_revision]);
        $intake->runs()->create(['kind' => 'analysis', 'status' => 'succeeded', 'source_revision' => $intake->source_revision, 'settings_revision' => 5,
            'supervising_user_id' => $this->actor->id, 'input_hash' => str_repeat('a', 64), 'configuration' => ['extraction_version' => AiIntakeMessageContent::VERSION], 'started_at' => now()->utc(), 'finished_at' => now()->utc()]);
        $before = $inquiry->fresh()->getRawOriginal();

        $this->assertSame($intake->revision, $this->recognize($intake)->revision);
        $this->rejected(fn () => $this->recognize($intake, 'Analyse ausdrücklich erneut prüfen.'), 409);
        $this->assertSame($before, $inquiry->fresh()->getRawOriginal());
        $this->assertSame('proposed', $proposal->fresh()->status);
        $this->assertSame(1, AiIntakeRun::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_abandoned_manual_run_expires_to_review_even_with_global_automation_off(): void
    {
        $intake = $this->capture();
        $intake->update(['status' => 'analyzing', 'error_code' => 'mailbox_test_processing']);
        $run = $intake->runs()->create(['kind' => 'mailbox_test', 'status' => 'running', 'source_revision' => $intake->source_revision,
            'settings_revision' => 5, 'supervising_user_id' => $this->actor->id, 'input_hash' => str_repeat('a', 64), 'started_at' => now()->utc()->subMinutes(16)]);
        $this->provider->shouldNotReceive('complete');

        $this->assertSame(1, app(AiIntakeService::class)->expireStaleAnalysisRuns());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('mailbox_test_review', $intake->fresh()->error_code);
        $this->noBindingOrSending();
    }

    public function test_provider_failure_is_a_review_only_failed_run_and_can_be_explicitly_retried(): void
    {
        $intake = $this->capture();
        $this->provider->shouldReceive('complete')->once()->andThrow(new OpenRouterChatException('transport_error'));
        try {
            $this->recognize($intake);
            $this->fail('Provider failure must be surfaced.');
        } catch (OpenRouterChatException $error) {
            $this->assertSame('transport_error', $error->reasonCode);
        }
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('mailbox_test_review', $intake->fresh()->error_code);
        $this->assertSame('failed', AiIntakeRun::firstOrFail()->status);
        $this->expectResult($intake);

        $this->assertSame('inquiry', $this->recognize($intake->fresh())->analysis['intent']);
        $this->assertSame(['failed', 'succeeded'], AiIntakeRun::orderBy('id')->pluck('status')->all());
        $this->assertSame(1, AiIntakeProposal::count());
        $this->noBindingOrSending();
    }

    public function test_revised_settings_after_provider_response_discard_the_result_and_keep_review_only(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake, duringRequest: fn () => $this->settings(['revision' => 6]));

        $this->rejected(fn () => $this->recognize($intake), 409);

        $this->assertSame('failed', AiIntakeRun::firstOrFail()->status);
        $this->assertSame('review', $intake->fresh()->status);
        $this->assertSame('mailbox_test_review', $intake->fresh()->error_code);
        $this->assertNull($intake->fresh()->analysis);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->noBindingOrSending();
    }

    public function test_revoked_actor_after_provider_response_cannot_commit_the_extraction(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake, duringRequest: fn () => User::whereKey($this->actor->id)->update(['status' => false]));

        $this->rejected(fn () => $this->recognize($intake), 403);

        $this->assertSame('failed', AiIntakeRun::firstOrFail()->status);
        $this->assertNull($intake->fresh()->analysis);
        $this->noBindingOrSending();
    }

    public function test_changed_source_content_after_provider_response_cannot_be_accepted_as_evidence(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake, duringRequest: fn () => DB::table('ai_intake_messages')->where('id', $intake->latest_inbound_message_id)->update(['raw_hash' => hash('sha256', 'Changed immutable fixture.')]));

        $this->rejected(fn () => $this->recognize($intake), 409);

        $this->assertNull($intake->fresh()->analysis);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->noBindingOrSending();
    }

    public function test_unrelated_mail_records_classification_without_proposals_or_business_actions(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake, ['intent' => 'other', 'classification' => 'unrelated', 'positions' => []]);

        $recognized = $this->recognize($intake);

        $this->assertSame('unrelated', $recognized->analysis['classification']);
        $this->assertSame('review', $recognized->status);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->noBindingOrSending();
    }

    public function test_incomplete_or_unproven_fields_never_become_native_demand(): void
    {
        $intake = $this->capture();
        $result = $this->extractionResult($intake);
        $result['positions'][0]['evidence'] = [];
        $this->expectResult($intake, $result);

        $recognized = $this->recognize($intake);
        $proposal = $recognized->proposals()->firstOrFail();

        $this->assertNull($proposal->payload['demand']['location_name']);
        $this->assertNull($proposal->payload['demand']['starts_at']);
        $this->assertSame([], $proposal->payload['segments']);
        $this->assertContains('starts_at', $proposal->payload['missing_fields']);
        $this->noBindingOrSending();
    }

    public function test_single_offset_timestamp_is_rejected_even_without_the_other_endpoint(): void
    {
        $intake = $this->capture();
        $result = $this->extractionResult($intake);
        $result['positions'][0]['starts_at'] = '2027-05-13T08:00:00+02:00';
        $result['positions'][0]['ends_at'] = null;
        $this->expectResult($intake, $result);

        $recognized = $this->recognize($intake);
        $proposal = $recognized->proposals()->firstOrFail();

        $this->assertNull($proposal->payload['demand']['starts_at']);
        $this->assertNull($proposal->payload['demand']['ends_at']);
        $this->assertSame([], $proposal->payload['segments']);
        $this->assertContains('starts_at', $proposal->payload['missing_fields']);
        $this->assertContains('ends_at', $proposal->payload['missing_fields']);
        $this->noBindingOrSending();
    }

    public function test_single_malformed_end_is_rejected_without_dropping_the_valid_local_beginning(): void
    {
        $intake = $this->capture();
        $result = $this->extractionResult($intake);
        $result['positions'][0]['ends_at'] = 'not-a-date';
        $this->expectResult($intake, $result);

        $recognized = $this->recognize($intake);
        $proposal = $recognized->proposals()->firstOrFail();

        $this->assertSame('2027-05-13T08:00', $proposal->payload['demand']['starts_at']);
        $this->assertNull($proposal->payload['demand']['ends_at']);
        $this->assertSame([], $proposal->payload['segments']);
        $this->assertContains('ends_at', $proposal->payload['missing_fields']);
        $this->noBindingOrSending();
    }

    public function test_single_valid_local_endpoint_remains_available_as_a_partial_proposal(): void
    {
        $intake = $this->capture();
        $result = $this->extractionResult($intake);
        $result['positions'][0]['ends_at'] = null;
        $result['positions'][0]['segments'] = [];
        $this->expectResult($intake, $result);

        $recognized = $this->recognize($intake);
        $proposal = $recognized->proposals()->firstOrFail();

        $this->assertSame('2027-05-13T08:00', $proposal->payload['demand']['starts_at']);
        $this->assertNull($proposal->payload['demand']['ends_at']);
        $this->assertContains('ends_at', $proposal->payload['missing_fields']);
        $this->noBindingOrSending();
    }

    public function test_paused_automatic_api_and_forged_manual_run_are_not_provider_bypasses(): void
    {
        $intake = $this->capture();
        $client = app(AiDispositionClient::class);
        $this->provider->shouldNotReceive('complete');
        try {
            $client->structured('intake', [], []);
            $this->fail('Automatic API must remain disabled.');
        } catch (OpenRouterChatException $error) {
            $this->assertSame('disposition_not_configured', $error->reasonCode);
        }
        $run = $intake->runs()->create(['kind' => 'analysis', 'status' => 'running', 'source_revision' => $intake->source_revision,
            'settings_revision' => 5, 'supervising_user_id' => $this->actor->id, 'input_hash' => str_repeat('a', 64),
            'configuration' => ['mode' => 'manual_review', 'actor_id' => $this->actor->id], 'started_at' => now()->utc()]);

        $this->rejected(fn () => $client->structuredManual($run, 'intake', [], []), 409);
        $this->noBindingOrSending();
    }

    public function test_speech_provider_state_change_blocks_later_model_and_transcript_mutation(): void
    {
        $intake = $this->capture();
        $this->attachment($intake, 'audio');
        $this->provider->shouldNotReceive('complete');
        $this->mock(AssistantSpeechRouter::class, function ($mock): void {
            $mock->shouldReceive('transcribe')->once()->andReturnUsing(function (): array {
                $this->settings(['revision' => 6]);

                return ['text' => 'Synthetic transcript', 'provider' => 'synthetic'];
            });
        });

        $this->rejected(fn () => $this->recognize($intake), 409);

        $this->assertNull(AiIntakeAttachment::firstOrFail()->extracted_text);
        $this->noBindingOrSending();
    }

    public function test_attachment_processing_state_change_blocks_later_model_and_extraction_mutation(): void
    {
        $intake = $this->capture();
        $this->attachment($intake, 'text');
        $this->provider->shouldNotReceive('complete');
        $this->mock(AssistantAttachmentProcessor::class, function ($mock): void {
            $mock->shouldReceive('process')->once()->andReturnUsing(function (): AssistantAttachmentBatch {
                $this->settings(['revision' => 6]);

                return new AssistantAttachmentBatch([]);
            });
        });

        $this->rejected(fn () => $this->recognize($intake), 409);

        $this->assertNull(AiIntakeAttachment::firstOrFail()->extracted_text);
        $this->noBindingOrSending();
    }

    public function test_inline_signature_image_keeps_the_data_model_and_full_image_without_losing_source_evidence(): void
    {
        $intake = $this->capture();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=', true);
        $paths = [];
        $path = app(AiIntakeAttachmentService::class)->put($intake, $png, $paths);
        $file = $intake->attachments()->create(['message_id' => $intake->latest_inbound_message_id, 'file_name' => 'signature.png',
            'file_path' => $path, 'file_hash' => hash('sha256', $png), 'file_size' => strlen($png), 'file_mime' => 'image/png',
            'kind' => 'image', 'status' => 'stored', 'metadata' => ['extension' => 'png']]);
        $imageUrl = 'data:image/png;base64,'.base64_encode($png);
        $batch = new AssistantAttachmentBatch([new AssistantAttachment('signature.png', 'png', 'image/png', strlen($png), AssistantAttachmentKind::Image, dataUrl: $imageUrl)]);
        $this->assertSame(OpenRouterModelProfile::ImageUnderstanding, $batch->modelProfile());
        $this->mock(AssistantAttachmentProcessor::class, function ($mock) use ($batch, $png): void {
            $mock->shouldReceive('process')->once()->andReturnUsing(function (array $uploads) use ($batch, $png): AssistantAttachmentBatch {
                $this->assertSame($png, file_get_contents($uploads[0]->getRealPath()));

                return $batch;
            });
        });
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function (array $messages, OpenRouterModelProfile $profile, array $plugins, array $tools = [], string|array|null $toolChoice = null, ?array $structuredSchema = null) use ($intake, $file, $imageUrl): OpenRouterChatResponse {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(OpenRouterModelProfile::Data, $profile);
            $this->assertSame([], $plugins);
            $this->assertSame([], $tools);
            $this->assertSame('intake', $structuredSchema['name']);
            $this->assertStringContainsString('YYYY-MM-DDTHH:mm', $messages[0]['content']);
            $this->assertSame(['type' => 'image_url', 'image_url' => ['url' => $imageUrl]], $messages[1]['content'][1]);
            $source = json_decode($messages[1]['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(self::SOURCE, $source['received_messages'][0]['text']);
            $this->assertSame($file->id, $source['attachments'][0]['attachment_id']);

            return new OpenRouterChatResponse(json_encode($this->extractionResult($intake), JSON_THROW_ON_ERROR), model: 'synthetic-data-model');
        });

        $recognized = $this->recognize($intake);

        $proposal = $recognized->proposals()->firstOrFail();
        $this->assertSame('inquiry', $recognized->analysis['intent']);
        $this->assertSame('Hamburg', $proposal->payload['demand']['location_name']);
        $this->assertSame(2, $proposal->payload['demand']['required_staff']);
        $this->assertSame(self::SOURCE, $proposal->payload['evidence']['starts_at']['quote']);
        $this->assertSame($intake->latest_inbound_message_id, $proposal->payload['evidence']['starts_at']['message_id']);
        $this->assertSame('synthetic-data-model', $recognized->runs()->firstOrFail()->model);
        $this->assertSame(AiIntakeMessageContent::VERSION, $recognized->runs()->firstOrFail()->configuration['extraction_version']);
        $this->assertSame($png, app(AiIntakeAttachmentService::class)->bytes($recognized, $file->file_path, $file->file_hash));
        $this->noBindingOrSending();
    }

    public function test_structured_dates_use_supported_local_minutes_with_nullable_positions_and_strict_segments(): void
    {
        $position = AiIntakeExtractionService::schema()['properties']['positions']['items'];
        foreach (['starts_at', 'ends_at'] as $field) {
            $date = $position['properties'][$field];
            $segmentDate = $position['properties']['segments']['items']['properties'][$field];
            $this->assertSame(['string', 'null'], $date['type']);
            $this->assertSame('string', $segmentDate['type']);
            foreach ([$date, $segmentDate] as $rule) {
                $pattern = '~'.$rule['pattern'].'~';
                foreach (['2027-05-13T08:00', '2027-05-13T16:00'] as $local) {
                    $this->assertMatchesRegularExpression($pattern, $local);
                    $this->assertSame($local, OperationsDateTime::local($local, 'Europe/Berlin')->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i'));
                }
                foreach (['2027-05-13T08:00+02:00', '2027-05-13T08:00Z', '2027-05-13T08:00:00', '13.05.2027 08:00', '2027-05-13 08:00'] as $unsupported) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $unsupported);
                }
            }
        }
        $this->assertSame(0, AiIntakeRun::count());
        $this->noBindingOrSending();
    }

    public function test_unknown_staffing_defaults_to_one_as_a_visible_proposal_without_forged_evidence(): void
    {
        $body = 'Nachbestellung Tf Hamburg 2027-05-13T08:00 2027-05-13T16:00.';
        $intake = $this->capture(['text' => $body, 'raw' => $this->raw($body)]);
        $position = $this->extractionResult($intake)['positions'][0];
        $position['required_staff'] = null;
        $position['segments'] = [];
        $position['missing_fields'] = ['required_staff'];
        $position['evidence'] = array_values(array_filter($position['evidence'], fn ($item) => ! in_array($item['field'], ['required_staff', 'segments'], true)));
        foreach ($position['evidence'] as &$evidence) {
            $evidence['quote'] = $body;
        }
        unset($evidence);
        $position['assumptions'] = ['required_staff' => ['value' => 99, 'basis' => 'forged']];
        $this->expectResult($intake, ['positions' => [$position], 'questions' => [['field' => 'required_staff', 'question' => 'Wie viele?']]]);

        $recognized = $this->recognize($intake);
        $payload = $recognized->proposals()->firstOrFail()->payload;
        $this->assertSame(1, $payload['demand']['required_staff']);
        $this->assertSame('default', $payload['assumptions']['required_staff']['basis']);
        $this->assertArrayNotHasKey('required_staff', $payload['evidence']);
        $this->assertNotContains('required_staff', $payload['missing_fields']);
        $this->assertSame([], $recognized->analysis['questions']);
        $this->assertSame($body, $recognized->messages()->firstOrFail()->body);
        $this->noBindingOrSending();
    }

    public function test_explicit_staffing_and_non_inquiries_never_receive_a_staffing_default(): void
    {
        $intake = $this->capture();
        $this->expectResult($intake);
        $recognized = $this->recognize($intake);
        $this->assertSame(2, $recognized->analysis['positions'][0]['required_staff']);
        $this->assertSame([], $recognized->analysis['positions'][0]['assumptions']);

        $other = $this->capture(['uid' => 33, 'message_id' => 'other@example.test']);
        $position = $this->extractionResult($other)['positions'][0];
        $position['required_staff'] = null;
        $position['segments'] = [];
        $this->expectResult($other, ['intent' => 'other', 'classification' => 'unrelated', 'positions' => [$position]]);
        $result = $this->recognize($other);
        $this->assertNull($result->analysis['positions'][0]['required_staff']);
        $this->assertSame([], $result->analysis['positions'][0]['assumptions']);
        $this->assertSame(0, $result->proposals()->count());
        $this->noBindingOrSending();
    }

    public function test_history_hints_are_separate_from_original_evidence_and_model_cannot_forge_their_provenance(): void
    {
        $intake = $this->capture();
        $hints = ['candidates' => [['customer_id' => 42, 'name' => 'Historical customer']],
            'history' => [['customer' => ['id' => 42], 'orders' => [['id' => 73, 'location_name' => 'Historical place', 'required_staff' => 8]]]],
            'provenance' => ['kind' => 'historical_hint', 'original_evidence' => false]];
        $this->mock(\App\Services\Operations\AiIntakeHistoryToolService::class, function ($mock) use ($hints): void {
            $mock->shouldReceive('lookup')->once()->andReturn($hints);
        });
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function (array $messages) use ($intake): OpenRouterChatResponse {
            $source = is_array($messages[1]['content']) ? $messages[1]['content'][0]['text'] : $messages[1]['content'];
            $this->assertStringContainsString(self::SOURCE, $source);
            $this->assertStringNotContainsString('Historical place', $source);
            $this->assertStringContainsString('Historical place', $messages[2]['content']);
            $this->assertStringContainsString('keine Originalbelege', $messages[2]['content']);
            $result = $this->extractionResult($intake);
            $result['context_hints'] = ['provenance' => ['kind' => 'model_forgery']];
            $result['positions'][0]['location_name'] = 'Historical place';
            foreach ($result['positions'][0]['evidence'] as &$evidence) {
                if ($evidence['field'] === 'location_name') {
                    $evidence['quote'] = 'Historical place';
                }
            }
            unset($evidence);

            return new OpenRouterChatResponse(json_encode($result, JSON_THROW_ON_ERROR));
        });

        $recognized = $this->recognize($intake);
        $this->assertSame($hints, $recognized->analysis['context_hints']);
        $this->assertNull($recognized->analysis['positions'][0]['location_name']);
        $this->assertNull($recognized->customer_id);
        $this->assertSame(self::SOURCE, $recognized->messages()->firstOrFail()->body);
        $this->noBindingOrSending();
    }

    private function attachment(AiIntake $intake, string $kind): void
    {
        $paths = [];
        $path = app(AiIntakeAttachmentService::class)->put($intake, 'Synthetic fixture bytes.', $paths);
        $intake->attachments()->create(['message_id' => $intake->latest_inbound_message_id, 'file_name' => 'fixture.'.($kind === 'audio' ? 'wav' : 'txt'),
            'file_path' => $path, 'file_hash' => hash('sha256', 'Synthetic fixture bytes.'), 'file_size' => 24,
            'file_mime' => $kind === 'audio' ? 'audio/wav' : 'text/plain', 'kind' => $kind, 'status' => 'stored', 'metadata' => ['extension' => $kind === 'audio' ? 'wav' : 'txt']]);
    }
}
