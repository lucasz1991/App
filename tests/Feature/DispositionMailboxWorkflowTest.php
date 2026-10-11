<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\DispositionMailbox;
use App\Livewire\Operations\InquiryProcessPanel;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DispositionMailboxReview;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\AiDispositionSmtpTransport;
use App\Services\Operations\AiIntakeExtractionService;
use App\Services\Operations\AiIntakeImapClient;
use App\Services\Operations\AiIntakeMailService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\CustomerWorkflowService;
use App\Services\Operations\DispositionMailboxService;
use App\Services\Operations\DispositionMailboxWorkflowService;
use App\Support\Ai\OpenRouterSettings;
use App\Support\Operations\AiDispositionSettings;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispositionMailboxWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    private MockInterface $imap;

    private const SOURCE = 'Synthetic Rail GmbH needs two Tf in Hamburg on 2026-10-19T08:00 until 2026-10-19T16:00, break 30 minutes.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_09_090000_create_staffing_automation_runs.php', '2026_10_09_100000_add_ai_customer_communication_controls.php', '2026_10_11_150000_create_disposition_mailbox_reviews.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['company_name' => 'Synthetic Rail GmbH', 'email' => 'office@rail.example.test', 'is_active' => true]);
        $this->withoutVite();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('disposition-mailbox-workflow-'.getmypid()));
        Storage::disk('local')->makeDirectory('compiled-views');
        config(['view.compiled' => Storage::disk('local')->path('compiled-views')]);
        $this->imap = $this->mock(AiIntakeImapClient::class);
        AiDispositionSettings::save([
            'enabled' => false, 'supervisor_id' => null, 'imap_host' => 'imap.example.test',
            'imap_username' => 'dispatch@example.test', 'imap_password' => 'synthetic-workflow-secret',
            'imap_folder' => 'INBOX', 'from_address' => 'dispatch@example.test',
        ], $this->admin);
    }

    private function email(array $changes = []): array
    {
        return array_replace([
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'folder' => 'INBOX', 'uid_validity' => 401, 'uid' => 32,
            'from' => 'customer@example.test', 'reply_to' => 'customer@example.test', 'to' => 'dispatch@example.test',
            'message_id' => '<workflow-32@example.test>', 'subject' => 'Synthetic guided inquiry',
            'date' => '2026-10-11T12:00:00Z', 'seen' => false, 'text' => self::SOURCE,
            'raw' => "From: customer@example.test\r\nSubject: Synthetic guided inquiry\r\n\r\n".self::SOURCE, 'attachments' => [],
        ], $changes);
    }

    private function mockExtraction(array $positionChanges = [], array $resultChanges = []): void
    {
        $this->mock(AiIntakeExtractionService::class, function (MockInterface $mock) use ($positionChanges, $resultChanges): void {
            $mock->shouldReceive('extract')->andReturnUsing(function (AiIntake $intake, ?AiIntakeRun $run = null) use ($positionChanges, $resultChanges): array {
                $this->assertSame('mailbox_test', $run?->kind);
                $this->assertSame('running', $run?->status);
                $messageId = $intake->messages()->where('direction', 'inbound')->firstOrFail()->id;
                $position = array_replace([
                    'title' => 'Synthetic guided service', 'starts_at' => '2026-10-19T08:00', 'ends_at' => '2026-10-19T16:00',
                    'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2,
                    'segments' => [['starts_at' => '2026-10-19T08:00', 'ends_at' => '2026-10-19T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]],
                    'qualification_requirements' => [], 'missing_fields' => [], 'evidence' => [],
                ], $positionChanges);
                foreach (['starts_at', 'ends_at', 'location_name', 'role_name', 'required_staff', 'segments'] as $field) {
                    $position['evidence'][] = ['field' => $field, 'message_id' => $messageId, 'attachment_id' => null, 'quote' => self::SOURCE];
                }
                $result = array_replace([
                    'intent' => 'inquiry', 'classification' => 'new_inquiry', 'extraction_complete' => true, 'overflow' => false,
                    'title' => 'Synthetic guided inquiry', 'summary' => 'Two Tf in Hamburg need personal review.', 'confidence' => 0.9,
                    'positions' => [$position], 'questions' => [],
                    'customer_draft' => ['company_name' => 'Synthetic Rail GmbH', 'contact_name' => null, 'contact_email' => null, 'contact_phone' => null,
                        'evidence' => [['field' => 'company_name', 'message_id' => $messageId, 'attachment_id' => null, 'quote' => 'Synthetic Rail GmbH']]],
                ], $resultChanges);

                return ['result' => $result, 'model' => 'synthetic-only', 'request_id' => 'synthetic-guided', 'cost_usd' => 0.001];
            });
        });
    }

    private function recognize(array $positionChanges = [], array $resultChanges = []): array
    {
        $this->mockExtraction($positionChanges, $resultChanges);
        $this->imap->shouldReceive('fetch')->andReturn($this->email());
        $this->imap->shouldReceive('readMessage')->andReturnUsing(fn ($settings, $folder, $uid, $validity) => $this->email(['folder' => $folder, 'uid' => $uid, 'uid_validity' => $validity]));

        return app(DispositionMailboxWorkflowService::class)->recognize($this->admin, 'INBOX', 32, 401, AiDispositionSettings::all(true)['revision']);
    }

    private function contact(?Customer $customer = null, array $changes = []): CustomerContact
    {
        return CustomerContact::create(array_replace([
            'customer_id' => ($customer ?? $this->customer)->id, 'name' => 'Synthetic dispatcher',
            'email' => 'customer@example.test', 'roles' => ['ordering'], 'is_active' => true, 'updated_by' => $this->admin->id,
        ], $changes));
    }

    private function confirmedCustomer(array $review): array
    {
        $workflow = app(DispositionMailboxWorkflowService::class);
        $review = $workflow->confirmClassification($this->admin, $review, true);
        $contact = $this->contact();

        return $workflow->assignCustomer($this->admin, $review, $this->customer->id, $contact->id);
    }

    private function enableAutomation(): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['enabled' => true, 'supervisor_id' => $this->admin->id, 'automation_mode' => 'automatic', 'customer_clarification_mode' => 'automatic']
        ));
    }

    private function noAutomaticEffects(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    private function rejected(array $statuses, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the guided mailbox action to be rejected.');
        } catch (HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), $statuses);
        } catch (AuthorizationException) {
            $this->assertContains(403, $statuses);
        } catch (ModelNotFoundException) {
            $this->assertContains(404, $statuses);
        }
    }

    public function test_disabled_mailbox_recognition_captures_one_original_and_only_prepares_an_unconfirmed_result(): void
    {
        $review = $this->recognize();

        $this->assertFalse(AiDispositionSettings::enabled());
        $this->assertSame('inquiry', $review['detected_classification']);
        $this->assertSame('unreviewed', DispositionMailboxReview::firstOrFail()->classification);
        $this->assertNull(DispositionMailboxReview::firstOrFail()->reviewed_by);
        $this->assertSame(32, $review['uid']);
        $this->assertSame(401, $review['uid_validity']);
        $this->assertSame(AiDispositionSettings::mailboxId(), $review['mailbox_id']);
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(1, AiIntakeProposal::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->assertNull(AiIntake::firstOrFail()->customer_id);
        $this->assertSame(1, Customer::count());
        $this->noAutomaticEffects();
    }

    public function test_enabled_automatic_mode_still_leaves_explicit_recognition_without_native_or_mail_side_effects(): void
    {
        $this->enableAutomation();
        $review = $this->recognize();

        $this->assertSame('mailbox_test_review', AiIntake::findOrFail($review['intake_id'])->error_code);
        $this->assertSame('review', AiIntake::findOrFail($review['intake_id'])->status);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    public function test_repeated_recognition_keeps_one_source_proposal_and_completed_manual_analysis(): void
    {
        $first = $this->recognize();
        $second = app(DispositionMailboxWorkflowService::class)->recognize($this->admin, 'INBOX', 32, 401, $first['settings_revision']);

        $this->assertSame($first['intake_id'], $second['intake_id']);
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(1, AiIntakeProposal::count());
        $this->assertSame(1, AiIntakeRun::where('kind', 'mailbox_test')->where('status', 'succeeded')->count());
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_classification_and_customer_each_require_an_explicit_step_before_native_capture(): void
    {
        $workflow = app(DispositionMailboxWorkflowService::class);
        $review = $this->recognize();
        $this->rejected([409, 422], fn () => $workflow->createInquiries($this->admin, $review));
        $classified = $workflow->confirmClassification($this->admin, $review, true);
        $this->assertSame('inquiry', $classified['classification']);
        $this->assertSame($this->admin->id, DispositionMailboxReview::firstOrFail()->reviewed_by);
        $this->assertNull(AiIntake::firstOrFail()->customer_id);
        $this->rejected([409, 422], fn () => $workflow->createInquiries($this->admin, $classified));
        $contact = $this->contact();
        $assigned = $workflow->assignCustomer($this->admin, $classified, $this->customer->id, $contact->id);

        $this->assertSame($this->customer->id, $assigned['customer_id']);
        $this->assertSame($contact->id, $assigned['contact_id']);
        $this->assertSame('manual', AiIntake::firstOrFail()->match_method);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertTrue($assigned['can_create_inquiries']);
        $this->noAutomaticEffects();
    }

    public function test_non_inquiry_confirmation_stops_the_guided_domain_actions_and_pauses_the_intake(): void
    {
        $review = $this->recognize();
        $workflow = app(DispositionMailboxWorkflowService::class);
        $rejected = $workflow->confirmClassification($this->admin, $review, false);

        $this->assertSame('not_inquiry', $rejected['classification']);
        $this->assertSame('paused', AiIntake::firstOrFail()->status);
        $this->rejected([409, 422], fn () => $workflow->createInquiries($this->admin, $rejected));
        $this->rejected([409, 422], fn () => $workflow->prepareClarification($this->admin, $rejected));
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_confirmed_native_capture_creates_only_one_inquiry_and_exposes_its_existing_workflow_link(): void
    {
        $workflow = app(DispositionMailboxWorkflowService::class);
        $review = $this->confirmedCustomer($this->recognize());
        $created = $workflow->createInquiries($this->admin, $review);
        $again = $workflow->createInquiries($this->admin, $created);

        $this->assertSame(1, OperationInquiry::count());
        $inquiry = OperationInquiry::firstOrFail();
        $this->assertSame($this->admin->id, $inquiry->created_by);
        $this->assertSame($this->customer->id, $inquiry->customer_id);
        $this->assertSame($inquiry->id, $created['positions'][0]['inquiry_id']);
        $this->assertSame($inquiry->id, $again['positions'][0]['inquiry_id']);
        $this->assertNotEmpty($created['positions'][0]['inquiry_url']);
        $this->assertNull($created['positions'][0]['order_id']);
        $this->assertNull($created['positions'][0]['order_url']);
        $this->noAutomaticEffects();
    }

    public function test_missing_information_creates_an_idempotent_draft_only_after_classification_and_customer_confirmation(): void
    {
        $this->enableAutomation();
        $review = $this->recognize(['starts_at' => null, 'ends_at' => null, 'segments' => []], ['questions' => [['field' => 'price', 'question' => 'UNTRUSTED promise one euro']]]);
        $review = $this->confirmedCustomer($review);
        $this->assertTrue($review['can_prepare_clarification']);
        $workflow = app(DispositionMailboxWorkflowService::class);
        $draft = $workflow->prepareClarification($this->admin, $review);
        $repeated = $workflow->prepareClarification($this->admin, $draft);

        $this->assertSame(1, AiIntakeDelivery::count());
        $delivery = AiIntakeDelivery::firstOrFail();
        $this->assertSame('draft', $delivery->status);
        $this->assertSame($delivery->id, $draft['clarification']['id']);
        $this->assertSame($delivery->id, $repeated['clarification']['id']);
        $this->assertSame('customer@example.test', $delivery->recipient_email);
        $this->assertStringNotContainsString('UNTRUSTED', $delivery->body);
        $this->assertSame(0, AiIntake::firstOrFail()->question_round);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_disabled_automation_also_supports_a_manual_clarification_draft_without_sending(): void
    {
        $review = $this->confirmedCustomer($this->recognize(['starts_at' => null, 'ends_at' => null, 'segments' => []]));
        $result = app(DispositionMailboxWorkflowService::class)->prepareClarification($this->admin, $review);

        $this->assertFalse(AiDispositionSettings::enabled());
        $this->assertSame('draft', $result['clarification']['status']);
        $this->assertSame(0, AiIntake::firstOrFail()->question_round);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_foreign_contact_and_inactive_customer_cannot_be_confirmed_in_the_guided_assignment(): void
    {
        $review = $this->recognize();
        $workflow = app(DispositionMailboxWorkflowService::class);
        $review = $workflow->confirmClassification($this->admin, $review, true);
        $other = Customer::create(['company_name' => 'Another synthetic company', 'is_active' => true]);
        $foreign = $this->contact($other);
        $this->rejected([404, 422], fn () => $workflow->assignCustomer($this->admin, $review, $this->customer->id, $foreign->id));
        $this->customer->update(['is_active' => false]);
        $this->rejected([404, 422], fn () => $workflow->assignCustomer($this->admin, $review, $this->customer->id));

        $this->assertNull(AiIntake::firstOrFail()->customer_id);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_customer_and_contact_options_only_list_active_current_customer_records(): void
    {
        $activeContact = $this->contact();
        $this->contact(null, ['name' => 'Inactive synthetic contact', 'email' => 'inactive@example.test', 'is_active' => false]);
        $inactive = Customer::create(['company_name' => 'Synthetic Rail inactive', 'is_active' => false]);
        $workflow = app(DispositionMailboxWorkflowService::class);

        $options = $workflow->customerOptions($this->admin, 'Synthetic Rail');
        $this->assertContains($this->customer->id, array_column($options, 'id'));
        $this->assertNotContains($inactive->id, array_column($options, 'id'));
        $this->assertSame([$activeContact->id], array_column($workflow->contactOptions($this->admin, $this->customer->id), 'id'));
        $this->noAutomaticEffects();
    }

    public function test_review_settings_and_intake_changes_reject_stale_guided_confirmation(): void
    {
        $review = $this->recognize();
        $workflow = app(DispositionMailboxWorkflowService::class);
        DispositionMailboxReview::query()->increment('revision');
        $this->rejected([409], fn () => $workflow->confirmClassification($this->admin, $review, true));
        DispositionMailboxReview::query()->decrement('revision');
        AiIntake::whereKey($review['intake_id'])->increment('revision');
        $this->rejected([409], fn () => $workflow->confirmClassification($this->admin, $review, true));
        AiIntake::whereKey($review['intake_id'])->decrement('revision');
        AiDispositionSettings::save(['from_name' => 'Updated synthetic configuration'], $this->admin);
        $this->rejected([409], fn () => $workflow->confirmClassification($this->admin, $review, true));

        $this->assertNull(DispositionMailboxReview::firstOrFail()->reviewed_by);
        $this->assertSame('unreviewed', DispositionMailboxReview::firstOrFail()->classification);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_every_workflow_endpoint_rechecks_fresh_actor_permissions(): void
    {
        $review = $this->recognize();
        $workflow = app(DispositionMailboxWorkflowService::class);
        $actions = [
            fn () => $workflow->recognize($this->admin, 'INBOX', 32, 401, $review['settings_revision']),
            fn () => $workflow->confirmClassification($this->admin, $review, true),
            fn () => $workflow->assignCustomer($this->admin, $review, $this->customer->id),
            fn () => $workflow->createInquiries($this->admin, $review),
            fn () => $workflow->prepareClarification($this->admin, $review),
            fn () => $workflow->customerOptions($this->admin, ''),
            fn () => $workflow->contactOptions($this->admin, $this->customer->id),
        ];
        User::whereKey($this->admin->id)->update(['status' => false]);
        foreach ($actions as $action) {
            $this->rejected([403], $action);
        }
        User::whereKey($this->admin->id)->update(['status' => true, 'role' => 'guest']);
        foreach ($actions as $action) {
            $this->rejected([403], $action);
        }
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    private function openMessage(array $positionChanges = [], array $resultChanges = [], bool $realExtraction = false, array $emailChanges = []): Testable
    {
        if (! $realExtraction) {
            $this->mockExtraction($positionChanges, $resultChanges);
        }
        $email = $this->email($emailChanges);
        $this->imap->shouldReceive('fetch')->andReturn($email);
        $this->imap->shouldReceive('snapshot')->andReturn(['uid_validity' => 401, 'uid_next' => 63]);
        $this->imap->shouldReceive('sentFolder')->andReturn('Sent');
        $this->imap->shouldReceive('browse')->andReturnUsing(function ($settings, $folder, $page, $perPage, $search) use ($email): array {
            return ['folder' => $folder, 'uid_validity' => $folder === 'Sent' ? 987 : 401, 'total' => 25, 'page' => $page, 'per_page' => $perPage,
                'messages' => array_map(fn ($uid) => array_intersect_key(array_replace($email, ['uid' => $uid]), array_flip(['uid', 'from', 'to', 'date', 'seen', 'subject'])), [32, 31])];
        });
        $this->imap->shouldReceive('readMessage')->andReturnUsing(fn ($settings, $folder, $uid, $validity) => array_replace($email, ['folder' => $folder, 'uid' => $uid, 'uid_validity' => $validity]));

        return Livewire::test(DispositionMailbox::class)->call('open')->call('selectMessage', 32);
    }

    private function welcomeEmail(): array
    {
        $body = 'Willkommen! Ihr neues E-Mail-Postfach wurde eingerichtet. Die Einrichtungshinweise finden Sie in Ihren Einstellungen.';

        return [
            'subject' => 'Willkommen bei Ihrem neuen Mailpostfach', 'text' => $body,
            'raw' => "From: customer@example.test\r\nSubject: Willkommen bei Ihrem neuen Mailpostfach\r\n\r\n".$body,
        ];
    }

    private function welcomeProviderResponse(): Response
    {
        $result = [
            'intent' => 'other', 'classification' => 'unrelated', 'extraction_complete' => true, 'overflow' => false,
            'title' => 'Willkommensmail zum neuen Postfach', 'summary' => 'Einrichtungshinweise ohne Personal- oder Leistungsanfrage.',
            'confidence' => 1, 'positions' => [], 'questions' => [], 'customer_draft' => null,
        ];

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode($result, JSON_THROW_ON_ERROR)]]],
        ], JSON_THROW_ON_ERROR));
    }

    private function mockedHttpProvider(array $responses, array &$history): void
    {
        OpenRouterSettings::save([
            'api_key' => 'synthetic-provider-workflow-secret', 'api_url' => 'https://openrouter.ai/api/v1/chat/completions',
            'text_model' => 'synthetic/text', 'data_model' => 'synthetic/data',
        ]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $this->app->instance(OpenRouterChatClient::class, new OpenRouterChatClient(new Client(['handler' => $stack])));
    }

    public function test_real_structured_http_pipeline_recognizes_a_welcome_mail_without_inquiry_or_business_actions(): void
    {
        $history = [];
        $this->mockedHttpProvider([$this->welcomeProviderResponse()], $history);
        $component = $this->openMessage(realExtraction: true, emailChanges: $this->welcomeEmail())
            ->call('startRecognition')->assertHasNoErrors()->assertSet('showMailbox', true)
            ->assertSet('guidedStep', 'recognition')->assertSet('guidedReview.detected_classification', 'not_inquiry')
            ->assertSet('guidedReview.classification', 'not_inquiry')->assertSet('guidedReview.positions', [])
            ->assertSet('guidedReview.can_create_inquiries', false)->assertSet('guidedReview.can_prepare_clarification', false);
        $intake = AiIntake::firstOrFail();
        $this->assertSame('other', $intake->analysis['intent']);
        $this->assertNull($intake->analysis['customer_draft']);
        $this->assertNull($intake->customer_id);
        $this->assertSame('mailbox_test_review', $intake->error_code);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->assertSame('unreviewed', DispositionMailboxReview::firstOrFail()->classification);
        $this->assertNull(DispositionMailboxReview::firstOrFail()->reviewed_by);
        $this->assertCount(1, $history);
        $payload = json_decode((string) $history[0]['request']->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('synthetic/data', $payload['model']);
        $this->assertSame('json_schema', $payload['response_format']['type']);
        $this->assertTrue($payload['response_format']['json_schema']['strict']);
        $this->assertSame(AiIntakeExtractionService::schema(), $payload['response_format']['json_schema']['schema']);
        $this->assertTrue($payload['provider']['require_parameters']);
        $this->assertStringContainsString($this->welcomeEmail()['text'], json_encode($payload['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(self::SOURCE, json_encode($payload['messages'], JSON_UNESCAPED_UNICODE));
        $run = AiIntakeRun::firstOrFail();
        $this->assertSame('succeeded', $run->status);
        $this->assertNull($run->provider_request_id);
        $this->assertNull($run->cost_usd);
        $component->call('startRecognition')->assertHasNoErrors()->assertSet('guidedReview.intake_id', $intake->id);
        $this->assertCount(1, $history);
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(1, AiIntakeRun::count());
        $component->call('confirmGuidedClassification', false)->assertHasNoErrors()->assertSet('guidedStep', 'done');
        $this->assertSame('paused', $intake->fresh()->status);
        $this->noAutomaticEffects();
    }

    public function test_http_404_provider_failure_has_safe_provider_guidance_logging_and_an_explicit_complete_retry(): void
    {
        $history = [];
        $this->mockedHttpProvider([
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 404, 'message' => 'No endpoints found that support the requested parameters. RAW_PROVIDER_PAYLOAD: synthetic-provider-workflow-secret'],
            ], JSON_THROW_ON_ERROR)),
            $this->welcomeProviderResponse(),
        ], $history);
        Log::spy();
        $component = $this->openMessage(realExtraction: true, emailChanges: $this->welcomeEmail())
            ->call('startRecognition')->assertHasErrors('guidedReview')->assertSet('guidedReview', [])
            ->assertSet('guidedStep', 'recognition')->assertSet('showMailbox', true)->assertSet('selectedMessage.uid', 32)
            ->assertSee('Der Anbieter unterstützt das eingestellte AI-Modell oder die angeforderten Funktionen derzeit nicht.')
            ->assertSee('Die AI-Verbindung in den Einstellungen prüfen.')
            ->assertDontSee('RAW_PROVIDER_PAYLOAD')->assertDontSee('synthetic-provider-workflow-secret');
        $intake = AiIntake::firstOrFail();
        $this->assertSame('review', $intake->status);
        $this->assertSame('mailbox_test_review', $intake->error_code);
        $this->assertNull($intake->analysis);
        $this->assertSame('failed', AiIntakeRun::firstOrFail()->status);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $originalSource = AiIntakeMessage::firstOrFail()->getRawOriginal();
        $this->noAutomaticEffects();

        $loggedContext = [];
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
            $this->assertSame('Disposition mailbox guided processing provider failure.', $message);
            $loggedContext = $context;

            return true;
        });
        $this->assertSame(OpenRouterChatException::class, $loggedContext['exception_class']);
        $this->assertSame($this->admin->id, $loggedContext['actor_id']);
        $this->assertSame(32, $loggedContext['uid']);
        $this->assertSame('upstream_http_error', $loggedContext['reason_code']);
        $this->assertSame(404, $loggedContext['upstream_status']);
        $this->assertSame([], array_diff(array_keys($loggedContext), ['exception_class', 'actor_id', 'intake_id', 'uid', 'reason_code', 'upstream_status']));
        $encodedContext = json_encode($loggedContext, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('RAW_PROVIDER_PAYLOAD', $encodedContext);
        $this->assertStringNotContainsString('synthetic-provider-workflow-secret', $encodedContext);
        foreach (['exception', 'trace', 'message', 'body', 'payload', 'token', 'api_key', 'configuration', 'mail'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $loggedContext);
        }
        $component->call('startRecognition')->assertHasNoErrors()->assertSet('guidedStep', 'recognition')
            ->assertSet('guidedReview.intake_id', $intake->id)->assertSet('guidedReview.detected_classification', 'not_inquiry')
            ->assertSet('guidedReview.positions', [])->assertSet('showMailbox', true);
        $this->assertCount(2, $history);
        $this->assertSame(['failed', 'succeeded'], AiIntakeRun::orderBy('id')->pluck('status')->all());
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(1, DispositionMailboxReview::count());
        $this->assertSame($originalSource, AiIntakeMessage::firstOrFail()->getRawOriginal());
        $this->assertSame('other', $intake->fresh()->analysis['intent']);
        $this->assertSame(0, AiIntakeProposal::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    private function renderedAction(string $html, string $action): ?DOMElement
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
            foreach ((new DOMXPath($document))->query('//button') as $button) {
                if ($button instanceof DOMElement && $button->getAttribute('wire:click') === $action) {
                    return $button;
                }
            }

            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function test_ai_icon_is_above_the_selected_inbox_mail_and_absent_for_sent_and_outbox(): void
    {
        $component = $this->openMessage();
        $button = $this->renderedAction($component->html(), 'startRecognition');
        $this->assertNotNull($button);
        $this->assertFalse($button->hasAttribute('disabled'));
        $this->assertGreaterThan(0, $button->getElementsByTagName('i')->length);
        $this->assertNotSame('', $button->getAttribute('aria-label').$button->getAttribute('title'));
        $this->assertLessThan(strpos($component->html(), 'rt-disposition-mailbox__body'), strpos($component->html(), 'wire:click="startRecognition"'));
        $component->call('setTab', 'sent')->call('selectMessage', 32);
        $this->assertNull($this->renderedAction($component->html(), 'startRecognition'));
        $component->call('startRecognition')->assertStatus(422);
        $component = $this->openMessage()->call('setTab', 'outbox');
        $this->assertNull($this->renderedAction($component->html(), 'startRecognition'));
        $this->assertSame(0, AiIntake::count());
        $this->noAutomaticEffects();
    }

    public function test_wizard_stays_in_the_same_mailbox_and_advances_only_through_confirmed_steps(): void
    {
        $component = $this->openMessage()->call('startRecognition')->assertHasNoErrors()
            ->assertSet('guidedStep', 'recognition')->assertSet('showMailbox', true)
            ->assertSet('selectedMessage.uid', 32)->assertSet('selectedIntakeId', null)
            ->assertDontSeeLivewire(AiIntakeInbox::class);
        $component->call('createGuidedInquiries')->assertStatus(422);
        $component = $this->openMessage()->call('startRecognition')
            ->call('confirmGuidedClassification', true)->assertHasNoErrors()->assertSet('guidedStep', 'customer');
        $contact = $this->contact();
        $component->set('customerId', (string) $this->customer->id)->set('contactId', (string) $contact->id)
            ->call('confirmGuidedCustomer')->assertHasNoErrors()->assertSet('guidedStep', 'actions');
        $this->assertSame(0, OperationInquiry::count());
        $component->call('createGuidedInquiries')->assertHasNoErrors()
            ->assertSet('guidedStep', 'actions')->assertSet('showMailbox', true)->assertSet('selectedMessage.uid', 32)
            ->assertDispatched('ai-intake-submitted')->assertDontSeeLivewire(AiIntakeInbox::class);
        $this->assertSame(1, OperationInquiry::count());
        $this->assertNotEmpty($component->get('guidedReview')['positions'][0]['inquiry_url']);
        $this->noAutomaticEffects();
    }

    public function test_non_inquiry_confirmation_ends_the_wizard_without_creating_business_records(): void
    {
        $this->openMessage([], ['intent' => 'other', 'classification' => 'unrelated', 'positions' => []])
            ->call('startRecognition')->assertHasNoErrors()
            ->call('confirmGuidedClassification', false)->assertHasNoErrors()
            ->assertSet('guidedStep', 'done')->assertSet('showMailbox', true)
            ->assertSet('guidedReview.classification', 'not_inquiry');

        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    public function test_normal_mailbox_navigation_clears_the_guided_selection_and_forms(): void
    {
        $actions = [['selectMessage', 31], ['setTab', 'sent'], ['nextPage'], ['refresh'], ['clearSelection'], ['closeGuidedReview']];
        foreach ($actions as $arguments) {
            $component = $this->openMessage()->call('startRecognition')->call('confirmGuidedClassification', true)
                ->set('customerSearch', 'Synthetic')->set('customerId', (string) $this->customer->id);
            $component->call(...$arguments)
                ->assertSet('guidedReview', [])->assertSet('guidedStep', 'recognition')
                ->assertSet('customerSearch', '')->assertSet('customerId', '')->assertSet('contactId', '');
        }
        $component = $this->openMessage()->call('startRecognition')->set('search', 'another subject');
        $component->assertSet('guidedReview', [])->assertSet('guidedStep', 'recognition');
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_locked_guided_context_cannot_be_replaced_with_client_supplied_intake_ids(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(DispositionMailbox::class)->set('guidedReview', ['intake_id' => 999, 'intake_revision' => 1]);
    }

    public function test_locked_guided_step_cannot_skip_classification_and_customer_confirmation(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(DispositionMailbox::class)->set('guidedStep', 'actions');
    }

    public function test_changed_configuration_clears_guided_state_before_any_followup_action(): void
    {
        $component = $this->openMessage()->call('startRecognition');
        AiDispositionSettings::save(['from_name' => 'Changed synthetic settings'], $this->admin);
        $component->call('confirmGuidedClassification', true)->assertHasErrors('mailbox')
            ->assertSet('guidedReview', [])->assertSet('guidedStep', 'recognition')
            ->assertSet('selectedMessage', [])->assertSet('mailboxPage', []);

        $this->assertNull(DispositionMailboxReview::firstOrFail()->reviewed_by);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_native_handoff_opens_the_existing_intake_module_without_capturing_another_record(): void
    {
        $component = $this->openMessage()->call('startRecognition');
        $intakeId = $component->get('guidedReview')['intake_id'];
        $component->call('deferGuidedToNative')->assertSet('showMailbox', false)
            ->assertSet('selectedIntakeId', $intakeId)->assertSeeLivewire(AiIntakeInbox::class);

        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_created_inquiry_native_panel_initializes_closed_forms_and_loads_native_context_without_writes(): void
    {
        $review = $this->confirmedCustomer($this->recognize());
        $created = app(DispositionMailboxWorkflowService::class)->createInquiries($this->admin, $review);
        $inquiry = OperationInquiry::findOrFail($created['positions'][0]['inquiry_id']);
        $this->assertSame('Europe/Berlin', $inquiry->timezone);
        $detail = app(CustomerWorkflowService::class)->process($inquiry, null, [
            'customer_contact_id' => $review['contact_id'], 'assignee_id' => $this->admin->id,
            'priority' => 'high', 'timezone' => $inquiry->timezone,
            'due_at' => '2026-10-19T09:15',
        ], $this->admin);
        $this->assertSame('2026-10-19 07:15:00', $detail->fresh()->getRawOriginal('due_at'));
        $this->assertSame('UTC', $detail->fresh()->due_at->timezoneName);
        $originalDetail = $detail->fresh()->getAttributes();
        $currentActor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($currentActor);

        $panel = Livewire::test(InquiryProcessPanel::class, ['inquiryId' => $inquiry->id])
            ->assertSet('inquiryId', $inquiry->id)->assertSet('processOpen', false)->assertSet('followUpOpen', false)
            ->assertSet('form', ['customer_contact_id' => '', 'assignee_id' => '', 'priority' => 'normal', 'due_at' => ''])
            ->assertSet('followUp', ['title' => '', 'kind' => 'check', 'due_at' => '', 'assignee_id' => '', 'note' => '']);
        $panel->call('editProcess')->assertHasNoErrors()->assertSet('processOpen', true)
            ->assertSet('revision', 1)->assertSet('form.customer_contact_id', $review['contact_id'])
            ->assertSet('form.assignee_id', $this->admin->id)->assertSet('form.priority', 'high')
            ->assertSet('form.timezone', $inquiry->timezone)->assertSet('form.due_at', '2026-10-19T09:15');
        $panel->call('createFollowUp')->assertHasNoErrors()->assertSet('followUpOpen', true)
            ->assertSet('followUp.title', '')->assertSet('followUp.kind', 'check')->assertSet('followUp.note', '')
            ->assertSet('followUp.due_at', '')->assertSet('followUp.timezone', $inquiry->timezone)
            ->assertSet('followUp.assignee_id', $currentActor->id);

        $this->assertSame($originalDetail, $detail->fresh()->getAttributes());
        $this->assertSame(0, $inquiry->followUps()->count());
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(1, AiIntakeMessage::count());
        $this->noAutomaticEffects();
    }

    public function test_native_followup_due_and_completion_instants_remain_utc_with_local_display(): void
    {
        $review = $this->confirmedCustomer($this->recognize());
        $created = app(DispositionMailboxWorkflowService::class)->createInquiries($this->admin, $review);
        $inquiry = OperationInquiry::findOrFail($created['positions'][0]['inquiry_id']);
        $panel = Livewire::test(InquiryProcessPanel::class, ['inquiryId' => $inquiry->id])
            ->call('createFollowUp')->set('followUp.title', 'Synthetic native follow-up')
            ->set('followUp.due_at', '2026-10-19T09:15')->call('saveFollowUp')
            ->assertHasNoErrors()->assertSet('followUpOpen', false)->assertSee('19.10.2026 09:15');
        $followUp = $inquiry->followUps()->firstOrFail();
        $this->assertSame('2026-10-19 07:15:00', $followUp->getRawOriginal('due_at'));
        $this->assertSame('UTC', $followUp->due_at->timezoneName);
        $this->assertSame('2026-10-19T09:15', $followUp->due_at->setTimezone($inquiry->timezone)->format('Y-m-d\TH:i'));
        $this->assertNull($followUp->completed_at);
        $completedAt = CarbonImmutable::parse('2026-10-19T12:34:56Z');
        $this->travelTo($completedAt);
        try {
            $panel->call('selectFollowUp', $followUp->id)->set('completion', 'Synthetic completion verified')
                ->call('completeFollowUp')->assertHasNoErrors()->assertSet('completeOpen', false);
            $completed = $followUp->fresh();
            $this->assertSame('completed', $completed->status);
            $this->assertSame('2026-10-19 12:34:56', $completed->getRawOriginal('completed_at'));
            $this->assertSame('UTC', $completed->completed_at->timezoneName);
            $this->assertSame($completedAt->getTimestamp(), $completed->completed_at->getTimestamp());
        } finally {
            $this->travelBack();
        }
        $this->assertSame(1, $inquiry->followUps()->count());
        $this->assertSame(1, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    private function assertRenderedNativeDueDateRequired(string $html, string $submit, string $fieldId, bool $required): void
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $fields = $xpath->query('//form[@*[name()="wire:submit"]="'.$submit.'"]//div[@data-rt-date-time-field][.//input[@id="'.$fieldId.'"]]');
            $this->assertSame(1, $fields->length);
            $field = $fields->item(0);
            $visibleInputs = $xpath->query('.//input[not(@type="hidden")]', $field);
            $this->assertSame(2, $visibleInputs->length);
            $this->assertSame(0, $xpath->query('.//input[@type="hidden"]', $field)->length);
            $this->assertSame($required ? 2 : 0, $xpath->query('.//input[@required and not(@type="hidden")]', $field)->length);
            foreach ($visibleInputs as $input) {
                $this->assertSame($required, $input->hasAttribute('required'), $input->getAttribute('id'));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function test_native_process_due_date_is_optional_while_followup_due_date_is_required_in_open_forms(): void
    {
        $review = $this->confirmedCustomer($this->recognize());
        $created = app(DispositionMailboxWorkflowService::class)->createInquiries($this->admin, $review);
        $inquiryId = $created['positions'][0]['inquiry_id'];
        $panel = Livewire::test(InquiryProcessPanel::class, ['inquiryId' => $inquiryId])
            ->call('editProcess')->assertSet('processOpen', true)->assertHasNoErrors();
        $this->assertRenderedNativeDueDateRequired($panel->html(), 'saveProcess', 'ops-form-due_at', false);
        $panel->call('createFollowUp')->assertSet('followUpOpen', true)->assertHasNoErrors();
        $this->assertRenderedNativeDueDateRequired($panel->html(), 'saveProcess', 'ops-form-due_at', false);
        $this->assertRenderedNativeDueDateRequired($panel->html(), 'saveFollowUp', 'ops-followUp-due_at', true);

        $inquiry = OperationInquiry::findOrFail($inquiryId);
        $this->assertNull($inquiry->processDetail);
        $this->assertSame(0, $inquiry->followUps()->count());
        $this->noAutomaticEffects();
    }

    public function test_guided_clarification_action_is_enabled_after_confirmation_and_keeps_the_draft_in_the_same_modal(): void
    {
        $component = $this->openMessage(['starts_at' => null, 'ends_at' => null, 'segments' => []])
            ->call('startRecognition')->call('confirmGuidedClassification', true);
        $contact = $this->contact();
        $component->set('customerId', (string) $this->customer->id)->set('contactId', (string) $contact->id)
            ->call('confirmGuidedCustomer')->assertHasNoErrors()->assertSet('guidedStep', 'actions');
        $button = $this->renderedAction($component->html(), 'prepareGuidedClarification');
        $this->assertNotNull($button);
        $this->assertFalse($button->hasAttribute('disabled'));
        $component->call('prepareGuidedClarification')->assertHasNoErrors()
            ->assertSet('guidedStep', 'actions')->assertSet('showMailbox', true)->assertSet('selectedMessage.uid', 32)
            ->assertSet('guidedReview.clarification.status', 'draft')->assertDontSeeLivewire(AiIntakeInbox::class);
        $draftId = $component->get('guidedReview')['clarification']['id'];
        $component->call('prepareGuidedClarification')->assertHasNoErrors()->assertSet('guidedReview.clarification.id', $draftId);

        $this->assertSame(1, AiIntakeDelivery::count());
        $this->assertSame(0, AiIntake::firstOrFail()->question_round);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_changed_review_reports_a_guided_conflict_without_overwriting_the_other_review(): void
    {
        $component = $this->openMessage()->call('startRecognition');
        $record = DispositionMailboxReview::firstOrFail();
        $record->update(['classification' => 'not_inquiry', 'reviewed_by' => $this->admin->id, 'revision' => $record->revision + 1]);
        $changed = $record->fresh()->getRawOriginal();

        $component->call('confirmGuidedClassification', true)->assertHasErrors('guidedReview');

        $this->assertSame($changed, $record->fresh()->getRawOriginal());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        $this->noAutomaticEffects();
    }

    public function test_added_wizard_actions_and_form_updates_recheck_the_current_actor(): void
    {
        $actions = [
            ['startRecognition'], ['confirmGuidedClassification', true], ['confirmGuidedCustomer'],
            ['createGuidedInquiries'], ['prepareGuidedClarification'], ['guidedBack'],
            ['closeGuidedReview'], ['deferGuidedToNative'],
        ];
        foreach ($actions as $arguments) {
            User::whereKey($this->admin->id)->update(['status' => true]);
            $component = Livewire::test(DispositionMailbox::class);
            User::whereKey($this->admin->id)->update(['status' => false]);
            $component->call(...$arguments)->assertForbidden();
        }
        foreach (['customerSearch' => 'Synthetic Rail', 'customerId' => (string) $this->customer->id] as $property => $value) {
            User::whereKey($this->admin->id)->update(['status' => true]);
            $component = Livewire::test(DispositionMailbox::class);
            User::whereKey($this->admin->id)->update(['status' => false]);
            $component->set($property, $value)->assertForbidden();
        }

        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_old_conversation_mail_cannot_recognize_or_drive_the_latest_reply_even_with_current_revisions(): void
    {
        $review = $this->recognize();
        $reply = app(AiIntakeService::class)->receiveEmail($this->email([
            'uid' => 33, 'message_id' => '<workflow-reply-33@example.test>', 'in_reply_to' => '<workflow-32@example.test>',
            'subject' => 'Latest conversation reply', 'text' => 'Updated source received after recognition.',
        ]), $this->admin);
        $this->assertSame($review['intake_id'], $reply->id);
        $runs = AiIntakeRun::count();
        $proposals = AiIntakeProposal::count();
        $workflow = app(DispositionMailboxWorkflowService::class);
        $this->rejected([409], fn () => $workflow->recognize($this->admin, 'INBOX', 32, 401, $review['settings_revision']));
        $oldMailWithFreshRevisions = array_replace($review, ['intake_revision' => $reply->revision, 'source_revision' => $reply->source_revision]);
        $actions = [
            fn () => $workflow->confirmClassification($this->admin, $oldMailWithFreshRevisions, true),
            fn () => $workflow->assignCustomer($this->admin, $oldMailWithFreshRevisions, $this->customer->id),
            fn () => $workflow->createInquiries($this->admin, $oldMailWithFreshRevisions),
            fn () => $workflow->prepareClarification($this->admin, $oldMailWithFreshRevisions),
            fn () => $workflow->refresh($this->admin, $oldMailWithFreshRevisions),
        ];
        foreach ($actions as $action) {
            $this->rejected([409], $action);
        }

        $this->assertSame($runs, AiIntakeRun::count());
        $this->assertSame($proposals, AiIntakeProposal::count());
        $this->assertSame(2, AiIntakeMessage::count());
        $this->assertSame(33, $reply->messages()->findOrFail($reply->latest_inbound_message_id)->metadata['uid']);
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }

    public function test_private_test_draft_is_explicitly_labeled_and_cannot_be_approved_or_delivered(): void
    {
        $this->enableAutomation();
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            Setting::getValueUncached(AiDispositionSettings::GROUP, AiDispositionSettings::KEY),
            ['customer_clarification_mode' => 'draft']
        ));
        $review = $this->confirmedCustomer($this->recognize(['starts_at' => null, 'ends_at' => null, 'segments' => []]));
        $review = app(DispositionMailboxWorkflowService::class)->prepareClarification($this->admin, $review);
        $delivery = AiIntakeDelivery::firstOrFail();
        $this->assertTrue($delivery->metadata['manual_mailbox_test']);
        $this->assertSame('Testentwurf · ohne Versand', $review['clarification']['status_label']);
        $outboxDetail = app(DispositionMailboxService::class)->deliveryDetail($this->admin, $delivery->id);
        $this->assertTrue($outboxDetail['test_only']);
        $this->assertSame('Testentwurf · ohne Versand', $outboxDetail['status_label']);
        $mail = app(AiIntakeMailService::class);
        $this->rejected([409], fn () => $mail->approveDraft($delivery, $this->admin, $delivery->settings_revision, $review['intake_revision']));
        $this->assertSame('draft', $delivery->fresh()->status);
        $this->mock(AiDispositionSmtpTransport::class, fn (MockInterface $mock) => $mock->shouldNotReceive('send'));
        $delivery->update(['status' => 'pending']);
        $this->assertSame('canceled', app(AiIntakeMailService::class)->deliver($delivery->id));
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertSame(0, AiIntake::firstOrFail()->question_round);
        $component = $this->openMessage()->call('setTab', 'outbox')->call('selectDelivery', $delivery->id)
            ->assertSet('selectedMessage.test_only', true)->assertSee('Testentwurf · ohne Versand')
            ->assertDontSee('Nachricht freigeben / verwerfen');
        $this->assertTrue($component->get('mailboxPage')['messages'][0]['test_only']);
        $this->noAutomaticEffects();
    }

    private function fillBoundedOptions(): array
    {
        for ($index = 1; $index <= 25; $index++) {
            Customer::create(['company_name' => sprintf('A bounded company %02d', $index), 'is_active' => true]);
        }
        for ($index = 1; $index <= 35; $index++) {
            $this->contact(null, ['name' => sprintf('A bounded contact %02d', $index), 'email' => 'bounded-'.$index.'@example.test']);
        }

        return [
            $this->contact(null, ['name' => 'Z selected contact', 'email' => 'selected@example.test']),
            Customer::create(['company_name' => 'A bounded company inactive', 'is_active' => false]),
            $this->contact(null, ['name' => 'A bounded contact inactive', 'email' => 'inactive@example.test', 'is_active' => false]),
        ];
    }

    public function test_active_selected_customer_outside_the_first_page_or_search_stays_in_twenty_bounded_options(): void
    {
        [, $inactive] = $this->fillBoundedOptions();
        $workflow = app(DispositionMailboxWorkflowService::class);
        $firstPage = $workflow->customerOptions($this->admin);
        $this->assertCount(20, $firstPage);
        $this->assertNotContains($this->customer->id, array_column($firstPage, 'id'));

        foreach (['', 'A bounded company'] as $search) {
            $selected = $workflow->customerOptions($this->admin, $search, $this->customer->id);
            $this->assertCount(20, $selected);
            $ids = array_column($selected, 'id');
            $this->assertContains($this->customer->id, $ids);
            $this->assertCount(20, array_unique($ids));
            $this->assertNotContains($inactive->id, $ids);
        }
        $selectedWithinPage = $workflow->customerOptions($this->admin, '', $firstPage[0]['id']);
        $this->assertSame($firstPage, $selectedWithinPage);
        foreach ([$inactive->id, 999999] as $excludedId) {
            $options = $workflow->customerOptions($this->admin, '', $excludedId);
            $this->assertCount(20, $options);
            $this->assertNotContains($excludedId, array_column($options, 'id'));
        }
        $this->noAutomaticEffects();
    }

    public function test_active_selected_contact_outside_the_first_page_stays_bounded_and_owner_scoped(): void
    {
        [$selected, , $inactive] = $this->fillBoundedOptions();
        $other = Customer::create(['company_name' => 'Other bounded customer', 'is_active' => true]);
        $foreign = $this->contact($other, ['name' => 'Z foreign contact']);
        $workflow = app(DispositionMailboxWorkflowService::class);
        $firstPage = $workflow->contactOptions($this->admin, $this->customer->id);
        $this->assertCount(30, $firstPage);
        $this->assertNotContains($selected->id, array_column($firstPage, 'id'));
        $options = $workflow->contactOptions($this->admin, $this->customer->id, $selected->id);
        $this->assertCount(30, $options);
        $this->assertContains($selected->id, array_column($options, 'id'));
        $this->assertCount(30, array_unique(array_column($options, 'id')));
        $this->assertSame($firstPage, $workflow->contactOptions($this->admin, $this->customer->id, $firstPage[0]['id']));
        foreach ([$inactive->id, $foreign->id, 999999] as $excludedId) {
            $options = $workflow->contactOptions($this->admin, $this->customer->id, $excludedId);
            $this->assertCount(30, $options);
            $this->assertNotContains($excludedId, array_column($options, 'id'));
        }
        $this->noAutomaticEffects();
    }

    private function renderedOptionValues(string $html, string $idPrefix): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
            $nodes = (new DOMXPath($document))->query('//div[@data-rt-select-options][.//*[starts-with(@id, "'.$idPrefix.'")]]');
            $this->assertSame(1, $nodes->length);
            $options = json_decode($nodes->item(0)->getAttribute('data-rt-select-options'), true, 32, JSON_THROW_ON_ERROR);

            return array_values(array_filter(array_map(fn ($option) => (string) $option['value'], $options), fn ($value) => $value !== ''));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    public function test_rendered_guided_dropdowns_keep_valid_selections_beyond_limits_and_exclude_inactive_options(): void
    {
        [$selected, $inactiveCustomer, $inactiveContact] = $this->fillBoundedOptions();
        $component = $this->openMessage()->call('startRecognition')->call('confirmGuidedClassification', true)
            ->set('customerId', (string) $this->customer->id)->set('contactId', (string) $selected->id)
            ->set('customerSearch', 'A bounded company')->assertHasNoErrors();
        $customers = $this->renderedOptionValues($component->html(), 'guided-customer-');
        $contacts = $this->renderedOptionValues($component->html(), 'guided-contact-');
        $this->assertCount(20, $customers);
        $this->assertContains((string) $this->customer->id, $customers);
        $this->assertNotContains((string) $inactiveCustomer->id, $customers);
        $this->assertCount(30, $contacts);
        $this->assertContains((string) $selected->id, $contacts);
        $this->assertNotContains((string) $inactiveContact->id, $contacts);
        $component->assertSet('customerId', (string) $this->customer->id)->assertSet('contactId', (string) $selected->id);

        $selected->update(['is_active' => false]);
        $component->set('customerSearch', 'A bounded company 0')->assertHasNoErrors();
        $this->assertNotContains((string) $selected->id, $this->renderedOptionValues($component->html(), 'guided-contact-'));
        $this->assertSame(0, OperationInquiry::count());
        $this->noAutomaticEffects();
    }
}
