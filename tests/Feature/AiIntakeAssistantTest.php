<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeAssistant;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeRun;
use App\Models\OperationInquiry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\AiIntakeAssistantService;
use App\Services\Operations\AiIntakeMessageContent;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeAssistantTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        config(['view.compiled' => sys_get_temp_dir().'/railtime-intake-assistant-ui-'.getmypid()]);
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['revision' => 5]));
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
    }

    private function intake(array $changes = []): AiIntake
    {
        return AiIntake::create(array_replace(['source_type' => 'email', 'status' => 'review', 'revision' => 1, 'source_revision' => 1,
            'title' => 'Synthetic selected intake', 'created_by' => $this->actor->id], $changes));
    }

    private function history(AiIntake $intake, array $changes = [], array $configuration = []): AiIntakeRun
    {
        return $intake->runs()->create(array_replace(['kind' => 'intake_question', 'status' => 'succeeded', 'source_revision' => $intake->source_revision,
            'settings_revision' => 5, 'supervising_user_id' => $this->actor->id, 'input_hash' => str_repeat('a', 64),
            'configuration' => array_replace(['mode' => 'intake_question', 'actor_id' => $this->actor->id, 'intake_revision' => $intake->revision,
                'extraction_version' => AiIntakeMessageContent::VERSION, 'prompt' => 'Current matching question'], $configuration),
            'result' => ['answer' => 'Current matching answer'], 'started_at' => now()->utc(), 'finished_at' => now()->utc()], $changes));
    }

    private function unchangedBusiness(): void
    {
        $this->assertSame(0, OperationInquiry::count());
        $this->assertSame(0, AiIntakeDelivery::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_selection_and_actions_are_accessible_and_the_source_link_uses_native_identity(): void
    {
        $intake = $this->intake(['title' => '<img src=x onerror=alert(1)>']);
        $component = Livewire::test(AiIntakeAssistant::class)->assertSee('Eingang mit AI besprechen')->assertDontSee('Frage stellen')
            ->dispatch('select-ai-intake-assistant', intakeId: $intake->id)->assertSet('intakeId', $intake->id)
            ->assertSee('Frage stellen')->assertSee('data-rt-custom-select', false)->assertSee('Frage oder Prüfhinweis')->assertDispatched('ai-intake-assistant-selected');
        preg_match('/data-rt-select-options="([^"]+)"/', $component->html(), $options);
        $this->assertContains('Mit Hinweis erneut prüfen', array_column(json_decode(html_entity_decode($options[1], ENT_QUOTES), true), 'label'));
        $this->assertStringNotContainsString('<img src=x onerror=', $component->html());
        $this->assertStringContainsString('source=ai-intake', $component->html());
        $this->assertStringContainsString('record='.$intake->id, $component->html());
        $this->assertStringContainsString('wire:loading.attr="inert"', $component->html());
        $this->assertStringContainsString('aria-describedby="intake-assistant-help-', $component->html());
        $this->unchangedBusiness();
    }

    public function test_questions_display_only_current_actor_source_revision_and_settings_history(): void
    {
        $intake = $this->intake();
        $foreign = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->history($intake, ['result' => ['answer' => 'Private foreign answer'], 'supervising_user_id' => $foreign->id], ['actor_id' => $foreign->id]);
        $this->history($intake, ['source_revision' => 2, 'result' => ['answer' => 'Other source answer']]);
        $this->history($intake, ['result' => ['answer' => 'Other intake revision answer']], ['intake_revision' => 2]);
        $this->history($intake, ['settings_revision' => 4, 'result' => ['answer' => 'Other settings answer']]);
        $this->history($intake, ['result' => ['answer' => 'Old parser answer']], ['extraction_version' => 'old-fixture']);
        $this->history($intake, ['result' => ['answer' => '<script>synthetic</script> Current matching answer']]);

        $component = Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->assertSee('Current matching question')->assertSee('Current matching answer')
            ->assertDontSee('Private foreign answer')->assertDontSee('Other source answer')->assertDontSee('Other intake revision answer')->assertDontSee('Other settings answer')->assertDontSee('Old parser answer');
        $this->assertStringNotContainsString('<script>synthetic</script>', $component->html());
        $this->unchangedBusiness();
    }

    public function test_asking_delegates_exact_selected_context_and_displays_persisted_reply_without_business_mutation(): void
    {
        $intake = $this->intake();
        $service = $this->mock(AiIntakeAssistantService::class);
        $service->shouldReceive('ask')->once()->with(Mockery::on(fn ($value) => $value->id === $intake->id), Mockery::on(fn ($value) => $value->id === $this->actor->id), 1, 'Warum fehlen Tabellenzeilen?', [], null, 5)
            ->andReturnUsing(function () use ($intake): array {
                $run = $this->history($intake, ['result' => ['answer' => 'Die zweite Tabellenzeile muss geprüft werden.']], ['prompt' => 'Warum fehlen Tabellenzeilen?']);

                return ['answer' => $run->result['answer'], 'run_id' => $run->id];
            });

        Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->set('prompt', '  Warum fehlen Tabellenzeilen?  ')->call('ask')
            ->assertHasNoErrors()->assertSet('prompt', '')->assertSee('Die zweite Tabellenzeile muss geprüft werden.');
        $this->assertSame(1, $intake->fresh()->revision);
        $this->unchangedBusiness();
    }

    public function test_review_requires_nonempty_guidance_and_delegates_the_locked_revisions(): void
    {
        $intake = $this->intake();
        $service = $this->mock(AiIntakeService::class);
        $service->shouldReceive('analyzeManually')->once()->with(Mockery::on(fn ($value) => $value->id === $intake->id), Mockery::on(fn ($value) => $value->id === $this->actor->id), 1, 5, 'Bitte Tabelle zwei beachten.')
            ->andReturnUsing(function () use ($intake): AiIntake {
                $intake->update(['revision' => 3]);

                return $intake->fresh();
            });

        Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->call('review')->assertHasErrors('prompt')
            ->set('prompt', 'Bitte Tabelle zwei beachten.')->call('review')->assertHasNoErrors()->assertSet('intakeRevision', 3)->assertSet('prompt', '')
            ->assertSee('Die Mail wurde mit deinem Hinweis erneut erkannt.')->assertDispatched('disposition-mailbox-updated');
        $this->assertFalse(AiDispositionSettings::enabled());
        $this->unchangedBusiness();
    }

    public function test_changed_source_resets_input_and_history_before_any_service_call(): void
    {
        $intake = $this->intake();
        $this->history($intake);
        $service = $this->mock(AiIntakeAssistantService::class);
        $service->shouldNotReceive('ask');
        $component = Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->set('prompt', 'Unsent original question');
        $intake->update(['source_revision' => 2]);

        $component->call('ask')->assertSet('contextChanged', true)->assertSet('prompt', '')->assertDontSee('Current matching answer')->assertSee('Aktuellen Stand laden')
            ->call('reloadContext')->assertSet('contextChanged', false)->assertSet('sourceRevision', 2)->assertSet('prompt', '');
        $this->unchangedBusiness();
    }

    public function test_changed_settings_reset_the_question_without_running_recognition(): void
    {
        $intake = $this->intake();
        $service = $this->mock(AiIntakeService::class);
        $service->shouldNotReceive('analyzeManually');
        $component = Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->set('prompt', 'Unsent guidance');
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['revision' => 6]));

        $component->call('review')->assertSet('contextChanged', true)->assertSet('prompt', '')->call('reloadContext')->assertSet('settingsRevision', 6);
        $this->unchangedBusiness();
    }

    public function test_provider_failures_use_safe_text_and_preserve_the_question_for_retry(): void
    {
        $intake = $this->intake();
        $service = $this->mock(AiIntakeAssistantService::class);
        $service->shouldReceive('ask')->once()->andThrow(new OpenRouterChatException('upstream_http_error', 404, new \RuntimeException('synthetic-secret-provider-body')));

        Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->set('prompt', 'Frage für erneuten Versuch')->call('ask')->assertHasErrors('assistant')
            ->assertSee('Der Anbieter unterstützt das eingestellte AI-Modell oder die angeforderten Funktionen derzeit nicht.')
            ->assertDontSee('synthetic-secret-provider-body')->assertSet('prompt', 'Frage für erneuten Versuch');
        $this->unchangedBusiness();
    }

    public function test_other_intake_types_and_terminal_mails_allow_questions_but_hide_recognition(): void
    {
        foreach ([['source_type' => 'manual'], ['status' => 'not_inquiry'], ['status' => 'completed'], ['status' => 'paused']] as $changes) {
            $intake = $this->intake($changes);
            Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->assertSee('Frage stellen')->assertDontSee('wire:click="review"', false);
        }
        $this->unchangedBusiness();
    }

    public function test_direct_nonemail_review_is_rejected_before_the_recognition_service(): void
    {
        $intake = $this->intake(['source_type' => 'manual']);
        $service = $this->mock(AiIntakeService::class);
        $service->shouldNotReceive('analyzeManually');

        Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->set('prompt', 'Nicht als Mail erkennen.')->call('review')->assertHasErrors('assistant');
        $this->unchangedBusiness();
    }

    public function test_unselected_and_closed_context_never_calls_a_provider(): void
    {
        $intake = $this->intake();
        $service = $this->mock(AiIntakeAssistantService::class);
        $service->shouldNotReceive('ask');
        Livewire::test(AiIntakeAssistant::class)->set('prompt', 'No selected intake')->call('ask')->call('select', $intake->id)->set('prompt', 'Draft to clear')->call('close')
            ->assertSet('intakeId', null)->assertSet('prompt', '')->call('ask')->assertDontSee('Frage stellen');
        $this->unchangedBusiness();
    }

    public function test_selected_identity_and_revisions_cannot_be_forged(): void
    {
        $intake = $this->intake();
        $component = Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('intakeId', $intake->id + 1);
    }

    public function test_revoked_actor_is_checked_again_before_a_question(): void
    {
        $intake = $this->intake();
        $service = $this->mock(AiIntakeAssistantService::class);
        $service->shouldNotReceive('ask');
        $component = Livewire::test(AiIntakeAssistant::class)->call('select', $intake->id)->set('prompt', 'A question');
        User::whereKey($this->actor->id)->update(['status' => false]);

        $component->call('ask')->assertForbidden();
    }
}
