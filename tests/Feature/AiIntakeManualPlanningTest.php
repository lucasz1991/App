<?php

namespace Tests\Feature;

use App\Jobs\DeliverAiIntakeMail;
use App\Jobs\QueueAiIntakeOrderConfirmation;
use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\InquiryInbox;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\AiIntakeMailService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Contract coverage for the deliberately manual path from an AI-recognised
 * repeat order to native draft shifts.  This must never turn an inbox click
 * into a customer-facing confirmation, an order conversion, publication, or
 * employee assignment by itself.
 */
class AiIntakeManualPlanningTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $employee;

    private Customer $customer;

    private CustomerContact $contact;

    private AiIntakeService $service;

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
            '2026_10_11_120000_add_confirmed_routing_locations.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00Z'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($this->actor);
        $this->customer = Customer::create(['company_name' => 'Synthetic repeat-order customer', 'is_active' => true]);
        $this->contact = CustomerContact::create([
            'customer_id' => $this->customer->id,
            'name' => 'Synthetic ordering contact',
            'email' => 'repeat-order@example.test',
            'roles' => ['ordering', 'acceptance'],
            'is_active' => true,
            'revision' => 1,
            'updated_by' => $this->actor->id,
        ]);
        OperationsRuleProfile::create([
            'name' => 'Synthetic planning profile',
            'minimum_rest_minutes' => 0,
            'maximum_shift_minutes' => 720,
            'break_after_minutes' => 360,
            'minimum_break_minutes' => 30,
            'is_active' => true,
            'approved_at' => now(),
            'created_by' => $this->actor->id,
        ]);

        $this->configure(false);
        config(['view.compiled' => sys_get_temp_dir().'/railtime-ai-manual-planning-'.getmypid()]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->withoutVite();
        $this->service = app(AiIntakeService::class);
    }

    private function configure(bool $automaticCustomerCommunication): void
    {
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(
            AiDispositionSettings::DEFAULTS,
            [
                'enabled' => $automaticCustomerCommunication,
                'automation_mode' => 'automatic',
                'customer_receipt_mode' => $automaticCustomerCommunication ? 'automatic' : 'off',
                'customer_clarification_mode' => $automaticCustomerCommunication ? 'automatic' : 'off',
                'customer_confirmation_mode' => $automaticCustomerCommunication ? 'automatic' : 'off',
                'staffing_request_mode' => $automaticCustomerCommunication ? 'automatic' : 'off',
                'supervisor_id' => $this->actor->id,
                'revision' => $automaticCustomerCommunication ? 22 : 5,
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function position(array $changes = []): array
    {
        return array_replace([
            'title' => 'Synthetic Nachbestellung',
            'starts_at' => '2027-05-13T08:00',
            'ends_at' => '2027-05-13T16:00',
            'timezone' => 'Europe/Berlin',
            'location_name' => 'Hamburg Hohe Schaar',
            'role_name' => 'Tf',
            'required_staff' => 1,
            'qualification_requirements' => [],
            'segments' => [],
            'evidence' => [],
            'assumptions' => ['required_staff' => ['basis' => 'confirmed', 'value' => 1]],
        ], $changes);
    }

    private function intake(array $changes = [], array $positionChanges = []): AiIntake
    {
        $intake = AiIntake::create(array_replace([
            'source_type' => 'email',
            'status' => 'review',
            'title' => 'Synthetic repeat order',
            'revision' => 1,
            'source_revision' => 1,
            'processed_source_revision' => 1,
            'customer_id' => $this->customer->id,
            'customer_contact_id' => $this->contact->id,
            'supervising_user_id' => $this->actor->id,
            'match_method' => 'contact_email',
            'created_by' => $this->actor->id,
            'analysis' => [
                'intent' => 'amendment',
                'classification' => 'amendment',
                'extraction_complete' => true,
                'overflow' => false,
                'positions' => [$this->position($positionChanges)],
            ],
        ], $changes));
        $message = $intake->messages()->create([
            'direction' => 'inbound',
            'sender_email' => $this->contact->email,
            'reply_to_email' => $this->contact->email,
            'external_message_id' => 'repeat-order-'.$intake->id.'@example.test',
            'body' => 'Synthetic repeat order source only.',
        ]);
        $intake->update(['latest_inbound_message_id' => $message->id]);

        return $intake->fresh();
    }

    /**
     * Simulate an older recognition payload which did not yet emit a segment
     * key at all. This deliberately differs from an explicitly empty list:
     * confirmation may normalise the former but preparation still requires a
     * human to supply complete segments.
     */
    private function withoutRecognisedSegments(AiIntake $intake): AiIntake
    {
        $analysis = $intake->analysis;
        unset($analysis['positions'][0]['segments']);
        $intake->update(['analysis' => $analysis]);

        return $intake->fresh();
    }

    private function confirm(AiIntake $intake): AiIntakeProposal
    {
        $fresh = $intake->fresh();
        $this->service->confirmReview(
            $fresh,
            $this->actor,
            $fresh->revision,
            $fresh->source_revision,
            (int) AiDispositionSettings::all(true)['revision'],
            $fresh->proposals()->where('source_revision', $fresh->source_revision)->pluck('revision', 'id')->all(),
        );

        return $fresh->fresh()->proposals()->sole();
    }

    private function offer(AiIntakeProposal $proposal): OperationInquiry
    {
        $inquiry = $proposal->fresh()->inquiry()->firstOrFail();

        return app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'offer', [
            'amount' => '1250.50',
            'terms' => 'Synthetic human-reviewed offer terms.',
        ], $this->actor);
    }

    private function accept(OperationInquiry $inquiry): OperationInquiry
    {
        $current = $inquiry->fresh();

        return app(InquiryWorkflowService::class)->transition($current, $current->revision, 'accept', [
            'note' => 'Persönlich dokumentierte Kundenzusage.',
            'authorized' => true,
        ], $this->actor);
    }

    private function convert(AiIntakeProposal $proposal): AiIntakeProposal
    {
        $offered = $this->offer($proposal);
        $accepted = $this->accept($offered);
        app(InquiryWorkflowService::class)->transition($accepted->fresh(), $accepted->revision, 'convert', [], $this->actor);

        return $proposal->fresh();
    }

    /** @return array{0: AiIntake, 1: AiIntakeProposal} */
    private function convertedProposal(array $intakeChanges = [], array $positionChanges = []): array
    {
        $intake = $this->intake($intakeChanges, $positionChanges);
        $proposal = $this->convert($this->confirm($intake));

        return [$intake->fresh(), $proposal];
    }

    /** @return array<string, mixed> */
    private function segment(array $changes = []): array
    {
        return array_replace([
            'starts_at' => '2027-05-13T08:00',
            'ends_at' => '2027-05-13T16:00',
            'timezone' => 'Europe/Berlin',
            'required_staff' => 1,
            'planned_break_minutes' => 30,
        ], $changes);
    }

    /** @return array<string, mixed> */
    private function correctedProposalPayload(): array
    {
        return [
            'demand' => [
                'title' => 'Personally corrected repeat order',
                'starts_at' => '2027-05-13T08:00',
                'ends_at' => '2027-05-13T16:00',
                'timezone' => 'Europe/Berlin',
                'location_name' => 'Hamburg Hohe Schaar',
                'role_name' => 'Tf',
                'required_staff' => 1,
                'qualification_ids' => [],
            ],
            'segments' => [$this->segment()],
            'evidence' => [],
            'requirements_reviewed' => true,
        ];
    }

    private function assertRejected(callable $action, array $expectedFields = []): void
    {
        try {
            $action();
            $this->fail('The manual planning action should have been rejected.');
        } catch (ValidationException $exception) {
            foreach ($expectedFields as $field) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
            $this->assertNotEmpty($exception->errors());
        } catch (HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), [409, 422]);
        }
    }

    private function assertNoCustomerCommunication(): void
    {
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNotPushed(QueueAiIntakeOrderConfirmation::class);
        Queue::assertNotPushed(DeliverAiIntakeMail::class);
    }

    public function test_confirmation_normalizes_missing_segments_and_keeps_an_ai_repeat_order_internal_with_customer_automation_enabled(): void
    {
        $this->configure(true);
        $intake = $this->withoutRecognisedSegments($this->intake());
        $proposal = $this->confirm($intake);

        $this->assertSame([], $proposal->payload['segments']);
        $this->assertSame('off', $proposal->payload['review_confirmation']['customer_communication']);
        $this->assertSame('manual', $proposal->payload['review_confirmation']['planning_mode']);
        $this->assertSame('verified', $proposal->inquiry()->firstOrFail()->status);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Shift::count());
        $this->assertNoCustomerCommunication();
    }

    public function test_confirmation_state_explains_an_incomplete_segment_with_its_concrete_german_field_label(): void
    {
        $intake = $this->intake([], [
            'segments' => [$this->segment(['planned_break_minutes' => ''])],
        ]);

        $state = $this->service->confirmationState($intake);

        $this->assertFalse($state['enabled']);
        $this->assertStringContainsString('Pausenzeit des 1. Schichtabschnitts', $state['reason']);
    }

    public function test_a_preexisting_customer_receipt_draft_cannot_be_approved_or_sent_after_manual_takeover(): void
    {
        $this->configure(true);
        $settings = AiDispositionSettings::all(true);
        $settings['customer_receipt_mode'] = 'draft';
        $settings['revision'] = 23;
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, $settings);
        $intake = $this->intake();
        $mail = app(AiIntakeMailService::class);
        $delivery = $mail->enqueueReceipt($intake);
        $this->assertNotNull($delivery);
        $this->assertSame('draft', $delivery->status);

        // Confirming manual planning stamps the marker after the old draft
        // exists. It must protect both the human approval and a stale pending
        // worker claim from ever reaching the SMTP transport.
        $proposal = $this->confirm($intake);
        $freshIntake = $intake->fresh();
        $this->assertSame('off', $proposal->payload['review_confirmation']['customer_communication']);
        $this->assertRejected(fn () => $mail->approveDraft($delivery, $this->actor, 23, $freshIntake->revision));
        $this->assertSame('draft', $delivery->fresh()->status);
        Queue::assertNotPushed(DeliverAiIntakeMail::class);

        $delivery->forceFill(['status' => 'pending'])->save();
        $this->assertSame('canceled', $mail->deliver($delivery->id));
        $this->assertSame('canceled', $delivery->fresh()->status);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_manual_marker_survives_a_personal_proposal_correction_and_blocks_the_legacy_automatic_apply_worker(): void
    {
        $this->configure(true);
        $intake = $this->intake();
        $proposal = $this->confirm($intake);
        $corrected = $this->service->saveProposal($proposal, $this->actor, $this->correctedProposalPayload(), $proposal->revision);

        $this->assertSame('off', $corrected->payload['review_confirmation']['customer_communication']);
        $this->assertSame('manual', $corrected->payload['review_confirmation']['planning_mode']);
        $converted = $this->convert($corrected);
        // Fixture an old approved state deliberately: the old worker must skip every
        // record marked for the new manual path, rather than turning it into shifts.
        $converted->forceFill([
            'status' => 'approved',
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ])->save();

        $this->assertSame(0, $this->service->applyApprovedProposals($intake->id));
        $this->assertSame('approved', $converted->fresh()->status);
        $this->assertSame(0, OrderDemand::count());
        $this->assertSame(0, Shift::count());
        $this->assertNoCustomerCommunication();
    }

    public function test_manual_continuation_stamps_a_legacy_unmarked_proposal_before_conversion_and_is_a_no_op_for_current_records(): void
    {
        $this->configure(true);

        // A new confirmation already has the policy marker. Selecting the
        // native inquiry from the AI inbox must not mutate that current state.
        $currentIntake = $this->intake();
        $current = $this->confirm($currentIntake);
        $currentInquiry = $current->inquiry()->firstOrFail();
        $this->assertSame($currentInquiry->id, $this->service->continueProposalManually($current, $this->actor, $current->revision));
        $this->assertSame($current->revision, $current->fresh()->revision);

        // Mimic a record confirmed before the policy existed. The marker must
        // be written before a human offers, accepts and converts it, otherwise
        // enabled automatic customer modes could enqueue a confirmation.
        $legacyIntake = $this->intake();
        $legacy = $this->confirm($legacyIntake);
        $legacyPayload = $legacy->payload;
        unset($legacyPayload['review_confirmation']);
        $legacy->update(['payload' => $legacyPayload]);
        $legacy->refresh();

        $legacyInquiryId = $this->service->continueProposalManually($legacy, $this->actor, $legacy->revision);
        $legacy->refresh();
        $this->assertSame($legacy->inquiry_id, $legacyInquiryId);
        $this->assertSame('off', $legacy->payload['review_confirmation']['customer_communication']);
        $this->assertSame('manual', $legacy->payload['review_confirmation']['planning_mode']);

        $this->convert($legacy);
        $this->assertSame('converted', OperationInquiry::findOrFail($legacyInquiryId)->status);
        $this->assertNoCustomerCommunication();
    }

    public function test_livewire_manual_editor_requires_a_break_creates_drafts_and_publishes_only_after_native_site_confirmation(): void
    {
        $this->configure(true);
        $intake = $this->withoutRecognisedSegments($this->intake());
        $proposal = $this->convert($this->confirm($intake));
        $inquiry = $proposal->inquiry()->firstOrFail()->fresh();
        $order = Order::findOrFail($inquiry->order_id);

        $component = Livewire::actingAs($this->actor)->test(AiIntakeInbox::class, ['initialIntakeId' => $intake->id])
            ->assertSee('Schichten prüfen und vorbereiten')
            ->call('openPlanning', $proposal->id, $proposal->revision)
            ->assertSet('planningProposalId', $proposal->id)
            ->assertSet('planningSegments.0.starts_at', '2027-05-13T08:00')
            ->set('planningSegments.0.planned_break_minutes', '')
            ->call('preparePlanning')
            ->assertHasErrors(['planningSegments.0.planned_break_minutes']);

        $component
            ->set('planningSegments.0.planned_break_minutes', 30)
            ->call('preparePlanning')
            ->assertHasNoErrors()
            ->assertDispatched('operations-plan-changed');

        $proposal->refresh();
        $this->assertSame('applied', $proposal->status);
        $this->assertSame($this->actor->id, $proposal->approved_by);
        $this->assertCount(1, $proposal->demand_ids);
        $this->assertCount(1, $proposal->applied_shift_ids);
        $shift = Shift::findOrFail($proposal->applied_shift_ids[0]);
        $this->assertSame('draft', $shift->status->value);
        $this->assertSame(0, $shift->assignments()->count());
        $this->assertSame($order->id, $shift->order_id);

        $component
            ->call('publishPlanning', $proposal->id, $proposal->revision)
            ->assertHasErrors('workflow');
        $this->assertSame(0, $shift->fresh()->published_revision);

        $location = CustomerLocation::create([
            'customer_id' => $this->customer->id,
            'name' => 'Synthetic confirmed site',
            'country' => 'DE',
            'is_active' => true,
            'revision' => 1,
            'latitude' => 53.5511,
            'longitude' => 9.9937,
            'coordinates_confirmed_at' => now(),
            'updated_by' => $this->actor->id,
        ]);
        $shift = app(ShiftSchedulingService::class)->save($shift->fresh(), [
            'customer_location_id' => $location->id,
            'expected_revision' => $shift->revision,
        ], $this->actor);

        $component
            ->call('select', $intake->id)
            ->call('publishPlanning', $proposal->id, $proposal->fresh()->revision)
            ->assertHasNoErrors()
            ->assertDispatched('operations-plan-changed');

        $shift->refresh();
        $this->assertSame('open', $shift->status->value);
        $this->assertSame($shift->revision, $shift->published_revision);
        $this->assertSame($location->id, $shift->customer_location_id);
        $assignment = app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->actor, 'confirmed', null, $shift->revision);
        $this->assertSame('confirmed', $assignment->status->value);
        $this->assertSame($this->employee->id, $assignment->user_id);
        $this->assertNoCustomerCommunication();
    }

    public function test_native_inquiry_convert_returns_a_manual_ai_record_to_its_intake_planning_panel(): void
    {
        $this->configure(true);
        $intake = $this->intake();
        $proposal = $this->confirm($intake);
        $accepted = $this->accept($this->offer($proposal));

        Livewire::actingAs($this->actor)
            ->test(InquiryInbox::class, ['initialInquiryId' => $accepted->id, 'consolidated' => true])
            ->call('transition', 'convert')
            ->assertRedirect(OperationsPages::intakePanelUrl($intake->id));

        $this->assertSame('converted', $accepted->fresh()->status);
        $this->assertNotNull($accepted->fresh()->order_id);
        $this->assertNoCustomerCommunication();
    }

    public function test_manual_preparation_requires_offer_acceptance_and_native_conversion_then_is_idempotent_with_ai_disabled(): void
    {
        $intake = $this->intake();
        $proposal = $this->confirm($intake);
        $segments = [$this->segment()];

        $this->assertRejected(fn () => $this->service->prepareProposalShifts($proposal, $this->actor, $proposal->revision, $segments));
        $offered = $this->offer($proposal);
        $this->assertRejected(fn () => $this->service->prepareProposalShifts($proposal->fresh(), $this->actor, $proposal->fresh()->revision, $segments));
        $this->assertRejected(fn () => app(InquiryWorkflowService::class)->transition($offered, $offered->revision, 'accept', [
            'note' => 'Nicht autorisierte Zusage.',
            'authorized' => false,
        ], $this->actor), ['authorized']);
        $accepted = $this->accept($offered);
        $this->assertRejected(fn () => $this->service->prepareProposalShifts($proposal->fresh(), $this->actor, $proposal->fresh()->revision, $segments));
        app(InquiryWorkflowService::class)->transition($accepted->fresh(), $accepted->revision, 'convert', [], $this->actor);

        $prepared = $proposal->fresh();
        $first = $this->service->prepareProposalShifts($prepared, $this->actor, $prepared->revision, $segments);
        $this->assertCount(1, $first);
        $prepared->refresh();
        $second = $this->service->prepareProposalShifts($prepared, $this->actor, $prepared->revision, $segments);
        $this->assertSame($first, $second);
        $this->assertSame(1, Shift::count());
        $this->assertSame(1, OrderDemand::count());
        $this->assertSame('applied', $prepared->fresh()->status);
        $this->assertNoCustomerCommunication();
    }

    public function test_manual_preparation_validates_explicit_segments_and_rolls_back_all_drafts_on_a_later_invalid_segment(): void
    {
        [, $proposal] = $this->convertedProposal();
        $beforeDemands = OrderDemand::count();
        $beforeShifts = Shift::count();

        try {
            $this->service->prepareProposalShifts($proposal, $this->actor, $proposal->revision, [$this->segment(['planned_break_minutes' => ''])]);
            $this->fail('An incomplete manual segment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('segments.0.planned_break_minutes', $exception->errors());
            $this->assertStringContainsString('Pausenzeit des 1. Schichtabschnitts', $exception->errors()['segments.0.planned_break_minutes'][0]);
        }
        $this->assertSame($beforeDemands, OrderDemand::count());
        $this->assertSame($beforeShifts, Shift::count());

        $this->assertRejected(
            fn () => $this->service->prepareProposalShifts($proposal->fresh(), $this->actor, $proposal->fresh()->revision, [
                $this->segment(),
                $this->segment(['planned_break_minutes' => '']),
            ]),
            ['segments.1.planned_break_minutes'],
        );
        $this->assertSame($beforeDemands, OrderDemand::count());
        $this->assertSame($beforeShifts, Shift::count());
        $this->assertSame('proposed', $proposal->fresh()->status);
        $this->assertNoCustomerCommunication();
    }

    public function test_publication_requires_fresh_revisions_and_rolls_back_every_draft_when_a_later_shift_has_no_confirmed_site(): void
    {
        [, $proposal] = $this->convertedProposal();
        $shiftIds = $this->service->prepareProposalShifts($proposal, $this->actor, $proposal->revision, [
            $this->segment(['ends_at' => '2027-05-13T12:00', 'planned_break_minutes' => 0]),
            $this->segment(['starts_at' => '2027-05-13T12:00', 'planned_break_minutes' => 0]),
        ]);
        $proposal->refresh();
        $first = Shift::findOrFail($shiftIds[0]);
        $second = Shift::findOrFail($shiftIds[1]);
        $location = CustomerLocation::create([
            'customer_id' => $this->customer->id,
            'name' => 'Atomic publication site',
            'country' => 'DE',
            'is_active' => true,
            'revision' => 1,
            'latitude' => 53.5511,
            'longitude' => 9.9937,
            'coordinates_confirmed_at' => now(),
            'updated_by' => $this->actor->id,
        ]);
        $first = app(ShiftSchedulingService::class)->save($first, [
            'customer_location_id' => $location->id,
            'expected_revision' => $first->revision,
        ], $this->actor);
        $initialMap = [$first->id => $first->revision, $second->id => $second->revision];

        // The first publication runs before the second native site check, so
        // this asserts the outer proposal transaction truly keeps both drafts.
        $this->assertRejected(fn () => $this->service->publishProposalShifts($proposal, $this->actor, $proposal->revision, $initialMap));
        foreach ($shiftIds as $shiftId) {
            $shift = Shift::findOrFail($shiftId);
            $this->assertSame('draft', $shift->status->value);
            $this->assertSame(0, $shift->published_revision);
        }

        $second = app(ShiftSchedulingService::class)->save($second->fresh(), [
            'customer_location_id' => $location->id,
            'expected_revision' => $second->fresh()->revision,
        ], $this->actor);
        // Binding the second site changes its native revision. A stale map must
        // fail before publishing either shift.
        $this->assertRejected(fn () => $this->service->publishProposalShifts($proposal->fresh(), $this->actor, $proposal->fresh()->revision, $initialMap));
        foreach ($shiftIds as $shiftId) {
            $shift = Shift::findOrFail($shiftId);
            $this->assertSame('draft', $shift->status->value);
            $this->assertSame(0, $shift->published_revision);
        }

        $freshMap = Shift::whereIn('id', $shiftIds)->pluck('revision', 'id')->map(fn ($revision) => (int) $revision)->all();
        $this->service->publishProposalShifts($proposal->fresh(), $this->actor, $proposal->fresh()->revision, $freshMap);
        foreach ($shiftIds as $shiftId) {
            $shift = Shift::findOrFail($shiftId);
            $this->assertSame('open', $shift->status->value);
            $this->assertSame($shift->revision, $shift->published_revision);
        }
        $this->assertNoCustomerCommunication();
    }

    public function test_manual_preparation_rejects_stale_paused_source_cancelled_and_changed_native_context_without_writes(): void
    {
        $cases = [
            'stale proposal revision' => function (AiIntake $intake, AiIntakeProposal $proposal): array {
                return [$proposal->revision - 1, $proposal];
            },
            'paused intake' => function (AiIntake $intake, AiIntakeProposal $proposal): array {
                $intake->update(['status' => 'paused', 'paused_at' => now(), 'revision' => $intake->revision + 1]);

                return [$proposal->fresh()->revision, $proposal->fresh()];
            },
            'superseded source' => function (AiIntake $intake, AiIntakeProposal $proposal): array {
                $intake->update(['source_revision' => $intake->source_revision + 1, 'revision' => $intake->revision + 1]);

                return [$proposal->fresh()->revision, $proposal->fresh()];
            },
            'cancelled native order' => function (AiIntake $intake, AiIntakeProposal $proposal): array {
                $order = Order::findOrFail($proposal->inquiry()->firstOrFail()->order_id);
                $order->update(['status' => 'cancelled']);

                return [$proposal->fresh()->revision, $proposal->fresh()];
            },
            'changed inquiry demand fingerprint' => function (AiIntake $intake, AiIntakeProposal $proposal): array {
                $inquiry = $proposal->inquiry()->firstOrFail();
                $inquiry->forceFill(['location_name' => 'Changed native location'])->save();

                return [$proposal->fresh()->revision, $proposal->fresh()];
            },
        ];

        foreach ($cases as $label => $mutate) {
            [$intake, $proposal] = $this->convertedProposal();
            $beforeDemands = OrderDemand::count();
            $beforeShifts = Shift::count();
            [$revision, $target] = $mutate($intake, $proposal);

            $this->assertRejected(
                fn () => $this->service->prepareProposalShifts($target, $this->actor, $revision, [$this->segment()]),
            );
            $this->assertSame($beforeDemands, OrderDemand::count(), $label.' unexpectedly created a demand.');
            $this->assertSame($beforeShifts, Shift::count(), $label.' unexpectedly created a shift.');
        }

        $this->assertNoCustomerCommunication();
    }
}
