<?php

namespace Tests\Feature;

use App\Livewire\Operations\PersonnelEnhancements;
use App\Models\AbsenceApprovalStep;
use App\Models\EmployeeDocumentRequirement;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeEmergencyContact;
use App\Models\OperationAudit;
use App\Models\PersonnelSurveyResponse;
use App\Models\PersonnelTask;
use App\Models\PersonnelWorkflowStep;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\AbsenceApprovalChainService;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PersonnelWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonnelEnhancementsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $author;

    private User $reviewer;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_07_22_000002_create_employee_document_requirements_table.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_06_101000_create_personnel_enhancements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Storage::fake('private');
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        $this->author = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->reviewer = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
    }

    private function service(): PersonnelEnhancementService
    {
        return app(PersonnelEnhancementService::class);
    }

    private function invalid(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Business invariant must reject this action.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function forbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Private operation must reject this action.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } catch (AuthorizationException $exception) {
            $this->assertTrue(true);
        }
    }

    private function scopedManager(array $abilities): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($abilities, true)]);
        $team->forceFill(['user_id' => $this->author->id])->save();
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => $abilities], $this->author);

        return $manager->fresh();
    }

    private function version(): EmployeeDocumentVersion
    {
        $requirement = EmployeeDocumentRequirement::create(['user_id' => $this->employee->id, 'document_type' => 'employment_contract']);
        Storage::disk('private')->put('uploads/employee-documents/'.$this->employee->id.'/employment_contract/reference.pdf', '%PDF-1.4 synthetic fixture');
        $file = $requirement->file()->create(['user_id' => $this->author->id, 'name' => 'Arbeitsvertrag.pdf', 'path' => 'uploads/employee-documents/'.$this->employee->id.'/employment_contract/reference.pdf', 'disk' => 'private', 'mime_type' => 'application/pdf', 'size' => 25, 'type' => 'employee-document']);

        return EmployeeDocumentVersion::create(['employee_document_requirement_id' => $requirement->id, 'file_id' => $file->id, 'revision' => 1, 'snapshot' => ['sha256' => hash_file('sha256', Storage::disk('private')->path($file->path))], 'created_by' => $this->author->id, 'created_at' => now()->utc()]);
    }

    public function test_workflow_instantiates_relative_steps_and_existing_task_path_enforces_dependency(): void
    {
        $template = $this->service()->createWorkflowTemplate(['title' => 'Eintritt Tf', 'type' => 'onboarding', 'steps' => [
            ['title' => 'Unterlagen prüfen', 'offset_days' => -2, 'assigned_to' => $this->author->id, 'escalation_days' => 2],
            ['title' => 'Einsatzfreigabe prüfen', 'offset_days' => 0, 'assigned_to' => $this->reviewer->id, 'delegate_id' => $this->author->id, 'escalate_to' => $this->reviewer->id, 'escalation_days' => 1, 'depends_on' => 1],
        ]], $this->author);
        $this->forbidden(fn () => $this->service()->activate($template, 1, $this->author));
        $this->service()->activate($template, 1, $this->reviewer);
        $run = $this->service()->instantiateWorkflow($template->fresh(), $this->employee, '2027-05-12', 2, $this->author);
        $this->invalid(fn () => $this->service()->instantiateWorkflow($template->fresh(), $this->employee, '2027-05-12', 2, $this->author));
        $tasks = PersonnelTask::orderBy('id')->get();
        $this->assertSame(['2027-05-10', '2027-05-12'], $tasks->pluck('due_on')->all());
        $this->invalid(fn () => app(PersonnelProcessService::class)->completeTask($tasks[1], 1, '', $this->reviewer));
        app(PersonnelProcessService::class)->completeTask($tasks[0], 1, '', $this->author);
        app(PersonnelProcessService::class)->completeTask($tasks[1], 1, '', $this->author);
        $this->assertSame('done', $run->fresh()->status);
        $this->assertSame(2, PersonnelWorkflowStep::count());
        $this->assertArrayNotHasKey('snapshot', $run->toArray());
    }

    public function test_workflow_blocks_cycles_and_stale_templates_and_escalates_once(): void
    {
        $data = ['title' => 'Austritt', 'type' => 'offboarding', 'steps' => [['title' => 'Rückgabe', 'offset_days' => 0, 'assigned_to' => $this->author->id, 'escalate_to' => $this->reviewer->id, 'escalation_days' => 0]]];
        $bad = $data;
        $bad['steps'][0]['depends_on'] = 1;
        $this->invalid(fn () => $this->service()->createWorkflowTemplate($bad, $this->author));
        $template = $this->service()->createWorkflowTemplate($data, $this->author);
        $this->service()->activate($template, 1, $this->reviewer);
        $this->invalid(fn () => $this->service()->instantiateWorkflow($template->fresh(), $this->employee, '2027-05-10', 1, $this->author));
        $this->service()->instantiateWorkflow($template->fresh(), $this->employee, '2027-05-10', 2, $this->author);
        $this->assertSame(1, $this->service()->escalateWorkflows());
        $this->assertSame(0, $this->service()->escalateWorkflows());
        $this->assertNotNull(PersonnelWorkflowStep::first()->escalated_at);
    }

    public function test_multistage_approval_cannot_be_bypassed_by_legacy_approve_and_sickness_is_immediate(): void
    {
        $chain = app(AbsenceApprovalChainService::class);
        $policy = $chain->create(['title' => 'Urlaub zwei Stufen', 'kind' => 'vacation', 'minimum_days' => 1, 'maximum_days' => 366, 'stages' => [['reviewer_id' => $this->author->id, 'hours' => 24], ['reviewer_id' => $this->reviewer->id, 'hours' => 24]]], $this->author);
        $chain->activate($policy, 1, $this->reviewer);
        $workflow = app(PersonnelWorkflowService::class);
        $absence = $workflow->requestAbsence($this->employee, ['kind' => 'vacation', 'starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin']);
        $this->assertSame(2, AbsenceApprovalStep::count());
        $this->forbidden(fn () => $workflow->absence($absence, 1, 'approve', '', $this->reviewer));
        $workflow->absence($absence, 1, 'approve', '', $this->author);
        $this->assertSame('pending', $absence->fresh()->status);
        $this->assertSame(2, $absence->fresh()->revision);
        $this->invalid(fn () => $workflow->absence($absence, 1, 'approve', '', $this->author));
        $workflow->absence($absence->fresh(), 2, 'approve', '', $this->reviewer);
        $this->assertSame('approved', $absence->fresh()->status);
        $sickness = $workflow->reportSickness($this->employee, ['starts_at' => '2027-05-15T00:00', 'ends_at' => '2027-05-16T00:00', 'timezone' => 'Europe/Berlin']);
        $this->assertSame('reported', $sickness->status);
        $this->assertFalse(AbsenceApprovalStep::where('absence_request_id', $sickness->id)->exists());
    }

    public function test_absence_chain_delegate_cannot_decide_two_stages_and_withdraw_closes_waiting_steps(): void
    {
        $chain = app(AbsenceApprovalChainService::class);
        $policy = $chain->create(['title' => 'Vertretung', 'kind' => 'other', 'minimum_days' => 1, 'maximum_days' => 366, 'stages' => [['reviewer_id' => $this->author->id, 'hours' => 24], ['reviewer_id' => $this->reviewer->id, 'delegate_id' => $this->author->id, 'hours' => 24]]], $this->author);
        $chain->activate($policy, 1, $this->reviewer);
        $workflow = app(PersonnelWorkflowService::class);
        $absence = $workflow->requestAbsence($this->employee, ['kind' => 'other', 'starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin']);
        $workflow->absence($absence, 1, 'approve', '', $this->author);
        $this->invalid(fn () => $workflow->absence($absence->fresh(), 2, 'approve', '', $this->author));
        $workflow->absence($absence->fresh(), 2, 'withdraw', '', $this->employee);
        $this->assertSame('withdrawn', $absence->fresh()->status);
        $this->assertSame(0, AbsenceApprovalStep::whereIn('status', ['open', 'waiting'])->count());
    }

    public function test_report_snapshot_is_encrypted_idempotent_and_inaccessible_after_scope_loss(): void
    {
        $manager = $this->scopedManager(['employees.master-data.view', 'operations.time.review']);
        $report = $this->service()->saveReport(['title' => 'Konten', 'from' => '2027-05-01', 'until' => '2027-05-10', 'user_ids' => [$this->employee->id], 'interval_days' => 7], $manager);
        $result = $this->service()->runReport($report, 1, $manager, 'scheduled:2027-05-10');
        $this->assertNull($result['rows'][0]['target_minutes']);
        $this->assertSame($result, $this->service()->runReport($report, 1, $manager, 'scheduled:2027-05-10'));
        $this->assertSame(0, $this->service()->runScheduledReports());
        $raw = DB::table('personnel_saved_reports')->find($report->id);
        $this->assertStringNotContainsString($this->employee->name, $raw->results);
        $this->assertArrayNotHasKey('results', $report->fresh()->toArray());
        DB::table('personnel_responsibilities')->where('responsible_user_id', $manager->id)->update(['ends_on' => '2027-05-09']);
        $this->forbidden(fn () => $this->service()->authorizedReportResult($report->fresh(), $manager));
        $this->forbidden(fn () => $this->service()->authorizedReportResult($report, $this->reviewer));
    }

    public function test_periodic_report_writes_once_and_stops_for_deactivated_owner(): void
    {
        $report = $this->service()->saveReport(['title' => 'Periodisch', 'from' => '2027-05-01', 'until' => '2027-05-10', 'user_ids' => [$this->employee->id], 'interval_days' => 1], $this->author);
        $this->assertSame(1, $this->service()->runScheduledReports());
        $this->assertSame(0, $this->service()->runScheduledReports());
        $this->assertCount(1, $report->fresh()->results);
        $this->travel(1)->days();
        $this->author->update(['status' => false]);
        $this->assertSame(0, $this->service()->runScheduledReports());
    }

    public function test_periodic_report_can_pause_and_resume_without_erasing_snapshots(): void
    {
        $report = $this->service()->saveReport(['title' => 'Periodisch', 'from' => '2027-05-01', 'until' => '2027-05-10', 'user_ids' => [$this->employee->id], 'interval_days' => 1], $this->author);
        $this->assertSame(1, $this->service()->runScheduledReports());
        $this->service()->reportState($report->fresh(), 2, false, $this->author);
        $this->assertSame(0, $this->service()->runScheduledReports());
        $this->assertCount(1, $report->fresh()->results);
        $this->service()->reportState($report->fresh(), 3, true, $this->author);
        $this->assertSame('active', $report->fresh()->status);
        $this->assertCount(1, $report->fresh()->results);
        $this->assertSame('2027-05-11', $report->fresh()->next_run_on);
        $this->assertSame(0, $this->service()->runScheduledReports());
    }

    public function test_document_template_and_signature_results_are_private_version_bound_not_acknowledgement(): void
    {
        $template = $this->service()->saveDocumentTemplate(['title' => 'Vertrag', 'body' => 'Name: {{employee_name}} Stand: {{date}}'], $this->author);
        $this->service()->activate($template, 1, $this->reviewer);
        $this->assertStringContainsString($this->employee->name, $this->service()->renderDocument($template->fresh(), $this->employee, $this->author));
        $version = $this->version();
        $request = $this->service()->requestSignature($version, ['title' => 'Vertrag', 'due_on' => '2027-05-20', 'template_id' => $template->id], $this->author);
        $this->assertSame('pending_external', $request->status);
        $this->assertFalse($request->payload['external_adapter_enabled']);
        $this->service()->submitSignatureResult($request, 1, UploadedFile::fake()->create('Ergebnis.pdf', 10, 'application/pdf'), $this->employee);
        $this->assertSame('result_submitted', $request->fresh()->status);
        $this->service()->reviewSignatureResult($request->fresh(), 2, true, $this->reviewer);
        $this->assertSame('verified_result', $request->fresh()->status);
        $this->assertNull($version->fresh()->acknowledged_at);
        $this->assertStringNotContainsString('result_path', json_encode($request->fresh()->toArray()));
        $this->forbidden(fn () => $this->service()->signatureResult($request, User::factory()->create(['role' => 'staff'])));
    }

    public function test_signature_request_rejects_replaced_document_and_damaged_result(): void
    {
        $version = $this->version();
        $request = $this->service()->requestSignature($version, ['title' => 'Vertrag', 'due_on' => '2027-05-20'], $this->author);
        $this->service()->submitSignatureResult($request, 1, UploadedFile::fake()->create('Ergebnis.pdf', 10, 'application/pdf'), $this->employee);
        Storage::disk('private')->put($request->fresh()->payload['result_path'], 'altered');
        $this->invalid(fn () => $this->service()->reviewSignatureResult($request->fresh(), 2, true, $this->reviewer));
        $version->forceFill(['withdrawn_at' => now()->utc()])->save();
        $this->invalid(fn () => $this->service()->requestSignature($version->fresh(), ['title' => 'Alt', 'due_on' => '2027-05-20'], $this->author));
    }

    public function test_emergency_contact_requires_own_consent_and_audited_restricted_break_glass(): void
    {
        $contact = $this->service()->saveEmergency($this->employee, ['name' => 'Fixture Contact', 'relationship' => 'Angehörig', 'phone' => '+49 1234', 'consent' => true], 0, $this->employee);
        $this->assertStringNotContainsString('Fixture Contact', DB::table('employee_emergency_contacts')->find($contact->id)->payload);
        $this->assertArrayNotHasKey('payload', $contact->toArray());
        $manager = $this->scopedManager(['employees.master-data.view']);
        $this->forbidden(fn () => $this->service()->readEmergency($this->employee, 'Synthetischer Notfall', $manager));
        $this->invalid(fn () => $this->service()->readEmergency($this->employee, '', $this->author));
        $this->assertSame('Fixture Contact', $this->service()->readEmergency($this->employee, 'Synthetischer Notfall', $this->author)['name']);
        $audit = OperationAudit::where('action', 'emergency.break_glass')->firstOrFail();
        $this->assertStringNotContainsString('Synthetischer Notfall', json_encode($audit->data));
        $this->assertStringNotContainsString('Fixture Contact', json_encode($this->service()->inbox($this->author)->all()));
        $this->invalid(fn () => $this->service()->saveEmergency($this->employee, ['name' => 'Other', 'relationship' => 'Familie', 'phone' => '123', 'consent' => true], 0, $this->employee));
    }

    public function test_regional_calendar_is_manual_versioned_four_eyes_and_prepares_a_draft_not_historical_rewrite(): void
    {
        $calendar = $this->service()->saveCalendar(['title' => 'Region 2028', 'region' => 'DE-NW', 'year' => 2028, 'dates' => ['2028-01-01', '2028-12-25'], 'source' => 'Manuell geprüfte Fixture'], $this->author);
        $this->forbidden(fn () => $this->service()->activate($calendar, 1, $this->author));
        $this->service()->activate($calendar, 1, $this->reviewer);
        $impact = $this->service()->calendarImpact($calendar->fresh(), $this->employee, $this->author);
        $this->assertTrue($impact['existing_credits_unchanged']);
        $this->assertTrue($impact['carry_requires_separate_credit']);
        $policy = $this->service()->applyCalendar($calendar->fresh(), $this->employee, ['unit' => 'days'], 2, $this->author);
        $this->assertSame('draft', $policy->status);
        $this->assertSame(['2028-01-01', '2028-12-25'], $policy->non_working_dates);
        $this->assertSame(0, DB::table('workforce_account_entries')->count());
        $this->invalid(fn () => $this->service()->saveCalendar(['title' => 'Falsch', 'region' => 'DE-NW', 'year' => 2028, 'dates' => ['2027-01-01'], 'source' => 'Fixture'], $this->author));
    }

    public function test_recruiting_has_private_stages_interview_controlled_conversion_and_no_identity_side_effect(): void
    {
        $applicant = $this->service()->saveApplicant(['name' => 'Synthetic applicant', 'email' => 'applicant@example.test', 'role' => 'Lokführer', 'retain_until' => '2027-11-10'], $this->author);
        $this->assertStringNotContainsString('applicant@example.test', DB::table('personnel_applicants')->find($applicant->id)->payload);
        $before = User::count();
        $this->invalid(fn () => $this->service()->convertApplicant($applicant, 1, $this->employee, true, $this->author));
        $this->service()->applicantStage($applicant, 1, ['stage' => 'screening', 'note' => 'Erste Sichtung'], $this->author);
        $this->invalid(fn () => $this->service()->applicantStage($applicant->fresh(), 2, ['stage' => 'interview', 'note' => 'Einladung zum Gespräch'], $this->author));
        $this->service()->applicantStage($applicant->fresh(), 2, ['stage' => 'interview', 'interview_on' => '2027-05-15', 'note' => 'Einladung zum Gespräch'], $this->author);
        $this->service()->applicantStage($applicant->fresh(), 3, ['stage' => 'offer', 'note' => 'Angebot nach Gespräch'], $this->author);
        $this->service()->convertApplicant($applicant->fresh(), 4, $this->employee, true, $this->author);
        $this->assertSame('converted', $applicant->fresh()->status);
        $this->assertSame($this->employee->id, $applicant->fresh()->user_id);
        $this->assertSame($before, User::count());
        $this->forbidden(fn () => $this->service()->saveApplicant(['name' => 'X'], $this->employee));
    }

    public function test_rejected_applicant_can_be_erased_only_after_retention_and_is_not_in_inbox(): void
    {
        $applicant = $this->service()->saveApplicant(['name' => 'Private Applicant', 'email' => 'private@example.test', 'role' => 'Tf', 'retain_until' => '2027-05-12'], $this->author);
        $this->service()->applicantStage($applicant, 1, ['stage' => 'rejected', 'note' => 'Fachentscheidung'], $this->author);
        $this->invalid(fn () => $this->service()->eraseApplicant($applicant->fresh(), 2, $this->author));
        $this->assertStringNotContainsString('Private Applicant', json_encode($this->service()->inbox($this->author)->all()));
        $this->travel(4)->days();
        $this->service()->eraseApplicant($applicant->fresh(), 2, $this->author);
        $this->assertSame('erased', $applicant->fresh()->status);
        $this->assertArrayNotHasKey('email', $applicant->fresh()->payload);
    }

    public function test_development_feedback_is_private_revision_bound_and_not_a_disposition_score(): void
    {
        $review = $this->service()->saveDevelopment($this->employee, ['title' => 'Befähigung', 'goal' => 'Private development target', 'due_on' => '2027-05-20', 'responsible_id' => $this->author->id], $this->author);
        $this->service()->developmentFeedback($review, 1, 'Eigene Rückmeldung', false, $this->employee);
        $this->forbidden(fn () => $this->service()->developmentFeedback($review->fresh(), 2, 'Eigenfreigabe', true, $this->employee));
        $this->service()->developmentFeedback($review->fresh(), 2, 'Gemeinsam besprochen', true, $this->author);
        $this->assertSame('completed', $review->fresh()->status);
        $this->assertArrayNotHasKey('score', $review->fresh()->payload);
        $manager = $this->scopedManager(['employees.master-data.view']);
        Livewire::actingAs($manager)->test(PersonnelEnhancements::class, ['tab' => 'development'])->assertForbidden();
    }

    public function test_survey_is_voluntary_hidden_until_close_and_suppresses_small_groups(): void
    {
        $users = collect([$this->employee]);
        for ($i = 0; $i < 5; $i++) {
            $users->push(User::factory()->create(['role' => 'staff', 'status' => true]));
        }
        $survey = $this->service()->createSurvey(['title' => 'Planqualität', 'question' => 'Wie passt der Plan?', 'threshold' => 5, 'until' => '2027-05-12', 'user_ids' => $users->pluck('id')->all()], $this->author);
        foreach ($users->take(5) as $user) {
            $this->service()->surveyResponse($survey, 1, 'submit', 4, $user);
        }
        $this->service()->surveyResponse($survey, 1, 'decline', null, $users->last());
        $this->invalid(fn () => $this->service()->surveyAggregate($survey, $this->author));
        $this->service()->surveyResponse($survey, 1, 'withdraw', null, $this->employee);
        $this->assertNull(PersonnelSurveyResponse::where('user_id', $this->employee->id)->first()->payload);
        $this->service()->surveyResponse($survey, 1, 'submit', 4, $this->employee);
        $this->travel(4)->days();
        $aggregate = $this->service()->surveyAggregate($survey, $this->author);
        $this->assertSame(5, $aggregate['count']);
        $this->assertSame(4.0, $aggregate['average']);
        $this->assertArrayNotHasKey('user_ids', $aggregate);
        $this->assertStringNotContainsString($this->employee->name, json_encode($aggregate));
        $this->assertStringNotContainsString('score', json_encode(PersonnelSurveyResponse::first()->toArray()));
        $second = $this->service()->createSurvey(['title' => 'Geschützte kleine Gruppe', 'question' => 'Wie passt der Plan?', 'threshold' => 5, 'until' => '2027-05-17', 'user_ids' => $users->pluck('id')->all()], $this->author);
        foreach ($users->take(4) as $user) {
            $this->service()->surveyResponse($second, 1, 'submit', 4, $user);
        }
        $this->travel(4)->days();
        $this->invalid(fn () => $this->service()->surveyAggregate($second, $this->author));
    }

    public function test_sickness_evidence_is_internal_restricted_full_coverage_and_keeps_report_effective(): void
    {
        $absence = app(PersonnelWorkflowService::class)->reportSickness($this->employee, ['starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-15T00:00', 'timezone' => 'Europe/Berlin']);
        $evidence = $this->service()->openSicknessEvidence($absence, '2027-05-14', $this->author);
        $this->assertFalse($evidence->payload['external_adapter_enabled']);
        $this->service()->sicknessEvidence($evidence, 1, ['action' => 'submitted', 'note' => 'Nachweis gemeldet'], $this->employee);
        $this->forbidden(fn () => $this->service()->sicknessEvidence($evidence->fresh(), 2, ['action' => 'covered', 'covered_from' => '2027-05-12', 'covered_until' => '2027-05-14', 'note' => 'Eigenfreigabe'], $this->employee));
        $this->invalid(fn () => $this->service()->sicknessEvidence($evidence->fresh(), 2, ['action' => 'covered', 'covered_from' => '2027-05-13', 'covered_until' => '2027-05-14', 'note' => 'Lücke prüfen'], $this->author));
        $this->service()->sicknessEvidence($evidence->fresh(), 2, ['action' => 'covered', 'covered_from' => '2027-05-12', 'covered_until' => '2027-05-14', 'note' => 'Abdeckung geprüft'], $this->author);
        $this->assertSame('covered', $evidence->fresh()->status);
        $this->assertSame('reported', $absence->fresh()->status);
        $this->assertStringNotContainsString('Abdeckung geprüft', json_encode($this->service()->inbox($this->author)->all()));
        $manager = $this->scopedManager(['employees.master-data.view']);
        $this->assertCount(0, $this->service()->inbox($manager));
    }

    public function test_component_preserves_private_perspective_and_standard_controls(): void
    {
        Livewire::actingAs($this->author)->test(PersonnelEnhancements::class)->assertOk()->assertSee('Personalbereich')->call('open', 'workflow_template')->assertSee('Tage zum Stichtag')->assertSee('Schritt hinzufügen');
        Livewire::actingAs($this->employee)->test(PersonnelEnhancements::class, ['personal' => true, 'tab' => 'emergency'])->assertOk()->call('open', 'emergency_edit')->set('form.name', 'Private Contact')->set('form.relationship', 'Familie')->set('form.phone', '+49123')->set('form.consent', true)->call('save')->assertHasNoErrors()->assertSet('formOpen', false);
        $this->assertSame(1, EmployeeEmergencyContact::count());
        Livewire::actingAs($this->employee)->test(PersonnelEnhancements::class, ['personal' => true])->call('open', 'calendar')->assertForbidden();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->employee)->test(PersonnelEnhancements::class, ['personal' => true])->set('personal', false);
    }

    public function test_own_component_cannot_target_other_person_and_optional_schema_loss_does_not_affect_legacy_tasks(): void
    {
        Livewire::actingAs($this->employee)->test(PersonnelEnhancements::class, ['personal' => true])->set('userId', $this->author->id)->assertForbidden();
        Schema::drop('personnel_surveys');
        $this->assertFalse($this->service()->ready());
        Livewire::actingAs($this->author)->test(PersonnelEnhancements::class)->assertSee('Personalbereich nicht verfügbar');
        $task = app(PersonnelProcessService::class)->createTask($this->employee, ['title' => 'Legacy Aufgabe', 'type' => 'general', 'assigned_to' => $this->author->id], $this->author);
        app(PersonnelProcessService::class)->completeTask($task, 1, '', $this->author);
        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_created_templates_have_immediate_revision_and_activation_state(): void
    {
        $template = $this->service()->saveDocumentTemplate(['title' => 'Fixture', 'body' => 'Name {{ employee_name }}'], $this->author);
        $this->assertSame(1, $template->revision);
        $this->assertSame('draft', $template->status);
        $this->service()->activate($template, $template->revision, $this->reviewer);
        $this->assertStringContainsString($this->employee->name, $this->service()->renderDocument($template->fresh(), $this->employee, $this->author));
    }

    public function test_readonly_manager_cannot_open_sensitive_creation_modals_from_another_tab(): void
    {
        $manager = $this->scopedManager(['employees.master-data.view']);
        Livewire::actingAs($manager)->test(PersonnelEnhancements::class)->assertOk()->call('open', 'sickness')->assertForbidden();
        Livewire::actingAs($manager)->test(PersonnelEnhancements::class)->call('open', 'applicant')->assertForbidden();
        Livewire::actingAs($manager)->test(PersonnelEnhancements::class)->call('open', 'emergency_read')->assertForbidden();
        Livewire::actingAs($manager)->test(PersonnelEnhancements::class)->call('open', 'development')->assertForbidden();
    }

    public function test_private_capability_still_needs_its_exact_subject_scope(): void
    {
        $manager = $this->scopedManager(['employees.master-data.view', 'employees.master-data.edit', 'employees.emergency.access', 'employees.development.manage']);
        DB::table('personnel_responsibilities')->where('responsible_user_id', $manager->id)->update(['abilities' => json_encode(['employees.master-data.view', 'employees.master-data.edit'])]);
        $this->service()->saveEmergency($this->employee, ['name' => 'Private contact', 'relationship' => 'Familie', 'phone' => '1234', 'consent' => true], 0, $this->employee);
        $this->forbidden(fn () => $this->service()->readEmergency($this->employee, 'Synthetischer Notfall', $manager));
        $this->forbidden(fn () => $this->service()->saveDevelopment($this->employee, ['title' => 'Privat', 'goal' => 'Befähigung', 'due_on' => '2027-05-20', 'responsible_id' => $manager->id], $manager));
    }

    public function test_retired_chain_keeps_existing_steps_and_partial_schema_cannot_bypass_them(): void
    {
        $chain = app(AbsenceApprovalChainService::class);
        $policy = $chain->create(['title' => 'Stand 1', 'kind' => 'other', 'minimum_days' => 1, 'maximum_days' => 366, 'stages' => [['reviewer_id' => $this->reviewer->id, 'hours' => 24]]], $this->author);
        $chain->activate($policy, 1, $this->reviewer);
        $absence = app(PersonnelWorkflowService::class)->requestAbsence($this->employee, ['kind' => 'other', 'starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin']);
        $chain->retire($policy->fresh(), 2, 'Neue Regelversion', $this->author);
        $this->assertSame('retired', $policy->fresh()->status);
        $this->assertCount(1, $chain->inbox($this->reviewer));
        Schema::drop('personnel_enhancement_locks');
        try {
            app(PersonnelWorkflowService::class)->absence($absence, 1, 'approve', '', $this->reviewer);
            $this->fail('Partial chain schema must not allow bypass.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame('pending', $absence->fresh()->status);
    }

    public function test_workflow_dependency_survives_an_unrelated_optional_table_loss(): void
    {
        $template = $this->service()->createWorkflowTemplate(['title' => 'Abhängigkeit', 'type' => 'general', 'steps' => [['title' => 'A', 'offset_days' => 0, 'assigned_to' => $this->author->id, 'escalation_days' => 1], ['title' => 'B', 'offset_days' => 1, 'assigned_to' => $this->author->id, 'escalation_days' => 1, 'depends_on' => 1]]], $this->author);
        $this->service()->activate($template, $template->revision, $this->reviewer);
        $this->service()->instantiateWorkflow($template->fresh(), $this->employee, '2027-05-12', 2, $this->author);
        Schema::drop('personnel_surveys');
        $this->invalid(fn () => app(PersonnelProcessService::class)->completeTask(PersonnelTask::orderByDesc('id')->first(), 1, '', $this->author));
    }

    public function test_sickness_date_correction_reopens_coverage_in_inbox_without_leaking_details(): void
    {
        $absence = app(PersonnelWorkflowService::class)->reportSickness($this->employee, ['starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin']);
        $evidence = $this->service()->openSicknessEvidence($absence, '2027-05-14', $this->author);
        $this->service()->sicknessEvidence($evidence, 1, ['action' => 'covered', 'covered_from' => '2027-05-12', 'covered_until' => '2027-05-12', 'note' => 'Privater Nachweisinhalt'], $this->author);
        $this->assertEmpty($this->service()->inbox($this->author));
        app(PersonnelWorkflowService::class)->correctSickness($absence->fresh(), 1, ['starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin', 'note' => 'Verlängerung'], $this->author);
        $item = $this->service()->inbox($this->author)->first();
        $this->assertSame('needs_review', $item['status']);
        $this->assertStringNotContainsString('Privater Nachweisinhalt', json_encode($item));
    }

    public function test_new_migration_is_resumable_and_refuses_material_history_rollback_before_drops(): void
    {
        $migration = require database_path('migrations/2026_10_06_101000_create_personnel_enhancements.php');
        $migration->up();
        $this->assertSame(1, DB::table('personnel_enhancement_locks')->count());
        $this->service()->saveEmergency($this->employee, ['name' => 'Fixture', 'relationship' => 'Familie', 'phone' => '123', 'consent' => true], 0, $this->employee);
        try {
            $migration->down();
            $this->fail('Private records must not be deleted by rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('historie', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('personnel_surveys'));
        $this->assertSame(1, EmployeeEmergencyContact::count());
    }

    public function test_management_and_personal_module_forms_compile_with_existing_standard_components(): void
    {
        foreach (['workflows' => 'workflow_template', 'reports' => 'report', 'documents' => 'document_template', 'approvals' => 'approval_policy', 'calendars' => 'calendar', 'recruiting' => 'applicant', 'development' => 'development', 'surveys' => 'survey', 'sickness' => 'sickness'] as $tab => $kind) {
            Livewire::actingAs($this->author)->test(PersonnelEnhancements::class, ['tab' => $tab])->assertOk()->call('open', $kind)->assertOk()->assertSet('formOpen', true);
        }
        foreach (['workflows', 'documents', 'emergency', 'development', 'surveys', 'sickness'] as $tab) {
            Livewire::actingAs($this->employee)->test(PersonnelEnhancements::class, ['personal' => true, 'tab' => $tab])->assertOk();
        }
        $view = file_get_contents(resource_path('views/livewire/operations/personnel-enhancements.blade.php'));
        $this->assertStringContainsString('<x-tables.table', $view);
        $this->assertStringContainsString('<x-operations.modal', $view);
        $this->assertStringContainsString('<x-ui.buttons.multi-toggle', $view);
    }

    public function test_duplicate_main_reviewer_and_delegate_consuming_the_only_later_reviewer_are_rejected(): void
    {
        $chain = app(AbsenceApprovalChainService::class);
        $data = ['title' => 'Schutz', 'kind' => 'other', 'minimum_days' => 1, 'maximum_days' => 366, 'stages' => [['reviewer_id' => $this->author->id, 'hours' => 24], ['reviewer_id' => $this->author->id, 'hours' => 24]]];
        $this->invalid(fn () => $chain->create($data, $this->author));
        $data['stages'][0]['delegate_id'] = $this->reviewer->id;
        $data['stages'][1]['reviewer_id'] = $this->reviewer->id;
        $policy = $chain->create($data, $this->author);
        $chain->activate($policy, $policy->revision, $this->reviewer);
        $absence = app(PersonnelWorkflowService::class)->requestAbsence($this->employee, ['kind' => 'other', 'starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin']);
        $this->invalid(fn () => app(PersonnelWorkflowService::class)->absence($absence, 1, 'approve', '', $this->reviewer));
        $this->assertSame('open', AbsenceApprovalStep::where('absence_request_id', $absence->id)->where('position', 1)->first()->status);
        app(PersonnelWorkflowService::class)->absence($absence, 1, 'approve', '', $this->author);
        app(PersonnelWorkflowService::class)->absence($absence->fresh(), 2, 'approve', '', $this->reviewer);
        $this->assertSame('approved', $absence->fresh()->status);
    }
}
