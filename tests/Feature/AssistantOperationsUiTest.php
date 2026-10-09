<?php

namespace Tests\Feature;

use App\Livewire\Tools\Chatbot;
use App\Models\AiIntake;
use App\Models\User;
use App\Support\Ai\AssistantSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AssistantOperationsUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['name' => 'Assistant QA', 'role' => 'admin', 'status' => true]));
    }

    public function test_enabled_global_chat_is_the_single_assistant_on_operations_pages(): void
    {
        AssistantSettings::setEnabled(true);
        $html = $this->get('/arbeitsplatz/ansicht/cases?view=inbox')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-railtime-chatbot-root'));
        $this->assertStringNotContainsString('rt-ai-assist__launcher', $html);
        $this->assertStringContainsString('AI-Annahme', $html);
        $this->assertStringContainsString('railtime-assistant-tab-activity', $html);
    }

    public function test_disabled_chat_keeps_the_independent_disposition_assistant(): void
    {
        AssistantSettings::setEnabled(false);
        $html = $this->get('/arbeitsplatz/ansicht/cases?view=inbox')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-railtime-chatbot-root', $html);
        $this->assertSame(1, substr_count($html, 'class="rt-ai-assist__launcher"'));
        $this->assertStringContainsString('Was steht als Nächstes an?', $html);
    }

    public function test_intake_and_activity_tabs_show_real_state_and_explicit_capture(): void
    {
        AiIntake::create(['title' => 'Eingang zur Prüfung', 'source_type' => 'manual', 'status' => 'review']);
        $chat = Livewire::test(Chatbot::class)->call('setOperationsTab', 'intake');
        $chat->assertSee('Eingang zur Prüfung')->assertSee('Als AI-Eingang erfassen')->assertSee('Automatik pausiert');
        $chat->call('setOperationsTab', 'activity')->assertSee('Eingang zur Prüfung')->assertSee('Aktivitäten filtern');
        $this->assertSame(1, AiIntake::count());
        $chat->call('setOperationsTab', 'chat')->assertSee('Dispositionsaktionen im Chat');
    }
}
