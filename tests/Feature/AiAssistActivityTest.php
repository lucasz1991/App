<?php

namespace Tests\Feature;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\OperationAudit;
use App\Models\User;
use App\Services\Operations\AiAssistService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiAssistActivityTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00Z'));
        User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actor = User::factory()->create(['name' => 'Synthetic dispatcher', 'role' => 'staff', 'status' => true]);
        Gate::define('operations.inquiries.manage', fn (User $user) => $user->is($this->actor));
        Gate::define('operations.manage', fn (User $user) => $user->is($this->actor));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function intake(array $values = []): AiIntake
    {
        return AiIntake::create($values + ['source_type' => 'email', 'status' => 'review', 'title' => 'Synthetic source', 'created_at' => '2027-05-12 05:00:00', 'updated_at' => '2027-05-12 05:00:00']);
    }

    private function analysisRun(AiIntake $intake, array $values = []): AiIntakeRun
    {
        return $intake->runs()->create($values + ['kind' => 'analysis', 'status' => 'running', 'source_revision' => 1, 'settings_revision' => 1, 'supervising_user_id' => $this->actor->id, 'input_hash' => hash('sha256', 'synthetic'), 'started_at' => CarbonImmutable::parse('2027-05-12T05:01:00Z')]);
    }

    private function delivery(AiIntake $intake, array $values = []): AiIntakeDelivery
    {
        return $intake->deliveries()->create($values + ['recipient_email' => 'private-recipient@example.test', 'subject' => 'Private mail subject', 'body' => 'PRIVATE_MAIL_BODY', 'status' => 'pending', 'dedup_key' => hash('sha256', (string) $intake->id), 'settings_revision' => 1, 'intake_revision' => 1, 'source_revision' => 1, 'question_round' => 1, 'created_at' => '2027-05-12 05:03:00', 'updated_at' => '2027-05-12 05:03:00']);
    }

    private function proposal(AiIntake $intake, array $values = []): AiIntakeProposal
    {
        return $intake->proposals()->create($values + ['position_index' => 0, 'source_revision' => 1, 'status' => 'proposed', 'payload' => ['private' => 'PRIVATE_PROPOSAL'], 'created_at' => '2027-05-12 05:02:30', 'updated_at' => '2027-05-12 05:02:30']);
    }

    public function test_activity_uses_persisted_phase_times_and_excludes_private_payloads(): void
    {
        $intake = $this->intake(['analysis' => ['private' => 'PRIVATE_ANALYSIS']]);
        $message = $intake->messages()->create(['direction' => 'inbound', 'body' => 'PRIVATE_MESSAGE_BODY', 'sender_email' => 'private-sender@example.test', 'raw_path' => 'private/raw/PRIVATE_PATH.eml', 'metadata' => ['private' => 'PRIVATE_METADATA'], 'created_at' => '2027-05-12 05:00:00']);
        $run = $this->analysisRun($intake, ['status' => 'succeeded', 'finished_at' => CarbonImmutable::parse('2027-05-12T05:02:00Z'), 'input_snapshot' => ['private' => 'PRIVATE_INPUT'], 'result' => ['private' => 'PRIVATE_RESULT'], 'configuration' => ['private' => 'PRIVATE_CONFIG'], 'provider_request_id' => 'PRIVATE_PROVIDER_ID', 'model' => 'PRIVATE_MODEL']);
        $proposal = $this->proposal($intake);
        $delivery = $this->delivery($intake, ['status' => 'sent', 'attempted_at' => CarbonImmutable::parse('2027-05-12T05:03:30Z'), 'sent_at' => CarbonImmutable::parse('2027-05-12T05:04:00Z')]);
        $intake->update(['updated_at' => now()]);
        $service = app(AiAssistService::class);
        $events = $service->activity($this->actor, 'intake');
        $this->assertSame(['delivery:'.$delivery->id.':sent', 'delivery:'.$delivery->id.':attempted', 'delivery:'.$delivery->id.':queued', 'proposal:'.$proposal->id.':prepared', 'run:'.$run->id.':finished', 'run:'.$run->id.':started', 'message:'.$message->id.':received'], $events->pluck('id')->all());
        $this->assertSame('2027-05-12T05:04:00+00:00', $events->first()['at']->toIso8601String());
        $this->assertSame('2027-05-12T05:02:00+00:00', $events->firstWhere('id', 'run:'.$run->id.':finished')['at']->toIso8601String());
        $this->assertSame(['receipt', 'analysis', 'proposal', 'clarification'], $events->pluck('phase')->unique()->reverse()->values()->all());
        $this->assertSame([$intake->id], $events->pluck('intake_id')->unique()->all());
        $this->assertSame('Eingang #'.$intake->id, $events->first()['reference']);
        $this->assertSame($service->intakeUrl($intake), $events->first()['href']);
        $serialized = $events->toJson();
        foreach (['PRIVATE_', 'private-recipient', 'private-sender', 'Private mail subject', 'private/raw'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_running_failed_stale_and_unknown_sends_do_not_claim_success(): void
    {
        $intake = $this->intake();
        $running = $this->analysisRun($intake);
        $failed = $this->analysisRun($intake, ['status' => 'failed', 'finished_at' => CarbonImmutable::parse('2027-05-12T05:02:00Z')]);
        $stale = $this->analysisRun($intake, ['status' => 'stale', 'finished_at' => CarbonImmutable::parse('2027-05-12T05:02:10Z')]);
        $unknown = $this->delivery($intake, ['status' => 'unknown', 'attempted_at' => CarbonImmutable::parse('2027-05-12T05:03:30Z'), 'updated_at' => '2027-05-12 05:04:00']);
        $events = app(AiAssistService::class)->activity($this->actor, 'intake')->keyBy('id');
        $this->assertFalse($events->has('run:'.$running->id.':finished'));
        $this->assertSame('warn', $events['run:'.$failed->id.':finished']['tone']);
        $this->assertSame('Verworfen', $events['run:'.$stale->id.':finished']['status']);
        $this->assertSame('unknown', $events['delivery:'.$unknown->id.':unknown']['state']);
        $this->assertStringContainsString('Keine automatische Wiederholung', $events['delivery:'.$unknown->id.':unknown']['detail']);
        $this->assertFalse($events->has('delivery:'.$unknown->id.':sent'));
        $this->assertSame(0, $events->where('state', 'succeeded')->count());
        Queue::assertNothingPushed();
    }

    public function test_personal_approval_and_actual_draft_application_are_distinct_events(): void
    {
        $intake = $this->intake();
        $proposal = $this->proposal($intake, ['status' => 'applied', 'position_index' => 1, 'approved_at' => CarbonImmutable::parse('2027-05-12T05:05:00Z'), 'applied_at' => CarbonImmutable::parse('2027-05-12T05:06:00Z')]);
        $apply = $this->analysisRun($intake, ['kind' => 'apply', 'status' => 'succeeded', 'started_at' => CarbonImmutable::parse('2027-05-12T05:05:10Z'), 'finished_at' => CarbonImmutable::parse('2027-05-12T05:05:50Z')]);
        $events = app(AiAssistService::class)->activity($this->actor, 'intake')->keyBy('id');
        $this->assertSame('approved', $events['proposal:'.$proposal->id.':approved']['state']);
        $this->assertSame('applied', $events['proposal:'.$proposal->id.':applied']['state']);
        $this->assertStringContainsString('Leistung 2', $events['proposal:'.$proposal->id.':applied']['detail']);
        $this->assertSame('Entwurfserstellung abgeschlossen', $events['run:'.$apply->id.':finished']['title']);
        $this->assertSame('proposal', $events['run:'.$apply->id.':finished']['phase']);
        $this->assertSame('2027-05-12T05:05:00+00:00', $events['proposal:'.$proposal->id.':approved']['at']->toIso8601String());
        $this->assertSame(1, AiIntakeProposal::count());
        $this->assertSame(0, DB::table('shifts')->count());
    }

    public function test_pause_and_explicit_retry_approval_have_real_timestamps_and_stable_references(): void
    {
        $intake = $this->intake(['status' => 'paused', 'paused_at' => CarbonImmutable::parse('2027-05-12T05:07:00Z')]);
        $proposal = $this->proposal($intake, ['status' => 'approved']);
        $retry = OperationAudit::create(['subject_type' => 'AiIntakeProposal', 'subject_id' => $proposal->id, 'actor_id' => $this->actor->id, 'action' => 'ai.proposal.retry_approved', 'revision' => 2, 'data' => ['intake_id' => $intake->id, 'private' => 'PRIVATE_RETRY'], 'created_at' => '2027-05-12 05:08:00']);
        $events = app(AiAssistService::class)->activity($this->actor, 'intake')->keyBy('id');
        $this->assertSame('paused', $events['intake:'.$intake->id.':paused']['state']);
        $this->assertSame('2027-05-12T05:07:00+00:00', $events['intake:'.$intake->id.':paused']['at']->toIso8601String());
        $this->assertSame('retry_approved', $events['audit:'.$retry->id]['state']);
        $this->assertSame($intake->id, $events['audit:'.$retry->id]['intake_id']);
        $this->assertSame('2027-05-12T05:08:00+00:00', $events['audit:'.$retry->id]['at']->toIso8601String());
        $this->assertStringNotContainsString('PRIVATE_RETRY', $events->toJson());
    }

    public function test_overview_counts_all_real_statuses_beyond_the_bounded_inbox(): void
    {
        foreach (AiIntake::STATUSES as $index => $status) {
            for ($record = 0; $record <= $index; $record++) {
                $this->intake(['status' => $status]);
            }
        }
        $service = app(AiAssistService::class);
        $overview = $service->overview($this->actor);
        $this->assertTrue($overview['available']);
        $this->assertSame(36, $overview['total']);
        $this->assertSame(array_combine(AiIntake::STATUSES, range(1, 8)), $overview['statuses']);
        $this->assertSame(['review' => 3, 'busy' => 3, 'waiting' => 4, 'error' => 7, 'ready' => 5, 'completed' => 8, 'paused' => 6], $overview['counts']);
        $this->assertCount(5, $overview['recent']);
        $this->assertIsString($overview['recent'][0]['at']);
        $this->assertSame(30, $service->intakes($this->actor)->count());
        $this->assertSame(3, $service->reviewCount($this->actor));
    }

    public function test_fresh_permissions_and_active_status_gate_intake_and_planning_reads(): void
    {
        $intake = $this->intake();
        $this->analysisRun($intake);
        $this->assertNotEmpty(app(AiAssistService::class)->activity($this->actor));
        $this->actor->forceFill(['status' => false])->save();
        // The caller may still hold a stale, previously active User instance.
        $this->actor->status = true;
        $service = app(AiAssistService::class);
        $this->assertTrue($service->activity($this->actor)->isEmpty());
        $this->assertTrue($service->intakes($this->actor)->isEmpty());
        $this->assertSame(0, $service->reviewCount($this->actor));
        $overview = $service->overview($this->actor);
        $this->assertFalse($overview['available']);
        $this->assertSame(0, $overview['total']);
        $this->assertSame([], $overview['recent']);
        $this->actor->forceFill(['status' => true])->save();
        Gate::define('operations.inquiries.manage', fn () => false);
        $this->assertTrue($service->activity($this->actor, 'intake')->isEmpty());
        $this->assertFalse($service->overview($this->actor)['available']);
    }

    public function test_intake_and_planning_filters_preserve_requested_and_withdrawn_audits(): void
    {
        $intake = $this->intake();
        $requested = OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => 999, 'actor_id' => $this->actor->id, 'action' => 'ai_assist.requested', 'revision' => 1, 'data' => ['user_id' => $this->actor->id, 'private' => 'PRIVATE_AUDIT'], 'created_at' => '2027-05-12 05:05:00']);
        $withdrawn = OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => 999, 'actor_id' => $this->actor->id, 'action' => 'ai_assist.withdrawn', 'revision' => 1, 'data' => ['user_id' => $this->actor->id], 'created_at' => '2027-05-12 05:06:00']);
        $service = app(AiAssistService::class);
        $planning = $service->activity($this->actor, 'planning');
        $this->assertSame(['audit:'.$withdrawn->id, 'audit:'.$requested->id], $planning->pluck('id')->all());
        $this->assertSame(['withdrawn', 'requested'], $planning->pluck('state')->all());
        $this->assertStringContainsString('Als Angefragt eingeteilt', $planning->last()['detail']);
        $this->assertStringContainsString('Einteilung zurückgenommen', $planning->first()['detail']);
        $this->assertStringNotContainsString('PRIVATE_AUDIT', $planning->toJson());
        $this->assertSame([$intake->id], $service->activity($this->actor, 'intake')->pluck('intake_id')->all());
        Gate::define('operations.manage', fn () => false);
        $this->assertTrue($service->activity($this->actor, 'planning')->isEmpty());
        $this->assertSame(['intake'], $service->activity($this->actor)->pluck('kind')->unique()->all());
    }

    public function test_utc_instants_and_utc_audits_sort_correctly_with_berlin_app_timezone(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        date_default_timezone_set('Europe/Berlin');
        $intake = $this->intake(['created_at' => '2027-05-12 07:00:00']);
        $run = $this->analysisRun($intake, ['status' => 'succeeded', 'finished_at' => CarbonImmutable::parse('2027-05-12T05:02:00Z')]);
        $audit = OperationAudit::create(['subject_type' => 'Shift', 'subject_id' => 999, 'actor_id' => $this->actor->id, 'action' => 'ai_assist.requested', 'revision' => 1, 'data' => [], 'created_at' => '2027-05-12 05:03:00']);
        $events = app(AiAssistService::class)->activity($this->actor);
        $this->assertSame(['audit:'.$audit->id, 'run:'.$run->id.':finished', 'run:'.$run->id.':started', 'intake:'.$intake->id.':received'], $events->pluck('id')->all());
        $this->assertSame('2027-05-12T05:03:00+00:00', $events->first()['at']->toIso8601String());
        $this->assertSame('2027-05-12T07:00:00+02:00', $events->last()['at']->toIso8601String());
    }

    public function test_activity_queries_stay_bounded_and_recent_runs_of_older_intakes_remain_visible(): void
    {
        $old = $this->intake(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $recentRun = $this->analysisRun($old, ['started_at' => CarbonImmutable::parse('2027-05-12T06:59:00Z')]);
        $this->delivery($old);
        $this->proposal($old);
        $old->messages()->create(['direction' => 'inbound', 'body' => 'PRIVATE_MESSAGE', 'created_at' => '2026-01-01 00:00:00']);
        $this->intake();
        DB::enableQueryLog();
        app(AiAssistService::class)->activity($this->actor, 'intake');
        $smallQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 45; $index++) {
            $intake = $this->intake();
            $this->analysisRun($intake);
            $this->delivery($intake);
            $this->proposal($intake);
            $intake->messages()->create(['direction' => 'inbound', 'body' => 'PRIVATE_MESSAGE', 'created_at' => '2027-05-12 05:00:00']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $events = app(AiAssistService::class)->activity($this->actor, 'intake');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($smallQueryCount, count($queries));
        $this->assertSame(30, $events->count());
        $this->assertContains('run:'.$recentRun->id.':started', $events->pluck('id'));
        $this->assertCount(30, $events->pluck('id')->unique());
        foreach ($queries as $query) {
            if (preg_match('/from "ai_intake_(runs|deliveries|proposals|messages)"/', $query['query'])) {
                $selected = explode(' from ', $query['query'])[0];
                foreach (['input_snapshot', 'result', 'configuration', 'recipient_email', 'raw_path', 'body', 'metadata', 'payload'] as $privateColumn) {
                    $this->assertStringNotContainsString('"'.$privateColumn.'"', $selected);
                }
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_missing_schema_returns_an_unavailable_empty_overview_and_invalid_filter_is_rejected(): void
    {
        Schema::drop('ai_intake_runs');
        $service = app(AiAssistService::class);
        $this->assertFalse($service->overview($this->actor)['available']);
        $this->assertSame(0, $service->overview($this->actor)['total']);
        $this->assertTrue($service->activity($this->actor, 'intake')->isEmpty());
        $this->expectException(HttpException::class);
        $service->activity($this->actor, 'private');
    }
}
