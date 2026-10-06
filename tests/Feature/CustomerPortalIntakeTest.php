<?php

namespace Tests\Feature;

use App\Models\AbsenceRequest;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerCapacityReservation;
use App\Models\CustomerCondition;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAttachment;
use App\Models\CustomerPortalAutomationProfile;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalSetting;
use App\Models\CustomerPortalSubmission;
use App\Models\EmployeeWorkModel;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Services\CustomerPortal\CustomerPortalDecisionService;
use App\Services\CustomerPortal\CustomerPortalIntakeAttachmentService;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use App\Services\CustomerPortal\CustomerPortalSubmissionService;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\WorkforceAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class CustomerPortalIntakeTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected User $author;

    protected User $reviewer;

    protected User $employee;

    protected Customer $customer;

    protected CustomerPortalIdentity $identity;

    protected CustomerPortalMembership $member;

    protected CustomerPortalSetting $setting;

    protected CustomerLocation $location;

    protected CustomerCondition $condition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        config(['customer_portal.delivery_enabled' => false]);
        Mail::fake();
        Storage::fake('local');
        $this->author = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->reviewer = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Synthetic approved rules', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->author->id, 'approved_at' => now()->utc()]);
        $this->customer = Customer::create(['company_name' => 'Synthetic portal rail', 'is_active' => true]);
        $contact = CustomerContact::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic contact', 'email' => 'intake@example.test', 'roles' => ['ordering', 'acceptance'], 'is_active' => true, 'updated_by' => $this->author->id]);
        $this->identity = CustomerPortalIdentity::create(['name' => 'Synthetic customer', 'email' => $contact->email, 'password' => 'Only-synthetic-7!', 'active' => true, 'revision' => 1, 'email_verified_at' => now()]);
        $this->setting = CustomerPortalSetting::create(['customer_id' => $this->customer->id, 'enabled' => true, 'modules' => CustomerPortalSetting::MODULES, 'notifications' => ['requests', 'decisions'], 'revision' => 1, 'automation_mode' => 'manual', 'booking_authority' => false, 'updated_by' => $this->author->id]);
        $this->member = CustomerPortalMembership::create(['identity_id' => $this->identity->id, 'customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'role' => 'coordinator', 'status' => 'active', 'revision' => 1, 'capabilities' => CustomerPortalMembership::ROLES['coordinator'], 'location_ids' => [], 'activated_at' => now(), 'updated_by' => $this->author->id]);
        $this->location = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic yard', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->author->id]);
        $this->condition = CustomerCondition::create(['customer_id' => $this->customer->id, 'code' => 'SYNTH', 'label' => 'Synthetic rail service', 'unit' => 'h', 'unit_price_cents' => 10000, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31', 'terms' => 'Synthetic explicit booking terms', 'created_by' => $this->author->id]);
    }

    protected function data(array $extra = []): array
    {
        return $extra + ['intent' => 'quote', 'title' => 'Synthetic service', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'location_id' => $this->location->id, 'role_name' => 'Tf', 'required_staff' => 1, 'planned_break_minutes' => 30, 'condition_id' => $this->condition->id, 'quantity' => '8.000'];
    }

    protected function submit(array $extra = [], ?string $uuid = null, array $files = []): CustomerPortalSubmission
    {
        return app(CustomerPortalSubmissionService::class)->submit($this->identity, $this->customer->id, $uuid ?? (string) Str::uuid(), $this->data($extra), $files);
    }

    protected function profile(string $mode, array $extra = []): void
    {
        $service = app(CustomerPortalDecisionService::class);
        $profile = $service->saveProfile($this->author, $this->customer->id, 0, $extra + ['name' => 'Synthetic automation', 'mode' => $mode, 'auto_reject' => false, 'allowed_roles' => ['Tf'], 'location_ids' => [$this->location->id], 'condition_ids' => [$this->condition->id], 'minimum_lead_minutes' => 60, 'maximum_staff' => 1, 'maximum_total_cents' => 100000, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
        $service->approveProfile($this->reviewer, $this->customer->id, $profile->id, $profile->revision);
        $this->setting->update(['automation_mode' => $mode, 'booking_authority' => true]);
    }

    protected function framework(array $extra = []): array
    {
        $terms = app(CustomerPortalSubmissionService::class)->frameworkTerms($this->identity, $this->customer->id, '2027-05-13T08:00', 'Europe/Berlin');

        return $extra + ['intent' => 'framework', 'accept_conditions' => true, 'accepted_terms_hash' => $terms['hash']];
    }

    protected function capacity(): CustomerCapacityCommitment
    {
        $accounts = app(WorkforceAccountService::class);
        $model = $accounts->createModel($this->employee, ['name' => 'Synthetic contract', 'starts_on' => '2027-01-01', 'ends_on' => null, 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'maximum_weekly_minutes' => 3000, 'daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0]], $this->author);
        $accounts->activate($model, 1, $this->reviewer);
        $service = app(CustomerCapacityService::class);
        $commitment = $service->propose($this->author, $this->customer->id, ['user_id' => $this->employee->id, 'location_id' => $this->location->id, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'planned_break_minutes' => 30, 'qualification_ids' => []]);
        $commitment = $service->consent($this->employee, $commitment->id, $commitment->revision, true);

        return $service->approve($this->reviewer, $this->customer->id, $commitment->id, $commitment->revision);
    }

    protected function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected rejection');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_intake_uses_real_portal_identity_and_preserves_wall_times(): void
    {
        $s = $this->submit();
        $i = OperationInquiry::findOrFail($s->items[0]->inquiry_id);
        $this->assertSame('review', $s->status);
        $this->assertNull($i->created_by);
        $this->assertSame($this->identity->id, $i->customer_portal_identity_id);
        $this->assertSame('08:00', $i->starts_at->setTimezone('Europe/Berlin')->format('H:i'));
        $this->assertSame('verified', $i->status);
        $this->assertSame(0, Order::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_duplicate_delivery_creates_one_request_and_changed_payload_is_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $s = $this->submit([], $uuid);
        $this->assertSame($s->id, $this->submit([], $uuid)->id);
        $this->denied(fn () => $this->submit(['required_staff' => 2], $uuid), 409);
        $this->assertSame(1, OperationInquiry::count());
    }

    public function test_disabled_customer_and_foreign_location_cannot_submit(): void
    {
        $this->setting->update(['enabled' => false]);
        $this->denied(fn () => $this->submit(), 403);
        $this->assertSame(0, CustomerPortalSubmission::count());
    }

    public function test_series_is_atomic_and_does_not_leave_first_position(): void
    {
        $this->expectException(ModelNotFoundException::class);
        try {
            $this->submit(['positions' => [$this->data(), $this->data(['location_id' => 99999])]]);
        } finally {
            $this->assertSame(0, CustomerPortalSubmission::count());
            $this->assertSame(0, OperationInquiry::count());
        }
    }

    public function test_automatic_offer_is_published_but_not_a_capacity_promise(): void
    {
        $this->profile('offer');
        $s = $this->submit();
        $this->assertSame('offered', $s->status);
        $this->assertSame(1, CommercialOfferRevision::where('status', 'offered')->count());
        $this->assertSame(1, DB::table('customer_portal_publications')->count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CustomerCapacityReservation::count());
        $rev = $s->revision;
        $this->assertSame($rev, app(CustomerPortalDecisionService::class)->evaluate($s->id)->revision);
    }

    public function test_unknown_location_stays_review_even_when_auto_reject_is_enabled(): void
    {
        $this->profile('offer', ['auto_reject' => true]);
        $this->setting->update(['auto_reject' => true]);
        $s = $this->submit(['location_id' => null, 'location_name' => 'New requested place']);
        $this->assertSame('review', $s->status);
    }

    public function test_explicit_unsupported_customer_location_can_be_rejected_with_safe_details(): void
    {
        $this->profile('offer', ['auto_reject' => true]);
        $this->setting->update(['auto_reject' => true]);
        $outsideScope = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic excluded own yard', 'country' => 'DE', 'is_active' => true, 'updated_by' => $this->author->id]);
        $s = $this->submit(['location_id' => $outsideScope->id]);
        $this->assertSame('rejected', $s->status);
        $this->assertStringContainsString('nicht angeboten', $s->decision['message']);
        $this->assertSame(0, Order::count());
    }

    public function test_automatic_accept_without_capacity_stays_review_and_rolls_back_customer_acceptance(): void
    {
        $this->profile('accept');
        $s = $this->submit($this->framework());
        $this->assertSame('review', $s->status);
        $this->assertSame('offered', OperationInquiry::first()->status);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CustomerCapacityReservation::count());
    }

    public function test_automatic_accept_uses_consented_capacity_and_does_not_assign_or_invent_times(): void
    {
        $this->profile('accept');
        $this->capacity();
        $s = $this->submit($this->framework());
        $this->assertSame('accepted', $s->status);
        $this->assertSame(1, Order::count());
        $this->assertSame('committed', CustomerCapacityReservation::first()->status);
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, DB::table('shift_assignments')->count());
        $this->assertSame(0, DB::table('work_time_entries')->count());
        $this->assertSame($this->identity->id, Order::first()->customer_portal_identity_id);
        $orderOffer = CommercialOfferRevision::where('subject_type', 'Order')->first();
        $this->assertNull($orderOffer->accepted_by);
        $this->assertSame($this->identity->id, $orderOffer->customer_portal_identity_id);
        Mail::assertNothingSent();
    }

    public function test_second_request_cannot_consume_same_capacity_even_sequentially(): void
    {
        $this->profile('accept');
        $this->capacity();
        $this->submit($this->framework());
        $second = $this->submit($this->framework());
        $this->assertSame('review', $second->status);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, CustomerCapacityReservation::count());
    }

    public function test_two_position_acceptance_is_all_or_nothing_when_capacity_is_short(): void
    {
        $this->profile('accept');
        $this->capacity();
        $s = $this->submit($this->framework(['positions' => [$this->data(), $this->data(['title' => 'Second simultaneous position'])]]));
        $this->assertSame('review', $s->status);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CustomerCapacityReservation::count());
    }

    public function test_unconfirmed_terms_and_changed_price_cannot_auto_accept(): void
    {
        $this->profile('accept');
        $this->capacity();
        $s = $this->submit($this->framework(['accept_conditions' => false]));
        $this->assertSame('review', $s->status);
        $this->condition->update(['unit_price_cents' => 12000]);
        $this->assertSame('review', app(CustomerPortalDecisionService::class)->evaluate($s->id)->status);
        $this->assertSame(0, Order::count());
    }

    public function test_quarantined_upload_prevents_automatic_offer_until_reviewed_and_unchanged(): void
    {
        $this->profile('offer');
        $s = $this->submit([], null, [UploadedFile::fake()->image('synthetic.png')]);
        $this->assertSame('review', $s->status);
        $this->assertSame(0, CommercialOfferRevision::count());
        $file = CustomerPortalAttachment::first();
        app(CustomerPortalIntakeAttachmentService::class)->review($this->reviewer, $file->id, $file->revision, true, 'Synthetic security review');
        $this->assertSame('review', app(CustomerPortalDecisionService::class)->evaluate($s->id)->status);
        $this->assertSame('offered', app(CustomerPortalDecisionService::class)->decide($this->reviewer, $this->customer->id, $s->id, $s->fresh()->revision, 'offer', 'Synthetic interpreted attachment and terms review')->status);
    }

    public function test_only_customer_approved_profile_revision_runs(): void
    {
        $this->profile('offer');
        $p = CustomerPortalAutomationProfile::first();
        $p->update(['approved_at' => null]);
        $this->assertSame('review', $this->submit()->status);
    }

    public function test_staff_reservation_is_checked_by_existing_planning_guard(): void
    {
        $this->profile('accept');
        $this->capacity();
        $this->submit($this->framework());
        $order = Order::first();
        $target = new Shift(['order_id' => $order->id, 'title' => 'Synthetic full service', 'role_name' => 'Tf', 'location_name' => $this->location->name, 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'planned_break_minutes' => 30]);
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($target, collect([$this->employee]))[$this->employee->id]);
        $target->order_id = null;
        $codes = array_column(app(StaffEligibilityService::class)->assessMany($target, collect([$this->employee]))[$this->employee->id], 'code');
        $this->assertContains('customer_capacity_reserved', $codes);
    }

    public function test_without_active_work_model_no_capacity_promise_is_made(): void
    {
        $this->profile('accept');
        $this->capacity();
        EmployeeWorkModel::query()->update(['status' => 'draft']);
        $this->assertSame('review', $this->submit($this->framework())->status);
        $this->assertSame(0, Order::count());
    }

    public function test_repeated_dst_hour_preserves_explicit_offset_and_elapsed_duration(): void
    {
        $s = $this->submit(['starts_at' => '2027-10-31T02:15:00+02:00', 'ends_at' => '2027-10-31T02:45:00+01:00']);
        $i = OperationInquiry::findOrFail($s->items[0]->inquiry_id);
        $this->assertSame(90.0, (float) $i->starts_at->diffInMinutes($i->ends_at));
        $this->assertStringEndsWith('+02:00', $s->items[0]->payload['starts_at']);
        $this->assertStringEndsWith('+01:00', $s->items[0]->payload['ends_at']);
    }

    public function test_historical_migration_rerun_does_not_change_source_ids_or_default_off(): void
    {
        $s = $this->submit();
        $before = OperationInquiry::first()->getAttributes();
        (require database_path('migrations/2026_10_06_112000_create_customer_portal_intake_and_capacity.php'))->up();
        $this->assertSame($before, OperationInquiry::first()->getAttributes());
        $this->assertSame($s->id, CustomerPortalSubmission::first()->id);
        $unused = Customer::create(['company_name' => 'Synthetic unactivated', 'is_active' => true]);
        $this->assertFalse((bool) CustomerPortalSetting::firstOrCreate(['customer_id' => $unused->id], ['modules' => [], 'notifications' => [], 'updated_by' => $this->author->id])->fresh()->enabled);
        Mail::assertNothingSent();
    }

    public function test_direct_domain_accept_cannot_bypass_withdrawn_publication(): void
    {
        $this->profile('offer');
        $s = $this->submit();
        $i = OperationInquiry::findOrFail($s->items[0]->inquiry_id);
        $p = CustomerPortalPublication::first();
        app(CustomerPortalPublicationService::class)->withdraw($this->reviewer, $p->id, $p->revision, 'Synthetic deliberate withdrawal');
        $this->denied(fn () => app(InquiryWorkflowService::class)->transition($i, $i->revision, 'accept', ['note' => 'Synthetic direct acceptance', 'authorized' => true], $this->identity), 409);
        $this->assertSame('offered', $i->fresh()->status);
        $count = CustomerPortalPublication::count();
        $this->assertSame('review', app(CustomerPortalDecisionService::class)->evaluate($s->id)->status);
        $this->assertSame($count, CustomerPortalPublication::count());
    }

    public function test_unrepresentable_prices_keep_received_demand_for_manual_review(): void
    {
        $this->condition->update(['unit_price_cents' => 100000000]);
        $this->profile('offer', ['maximum_total_cents' => 9999999900]);
        $s = $this->submit();
        $this->assertSame('review', $s->status);
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(0, CommercialOfferRevision::count());
    }

    public function test_missing_qualification_definition_cannot_be_approved_or_reserved(): void
    {
        $this->profile('accept');
        $c = $this->capacity();
        $c->update(['qualification_ids' => [999999]]);
        $this->assertSame('review', $this->submit($this->framework())->status);
        $this->assertSame(0, Order::count());
    }

    public function test_manual_acceptance_cannot_consume_capacity_for_changed_demand(): void
    {
        $this->profile('offer');
        $this->capacity();
        $s = $this->submit();
        $i = OperationInquiry::findOrFail($s->items[0]->inquiry_id);
        $i->update(['location_name' => 'Different place', 'revision' => 2, 'verified_revision' => 2, 'accepted_revision' => 2, 'status' => 'accepted']);
        $this->denied(fn () => app(CustomerPortalDecisionService::class)->decide($this->reviewer, $this->customer->id, $s->id, $s->revision, 'accept', 'Synthetic manual decision must not rebase demand'), 409);
        $this->assertSame(0, CustomerCapacityReservation::count());
        $this->assertSame(0, Order::count());
    }

    public function test_later_absence_flags_promised_capacity_without_exposing_private_reason(): void
    {
        $this->profile('accept');
        $this->capacity();
        $this->submit($this->framework());
        $this->assertCount(0, app(CustomerCapacityService::class)->reviewItems($this->reviewer));
        $shift = Shift::create(['order_id' => Order::first()->id, 'title' => 'Synthetic fulfilling shift', 'role_name' => 'Tf', 'location_name' => $this->location->name, 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'planned_break_minutes' => 30, 'status' => 'open', 'required_staff' => 1, 'created_by' => $this->reviewer->id]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'requested', 'assigned_by' => $this->reviewer->id]);
        $this->assertCount(0, app(CustomerCapacityService::class)->reviewItems($this->reviewer));
        AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'sick', 'status' => 'reported', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'reason' => 'PRIVATE HEALTH SECRET']);
        $items = app(CustomerCapacityService::class)->reviewItems($this->reviewer);
        $this->assertCount(1, $items);
        $this->assertStringNotContainsString('PRIVATE HEALTH', $items->toJson());
        $this->assertSame('committed', CustomerCapacityReservation::first()->status);
        $this->assertSame('confirmed', Order::first()->status->value);
    }
}
