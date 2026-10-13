<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Operations\AiIntakeAttachmentService;
use App\Services\Operations\AiIntakeContextHints;
use App\Services\Operations\AiIntakeCustomerContextService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeContextHintsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Customer $customer;

    private Order $order;

    private AiIntake $current;

    private AiIntake $prior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_11_150000_create_disposition_mailbox_reviews.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic historical customer', 'email' => 'office@vendor.test', 'is_active' => true]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['revision' => 4, 'from_address' => 'dispo@internal.test']));
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('context-hints-'.getmypid()));
        $this->order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Earlier WU', 'service_type' => 'WU', 'starts_at' => '2026-07-01T16:00', 'ends_at' => '2026-07-01T18:00',
            'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'status' => 'confirmed', 'created_at' => now()->subDay()]);
        $this->prior = $this->intake('Earlier WU Hamburg', true);
        $this->current = $this->intake('Current WU Hamburg');
        $history = app(AiIntakeCustomerContextService::class)->history($this->current, $this->actor, $this->customer->id);
        $this->current->update(['analysis' => ['context_hints' => ['provenance' => ['kind' => 'historical_hint', 'intake_id' => $this->current->id,
            'source_revision' => $this->current->source_revision, 'settings_revision' => 4, 'actor_id' => $this->actor->id], 'history' => [$history]]]]);
        config(['view.compiled' => sys_get_temp_dir().'/railtime-context-hints-'.getmypid()]);
    }

    private function intake(string $body, bool $prior = false): AiIntake
    {
        $record = AiIntake::create(['source_type' => 'email', 'status' => 'review', 'title' => $body, 'revision' => 1, 'source_revision' => 1,
            'processed_source_revision' => $prior ? 1 : null, 'customer_id' => $this->customer->id,
            'analysis' => $prior ? ['positions' => [['role_name' => 'WU', 'location_name' => 'Hamburg', 'required_staff' => 2]]] : []]);
        $raw = "From: office@vendor.test\r\nTo: dispo@internal.test\r\nSubject: {$body}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}";
        $paths = [];
        $path = app(AiIntakeAttachmentService::class)->put($record, $raw, $paths);
        $message = $record->messages()->create(['direction' => 'inbound', 'sender_email' => 'office@vendor.test', 'body' => $body, 'mailbox_id' => 'shared-mailbox',
            'raw_path' => $path, 'raw_hash' => hash('sha256', $raw), 'metadata' => ['subject' => $body, 'mailbox_id' => 'shared-mailbox', 'raw_archive_complete' => true]]);
        $record->update(['latest_inbound_message_id' => $message->id]);

        return $record;
    }

    private function hints(?User $actor = null): array
    {
        return app(AiIntakeContextHints::class)->forReview($this->current, $actor ?? $this->actor);
    }

    protected function tearDown(): void
    {
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        parent::tearDown();
    }

    public function test_current_server_generated_hints_are_presented_with_real_flat_positions(): void
    {
        $hints = $this->hints();
        $this->assertSame($this->order->id, $hints['history'][0]['orders'][0]['id']);
        $this->assertSame($this->prior->id, $hints['history'][0]['related_mails'][0]['intake_id']);
        $this->assertSame('Hamburg', $hints['history'][0]['related_mails'][0]['positions'][0]['location_name']);
    }

    public function test_changed_current_source_does_not_display_old_hints_from_a_stale_model_instance(): void
    {
        AiIntake::whereKey($this->current->id)->update(['revision' => 2, 'source_revision' => 2]);
        $this->assertSame([], $this->hints());
    }

    public function test_changed_settings_hide_old_hints(): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::all(true), ['revision' => 5]));
        $this->assertSame([], $this->hints());
    }

    public function test_revoked_intake_access_is_checked_from_fresh_actor(): void
    {
        User::whereKey($this->actor->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        $this->hints();
    }

    public function test_order_permission_is_rechecked_without_granting_it_from_history(): void
    {
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn (User $user) => $user->is($actor));
        Gate::define('operations.manage', fn () => false);
        $hints = $this->hints($actor);
        $this->assertSame([], $hints['history'][0]['orders']);
        $this->assertFalse($hints['history'][0]['permissions']['orders']);
        $this->assertCount(1, $hints['history'][0]['related_mails']);
    }

    public function test_deleted_and_reassigned_foreign_orders_are_removed(): void
    {
        $foreign = Customer::create(['company_name' => 'Foreign customer', 'email' => 'foreign@other.test', 'is_active' => true]);
        $this->order->update(['customer_id' => $foreign->id]);
        $this->assertSame([], $this->hints()['history'][0]['orders']);
        $this->order->update(['customer_id' => $this->customer->id]);
        $this->order->delete();
        $this->assertSame([], $this->hints()['history'][0]['orders']);
    }

    public function test_deleted_and_reassigned_foreign_mails_are_removed(): void
    {
        $foreign = Customer::create(['company_name' => 'Foreign customer', 'email' => 'foreign@other.test', 'is_active' => true]);
        $this->prior->update(['customer_id' => $foreign->id]);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
        $this->prior->update(['customer_id' => $this->customer->id]);
        DB::table('ai_intake_messages')->where('intake_id', $this->prior->id)->delete();
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
    }

    public function test_closed_intake_and_outbound_message_are_not_historical_inbound_hints(): void
    {
        $this->prior->update(['status' => 'not_inquiry']);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
        $this->prior->update(['status' => 'review']);
        DB::table('ai_intake_messages')->where('intake_id', $this->prior->id)->update(['direction' => 'outbound']);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
    }

    public function test_changed_related_source_or_review_revision_invalidates_its_snapshot(): void
    {
        $this->prior->update(['revision' => 2, 'source_revision' => 2]);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
        $this->prior->update(['revision' => 2, 'source_revision' => 1]);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
    }

    public function test_changed_historical_message_hash_is_rejected_even_without_intake_revision_change(): void
    {
        DB::table('ai_intake_messages')->where('intake_id', $this->prior->id)->update(['sender_email' => Crypt::encryptString('changed@other.test')]);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
    }

    public function test_corrupt_or_missing_historical_raw_archives_are_excluded(): void
    {
        $message = $this->prior->messages->first();
        Storage::disk('local')->put($message->raw_path, 'CORRUPTED ARCHIVE');
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
        Storage::disk('local')->delete($message->raw_path);
        $this->assertSame([], $this->hints()['history'][0]['related_mails']);
    }

    public function test_corrupt_current_original_hides_history_without_flat_body_fallback(): void
    {
        Storage::disk('local')->put($this->current->messages->first()->raw_path, 'CORRUPTED CURRENT');
        $this->assertSame([], $this->hints());
    }

    public function test_shared_partial_uses_native_accordion_and_shared_sender_cc_provenance_labels(): void
    {
        $hints = $this->hints();
        $hints['history'][0]['related_mails'][0]['reasons'] = ['same_cc', 'same_forwarded_sender'];
        $hints['history'][0]['related_mails'][0]['subject'] = '<script>untrusted source</script>';
        $html = view('livewire.operations.partials.ai-intake-context-hints', ['contextHints' => $hints, 'contextHintId' => 'fixture-host'])->render();
        $this->assertStringContainsString('intake-history-fixture-host-history-trigger', $html);
        $this->assertStringContainsString('aria-expanded', $html);
        $this->assertStringContainsString('Gleiche Adresse in CC', $html);
        $this->assertStringContainsString('Gleicher Absender im weitergeleiteten Original', $html);
        $this->assertStringContainsString('&lt;script&gt;untrusted source&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('<script>untrusted source', $html);
    }
}
