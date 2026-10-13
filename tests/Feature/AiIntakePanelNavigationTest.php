<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\UnifiedInbox;
use App\Livewire\Tools\Chatbot;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Operations\AiAssistService;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Support\Ai\AssistantSettings;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\UnifiedInboxProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakePanelNavigationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php',
            '2026_10_07_010000_create_ai_disposition_intake.php', '2026_10_09_090000_create_staffing_automation_runs.php',
            '2026_10_09_100000_add_ai_customer_communication_controls.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic panel customer', 'is_active' => true]);
        $this->actingAs($this->actor);
        $this->withoutVite();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::set('local', Storage::fake('ai-intake-panel-navigation-'.getmypid()));
        AssistantSettings::setEnabled(true);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, AiDispositionSettings::DEFAULTS);
    }

    private function intake(string $title = 'Synthetic selected panel intake', ?int $customerId = null): AiIntake
    {
        return AiIntake::create(['source_type' => 'manual', 'status' => 'review', 'title' => $title,
            'customer_id' => $customerId, 'revision' => 1, 'source_revision' => 1, 'supervising_user_id' => $this->actor->id])->fresh();
    }

    private function rejected(callable $action, int $status): void
    {
        try {
            $result = $action();
            if ($result instanceof Testable) {
                $result->assertStatus($status);

                return;
            }
            $this->fail('Unauthorized or invalid navigation was accepted.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        } catch (AuthorizationException) {
            $this->assertSame(403, $status);
        }
    }

    private function noBusinessChanges(AiIntake $intake, array $before): void
    {
        $this->assertSame($before, $intake->fresh()->getRawOriginal());
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeProposal::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_canonical_urls_and_legacy_links_keep_exact_source_identity_without_a_standalone_section(): void
    {
        $intake = $this->intake(customerId: $this->customer->id);
        $url = OperationsPages::intakePanelUrl($intake->id, $this->customer->id);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertSame(['view' => 'inbox', 'assistant' => 'intake', 'source' => 'ai-intake',
            'record' => (string) $intake->id, 'customer' => (string) $this->customer->id], $parameters);
        $this->assertSame($url, OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake',
            'source' => 'ai-intake', 'record' => $intake->id, 'customer' => $this->customer->id]));
        $this->assertSame($url, OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake',
            'intake' => $intake->id, 'customer' => $this->customer->id]));
        $this->assertSame($url, app(AiAssistService::class)->intakeUrl($intake));
        $this->assertStringNotContainsString('section=ai-intake', $url);
        $this->assertSame(['intakeId' => $intake->id, 'customerId' => $this->customer->id, 'create' => false],
            OperationsPages::intakePanelContext(Request::create($url)));
        $capture = OperationsPages::intakePanelUrl(customerId: $this->customer->id, create: true);
        $this->assertSame(['intakeId' => null, 'customerId' => $this->customer->id, 'create' => true],
            OperationsPages::intakePanelContext(Request::create($capture)));
        $this->assertNull(OperationsPages::intakePanelContext(Request::create('/arbeitsplatz/ansicht/cases?view=inbox')));
    }

    #[DataProvider('invalidPanelQueries')]
    public function test_invalid_query_shapes_are_rejected_before_any_source_can_be_selected(array $query): void
    {
        $this->rejected(fn () => OperationsPages::intakePanelContext(Request::create('/arbeitsplatz/ansicht/cases', 'GET', $query)), 422);
        $this->assertSame(0, AiIntake::count());
    }

    public static function invalidPanelQueries(): array
    {
        return [
            'assistant array' => [['assistant' => ['intake']]],
            'unknown assistant' => [['assistant' => 'arbitrary']],
            'record zero' => [['assistant' => 'intake', 'source' => 'ai-intake', 'record' => '0']],
            'record array' => [['assistant' => 'intake', 'source' => 'ai-intake', 'record' => ['1']]],
            'foreign source' => [['assistant' => 'intake', 'source' => 'submission', 'record' => '1']],
            'untyped source record' => [['assistant' => 'intake', 'record' => '1']],
            'customer array' => [['assistant' => 'intake', 'customer' => ['1']]],
            'malformed legacy intake' => [['section' => 'ai-intake', 'intake' => 'other']],
            'conflicting legacy identity' => [['section' => 'ai-intake', 'intake' => '2', 'record' => '1']],
            'capture and detail' => [['assistant' => 'intake', 'source' => 'ai-intake', 'record' => '1', 'capture' => '1']],
            'unknown capture flag' => [['assistant' => 'intake', 'capture' => '2']],
        ];
    }

    public function test_legacy_page_and_intake_alias_redirect_to_the_panel_after_scope_validation(): void
    {
        $intake = $this->intake(customerId: $this->customer->id);
        $before = $intake->getRawOriginal();
        Livewire::test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'ai-intake',
            'context' => ['source' => 'ai-intake', 'record' => $intake->id, 'customer' => $this->customer->id]])
            ->assertRedirect(OperationsPages::intakePanelUrl($intake->id, $this->customer->id));
        Livewire::withQueryParams(['section' => 'ai-intake', 'intake' => (string) $intake->id])
            ->test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'ai-intake', 'context' => ['customer' => $this->customer->id]])
            ->assertRedirect(OperationsPages::intakePanelUrl($intake->id, $this->customer->id));
        $this->noBusinessChanges($intake, $before);
    }

    public function test_legacy_navigation_rejects_unknown_foreign_customer_and_stale_record_context(): void
    {
        $intake = $this->intake();
        $this->rejected(fn () => Livewire::test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'ai-intake',
            'context' => ['source' => 'ai-intake', 'record' => 99999]]), 404);
        $this->rejected(fn () => Livewire::test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'ai-intake',
            'context' => ['source' => 'ai-intake', 'record' => $intake->id, 'customer' => $this->customer->id]]), 404);
        $this->rejected(fn () => Livewire::test(CaseWorkspace::class, ['initialView' => 'inbox', 'initialSection' => 'ai-intake',
            'context' => ['source' => 'ai-intake', 'record' => $intake->id, 'revision' => 2]]), 409);
    }

    public function test_internal_ai_capability_preserves_projection_dedup_and_scoped_panel_drilldown(): void
    {
        $intake = $this->intake(customerId: $this->customer->id);
        $inquiry = OperationInquiry::create(['customer_id' => $this->customer->id, 'channel' => 'manual',
            'title' => 'Synthetic linked native inquiry', 'original' => 'Synthetic original', 'status' => 'new',
            'timezone' => 'Europe/Berlin', 'revision' => 1, 'created_by' => $this->actor->id]);
        AiIntakeProposal::create(['intake_id' => $intake->id, 'source_revision' => 1, 'position_index' => 0,
            'status' => 'proposed', 'inquiry_id' => $inquiry->id, 'payload' => ['demand' => [], 'segments' => []]]);
        $this->assertArrayHasKey('ai-intake', CaseWorkspace::availableSections($this->actor));
        $rows = UnifiedInboxProjection::query($this->actor, $this->customer->id)->get();
        $this->assertSame(['ai'], $rows->pluck('kind')->all());
        Livewire::test(UnifiedInbox::class, ['customerId' => $this->customer->id])->call('openRow', $intake->id * 8 + 1)
            ->assertDispatched('open-ai-intake-panel', intakeId: $intake->id, create: false, customerId: $this->customer->id)
            ->assertSet('detail', null)->assertDontSeeLivewire(AiIntakeInbox::class);
        $this->assertSame('new', $inquiry->fresh()->status);
        $this->assertSame('proposed', $intake->proposals()->sole()->status);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unified_capture_and_case_section_actions_open_the_scoped_panel_without_creating_records(): void
    {
        Livewire::test(UnifiedInbox::class, ['customerId' => $this->customer->id])->call('create', 'ai')
            ->assertDispatched('open-ai-intake-panel', intakeId: null, create: true, customerId: $this->customer->id)
            ->assertSet('detail', null);
        Livewire::test(CaseWorkspace::class, ['context' => ['customer' => $this->customer->id]])->call('setSection', 'ai-intake')
            ->assertDispatched('open-ai-intake-panel', intakeId: null, create: false, customerId: $this->customer->id)
            ->assertNoRedirect();
        $this->assertSame(0, AiIntake::count());
        $this->assertSame(0, OperationInquiry::count());
        Queue::assertNothingPushed();
    }

    public function test_worklist_destination_uses_the_exact_canonical_panel_and_customer(): void
    {
        $intake = $this->intake(customerId: $this->customer->id);
        $this->assertSame(OperationsPages::intakePanelUrl($intake->id, $this->customer->id),
            app(UnifiedOperationsInboxService::class)->destination('ai-intake-'.$intake->id, $this->actor));
    }

    public function test_global_chat_event_opens_the_complete_scoped_child_and_preserves_it_across_tabs(): void
    {
        $intake = $this->intake(customerId: $this->customer->id);
        $before = $intake->getRawOriginal();
        $host = Livewire::test(Chatbot::class)->dispatch('open-ai-intake-panel', intakeId: $intake->id, create: false, customerId: $this->customer->id)
            ->assertSet('operationsTab', 'intake')->assertSet('operationsIntakeId', $intake->id)
            ->assertSet('operationsIntakeCustomerId', $this->customer->id)->assertSet('operationsIntakeMounted', true)
            ->assertDispatched('railtime-assistant-open')->assertSeeLivewire(AiIntakeInbox::class)
            ->assertSee('Nachrichten und Anlagen')->assertDontSee('Vollständige Annahme öffnen');
        $children = $host->snapshot['memo']['children'];
        $this->assertNotEmpty($children);
        $host->call('setOperationsTab', 'chat')->assertDispatched('ai-intake-panel-hidden');
        $this->assertSame($children, $host->snapshot['memo']['children']);
        $host->call('setOperationsTab', 'activity')->call('setOperationsTab', 'intake');
        $this->assertSame($children, $host->snapshot['memo']['children']);
        $this->noBusinessChanges($intake, $before);
    }

    public function test_global_host_reuses_same_customer_child_and_replaces_it_for_a_new_customer(): void
    {
        $first = $this->intake(customerId: $this->customer->id);
        $second = $this->intake('Second synthetic panel intake', $this->customer->id);
        $host = Livewire::test(Chatbot::class)->call('openOperationsIntake', $first->id, false, $this->customer->id);
        $children = $host->snapshot['memo']['children'];
        $host->call('openOperationsIntake', $second->id, false, $this->customer->id)
            ->assertDispatched('open-ai-intake', intakeId: $second->id, create: false)->assertSet('operationsIntakeId', $second->id);
        $this->assertSame($children, $host->snapshot['memo']['children']);
        $otherCustomer = Customer::create(['company_name' => 'Other synthetic panel customer', 'is_active' => true]);
        $host->call('openOperationsIntake', null, true, $otherCustomer->id)
            ->assertSet('operationsIntakeCustomerId', $otherCustomer->id)->assertSet('operationsIntakeCapture', true);
        $this->assertNotSame($children, $host->snapshot['memo']['children']);
        $this->assertSame(2, AiIntake::count());
    }

    public function test_global_host_rejects_foreign_scope_and_rechecks_deactivation_before_opening(): void
    {
        $intake = $this->intake();
        $before = $intake->getRawOriginal();
        $host = Livewire::test(Chatbot::class);
        $this->rejected(fn () => $host->call('openOperationsIntake', $intake->id, false, $this->customer->id), 404);
        $host = Livewire::test(Chatbot::class);
        $this->actor->update(['status' => false]);
        $this->rejected(fn () => $host->call('openOperationsIntake', $intake->id), 403);
        $this->noBusinessChanges($intake, $before);
    }

    public function test_global_host_source_and_customer_properties_cannot_be_forged(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Chatbot::class)->set('operationsIntakeCustomerId', $this->customer->id);
    }

    public function test_both_assistant_modes_mount_canonical_query_context_with_only_one_visible_host(): void
    {
        $intake = $this->intake(customerId: $this->customer->id);
        $url = OperationsPages::intakePanelUrl($intake->id, $this->customer->id);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-railtime-chatbot-root'));
        $this->assertSame(1, substr_count($html, 'data-ai-intake-inbox'));
        $this->assertStringNotContainsString('rt-ai-assist__launcher', $html);
        $this->assertStringNotContainsString('case-ai-intake-', $html);
        AssistantSettings::setEnabled(false);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-railtime-chatbot-root', $html);
        $this->assertSame(1, substr_count($html, 'class="rt-ai-assist__launcher"'));
        $this->assertSame(1, substr_count($html, 'data-ai-intake-inbox'));
    }
}
