<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiIntake;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Ai\OpenRouterToolDecision;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeCustomerContextService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeCustomerAssignmentTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private MockInterface $provider;

    private const BODY = 'Nachbestellung Synthetic Dispatch Rail: Tf Hamburg 2027-05-13T08:00 2027-05-13T16:00, zwei Mitarbeiter, Pause 30 Minuten.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true, 'email' => 'operator@internal.test']);
        $this->actingAs($this->actor);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('customer-assignment-'.getmypid()));
        config(['view.compiled' => sys_get_temp_dir().'/railtime-customer-assignment-'.getmypid()]);
        Cache::flush();
        $this->settings();
        $this->provider = $this->mock(OpenRouterChatClient::class);
        $this->provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $this->provider->shouldReceive('completeToolDecision')->andReturn(new OpenRouterToolDecision('No historical lookup needed.', []));
    }

    private function settings(array $changes = []): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['enabled' => false, 'supervisor_id' => null, 'revision' => 5, 'imap_host' => 'imap.example.test',
                'imap_username' => 'dispatch@internal.test', 'from_address' => 'dispatch@internal.test'], $changes));
    }

    private function customer(string $name = 'Synthetic Dispatch Rail', string $email = 'office@rail.example.test'): Customer
    {
        return Customer::create(['company_name' => $name, 'email' => $email, 'is_active' => true]);
    }

    private function capture(string $body = self::BODY, string $sender = 'unknown@forwarder.test', string $cc = '', bool $automatic = false): AiIntake
    {
        return app(AiIntakeService::class)->receiveEmail([
            'mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 401, 'uid' => 37,
            'from' => $sender, 'reply_to' => $sender, 'subject' => 'Synthetic review', 'message_id' => 'synthetic-auto-customer@example.test',
            'text' => $body, 'raw' => "From: {$sender}\r\nCc: {$cc}\r\nTo: dispatch@internal.test\r\nSubject: Synthetic review\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body,
        ], $automatic ? null : $this->actor)->fresh();
    }

    private function extractionResult(AiIntake $intake): array
    {
        $body = $intake->messages()->firstOrFail()->body;
        $position = ['title' => 'Synthetic service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00',
            'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2,
            'segments' => [['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'planned_break_minutes' => 30]],
            'qualification_requirements' => [], 'missing_fields' => [], 'evidence' => []];
        foreach (['starts_at', 'ends_at', 'location_name', 'role_name', 'required_staff', 'segments'] as $field) {
            $position['evidence'][] = ['field' => $field, 'message_id' => $intake->latest_inbound_message_id, 'attachment_id' => null, 'quote' => $body];
        }

        return ['intent' => 'inquiry', 'classification' => 'new_inquiry', 'extraction_complete' => true, 'overflow' => false,
            'title' => 'Synthetic inquiry', 'summary' => 'Synthetic original demand', 'confidence' => .9,
            'positions' => [$position], 'questions' => [], 'customer_draft' => null];
    }

    private function expectResult(AiIntake $intake, array $changes = [], ?callable $callback = null): void
    {
        $result = array_replace($this->extractionResult($intake), $changes);
        $this->provider->shouldReceive('complete')->once()->andReturnUsing(function () use ($result, $callback): OpenRouterChatResponse {
            $this->assertSame(0, DB::transactionLevel());
            $callback?->__invoke();

            return new OpenRouterChatResponse(json_encode($result, JSON_THROW_ON_ERROR));
        });
    }

    private function recognize(AiIntake $intake): AiIntake
    {
        return app(AiIntakeService::class)->analyzeManually($intake, $this->actor, $intake->revision, 5);
    }

    private function noBusinessChanges(): void
    {
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_unique_literal_body_company_assigns_existing_customer_without_contact_guess_or_business_changes(): void
    {
        $customer = $this->customer();
        CustomerContact::create(['customer_id' => $customer->id, 'name' => 'Synthetic unrelated contact', 'email' => 'contact@rail.example.test', 'roles' => [], 'is_active' => true, 'updated_by' => $this->actor->id]);
        $intake = $this->capture();
        $message = $intake->messages()->firstOrFail();
        $original = app(AiIntakeAttachmentService::class)->bytes($intake, $message->raw_path, $message->raw_hash);
        $candidate = app(AiIntakeCustomerContextService::class)->context($intake, $this->actor)['candidates'][0];
        $this->assertSame(.76, $candidate['confidence']);
        $this->expectResult($intake);

        $recognized = $this->recognize($intake);
        $this->assertSame($customer->id, $recognized->customer_id);
        $this->assertSame('source_context', $recognized->match_method);
        $this->assertNull($recognized->customer_contact_id);
        $this->assertNotContains('customer_id', $recognized->missing_fields);
        $this->assertSame(['body_company'], $recognized->analysis['customer_match']['reasons']);
        $this->assertSame($customer->id, $recognized->analysis['customer_match']['customer_id']);
        $this->assertTrue(app(AiIntakeCustomerContextService::class)->assignmentIsCurrent($recognized, $this->actor));
        $this->assertSame('automatic_customer_match', $recognized->proposals()->firstOrFail()->payload['assumptions']['customer_match']['basis']);
        $this->assertSame(1, Customer::count());
        $this->assertSame($original, app(AiIntakeAttachmentService::class)->bytes($recognized, $message->raw_path, $message->raw_hash));
        $this->assertSame(self::BODY, $message->fresh()->body);
        $this->assertSame($customer->id, OperationAudit::where('action', 'ai.customer_auto_assigned')->sole()->data['customer_id']);
        Queue::assertNothingPushed();
        $this->noBusinessChanges();
    }

    public function test_actual_automatic_job_assigns_customer_but_does_not_create_native_inquiry_or_delivery(): void
    {
        $customer = $this->customer();
        $this->settings(['enabled' => true, 'supervisor_id' => $this->actor->id]);
        $intake = $this->capture(automatic: true);
        $this->expectResult($intake);
        $this->actingAs(User::factory()->create(['role' => 'staff', 'status' => false]));
        (new ProcessAiIntake($intake->id))->handle(app(AiIntakeService::class));

        $recognized = $intake->fresh();
        $this->assertSame($customer->id, $recognized->customer_id);
        $this->assertSame('source_context', $recognized->match_method);
        $this->assertSame('review', $recognized->status);
        $this->assertContains('native_review', $recognized->missing_fields);
        $this->assertNotContains('customer_id', $recognized->missing_fields);
        $this->assertSame('succeeded', $recognized->runs()->sole()->status);
        $this->noBusinessChanges();
    }

    public function test_ambiguous_candidates_and_model_ids_cannot_assign_a_customer(): void
    {
        $one = $this->customer();
        $two = $this->customer('Synthetic South Rail', 'office@south.example.test');
        $intake = $this->capture(self::BODY.' Synthetic South Rail.');
        $this->expectResult($intake, ['customer_id' => $one->id, 'customer_match' => ['method' => 'source_context', 'customer_id' => $two->id],
            'context_hints' => ['candidates' => [['customer_id' => $one->id]]]]);

        $recognized = $this->recognize($intake);
        $this->assertNull($recognized->customer_id);
        $this->assertArrayNotHasKey('customer_match', $recognized->analysis);
        $this->assertContains('customer_id', $recognized->missing_fields);
        $this->assertSame(0, OperationAudit::where('action', 'ai.customer_auto_assigned')->count());
        $this->noBusinessChanges();
    }

    public function test_domain_only_candidate_is_left_for_personal_selection(): void
    {
        $this->customer();
        $intake = $this->capture('Tf Hamburg 2027-05-13T08:00 2027-05-13T16:00 zwei Mitarbeiter, Pause 30 Minuten.', 'unknown@rail.example.test');
        $this->expectResult($intake);
        $recognized = $this->recognize($intake);
        $this->assertNull($recognized->customer_id);
        $this->assertContains('customer_id', $recognized->missing_fields);
        $this->noBusinessChanges();
    }

    #[DataProvider('personalAssignments')]
    public function test_existing_customer_scope_and_explicit_contact_are_never_overwritten(bool $contact): void
    {
        $this->customer();
        $selected = $this->customer('Personally selected customer', 'selected@other.test');
        $intake = $this->capture();
        $selectedContact = $contact ? CustomerContact::create(['customer_id' => $selected->id, 'name' => 'Chosen contact', 'email' => 'chosen@other.test', 'roles' => [], 'is_active' => true, 'updated_by' => $this->actor->id]) : null;
        app(AiIntakeService::class)->assignCustomer($intake, $this->actor, $selected->id, $selectedContact?->id, $intake->revision);
        $intake = $intake->fresh();
        $this->expectResult($intake);
        $recognized = $this->recognize($intake);
        $this->assertSame($selected->id, $recognized->customer_id);
        $this->assertSame($selectedContact?->id, $recognized->customer_contact_id);
        $this->assertSame('manual', $recognized->match_method);
        $this->assertArrayNotHasKey('customer_match', $recognized->analysis);
        $this->noBusinessChanges();
    }

    public static function personalAssignments(): array
    {
        return ['customer only' => [false], 'explicit contact' => [true]];
    }

    public function test_customer_deactivated_during_provider_request_is_not_assigned(): void
    {
        $customer = $this->customer();
        $intake = $this->capture();
        $this->expectResult($intake, callback: fn () => $customer->update(['is_active' => false]));
        $this->assertNull($this->recognize($intake)->customer_id);
        $this->noBusinessChanges();
    }

    #[DataProvider('contextChanges')]
    public function test_provider_callback_changes_reject_manual_assignment_commit(string $change): void
    {
        $this->customer();
        $intake = $this->capture();
        $this->expectResult($intake, callback: function () use ($change, $intake): void {
            match ($change) {
                'actor' => User::whereKey($this->actor->id)->update(['status' => false]),
                'settings' => $this->settings(['revision' => 6]),
                'revision' => AiIntake::whereKey($intake->id)->increment('revision'),
                'source' => AiIntake::whereKey($intake->id)->increment('source_revision'),
                'customer' => AiIntake::whereKey($intake->id)->update(['customer_id' => $this->customer('Other customer', 'other@other.test')->id]),
            };
        });
        try {
            $this->recognize($intake);
            $this->fail('A changed context cannot commit a recognized customer.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 409]);
        }
        $this->assertNotSame('source_context', $intake->fresh()->match_method);
        $this->assertSame(0, OperationAudit::where('action', 'ai.customer_auto_assigned')->count());
        $this->noBusinessChanges();
    }

    public static function contextChanges(): array
    {
        return array_map(fn ($value) => [$value], ['actor', 'settings', 'revision', 'source', 'customer']);
    }

    public function test_automatic_job_cannot_overwrite_a_personal_assignment_made_during_analysis(): void
    {
        $this->customer();
        $selected = $this->customer('Selected during analysis', 'selected@other.test');
        $this->settings(['enabled' => true, 'supervisor_id' => $this->actor->id]);
        $intake = $this->capture(automatic: true);
        $this->expectResult($intake, callback: function () use ($intake, $selected): void {
            $fresh = $intake->fresh();
            app(AiIntakeService::class)->assignCustomer($fresh, $this->actor, $selected->id, expectedRevision: $fresh->revision);
        });
        (new ProcessAiIntake($intake->id))->handle(app(AiIntakeService::class));
        $this->assertSame($selected->id, $intake->fresh()->customer_id);
        $this->assertSame('manual', $intake->fresh()->match_method);
        $this->assertSame('stale', $intake->runs()->sole()->status);
        $this->noBusinessChanges();
    }

    public function test_source_context_customer_is_preserved_on_new_reply_and_proof_refreshed_by_fresh_analysis(): void
    {
        $customer = $this->customer();
        $intake = $this->capture();
        $this->expectResult($intake);
        $recognized = $this->recognize($intake);
        $body = self::BODY.' Ergänzende Nachricht.';
        $reply = app(AiIntakeService::class)->receiveEmail(['mailbox_id' => AiDispositionSettings::mailboxId(), 'uid_validity' => 401, 'uid' => 38,
            'from' => 'unknown@forwarder.test', 'reply_to' => 'unknown@forwarder.test', 'message_id' => 'new-reply@example.test',
            'in_reply_to' => 'synthetic-auto-customer@example.test', 'text' => $body,
            'raw' => "From: unknown@forwarder.test\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body], $this->actor)->fresh();
        $this->assertSame($recognized->id, $reply->id);
        $this->assertSame($customer->id, $reply->customer_id);
        $this->assertSame('source_context', $reply->match_method);
        $this->assertFalse(app(AiIntakeCustomerContextService::class)->assignmentIsCurrent($reply, $this->actor));
        $this->expectResult($reply);
        $newAnalysis = $this->recognize($reply);
        $this->assertTrue(app(AiIntakeCustomerContextService::class)->assignmentIsCurrent($newAnalysis, $this->actor));
        $this->assertSame($reply->source_revision, $newAnalysis->analysis['customer_match']['source_revision']);
        $this->noBusinessChanges();
    }

    public function test_header_and_body_signal_truncation_prevent_false_unique_assignment(): void
    {
        $this->customer('One header customer', 'one@vendor.test');
        $this->customer('Hidden second customer', 'hidden@vendor.test');
        $cc = ['one@vendor.test', ...array_map(fn ($index) => 'noise'.$index.'@unknown.test', range(1, 31)), 'hidden@vendor.test'];
        $intake = $this->capture('Synthetic mail.', cc: implode(', ', $cc));
        $helper = app(AiIntakeCustomerContextService::class);
        $this->assertTrue($helper->context($intake, $this->actor)['lookup_truncated']);
        $this->assertNull($helper->assignmentCandidate($intake, $this->actor));
        $this->assertNull($intake->fresh()->customer_id);
        $body = implode("\n", ['one@vendor.test', ...array_map(fn ($index) => 'noise'.$index.'@unknown.test', range(1, 63)), 'hidden@vendor.test']);
        $separate = app(AiIntakeService::class)->submit($this->actor, $body);
        $this->assertTrue($helper->context($separate, $this->actor)['lookup_truncated']);
        $this->assertNull($helper->assignmentCandidate($separate, $this->actor));
        $this->noBusinessChanges();
    }

    public function test_limited_authorized_actor_can_assign_but_permission_revocation_during_provider_blocks_commit(): void
    {
        $this->actor->update(['role' => 'staff']);
        $allowed = true;
        Gate::define('operations.inquiries.manage', function (User $user) use (&$allowed): bool {
            return $allowed && $user->is($this->actor);
        });
        Gate::define('operations.manage', fn () => false);
        $this->customer();
        $intake = $this->capture();
        $this->expectResult($intake, callback: function () use (&$allowed): void {
            $allowed = false;
        });
        $this->expectException(AuthorizationException::class);
        try {
            $this->recognize($intake);
        } finally {
            $this->assertNull($intake->fresh()->customer_id);
            $this->assertSame(0, OperationAudit::where('action', 'ai.customer_auto_assigned')->count());
            $this->noBusinessChanges();
        }
    }

    public function test_incomplete_amendment_assigns_customer_without_enabling_proposal_capture(): void
    {
        $customer = $this->customer();
        $intake = $this->capture();
        $this->expectResult($intake, ['intent' => 'amendment', 'classification' => 'amendment', 'extraction_complete' => false]);
        $recognized = $this->recognize($intake);
        $this->assertSame($customer->id, $recognized->customer_id);
        $this->assertNotContains('customer_id', $recognized->missing_fields);
        $this->assertSame(0, $recognized->proposals()->count());
        $this->assertFalse($recognized->analysis['extraction_complete']);
        $this->assertTrue(app(AiIntakeCustomerContextService::class)->assignmentIsCurrent($recognized, $this->actor));
        $this->noBusinessChanges();
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_or_paused_intake_cannot_be_assigned_by_a_fresh_model_request(string $status): void
    {
        $this->customer();
        $intake = $this->capture();
        $intake->update(['status' => $status]);
        $this->provider->shouldReceive('complete')->never();
        try {
            $this->recognize($intake);
            $this->fail('Terminal and paused workflows must remain unchanged.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [404, 409]);
        }
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame($status, $intake->fresh()->status);
        $this->noBusinessChanges();
    }

    public static function terminalStates(): array
    {
        return ['completed' => ['completed'], 'not inquiry' => ['not_inquiry'], 'paused' => ['paused']];
    }

    public function test_linked_native_inquiry_cannot_receive_a_new_customer_assignment_from_recognition(): void
    {
        $this->customer();
        $originalCustomer = $this->customer('Native existing customer', 'native@other.test');
        $intake = $this->capture();
        $inquiry = OperationInquiry::create(['channel' => 'email', 'title' => 'Existing native inquiry', 'original' => 'Synthetic native source',
            'customer_id' => $originalCustomer->id, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        AiIntakeProposal::create(['intake_id' => $intake->id, 'source_revision' => $intake->source_revision, 'position_index' => 0,
            'status' => 'proposed', 'revision' => 1, 'inquiry_id' => $inquiry->id, 'payload' => []]);
        $original = $inquiry->fresh()->getAttributes();
        $this->provider->shouldReceive('complete')->never();
        try {
            $this->recognize($intake);
            $this->fail('Native workflows require their separate review path.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame($original, $inquiry->fresh()->getAttributes());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
    }

    public function test_complete_customer_inventory_is_required_and_corrupt_archive_cannot_assign(): void
    {
        $customer = $this->customer();
        $template = $customer->getAttributes();
        $rows = [];
        for ($index = 1; $index <= 2000; $index++) {
            $rows[] = array_replace($template, ['id' => $index + 1, 'public_id' => 'synthetic-'.$index, 'customer_number' => 'BOUND-'.$index,
                'company_name' => 'Unrelated inventory '.$index, 'email' => 'inventory'.$index.'@unrelated.test']);
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('customers')->insert($chunk);
        }
        $intake = $this->capture();
        $helper = app(AiIntakeCustomerContextService::class);
        $this->assertTrue($helper->context($intake, $this->actor)['lookup_truncated']);
        $this->assertNull($helper->assignmentCandidate($intake, $this->actor));
        DB::table('ai_intake_messages')->where('id', $intake->latest_inbound_message_id)->update(['raw_hash' => str_repeat('0', 64)]);
        $this->assertFalse($helper->context($intake, $this->actor)['source_available']);
        $this->assertNull($helper->assignmentCandidate($intake, $this->actor));
        $this->assertNull($intake->fresh()->customer_id);
        $this->noBusinessChanges();
    }

    public function test_customer_assignment_and_analysis_roll_back_together_when_audit_fails(): void
    {
        $this->customer();
        $intake = $this->capture();
        $this->expectResult($intake);
        $this->mock(OperationsAuditService::class)->shouldReceive('record')->andReturnUsing(function ($subject, $actor, string $action, array $data): void {
            if ($action === 'ai.customer_auto_assigned') {
                throw new RuntimeException('Synthetic audit failure.');
            }
            (new OperationsAuditService)->record($subject, $actor, $action, $data);
        });
        try {
            $this->recognize($intake);
            $this->fail('No customer assignment may survive a failed analysis transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure.', $error->getMessage());
        }
        $this->assertNull($intake->fresh()->customer_id);
        $this->assertSame(0, $intake->proposals()->count());
        $this->assertSame('failed', $intake->runs()->sole()->status);
        $this->noBusinessChanges();
    }
}
