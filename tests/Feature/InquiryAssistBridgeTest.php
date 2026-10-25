<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiAssist;
use App\Models\AiIntake;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatClient;
use App\Services\Ai\OpenRouterChatResponse;
use App\Services\Operations\AiIntakeAssistantService;
use App\Services\Operations\AiIntakeManualService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class InquiryAssistBridgeTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private OperationInquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php',
            '2026_10_25_110000_add_manual_processing_mode_to_ai_intakes.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        $customer = Customer::create(['company_name' => 'Synthetic inquiry customer', 'is_active' => true]);
        $this->inquiry = OperationInquiry::create(['customer_id' => $customer->id, 'title' => 'Current native form', 'original' => 'Original incoming source',
            'channel' => 'phone', 'timezone' => 'Europe/Berlin', 'status' => 'new', 'revision' => 1, 'created_by' => $this->actor->id]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['revision' => 5, 'enabled' => false]));
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
    }

    public function test_optional_assist_opens_same_native_source_without_automatic_processing_or_duplicate_inquiry(): void
    {
        Livewire::test(AiAssist::class, ['page' => 'cases', 'view' => 'inbox'])
            ->dispatch('open-inquiry-assist', inquiryId: $this->inquiry->id, revision: 1, customerId: $this->inquiry->customer_id)
            ->assertSet('tab', 'intake')->assertSet('intakeId', 1)->assertDispatched('ai-assist-intake-opened');
        Livewire::test(AiAssist::class)->call('openInquiryAssist', $this->inquiry->id, 1, $this->inquiry->customer_id)->assertSet('intakeId', 1);
        $intake = AiIntake::firstOrFail();
        $this->assertSame('manual', $intake->processing_mode);
        $this->assertSame($this->inquiry->id, $intake->proposals()->sole()->inquiry_id);
        $this->assertSame(1, AiIntake::count());
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(0, $intake->runs()->count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_manual_ai_request_compares_original_and_live_form_and_remains_advisory(): void
    {
        $intake = app(AiIntakeManualService::class)->forInquiryAssist($this->inquiry, $this->actor, 1);
        $this->inquiry->update(['title' => 'Later saved native form', 'revision' => 2]);
        $before = $this->inquiry->fresh()->getRawOriginal();
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        // Manual ownership routes even an extraction instruction to one advisory answer.
        $provider->shouldReceive('complete')->once()->andReturnUsing(function ($messages) {
            $payload = json_decode($messages[1]['content'], true);
            $this->assertSame('manual', $payload['processing_mode']);
            $this->assertSame('Later saved native form', $payload['current_form_positions'][0]['inquiry']['title']);
            $this->assertStringContainsString('Original incoming source', $payload['received_messages'][0]['text']);

            return new OpenRouterChatResponse('{"answer":"Die aktuelle Bezeichnung bitte mit dem Original abgleichen."}');
        });
        $result = app(AiIntakeAssistantService::class)->respond($intake, $this->actor, $intake->revision, 'Bitte die Erkennung korrigieren.', expectedSettingsRevision: 5);
        $this->assertSame('question', $result['action']);
        $this->assertSame($before, $this->inquiry->fresh()->getRawOriginal());
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(0, $intake->deliveries()->count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_neutral_return_does_not_restore_a_previously_open_assist_window(): void
    {
        Livewire::withQueryParams(['source' => 'ai-intake', 'record' => 1])
            ->test(AiAssist::class, ['page' => 'cases', 'view' => 'inbox'])
            ->assertSet('intakeRequested', false)
            ->assertSeeHtml('restoreWindowSession({...rtWindowSession, open: false})');
    }

    public function test_native_form_change_during_ai_answer_discards_stale_result(): void
    {
        $intake = app(AiIntakeManualService::class)->forInquiryAssist($this->inquiry, $this->actor, 1);
        $provider = $this->mock(OpenRouterChatClient::class);
        $provider->shouldReceive('isConfiguredFor')->andReturnTrue();
        $provider->shouldReceive('complete')->once()->andReturnUsing(function () {
            $this->inquiry->update(['revision' => 2, 'title' => 'Concurrent human edit']);

            return new OpenRouterChatResponse('{"answer":"Obsolete answer"}');
        });
        try {
            app(AiIntakeAssistantService::class)->ask($intake, $this->actor, $intake->revision, 'Was fehlt?', expectedSettingsRevision: 5);
            $this->fail('A stale advisory answer must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('failed', $intake->runs()->sole()->status);
        $this->assertSame('Concurrent human edit', $this->inquiry->fresh()->title);
    }
}
