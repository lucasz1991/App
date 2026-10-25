<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\AiIntake;
use App\Models\AiIntakeProposal;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\OperationsAuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DirectIntakeOrderTest extends TestCase
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
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_07_010000_create_ai_disposition_intake.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic direct-order customer', 'is_active' => true]);
        $this->actingAs($this->actor);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    private function inquiry(array $changes = []): OperationInquiry
    {
        return app(InquiryWorkflowService::class)->save(null, array_replace([
            'channel' => 'manual', 'title' => 'Synthetic direct order',
            'original' => 'Synthetic original commissioning remains unchanged.',
            'customer_id' => $this->customer->id, 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T06:00',
            'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 2,
        ], $changes), $this->actor)->fresh();
    }

    /** @return array{AiIntake, AiIntakeProposal} */
    private function source(OperationInquiry $inquiry): array
    {
        $intake = AiIntake::create([
            'source_type' => 'email', 'status' => 'review', 'revision' => 3, 'source_revision' => 1,
            'customer_id' => $this->customer->id, 'created_by' => $this->actor->id,
        ]);
        $inquiry->update(['source_reference' => 'ai-intake:'.$intake->public_id.':0']);
        $proposal = $intake->proposals()->create([
            'source_revision' => 1, 'position_index' => 0, 'revision' => 2, 'status' => 'proposed',
            'inquiry_id' => $inquiry->id, 'inquiry_revision' => $inquiry->revision,
            'inquiry_fingerprint' => $this->fingerprint($inquiry),
            'payload' => ['review_confirmation' => ['actor_id' => $this->actor->id, 'source_revision' => 1,
                'customer_communication' => 'off', 'planning_mode' => 'manual']],
        ]);

        return [$intake, $proposal];
    }

    private function fingerprint(OperationInquiry $inquiry): string
    {
        return hash('sha256', json_encode([$inquiry->customer_id, $inquiry->title, $inquiry->starts_at?->toIso8601String(), $inquiry->ends_at?->toIso8601String(), $inquiry->timezone, $inquiry->role_name, $inquiry->location_name, $inquiry->required_staff], JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected workflow validation to reject this command.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_native_direct_order_needs_no_offer_or_customer_acceptance_and_has_no_delivery_or_planning_side_effects(): void
    {
        $inquiry = $this->inquiry();
        $converted = app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $order = $converted->order;

        $this->assertSame('converted', $converted->status);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($inquiry->title, $order->title);
        $this->assertTrue($inquiry->starts_at->eq($order->starts_at));
        $this->assertSame('2027-05-14 20:00:00', $order->getRawOriginal('starts_at'));
        $this->assertSame($this->actor->id, $order->created_by);
        $this->assertNull($converted->offer);
        $this->assertNull($converted->accepted_revision);
        $this->assertNull($converted->acceptance_note);
        $this->assertNull($converted->verified_revision);
        $this->assertSame($inquiry->original, $converted->original);
        $this->assertDatabaseCount('commercial_offer_revisions', 0);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('shift_assignments', 0);
        $this->assertDatabaseCount('ai_intake_deliveries', 0);
        $proof = OperationAudit::where('action', 'inquiry.direct_order')->sole();
        $this->assertSame($this->actor->id, $proof->actor_id);
        $this->assertSame($inquiry->revision, $proof->revision);
        $this->assertSame($order->id, $proof->data['order_id']);
        $this->assertSame($this->fingerprint($inquiry), $proof->data['inquiry_fingerprint']);
        $this->assertSame([], $proof->data['source_context']);
        $this->assertSame($this->customer->id, $proof->data['demand']['customer_id']);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_repeat_commands_return_same_order_and_keep_one_human_acceptance(): void
    {
        $inquiry = $this->inquiry();
        $service = app(InquiryWorkflowService::class);
        $first = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $second = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $third = $service->takeDirectOrder($first->fresh(), $first->revision, $this->actor);

        $this->assertSame($first->order_id, $second->order_id);
        $this->assertSame($first->order_id, $third->order_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, OperationAudit::where('action', 'inquiry.direct_order')->count());
    }

    public function test_direct_order_requires_order_management_permission_in_addition_to_inquiry_access(): void
    {
        $inquiry = $this->inquiry();
        $staff = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn () => true);
        Gate::define('operations.manage', fn () => false);

        try {
            app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $staff);
            $this->fail('Inquiry management alone must not accept an order.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_direct_order_rechecks_a_previously_active_actor_inside_the_transaction(): void
    {
        $inquiry = $this->inquiry();
        User::whereKey($this->actor->id)->update(['status' => false]);

        try {
            app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
            $this->fail('A stale active actor instance must not bypass fresh authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_stale_inquiry_revision_is_rejected_before_creation_and_on_retry(): void
    {
        $inquiry = $this->inquiry();
        $service = app(InquiryWorkflowService::class);
        $this->rejected(fn () => $service->takeDirectOrder($inquiry, $inquiry->revision + 1, $this->actor));
        $this->assertDatabaseCount('orders', 0);
        $converted = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $this->rejected(fn () => $service->takeDirectOrder($converted, $converted->revision + 1, $this->actor));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_missing_or_invalid_native_demand_never_creates_an_order(): void
    {
        foreach ([
            ['customer_id' => null], ['starts_at' => null], ['ends_at' => null],
            ['title' => ''], ['location_name' => ''], ['role_name' => ''],
            ['required_staff' => 0], ['required_staff' => 1000], ['timezone' => 'Invalid/Timezone'],
        ] as $changes) {
            $inquiry = $this->inquiry();
            $inquiry->forceFill($changes)->save();
            $this->rejected(fn () => app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
            $this->assertNull($inquiry->fresh()->order_id);
        }
        $inquiry = $this->inquiry();
        $inquiry->update(['ends_at' => $inquiry->starts_at]);
        $this->rejected(fn () => app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
        $this->customer->update(['is_active' => false]);
        $inquiry = $this->inquiry();
        $this->rejected(fn () => app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_linked_current_human_review_is_audited_and_retry_is_idempotent(): void
    {
        $inquiry = $this->inquiry();
        [$intake, $proposal] = $this->source($inquiry);
        $service = app(InquiryWorkflowService::class);
        $converted = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $retry = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);

        $this->assertSame($converted->order_id, $retry->order_id);
        $this->assertSame([
            'intake_id' => $intake->id, 'source_revision' => 1, 'proposal_id' => $proposal->id,
            'proposal_revision' => 2, 'position_index' => 0,
        ], OperationAudit::where('action', 'inquiry.direct_order')->sole()->data['source_context']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_new_inbound_source_revision_blocks_creation_and_old_direct_retry(): void
    {
        $service = app(InquiryWorkflowService::class);
        $inquiry = $this->inquiry();
        [$intake] = $this->source($inquiry);
        $intake->update(['source_revision' => 2]);
        $this->rejected(fn () => $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
        $this->assertDatabaseCount('orders', 0);
        $intake->update(['source_revision' => 1]);
        $converted = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $intake->update(['source_revision' => 2]);
        $this->rejected(fn () => $service->takeDirectOrder($converted, $converted->revision, $this->actor));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unreviewed_stale_or_changed_linked_position_is_rejected(): void
    {
        foreach (['confirmation', 'stale', 'fingerprint', 'inquiry_revision', 'customer', 'paused'] as $change) {
            $inquiry = $this->inquiry();
            [$intake, $proposal] = $this->source($inquiry);
            match ($change) {
                'confirmation' => $proposal->update(['payload' => []]),
                'stale' => $proposal->update(['status' => 'stale']),
                'fingerprint' => $inquiry->update(['location_name' => 'Changed without current source review']),
                'inquiry_revision' => $inquiry->update(['revision' => $inquiry->revision + 1]),
                'customer' => $intake->update(['customer_id' => null]),
                'paused' => $intake->update(['paused_at' => now()]),
            };
            $this->rejected(fn () => app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_and_ambiguous_source_positions_are_rejected(): void
    {
        $inquiry = $this->inquiry(['source_reference' => 'ai-intake:missing:0']);
        $this->rejected(fn () => app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
        [$intake, $proposal] = $this->source($inquiry);
        $intake->proposals()->create(array_replace($proposal->only([
            'source_revision', 'position_index', 'revision', 'status', 'inquiry_id', 'inquiry_revision', 'inquiry_fingerprint', 'payload',
        ]), ['position_index' => 1]));
        $this->rejected(fn () => app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $this->actor));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_direct_order_keeps_existing_offer_and_acceptance_history_without_copying_it_to_the_order(): void
    {
        $service = app(InquiryWorkflowService::class);
        $inquiry = $this->inquiry();
        $inquiry = $service->transition($inquiry, $inquiry->revision, 'verify', [], $this->actor);
        $inquiry = $service->transition($inquiry, $inquiry->revision, 'offer', ['amount' => '1250.50', 'terms' => 'Existing terms'], $this->actor);
        $inquiry = $service->transition($inquiry, $inquiry->revision, 'accept', ['note' => 'Existing customer acceptance', 'authorized' => true], $this->actor);
        $history = $inquiry->only(['offer', 'accepted_revision', 'acceptance_note', 'verified_revision']);
        $converted = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);

        $this->assertSame($history, $converted->only(array_keys($history)));
        $this->assertSame(1, CommercialOfferRevision::where('subject_type', 'OperationInquiry')->count());
        $this->assertSame(0, CommercialOfferRevision::where('subject_type', 'Order')->count());
        $this->assertSame(1, OperationAudit::where('action', 'inquiry.accept')->count());
        $this->assertSame(0, OperationAudit::where('action', 'inquiry.convert')->count());
    }

    public function test_existing_offer_conversion_still_requires_current_customer_acceptance(): void
    {
        $service = app(InquiryWorkflowService::class);
        $inquiry = $this->inquiry();
        $this->rejected(fn () => $service->transition($inquiry, $inquiry->revision, 'convert', [], $this->actor));
        $inquiry = $service->transition($inquiry, $inquiry->revision, 'verify', [], $this->actor);
        $inquiry = $service->transition($inquiry, $inquiry->revision, 'offer', ['amount' => '500', 'terms' => 'Original offer path'], $this->actor);
        $this->rejected(fn () => $service->transition($inquiry, $inquiry->revision, 'convert', [], $this->actor));
        $this->assertDatabaseCount('orders', 0);
        $accepted = $service->transition($inquiry, $inquiry->revision, 'accept', ['note' => 'Explicit customer acceptance', 'authorized' => true], $this->actor);
        $converted = $service->transition($accepted, $accepted->revision, 'convert', [], $this->actor);
        $retry = $service->takeDirectOrder($converted, $converted->revision, $this->actor);
        $this->assertSame($converted->order_id, $retry->order_id);
        $this->assertSame(1, CommercialOfferRevision::where('subject_type', 'Order')->count());
        $this->assertSame(0, OperationAudit::where('action', 'inquiry.direct_order')->count());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_order_and_source_link_roll_back_if_direct_audit_cannot_be_saved(): void
    {
        $inquiry = $this->inquiry();
        $audit = \Mockery::mock(OperationsAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit write failure'));
        $service = new InquiryWorkflowService($audit);

        try {
            $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
            $this->fail('Audit failure must prevent a partly committed direct order.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit write failure', $exception->getMessage());
            $this->assertNull($inquiry->fresh()->order_id);
            $this->assertSame('new', $inquiry->fresh()->status);
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_native_direct_order_retry_accepts_an_honest_optional_assist_bridge_without_rewriting_conversion_proof(): void
    {
        $service = app(InquiryWorkflowService::class);
        $inquiry = $this->inquiry();
        $converted = $service->takeDirectOrder($inquiry, $inquiry->revision, $this->actor);
        $proof = OperationAudit::where('action', 'inquiry.direct_order')->sole();
        [$intake, $proposal] = $this->source($converted);
        $proposal->update(['payload' => ['provenance' => ['kind' => 'native_inquiry'],
            'review_confirmation' => ['customer_communication' => 'off', 'planning_mode' => 'manual']]]);
        $this->rejected(fn () => $service->takeDirectOrder($converted, $converted->revision, $this->actor));
        app(OperationsAuditService::class)->record($intake, $this->actor, 'intake.native_assist_opened', [
            'inquiry_id' => $converted->id, 'inquiry_revision' => $converted->revision,
            'inquiry_fingerprint' => $this->fingerprint($converted), 'source_revision' => $intake->source_revision,
            'proposal_id' => $proposal->id, 'order_id' => $converted->order_id, 'conversion_audit_id' => $proof->id,
        ]);
        $retry = $service->takeDirectOrder($converted, $converted->revision, $this->actor);
        $this->assertSame($converted->order_id, $retry->order_id);
        $this->assertSame([], $proof->fresh()->data['source_context']);
        $this->assertDatabaseCount('orders', 1);
        $intake->update(['source_revision' => 2]);
        $this->rejected(fn () => $service->takeDirectOrder($converted, $converted->revision, $this->actor));
    }
}
