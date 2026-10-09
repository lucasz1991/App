<?php

namespace Tests\Feature;

use App\Enums\ShiftAssignmentStatus;
use App\Livewire\Operations\MyWork;
use App\Livewire\Operations\WorkforcePlanning;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeWorkModel;
use App\Models\OperationAudit;
use App\Models\OperationsAttentionItem;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftOffer;
use App\Models\StaffingAutomationRun;
use App\Models\StaffingCase;
use App\Models\User;
use App\Services\Operations\OrderDemandService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffingAutomationService;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Services\Operations\WorkforcePlanningService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\StaffingAutomationActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class StaffingAutomationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $anna;

    private User $ben;

    private Order $order;

    private StaffingAutomationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php',
            '2026_10_06_220000_create_staff_regional_preferences_table.php', '2026_10_09_090000_create_staffing_automation_runs.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T05:00:00Z'));
        $this->manager = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Private', 'email' => 'anna-private@example.test', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Private', 'email' => 'ben-private@example.test', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Geprüftes Testprofil', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720,
            'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        foreach ([$this->anna, $this->ben] as $employee) {
            $this->model($employee);
        }
        $customer = Customer::create(['company_name' => 'Private Customer', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Testleistung', 'status' => 'confirmed', 'priority' => 'normal',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-01-01T00:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
        $this->service = app(StaffingAutomationService::class);
        $this->configure();
        Http::preventStrayRequests();
        Notification::fake();
    }

    private function model(User $employee): void
    {
        // Explicit synthetic QA contract, never a default applied to business personnel.
        EmployeeWorkModel::create(['user_id' => $employee->id, 'name' => 'QA-Modell', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 3360, 'maximum_weekly_minutes' => 3360,
            'daily_minutes' => array_fill_keys(range(1, 7), 480), 'status' => 'active', 'created_by' => $this->manager->id,
            'approved_by' => $this->manager->id, 'approved_at' => now()->utc()]);
    }

    private function configure(array $extra = []): void
    {
        $settings = Setting::getValueUncached('operations', 'ai_disposition') ?? AiDispositionSettings::DEFAULTS;
        Setting::setValue('operations', 'ai_disposition', array_replace($settings, [
            'enabled' => false, 'supervisor_id' => $this->manager->id, 'staffing_request_mode' => 'automatic',
            'staffing_request_wave_size' => 1, 'staffing_request_max_waves' => 3, 'staffing_request_timeout_hours' => 1,
        ], $extra));
    }

    private function shift(array $extra = [], bool $publish = true): Shift
    {
        $shift = Shift::forceCreate(array_replace(['order_id' => $this->order->id, 'title' => 'Offener Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30,
            'status' => 'draft', 'location_name' => 'München', 'created_by' => $this->manager->id], $extra))->fresh();
        if ($publish) {
            app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->manager);
        }

        return $shift->fresh();
    }

    private function invalid(callable $action, string $expected): void
    {
        try {
            $action();
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($expected, collect($error->errors())->flatten()->implode(' '));
        }
    }

    public function test_opt_in_is_independent_of_mailbox_and_deduplicates_native_portal_invitations_without_assigning(): void
    {
        $shift = $this->shift();
        $before = $shift->getRawOriginal();
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertTrue($run->offer->expires_at->isFuture());
        $this->assertSame(now()->utc()->addHour()->timestamp, $run->offer->expires_at->timestamp);
        $duplicate = $this->service->process($shift->id, $shift->revision);
        $this->assertSame($run->id, $duplicate->id);
        $this->assertSame('soliciting', $run->state);
        $this->assertSame([$this->anna->id], $run->offer->invited_user_ids);
        $this->assertSame(1, ShiftOffer::count());
        $this->assertSame(1, StaffingAutomationRun::count());
        $this->assertSame(1, OperationsAttentionItem::where('kind', 'staffing_offer')->count());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $audit = OperationAudit::where('action', 'staffing.automation.wave_created')->firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertSame('automation', $audit->actor_kind);
        $this->assertSame($this->manager->id, $audit->supervising_user_id);
        $this->assertSame($run->run_uuid, $audit->data['staffing_run_uuid']);
        $this->assertSame([$this->anna->id], $audit->data['validated_user_ids']);
        $this->assertStringNotContainsString('Private', json_encode($audit->data));
        $events = $this->service->recentEvents($this->manager);
        $this->assertSame(now()->utc()->timestamp, CarbonImmutable::parse($events[0]['created_at'])->timestamp);
        $this->assertSame('soliciting', $events[0]['state']);
        $this->assertSame(1, $events[0]['wave_count']);
        Notification::assertNothingSent();
    }

    public function test_off_and_assisted_modes_never_send_or_assign(): void
    {
        $shift = $this->shift();
        $this->configure(['staffing_request_mode' => 'off']);
        $this->assertNull($this->service->process($shift->id, $shift->revision));
        $this->assertCount(0, $this->service->scheduledTargets());
        $this->assertSame(0, StaffingAutomationRun::count());
        $this->configure(['staffing_request_mode' => 'assisted']);
        $this->assertNull($this->service->process($shift->id, $shift->revision));
        $run = $this->service->prepare($shift->id, $shift->revision, $this->manager);
        $this->assertSame('assisted_proposal', $run->reason_code);
        $this->assertSame([$this->anna->id], $run->basis['candidate_ids']);
        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, OperationsAttentionItem::where('kind', 'staffing_offer')->count());
    }

    public function test_declined_and_timed_out_rounds_do_not_contact_the_same_person_again_and_stop_at_limit(): void
    {
        $shift = $this->shift();
        $this->configure(['staffing_request_max_waves' => 2]);
        $first = $this->service->process($shift->id, $shift->revision);
        app(WorkforcePlanningService::class)->respondOffer($first->offer_id, 1, 'declined', $this->anna);
        $second = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('expired', ShiftOffer::find($first->offer_id)->status);
        $this->assertSame([$this->ben->id], $second->offer->invited_user_ids);
        $this->assertSame(2, $second->wave_count);
        $this->travel(61)->minutes();
        $review = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('round_limit', $review->reason_code);
        $this->assertSame('expired', ShiftOffer::find($second->offer_id)->status);
        $this->assertSame(1, StaffingCase::count());
        $auditCount = OperationAudit::count();
        $this->service->process($shift->id, $shift->revision);
        $this->assertSame($auditCount, OperationAudit::count());
        $this->invalid(fn () => $this->service->retry($review->id, $review->revision, $this->manager), 'maximale');
        $this->assertSame(2, ShiftOffer::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_interest_requires_native_human_approval_then_employee_confirmation(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $response = app(WorkforcePlanningService::class)->respondOffer($run->offer_id, 1, 'interested', $this->anna);
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('interest_received', $run->reason_code);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertCount(1, $this->service->scheduledTargets());
        $this->invalid(fn () => $this->service->retry($run->id, $run->revision, $this->manager), 'Interessierte');
        app(WorkforcePlanningService::class)->reviewOffer($response->id, $response->revision, true, $this->manager);
        $assignment = ShiftAssignment::firstOrFail();
        $this->assertSame(ShiftAssignmentStatus::Requested, $assignment->status);
        $waiting = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('awaiting_confirmation', $waiting->state);
        app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $this->anna);
        $this->assertSame('completed', $this->service->process($shift->id, $shift->revision)->state);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignment->fresh()->status);
        $this->assertSame('open', StaffingCase::find($run->staffing_case_id)->status);
    }

    public function test_fresh_rules_absence_no_go_and_missing_approved_work_model_escalate_without_provider(): void
    {
        $shift = $this->shift();
        app(StaffRegionalPreferenceService::class)->save($this->anna, $this->manager, ['enabled' => true, 'base_location' => 'München',
            'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => [['location' => 'München', 'radius_km' => 10]]], 0);
        EmployeeWorkModel::where('user_id', $this->ben->id)->update(['approved_at' => null]);
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('uncertain_candidates', $run->reason_code);
        $this->assertContains('region_no_go', $run->basis['issue_codes']);
        $this->assertContains('approved_work_model_missing', $run->basis['issue_codes']);
        $this->assertSame(0, ShiftOffer::count());
        EmployeeWorkModel::where('user_id', $this->ben->id)->update(['approved_at' => now()->utc()]);
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'sick', 'status' => 'reported', 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'timezone' => $shift->timezone]);
        $retry = $this->service->retry($run->id, $run->revision, $this->manager);
        $this->assertSame('review', $retry->state);
        $this->assertContains('absence', $retry->basis['issue_codes']);
        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_existing_manual_offer_and_reservations_are_preserved(): void
    {
        $shift = $this->shift();
        $manual = app(WorkforcePlanningService::class)->offer($shift->id, $shift->revision, [$this->anna->id], now()->utc()->addHours(2)->format('Y-m-d\TH:i'), 'UTC', $this->manager);
        $before = $manual->fresh()->getRawOriginal();
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('existing_offer', $run->reason_code);
        $this->assertSame($before, $manual->fresh()->getRawOriginal());
        $this->assertSame(1, ShiftOffer::count());
        $assignment = app(ShiftAssignmentService::class)->assign($shift, $this->ben, $this->manager, 'requested');
        $assignmentBefore = $assignment->fresh()->getRawOriginal();
        $this->assertSame('awaiting_confirmation', $this->service->process($shift->id, $shift->revision)->state);
        $this->assertSame($before, $manual->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
    }

    public function test_stale_publication_invalidates_owned_invitation_and_never_publishes_drafts(): void
    {
        $draft = $this->shift([], false);
        $review = $this->service->prepare($draft->id, $draft->revision, $this->manager);
        $this->assertSame('publication_changed', $review->reason_code);
        $this->assertSame(0, $draft->fresh()->published_revision);
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $shift->increment('revision');
        $stale = $this->service->process($shift->id, $run->plan_revision);
        $this->assertSame('publication_changed', $stale->reason_code);
        $this->assertSame('expired', $run->offer->fresh()->status);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_revoked_supervision_disabled_configuration_and_stale_review_token_fail_closed(): void
    {
        $shift = $this->shift();
        $this->configure(['staffing_request_mode' => 'assisted']);
        $run = $this->service->prepare($shift->id, $shift->revision, $this->manager);
        $this->invalid(fn () => $this->service->retry($run->id, $run->revision - 1, $this->manager), 'inzwischen');
        $this->configure(['staffing_request_mode' => 'automatic']);
        $this->manager->update(['status' => false]);
        $blocked = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('supervisor_not_authorized', $blocked->reason_code);
        $this->assertCount(0, $this->service->scheduledTargets());
        $this->assertSame(0, ShiftOffer::count());
        $this->manager->update(['status' => true]);
        $this->configure(['staffing_request_mode' => 'off']);
        $this->assertNull($this->service->process($shift->id, $shift->revision));
        $this->assertSame(0, ShiftOffer::count());
    }

    public function test_employee_worklist_opens_only_current_owned_unanswered_portal_invites(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $inbox = app(UnifiedOperationsInboxService::class);
        $notice = OperationsAttentionItem::where('kind', 'staffing_offer')->firstOrFail();
        $this->assertTrue($inbox->items($this->anna, true)->contains('id', 'notice-'.$notice->id));
        $this->assertFalse($inbox->items($this->ben, true)->contains('id', 'notice-'.$notice->id));
        $this->assertSame(route('operations.mine', ['area' => 'work', 'tab' => 'planning', 'planning_tab' => 'offers']), $inbox->destination('notice-'.$notice->id, $this->anna, true));
        $this->actingAs($this->anna);
        Livewire::withQueryParams(['tab' => 'planning', 'planning_tab' => 'offers'])->test(MyWork::class)->assertSet('tab', 'planning')->assertSeeLivewire(WorkforcePlanning::class);
        Livewire::test(WorkforcePlanning::class, ['personal' => true, 'tab' => 'offers'])->assertSee('Offener Dienst');
        app(WorkforcePlanningService::class)->respondOffer($run->offer_id, 1, 'declined', $this->anna);
        $this->assertFalse($inbox->items($this->anna, true)->contains('id', 'notice-'.$notice->id));
    }

    public function test_machine_actor_cannot_assign_publish_or_offer_an_unvalidated_person(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $actor = new StaffingAutomationActor($run->id, $run->settings_revision, $this->manager->id);
        foreach (['assignment.save', 'shift.publish', 'exception.confirm'] as $operation) {
            try {
                $actor->authorize($operation);
                $this->fail('Machine operation unexpectedly authorized.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        try {
            $actor->assertOfferScope([$this->ben->id]);
            $this->fail('Unvalidated person unexpectedly authorized.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_bounded_offers_can_invite_multiple_people_without_reserving_any_capacity(): void
    {
        $shift = $this->shift();
        $this->configure(['staffing_request_wave_size' => 3]);
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertSame([$this->anna->id, $this->ben->id], $run->offer->invited_user_ids);
        $this->assertSame(0, ShiftAssignment::count());
        $service = app(WorkforcePlanningService::class);
        $a = $service->respondOffer($run->offer_id, 1, 'interested', $this->anna);
        $b = $service->respondOffer($run->offer_id, 1, 'interested', $this->ben);
        $this->assertSame(0, ShiftAssignment::count());
        $service->reviewOffer($a->id, 1, true, $this->manager);
        $this->invalid(fn () => $service->reviewOffer($b->id, 1, true, $this->manager), 'bearbeitet');
        $this->assertSame(1, ShiftAssignment::count());
        $this->assertSame(ShiftAssignmentStatus::Requested, ShiftAssignment::first()->status);
    }

    public function test_future_employee_conflict_blocks_response_and_does_not_mutate_an_invitation(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $other = $this->shift(['title' => 'Konkurrierender Dienst']);
        app(ShiftAssignmentService::class)->assign($other, $this->anna, $this->manager, 'requested');
        $this->invalid(fn () => app(WorkforcePlanningService::class)->respondOffer($run->offer_id, 1, 'interested', $this->anna), 'Schichtüberschneidung');
        $this->assertSame(0, $run->offer->responses()->count());
        $this->assertSame('open', $run->offer->fresh()->status);
        $this->assertSame(0, $shift->assignments()->count());
    }

    public function test_timeout_allows_a_new_wave_and_utc_deadline_is_not_shifted_by_default_timezone(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $firstId = $run->offer_id;
        $this->travel(59)->minutes();
        $this->assertSame($firstId, $this->service->process($shift->id, $shift->revision)->offer_id);
        $this->travel(2)->minutes();
        $next = $this->service->process($shift->id, $shift->revision);
        $this->assertNotSame($firstId, $next->offer_id);
        $this->assertSame([$this->ben->id], $next->offer->invited_user_ids);
        $this->assertSame('expired', ShiftOffer::find($firstId)->status);
        $this->assertSame(1, OperationsAttentionItem::where('kind', 'staffing_offer')->whereNull('resolved_at')->count());
    }

    public function test_explicit_preparation_targets_are_current_bounded_and_require_current_authority(): void
    {
        $this->configure(['staffing_request_mode' => 'assisted']);
        $open = $this->shift(['required_staff' => 2]);
        app(ShiftAssignmentService::class)->assign($open, $this->ben, $this->manager, 'requested');
        $this->shift(['starts_at' => '2027-08-01T08:00', 'ends_at' => '2027-08-01T16:00']);
        $this->shift(['title' => 'Noch unveröffentlicht'], false);
        $targets = $this->service->preparableTargets($this->manager);
        $this->assertSame([$open->id], array_column($targets, 'shift_id'));
        $this->assertSame(AiDispositionSettings::all()['revision'], $targets[0]['settings_revision']);
        $this->service->prepare($open->id, $open->revision, $this->manager);
        $this->assertSame([], $this->service->preparableTargets($this->manager));
        $this->manager->update(['status' => false]);
        try {
            $this->service->preparableTargets($this->manager);
            $this->fail('Revoked operator was authorized.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_schema_resumes_without_overwriting_runs_and_refuses_history_destroying_rollback(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $before = $run->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_09_090000_create_staffing_automation_runs.php');
        $migration->up();
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertTrue($this->service->ready());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_changed_review_reason_refreshes_only_automation_notice_and_preserves_manual_case_note(): void
    {
        $shift = $this->shift();
        app(StaffRegionalPreferenceService::class)->save($this->anna, $this->manager, ['enabled' => true, 'base_location' => 'München',
            'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => [['location' => 'München', 'radius_km' => 10]]], 0);
        EmployeeWorkModel::where('user_id', $this->ben->id)->update(['approved_at' => null]);
        $run = $this->service->process($shift->id, $shift->revision);
        $case = StaffingCase::findOrFail($run->staffing_case_id);
        $case->update(['note' => 'Manuell ergänzte Dispositionsnotiz']);
        $notice = OperationsAttentionItem::where('kind', 'monitor_case')->firstOrFail();
        $notice->update(['read_at' => now()->utc()]);
        EmployeeWorkModel::where('user_id', $this->ben->id)->update(['approved_at' => now()->utc()]);
        $run = $this->service->retry($run->id, $run->revision, $this->manager);
        app(WorkforcePlanningService::class)->respondOffer($run->offer_id, 1, 'interested', $this->ben);
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('interest_received', $run->reason_code);
        $this->assertSame($case->id, $run->staffing_case_id);
        $this->assertSame('Manuell ergänzte Dispositionsnotiz', $case->fresh()->note);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertStringContainsString('Interesse gemeldet', $notice->fresh()->headline);
    }

    public function test_later_human_cancellation_reopens_bounded_wave_and_new_review_never_reopens_a_resolved_case(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $workforce = app(WorkforcePlanningService::class);
        $response = $workforce->respondOffer($run->offer_id, 1, 'interested', $this->anna);
        $run = $this->service->process($shift->id, $shift->revision);
        $case = StaffingCase::findOrFail($run->staffing_case_id);
        $workforce->reviewOffer($response->id, $response->revision, true, $this->manager);
        $assignment = ShiftAssignment::firstOrFail();
        app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $this->anna);
        $this->service->process($shift->id, $shift->revision);
        $workforce->updateCase($case->id, $case->revision, 'resolve', ['note' => 'Besetzung persönlich geprüft'], $this->manager);
        $before = $case->fresh()->getRawOriginal();
        app(ShiftAssignmentService::class)->cancel($assignment->fresh(), $this->manager, 'Spätere manuelle Umplanung');
        $this->assertCount(1, $this->service->scheduledTargets());
        $reopened = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('soliciting', $reopened->state);
        $this->assertSame(2, $reopened->wave_count);
        $this->assertSame([$this->ben->id], $reopened->offer->invited_user_ids);
        $workforce->respondOffer($reopened->offer_id, 1, 'declined', $this->ben);
        $review = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('no_uncontacted_candidates', $review->reason_code);
        $this->assertNotSame($case->id, $review->staffing_case_id);
        $this->assertSame($before, $case->fresh()->getRawOriginal());
        $this->assertSame('open', StaffingCase::find($review->staffing_case_id)->status);
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $assignment->fresh()->status);
    }

    public function test_changed_settings_pause_new_waves_but_expire_only_own_offer_after_deadline(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $this->configure(['revision' => 2]);
        $paused = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('settings_changed', $paused->reason_code);
        $this->assertSame('open', $run->offer->fresh()->status);
        $this->travel(61)->minutes();
        $paused = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('settings_changed', $paused->reason_code);
        $this->assertSame('expired', $run->offer->fresh()->status);
        $this->assertSame(1, ShiftOffer::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_demand_capacity_already_reserved_on_another_shift_blocks_automatic_invitation(): void
    {
        $demand = app(OrderDemandService::class)->save($this->order->id, null, null,
            ['role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'range', 'maximum_staff' => 1,
                'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $other = $this->shift(['title' => 'Vorhandene Bedarfsreservierung', 'order_demand_id' => $demand->id]);
        app(ShiftAssignmentService::class)->assign($other, $this->ben, $this->manager, 'requested');
        $shift = $this->shift(['order_demand_id' => $demand->id]);
        $run = $this->service->process($shift->id, $shift->revision);
        $this->assertSame('uncertain_candidates', $run->reason_code);
        $this->assertContains('capacity_unavailable', $run->basis['issue_codes']);
        $this->assertSame(0, ShiftOffer::count());
        $this->assertSame(1, ShiftAssignment::count());
        $this->assertSame(0, $shift->assignments()->count());
    }

    public function test_removed_shift_retires_only_own_invitation_and_stops_polling_until_manual_retry(): void
    {
        $shift = $this->shift();
        $run = $this->service->process($shift->id, $shift->revision);
        $shift->delete();
        $removed = $this->service->process($shift->id, $run->plan_revision);
        $this->assertSame('shift_removed', $removed->reason_code);
        $this->assertSame('review', $removed->state);
        $this->assertSame('expired', $run->offer->fresh()->status);
        $this->assertCount(0, $this->service->scheduledTargets());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, StaffingCase::count());
        $shift->restore();
        $retried = $this->service->retry($run->id, $removed->revision, $this->manager);
        $this->assertSame('soliciting', $retried->state);
        $this->assertSame([$this->ben->id], $retried->offer->invited_user_ids);
    }
}
