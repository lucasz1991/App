<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\AiPlanningHelper;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PlanVariant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Operations\AiDispositionClient;
use App\Services\Operations\AiPlanningService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffRegionalPreferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiPlanningHelperTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $anna;

    private User $ben;

    private Order $order;

    private AiPlanningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_220000_create_staff_regional_preferences_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Private', 'email' => 'private-anna@example.test', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Private', 'email' => 'private-ben@example.test', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Geprüftes Testprofil', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Private Customer', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Testleistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-05-01T00:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
        $this->service = app(AiPlanningService::class);
        $this->actingAs($this->manager);
    }

    private function shift(array $extra = []): Shift
    {
        return Shift::forceCreate(array_replace(['order_id' => $this->order->id, 'title' => 'Offener Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'location_name' => 'München', 'created_by' => $this->manager->id], $extra))->fresh();
    }

    private function response(array $recommendations, ?callable $before = null, array $metadata = []): void
    {
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('structured')->once()->andReturnUsing(function ($task, $messages, $schema) use ($recommendations, $before, $metadata) {
            $this->assertContains($task, ['planning_shift', 'planning_period']);
            $this->assertStringNotContainsString('Private', $messages[1]['content']);
            $this->assertStringNotContainsString('@example', $messages[1]['content']);
            $this->assertFalse($schema['additionalProperties']);
            if ($before) {
                $before($messages);
            }

            return new OpenRouterChatResponse(json_encode(['summary' => 'Geprüfte Alternativen', 'recommendations' => $recommendations, 'warnings' => []], JSON_THROW_ON_ERROR),
                usage: $metadata['usage'] ?? [], requestId: $metadata['request_id'] ?? null, model: $metadata['model'] ?? null);
        });
        $this->app->instance(AiDispositionClient::class, $client);
    }

    private function row(Shift $shift, array $users): array
    {
        return ['shift_id' => $shift->id, 'user_ids' => $users, 'reason' => 'Die gepflegten Planungsregeln erlauben diesen Einsatz.'];
    }

    private function invalid(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, collect($exception->errors())->flatten()->first());
        }
    }

    public function test_single_analysis_and_selection_are_read_only_and_provider_has_no_personal_names_or_emails(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id]), $this->row($shift, [$this->ben->id])]);
        $before = $shift->getRawOriginal();
        $proposal = $this->service->analyzeShift($shift->id, $this->manager);
        $this->service->selectCandidate($proposal['token'], $shift->id, $this->ben->id, $this->manager);
        $this->assertCount(2, $proposal['recommendations']);
        $this->assertSame('Anna Private', $proposal['recommendations'][0]['people'][0]['name']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, PlanVariant::count());
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
    }

    public function test_disabled_provider_is_not_called_and_component_shows_normal_fallback(): void
    {
        $shift = $this->shift();
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(false);
        $client->shouldNotReceive('structured');
        $this->app->instance(AiDispositionClient::class, $client);
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'deaktiviert');
        Livewire::test(AiPlanningHelper::class, ['shiftId' => $shift->id])->assertSee('Die normale Besetzung bleibt verfügbar')->call('analyze')->assertHasErrors('aiPlanning');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_no_verified_candidates_do_not_trigger_a_provider_call(): void
    {
        $shift = $this->shift();
        foreach ([$this->anna, $this->ben] as $person) {
            AbsenceRequest::create(['user_id' => $person->id, 'kind' => 'sick', 'status' => 'reported', 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'timezone' => 'Europe/Berlin']);
        }
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldNotReceive('structured');
        $this->app->instance(AiDispositionClient::class, $client);
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'Keine konfliktfreien');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_provider_failure_and_invalid_json_do_not_prepare_any_assignments(): void
    {
        $shift = $this->shift();
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('structured')->once()->andThrow(new OpenRouterChatException('timeout'));
        $this->app->instance(AiDispositionClient::class, $client);
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'momentan nicht verfügbar');
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('structured')->once()->andReturn(new OpenRouterChatResponse('not JSON'));
        $this->app->instance(AiDispositionClient::class, $client);
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'nicht geprüft');
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, OperationAudit::where('action', 'like', 'ai_planning.%')->count());
    }

    public function test_unknown_person_or_shift_and_duplicate_recommendations_are_rejected(): void
    {
        $shift = $this->shift();
        foreach ([[$this->row($shift, [999999]), 'ungeprüfte Person'], [['shift_id' => 999999, 'user_ids' => [$this->anna->id], 'reason' => 'Falsche Schicht'], 'unbekannte Schicht']] as [$row, $message]) {
            $this->response([$row]);
            $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), $message);
        }
        $this->response([$this->row($shift, [$this->anna->id]), $this->row($shift, [$this->anna->id])]);
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'doppelt');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_no_go_candidates_are_not_sent_and_cannot_be_selected_by_the_ai(): void
    {
        $shift = $this->shift();
        app(StaffRegionalPreferenceService::class)->save($this->anna, $this->manager, ['enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => [['location' => 'München', 'radius_km' => 10]]], 0);
        $this->response([$this->row($shift, [$this->anna->id])], function ($messages) {
            $input = json_decode($messages[1]['content'], true);
            $this->assertSame([$this->ben->id], array_column($input['shifts'][0]['candidates'], 'user_id'));
        });
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'ungeprüfte Person');
    }

    public function test_fresh_eligibility_after_ai_response_and_selection_are_required(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])], function () use ($shift) {
            AbsenceRequest::create(['user_id' => $this->anna->id, 'kind' => 'sick', 'status' => 'reported', 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'timezone' => 'Europe/Berlin']);
        });
        $this->invalid(fn () => $this->service->analyzeShift($shift->id, $this->manager), 'Eignung hat sich geändert');
        $this->response([$this->row($shift, [$this->ben->id])]);
        $proposal = $this->service->analyzeShift($shift->id, $this->manager);
        $shift->increment('revision');
        $this->invalid(fn () => $this->service->selectCandidate($proposal['token'], $shift->id, $this->ben->id, $this->manager), 'Plan wurde');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_expired_or_another_users_token_cannot_select_or_apply(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])]);
        $proposal = $this->service->analyzeShift($shift->id, $this->manager);
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->invalid(fn () => $this->service->selectCandidate($proposal['token'], $shift->id, $this->anna->id, $other), 'abgelaufen');
        $this->travel(16)->minutes();
        $this->invalid(fn () => $this->service->selectCandidate($proposal['token'], $shift->id, $this->anna->id, $this->manager), 'abgelaufen');
    }

    public function test_period_analysis_and_variant_save_do_not_reserve_then_manual_apply_only_adds_requested_people(): void
    {
        $shift = $this->shift(['required_staff' => 2]);
        $existing = app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager);
        $before = $existing->fresh()->getRawOriginal();
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $this->response([$this->row($shift, [$this->ben->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame(1, ShiftAssignment::count());
        $variant = $this->service->saveDraftVariant($proposal['token'], $this->manager);
        $this->assertSame('draft', $variant->status);
        $this->assertSame(1, ShiftAssignment::count());
        $ids = $this->service->applyDraftVariant($proposal['token'], $this->manager);
        $this->assertCount(1, $ids);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame('requested', ShiftAssignment::findOrFail($ids[0])->status->value);
        $this->assertSame('applied', $variant->fresh()->status);
        $this->assertSame(0, OperationAudit::where('action', 'assignment.cancelled')->count());
        $this->assertSame($ids, $this->service->applyDraftVariant($proposal['token'], $this->manager));
        $this->assertSame(2, ShiftAssignment::count());
    }

    public function test_period_joint_conflicts_and_stale_variant_are_rejected_without_replacements(): void
    {
        $first = $this->shift();
        $second = $this->shift(['title' => 'Parallel']);
        $this->response([$this->row($first, [$this->anna->id]), $this->row($second, [$this->anna->id])]);
        $this->invalid(fn () => $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager), 'Eignung hat sich geändert');
        $this->response([$this->row($first, [$this->anna->id]), $this->row($second, [$this->ben->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $variant = $this->service->saveDraftVariant($proposal['token'], $this->manager);
        $variant->increment('revision');
        $this->invalid(fn () => $this->service->applyDraftVariant($proposal['token'], $this->manager), 'Variante wurde geändert');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_published_duties_are_add_only_and_require_current_publication_and_fresh_no_go_check(): void
    {
        $shift = $this->shift(['required_staff' => 2, 'status' => 'open', 'published_revision' => 1, 'published_at' => now()]);
        $existing = app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager);
        $before = $existing->fresh()->getRawOriginal();
        $this->response([$this->row($shift, [$this->ben->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $this->invalid(fn () => $this->service->saveDraftVariant($proposal['token'], $this->manager), 'Keine zusätzlichen');
        $id = $this->service->confirmPublished($proposal['token'], $shift->id, $this->ben->id, $this->manager);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame('requested', ShiftAssignment::findOrFail($id)->status->value);
        $this->assertSame($id, $this->service->confirmPublished($proposal['token'], $shift->id, $this->ben->id, $this->manager));
        $this->assertSame(0, OperationAudit::where('action', 'shift.published')->count());

        $other = $this->shift(['starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00', 'status' => 'open', 'published_revision' => 1]);
        $this->response([$this->row($other, [$this->ben->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-14', '2027-05-14', $this->manager);
        app(StaffRegionalPreferenceService::class)->save($this->ben, $this->manager, ['enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50, 'border_radius_km' => 100, 'no_go_areas' => [['location' => 'München', 'radius_km' => 10]]], 0);
        $this->invalid(fn () => $this->service->confirmPublished($proposal['token'], $other->id, $this->ben->id, $this->manager), 'Eignung hat sich geändert');
        $this->assertSame(2, ShiftAssignment::count());
    }

    public function test_single_helper_only_opens_normal_review_and_scoped_parent_rechecks_token(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])]);
        $component = Livewire::test(AiPlanningHelper::class, ['shiftId' => $shift->id])->call('analyze');
        $token = $component->get('proposal')['token'];
        $component->call('choose', $this->anna->id)->assertDispatched('operations-ai-candidate-choice');
        $this->assertSame(0, ShiftAssignment::count());
        Livewire::test(ShiftManagement::class)->call('openDetails', $shift->id)->call('chooseAiCandidate', $token, $shift->id, $this->anna->id)->assertSet('employeeId', $this->anna->id);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_new_reservation_or_absence_after_variant_save_prevents_manual_application(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $variant = $this->service->saveDraftVariant($proposal['token'], $this->manager);
        app(ShiftAssignmentService::class)->assign($shift, $this->ben, $this->manager);
        $this->invalid(fn () => $this->service->applyDraftVariant($proposal['token'], $this->manager), 'Plan wurde');
        $this->assertSame('draft', $variant->fresh()->status);
        $this->assertSame(1, ShiftAssignment::count());
        $this->assertSame($this->ben->id, ShiftAssignment::sole()->user_id);

        $other = $this->shift(['starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00']);
        $this->response([$this->row($other, [$this->anna->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-14', '2027-05-14', $this->manager);
        $variant = $this->service->saveDraftVariant($proposal['token'], $this->manager);
        AbsenceRequest::create(['user_id' => $this->anna->id, 'kind' => 'sick', 'status' => 'reported', 'starts_at' => $other->starts_at, 'ends_at' => $other->ends_at, 'timezone' => 'Europe/Berlin']);
        $this->invalid(fn () => $this->service->applyDraftVariant($proposal['token'], $this->manager), 'Eignung hat sich geändert');
        $this->assertSame('draft', $variant->fresh()->status);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_non_overlapping_period_duties_can_use_same_verified_person_without_duplicate_rejection(): void
    {
        $first = $this->shift();
        $second = $this->shift(['starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00']);
        $this->response([$this->row($first, [$this->anna->id]), $this->row($second, [$this->anna->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-14', $this->manager);
        $this->service->saveDraftVariant($proposal['token'], $this->manager);
        $ids = $this->service->applyDraftVariant($proposal['token'], $this->manager);
        $this->assertCount(2, $ids);
        $this->assertSame([$this->anna->id], ShiftAssignment::pluck('user_id')->unique()->all());
    }

    public function test_helper_rechecks_current_account_before_selection(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])]);
        $component = Livewire::test(AiPlanningHelper::class, ['shiftId' => $shift->id])->call('analyze');
        $this->manager->forceFill(['status' => false])->save();
        $component->call('choose', $this->anna->id)->assertForbidden();
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_proposal_state_cannot_be_overwritten_by_the_client(): void
    {
        $shift = $this->shift();
        $client = Mockery::mock(AiDispositionClient::class);
        $client->shouldReceive('isConfigured')->andReturn(false);
        $client->shouldNotReceive('structured');
        $this->app->instance(AiDispositionClient::class, $client);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(AiPlanningHelper::class, ['shiftId' => $shift->id])->set('proposal', ['token' => 'forged']);
    }

    public function test_new_publication_revision_does_not_allow_an_old_published_suggestion_to_be_applied(): void
    {
        $shift = $this->shift(['status' => 'open', 'published_revision' => 1]);
        $this->response([$this->row($shift, [$this->anna->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $shift->forceFill(['revision' => 2])->save();
        $this->invalid(fn () => $this->service->confirmPublished($proposal['token'], $shift->id, $this->anna->id, $this->manager), 'Plan wurde');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_durable_planning_events_keep_only_validated_ids_and_survive_session_expiry(): void
    {
        $shift = $this->shift();
        $row = $this->row($shift, [$this->anna->id]);
        $row['reason'] = 'Anna Private · private-anna@example.test';
        $this->response([$row], metadata: ['usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160, 'private_note' => 'Ben Private'], 'request_id' => 'gen-fixture-123', 'model' => 'provider/data-model']);
        $proposal = $this->service->analyzeShift($shift->id, $this->manager);
        $this->service->selectCandidate($proposal['token'], $shift->id, $this->anna->id, $this->manager);
        $this->service->selectCandidate($proposal['token'], $shift->id, $this->anna->id, $this->manager);
        $events = OperationAudit::where('action', 'like', 'ai_planning.%')->orderBy('id')->get();
        $this->assertSame(['ai_planning.analysis_completed', 'ai_planning.proposal_validated', 'ai_planning.proposal_reviewed'], $events->pluck('action')->all());
        $this->assertSame(['Shift'], $events->pluck('subject_type')->unique()->all());
        $this->assertSame([$shift->id], $events->pluck('subject_id')->unique()->all());
        $this->assertSame([$this->manager->id], $events->pluck('actor_id')->unique()->all());
        $metadata = $events->first()->data;
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/D', $metadata['run_uuid']);
        $this->assertSame($proposal['token'], $metadata['proposal_token']);
        $this->assertSame('gen-fixture-123', $metadata['provider_request_id']);
        $this->assertSame('provider/data-model', $metadata['model']);
        $this->assertSame(['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160], $metadata['usage']);
        $this->assertSame([['shift_id' => $shift->id, 'user_ids' => [$this->anna->id]]], $metadata['validated_recommendations']);
        $this->assertSame([$metadata['run_uuid']], $events->map(fn ($event) => $event->data['run_uuid'])->unique()->all());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $metadata['basis_hash']);
        $this->assertCount(3, $events->map(fn ($event) => $event->data['event_key'])->unique());
        $this->assertStringNotContainsString('Private', json_encode($events->pluck('data')));
        $this->assertStringNotContainsString('@example.test', json_encode($events->pluck('data')));
        session()->forget('operations_ai_planning_'.$this->manager->id);
        $this->travel(16)->minutes();
        $this->invalid(fn () => $this->service->selectCandidate($proposal['token'], $shift->id, $this->anna->id, $this->manager), 'abgelaufen');
        $this->assertSame(3, OperationAudit::where('action', 'like', 'ai_planning.%')->count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_saved_variant_manual_approval_and_application_share_durable_run_reference_once(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $run = OperationAudit::where('action', 'ai_planning.analysis_completed')->sole()->data;
        $variant = $this->service->saveDraftVariant($proposal['token'], $this->manager);
        $this->assertSame(0, OperationAudit::where('action', 'ai_planning.manually_approved')->count());
        $this->assertSame(0, ShiftAssignment::count());
        $ids = $this->service->applyDraftVariant($proposal['token'], $this->manager);
        $this->assertSame($ids, $this->service->applyDraftVariant($proposal['token'], $this->manager));
        $events = OperationAudit::where('subject_type', 'PlanVariant')->where('subject_id', $variant->id)->where('action', 'like', 'ai_planning.%')->orderBy('id')->get();
        $this->assertSame(['ai_planning.proposal_validated', 'ai_planning.manually_approved', 'ai_planning.additions_applied'], $events->pluck('action')->all());
        foreach ($events as $event) {
            $this->assertSame($run['run_uuid'], $event->data['run_uuid']);
            $this->assertSame($run['proposal_token'], $event->data['proposal_token']);
            $this->assertSame($run['basis_hash'], $event->data['basis_hash']);
        }
        $this->assertSame($ids, $events->last()->data['assignment_ids']);
        $this->assertSame(0, $events->last()->data['replacements']);
        $this->assertFalse($events->last()->data['published']);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_published_manual_application_events_are_durable_and_stale_proposals_cannot_claim_approval(): void
    {
        $shift = $this->shift(['status' => 'open', 'published_revision' => 1]);
        $this->response([$this->row($shift, [$this->anna->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-13', '2027-05-13', $this->manager);
        $id = $this->service->confirmPublished($proposal['token'], $shift->id, $this->anna->id, $this->manager);
        $this->assertSame($id, $this->service->confirmPublished($proposal['token'], $shift->id, $this->anna->id, $this->manager));
        $applied = OperationAudit::where('action', 'ai_planning.additions_applied')->sole();
        $this->assertSame('Shift', $applied->subject_type);
        $this->assertSame([$id], $applied->data['assignment_ids']);
        $this->assertSame([$this->anna->id], $applied->data['selected_user_ids']);
        $this->assertTrue($applied->data['published']);
        $this->assertSame(1, OperationAudit::where('action', 'ai_planning.manually_approved')->count());

        $other = $this->shift(['starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00', 'status' => 'open', 'published_revision' => 1]);
        $this->response([$this->row($other, [$this->ben->id])]);
        $proposal = $this->service->analyzePeriod('2027-05-14', '2027-05-14', $this->manager);
        $other->increment('revision');
        $this->invalid(fn () => $this->service->confirmPublished($proposal['token'], $other->id, $this->ben->id, $this->manager), 'Plan wurde');
        $this->assertSame(1, OperationAudit::where('action', 'ai_planning.manually_approved')->count());
        $this->assertSame(1, OperationAudit::where('action', 'ai_planning.additions_applied')->count());
    }

    public function test_revoked_manager_after_provider_response_cannot_persist_planning_events(): void
    {
        $shift = $this->shift();
        $this->response([$this->row($shift, [$this->anna->id])], function () {
            $this->manager->forceFill(['status' => false])->save();
        });
        try {
            $this->service->analyzeShift($shift->id, $this->manager);
            $this->fail('Current operation rights must be checked before recording the proposal.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, OperationAudit::where('action', 'like', 'ai_planning.%')->count());
        $this->assertSame([], session()->get('operations_ai_planning_'.$this->manager->id, []));
        $this->assertSame(0, ShiftAssignment::count());
    }
}
