<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeCustomerContextService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeCustomerContextTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private AiIntakeCustomerContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_11_150000_create_disposition_mailbox_reviews.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true, 'email' => 'operator@internal.test']);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS,
            ['from_address' => 'dispo@internal.test', 'imap_username' => 'dispo@internal.test', 'revision' => 4]));
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('customer-context-'.getmypid()));
        $this->service = app(AiIntakeCustomerContextService::class);
    }

    private function customer(string $name = 'Synthetic Rail GmbH', string $email = 'office@vendor.test'): Customer
    {
        return Customer::create(['company_name' => $name, 'email' => $email, 'is_active' => true]);
    }

    private function intake(string $text, string $sender = 'unknown@other.test', array $changes = [], ?string $raw = null, string $mailbox = 'shared-mailbox', array $metadata = []): AiIntake
    {
        $record = AiIntake::create(array_replace(['source_type' => 'email', 'status' => 'review', 'title' => 'Synthetic received mail', 'revision' => 1, 'source_revision' => 1,
            'created_by' => $this->actor->id, 'analysis' => []], $changes));
        $input = ['direction' => 'inbound', 'sender_email' => $sender, 'body' => $text, 'mailbox_id' => $mailbox,
            'metadata' => array_replace(['subject' => $record->title, 'mailbox_id' => $mailbox, 'raw_archive_complete' => true], $metadata)];
        if ($raw !== null) {
            $paths = [];
            $input['raw_path'] = app(AiIntakeAttachmentService::class)->put($record, $raw, $paths);
            $input['raw_hash'] = hash('sha256', $raw);
        }
        $message = $record->messages()->create($input);
        $record->update(['latest_inbound_message_id' => $message->id]);

        return $record;
    }

    private function raw(string $body, string $from = 'forwarder@internal.test', string $cc = '', string $bcc = ''): string
    {
        return "From: {$from}\r\nTo: dispo@internal.test\r\nCc: {$cc}\r\nBcc: {$bcc}\r\nSubject: Synthetic source\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}";
    }

    private function order(Customer $customer, array $changes = []): Order
    {
        return Order::create(array_replace(['customer_id' => $customer->id, 'order_number' => 'RT-HISTORY-'.Order::count(), 'title' => 'Historical Hamburg service', 'service_type' => 'WU',
            'starts_at' => '2026-07-01T16:00', 'ends_at' => '2026-07-01T18:00', 'timezone' => 'Europe/Berlin', 'location_name' => 'Hamburg Hohe Schaar', 'required_staff' => 2,
            'status' => 'confirmed', 'description' => 'PRIVATE STAFF NAME AND PRICE 999 EUR', 'notes' => 'PRIVATE PERSONNEL NOTE', 'created_at' => now()->subDay()], $changes));
    }

    protected function tearDown(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        parent::tearDown();
    }

    public function test_raw_cc_is_a_candidate_but_bcc_and_internal_sender_are_excluded(): void
    {
        $customer = $this->customer();
        $private = $this->customer('Private Blind Customer', 'private@blind.test');
        $internal = $this->customer('RailTime Internal', 'forwarder@internal.test');
        $record = $this->intake('Flattened body has no identity', raw: $this->raw('Please review the service.', cc: 'office@vendor.test', bcc: 'private@blind.test'));
        $context = $this->service->context($record, $this->actor);
        $this->assertSame([$customer->id], array_column($context['candidates'], 'customer_id'));
        $this->assertSame(['cc_email'], $context['candidates'][0]['reasons']);
        $this->assertSame('cc', $context['candidates'][0]['matched_signals'][0]['source']);
        $this->assertStringNotContainsString('blind.test', json_encode($context));
        $this->assertFalse($context['ambiguous']);
        $this->assertTrue($context['source_available']);
        $this->assertNull($record->fresh()->customer_id);
        $this->assertSame(1, $record->fresh()->revision);
    }

    public function test_forwarded_original_and_signature_are_found_without_binding_internal_forwarder(): void
    {
        $customer = $this->customer();
        $internal = $this->customer('Internal Company', 'forwarder@internal.test');
        $body = "Please check this.\nInternal Company\n\n---------- Weitergeleitete Nachricht ----------\nVon: Buyer <office@vendor.test>\nGesendet: Montag\nAn: dispo@internal.test\nBetreff: WU Hamburg\n\nMit freundlichen Grüßen\nSynthetic Rail GmbH\nE-Mail: office@vendor.test";
        $record = $this->intake('Flattened wrong source', raw: $this->raw($body));
        $candidates = $this->service->candidates($record, $this->actor);
        $this->assertSame([$customer->id], array_column($candidates, 'customer_id'));
        $this->assertContains('forwarded_email', $candidates[0]['reasons']);
        $this->assertContains('signature_email', $candidates[0]['reasons']);
        $this->assertContains('signature_company', $candidates[0]['reasons']);
        $this->assertNull($record->fresh()->customer_contact_id);
    }

    public function test_body_blind_copies_and_wrapped_blind_address_do_not_become_identity_signals(): void
    {
        $this->customer('Blind Recipient', 'private@blind.test');
        $record = $this->intake("Bcc: private@blind.test\n  Private continuation <private@blind.test>\n\nRequest remains unassigned.");
        $this->assertSame([], $this->service->candidates($record, $this->actor));
    }

    public function test_unverified_model_company_and_similar_company_names_do_not_create_candidates(): void
    {
        $this->customer('Synthetic Rail GmbH');
        $record = $this->intake('Synthetic Rail AG sends this request.', changes: ['analysis' => ['customer_draft' => ['company_name' => 'Synthetic Rail GmbH']]]);
        $this->assertSame([], $this->service->candidates($record, $this->actor));
    }

    public function test_exact_public_email_is_supported_but_public_domain_is_not_an_identity(): void
    {
        $customer = $this->customer(email: 'known@gmail.com');
        $record = $this->intake('Unrelated content.', 'known@gmail.com');
        $this->assertSame(['sender_email'], $this->service->candidates($record, $this->actor)[0]['reasons']);
        $this->assertSame([], $this->service->candidates($this->intake('Unrelated content.', 'another@gmail.com'), $this->actor));
    }

    public function test_candidates_are_bounded_deduplicated_and_ambiguity_is_explicit(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $this->customer('Synthetic '.$index, 'office'.$index.'@vendor.test');
        }
        $record = $this->intake('No automatic association.', 'unknown@vendor.test');
        $context = $this->service->context($record, $this->actor);
        $this->assertCount(10, $context['candidates']);
        $this->assertTrue($context['ambiguous']);
        $this->assertTrue($context['lookup_truncated']);
        $this->assertTrue($context['candidates'][0]['ambiguous']);
        $this->assertSame($context, $this->service->context($record, $this->actor));
    }

    public function test_linked_customer_is_allowed_without_sender_and_other_ids_are_rejected(): void
    {
        $customer = $this->customer();
        $foreign = $this->customer('Foreign company', 'office@foreign.test');
        $record = $this->intake('', '', ['customer_id' => $customer->id]);
        $this->assertSame('linked_customer', $this->service->candidates($record, $this->actor)[0]['reasons'][0]);
        $this->assertSame($customer->id, $this->service->history($record, $this->actor, $customer->id)['customer']['id']);
        $this->expectException(HttpException::class);
        $this->service->history($record, $this->actor, $foreign->id);
    }

    public function test_corrupt_raw_is_fail_closed_and_does_not_use_flattened_body_or_sender_inference(): void
    {
        $customer = $this->customer();
        $record = $this->intake('Synthetic Rail GmbH', 'office@vendor.test', raw: $this->raw('Original archive'));
        $message = $record->messages->first();
        Storage::disk('local')->put($message->raw_path, 'CORRUPTED RAW');
        $context = $this->service->context($record, $this->actor);
        $this->assertFalse($context['source_available']);
        $this->assertSame([], $context['candidates']);
        $record->update(['customer_id' => $customer->id]);
        $this->assertSame([$customer->id], array_column($this->service->candidates($record, $this->actor), 'customer_id'));
    }

    public function test_historical_orders_are_bounded_ranked_local_and_exclude_private_fields(): void
    {
        $customer = $this->customer();
        $referenced = $this->order($customer, ['order_number' => 'RT-HISTORY-EXPLICIT']);
        for ($index = 0; $index < 8; $index++) {
            $this->order($customer, ['location_name' => 'Berlin']);
        }
        $foreign = $this->customer('Foreign customer', 'office@foreign.test');
        $this->order($foreign, ['title' => 'PRIVATE FOREIGN ORDER']);
        $record = $this->intake('RT-HISTORY-EXPLICIT, Hamburg Hohe Schaar WU.', 'office@vendor.test');
        $history = $this->service->history($record, $this->actor, $customer->id);
        $this->assertCount(5, $history['orders']);
        $this->assertSame($referenced->id, $history['orders'][0]['id']);
        $this->assertSame('2026-07-01T16:00', $history['orders'][0]['starts_at']);
        $this->assertSame('2026-07-01T18:00', $history['orders'][0]['ends_at']);
        $this->assertContains('order_reference', $history['orders'][0]['reasons']);
        $this->assertContains('same_role', $history['orders'][0]['reasons']);
        $this->assertTrue($history['truncated']['orders']);
        foreach (['PRIVATE', '999', 'description', 'notes', 'requirements', 'created_by'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($history));
        }
        $this->assertSame('historical_hint', $history['provenance']['kind']);
    }

    public function test_related_mails_include_sender_and_cc_unassigned_hints_but_exclude_foreign_customer_and_mailbox(): void
    {
        $customer = $this->customer();
        $priorSender = $this->intake('PRIVATE BODY 999 EUR', 'office@vendor.test', ['processed_source_revision' => 1, 'analysis' => ['positions' => [['role_name' => 'WU', 'location_name' => 'Hamburg', 'required_staff' => 2]]]]);
        $priorCc = $this->intake('PRIVATE CC BODY', raw: $this->raw('Related request', cc: 'office@vendor.test'));
        $this->intake('PRIVATE OTHER MAILBOX', 'office@vendor.test', mailbox: 'other-mailbox');
        $foreign = $this->customer('Foreign customer', 'foreign@other.test');
        $this->intake('PRIVATE FOREIGN CUSTOMER', 'office@vendor.test', ['customer_id' => $foreign->id]);
        $this->intake('Unrelated unassigned request', 'unrelated@other.test');
        $record = $this->intake('Current request.', 'office@vendor.test');
        $history = $this->service->history($record, $this->actor, $customer->id);
        $this->assertEqualsCanonicalizing([$priorSender->id, $priorCc->id], array_column($history['related_mails'], 'intake_id'));
        $sender = collect($history['related_mails'])->firstWhere('intake_id', $priorSender->id);
        $cc = collect($history['related_mails'])->firstWhere('intake_id', $priorCc->id);
        $this->assertContains('same_sender', $sender['reasons']);
        $this->assertContains('same_cc', $cc['reasons']);
        $this->assertSame('Hamburg', $sender['positions'][0]['location_name']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cc['source_hash']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($history));
        $this->assertStringNotContainsString('999', json_encode($history));
    }

    public function test_bcc_only_historical_mail_is_not_related_and_stale_analysis_has_no_position_facts(): void
    {
        $customer = $this->customer();
        $this->intake('Blind source', raw: $this->raw('No relation', bcc: 'office@vendor.test'));
        $stale = $this->intake('Earlier original', 'office@vendor.test', ['source_revision' => 2, 'processed_source_revision' => 1, 'analysis' => ['positions' => [['location_name' => 'STALE PLACE']]]]);
        $record = $this->intake('Current original', 'office@vendor.test');
        $history = $this->service->history($record, $this->actor, $customer->id);
        $this->assertSame([$stale->id], array_column($history['related_mails'], 'intake_id'));
        $this->assertSame([], $history['related_mails'][0]['positions']);
        $this->assertStringNotContainsString('STALE PLACE', json_encode($history));
    }

    public function test_order_permission_is_separate_from_intake_read_permission(): void
    {
        $customer = $this->customer();
        $this->order($customer);
        $record = $this->intake('Current original', 'office@vendor.test');
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn (User $user) => $user->is($actor));
        Gate::define('operations.manage', fn () => false);
        $history = $this->service->history($record, $actor, $customer->id);
        $this->assertFalse($history['permissions']['orders']);
        $this->assertSame([], $history['orders']);
    }

    public function test_stale_intake_object_and_revoked_actor_are_rechecked(): void
    {
        $record = $this->intake('Original');
        AiIntake::whereKey($record->id)->update(['revision' => 2]);
        try {
            $this->service->candidates($record, $this->actor);
            $this->fail('Stale revision was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        User::whereKey($this->actor->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        $this->service->candidates($record->fresh(), $this->actor);
    }

    public function test_settings_changed_during_raw_read_are_rejected_after_lookup(): void
    {
        $this->customer();
        $raw = $this->raw('Original synthetic source', 'office@vendor.test');
        $record = $this->intake('Flattened body', raw: $raw);
        $archive = Mockery::mock(AiIntakeAttachmentService::class);
        $archive->shouldReceive('bytes')->andReturnUsing(function () use ($raw) {
            $settings = AiDispositionSettings::all(true);
            Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace($settings, ['revision' => 5]));

            return $raw;
        });
        $this->app->instance(AiIntakeAttachmentService::class, $archive);
        try {
            $this->service->candidates($record, $this->actor);
            $this->fail('Settings change was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_actor_revoked_during_verified_source_read_is_rejected(): void
    {
        $raw = $this->raw('Original source', 'office@vendor.test');
        $record = $this->intake('Flattened body', raw: $raw);
        $archive = Mockery::mock(AiIntakeAttachmentService::class);
        $archive->shouldReceive('bytes')->andReturnUsing(function () use ($raw) {
            User::whereKey($this->actor->id)->update(['status' => false]);

            return $raw;
        });
        $this->app->instance(AiIntakeAttachmentService::class, $archive);
        try {
            $this->service->candidates($record, $this->actor);
            $this->fail('Revoked actor was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_source_changed_during_verified_source_read_is_rejected(): void
    {
        $raw = $this->raw('Original source', 'office@vendor.test');
        $record = $this->intake('Flattened body', raw: $raw);
        $archive = Mockery::mock(AiIntakeAttachmentService::class);
        $archive->shouldReceive('bytes')->andReturnUsing(function () use ($raw, $record) {
            AiIntake::whereKey($record->id)->update(['revision' => 2, 'source_revision' => 2]);

            return $raw;
        });
        $this->app->instance(AiIntakeAttachmentService::class, $archive);
        try {
            $this->service->candidates($record, $this->actor);
            $this->fail('Changed source was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_internal_user_address_is_filtered_case_insensitively_without_treating_domain_as_customer(): void
    {
        User::factory()->create(['role' => 'staff', 'status' => true, 'email' => 'Staff@public-vendor.test']);
        $this->customer('Unrelated vendor identity', 'staff@public-vendor.test');
        $record = $this->intake('Internal user forwards something without a customer identity.', 'staff@public-vendor.test');
        $this->assertSame([], $this->service->candidates($record, $this->actor));
    }

    public function test_mail_reassigned_to_foreign_customer_during_raw_read_is_removed_before_return(): void
    {
        $customer = $this->customer();
        $foreign = $this->customer('Foreign customer', 'office@foreign.test');
        $raw = $this->raw('Earlier request', 'office@vendor.test');
        $prior = $this->intake('Earlier request', 'office@vendor.test', ['customer_id' => $customer->id], $raw);
        $current = $this->intake('Current request', 'office@vendor.test');
        $archive = Mockery::mock(AiIntakeAttachmentService::class);
        $archive->shouldReceive('bytes')->andReturnUsing(function () use ($raw, $prior, $foreign) {
            AiIntake::whereKey($prior->id)->update(['customer_id' => $foreign->id]);

            return $raw;
        });
        $this->app->instance(AiIntakeAttachmentService::class, $archive);
        $this->assertSame([], $this->service->history($current, $this->actor, $customer->id)['related_mails']);
    }
}
