<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiAssist;
use App\Livewire\Operations\AiIntakeInbox;
use App\Livewire\Tools\Chatbot;
use App\Models\AiIntake;
use App\Models\User;
use App\Support\Ai\AssistantSettings;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiAssistNavigationHeaderTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    public function createApplication(): Application
    {
        $compiled = sys_get_temp_dir().'/railtime-assist-navigation-'.getmypid().'-'.bin2hex(random_bytes(6));
        mkdir($compiled, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$compiled);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $compiled;

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_103000_create_operations_attention_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php',
            '2026_10_09_090000_create_staffing_automation_runs.php', '2026_10_09_100000_add_ai_customer_communication_controls.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
        AssistantSettings::setEnabled(true);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    private function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function assertHeaderTabs(string $html, string $selected, string $action): void
    {
        $dom = $this->dom($html);
        $this->assertSame(1, $dom->query('//*[@data-assistant-header]')->length);
        $this->assertSame(1, $dom->query('//*[@data-assistant-header]/nav[@data-assistant-navigation]')->length);
        $this->assertSame(1, $dom->query('//*[@data-assistant-navigation]')->length);
        $this->assertSame(4, $dom->query('//*[@data-assistant-navigation]/button[@role="tab"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-assistant-navigation]/button[@aria-selected="true"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-assistant-navigation]/button[@tabindex="0"]')->length);
        foreach (['chat' => 'Chat', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'activity' => 'Aktivitäten'] as $key => $label) {
            $button = $dom->query('//*[@data-assistant-navigation]/button[@data-tab="'.$key.'"]')->item(0);
            $this->assertStringStartsWith($label, $button->getAttribute('aria-label'));
            $this->assertSame($key === $selected ? 'true' : 'false', $button->getAttribute('aria-selected'));
            $this->assertSame($action."('".$key."')", $button->getAttribute('wire:click'));
            $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
            $this->assertSame(1, $dom->query('//*[@id="'.$button->getAttribute('id').'"]/svg[@aria-hidden="true"]')->length);
            $this->assertSame('sr-only', $dom->query('//*[@id="'.$button->getAttribute('id').'"]/span[1]')->item(0)->getAttribute('class'));
        }
        $active = $dom->query('//*[@data-assistant-navigation]/button[@aria-selected="true"]')->item(0);
        $body = $dom->query('//*[@id="'.$active->getAttribute('aria-controls').'"]')->item(0);
        $this->assertNotNull($body);
        $this->assertSame('tabpanel', $body->getAttribute('role'));
        $this->assertSame($active->getAttribute('id'), $body->getAttribute('aria-labelledby'));
        $this->assertSame(0, $dom->query('//nav[contains(@class,"rt-ai-assist__tabs")]')->length);
    }

    public function test_standalone_assist_has_one_header_with_icon_tabs_and_preserves_settings_reset_close_and_context(): void
    {
        $component = Livewire::test(AiAssist::class, ['page' => 'cases', 'view' => 'inbox']);
        $this->assertHeaderTabs($component->html(), 'chat', 'setTab');
        $component->assertSeeHtml('aria-label="Assistenz-Einstellungen"')->assertSeeHtml('aria-label="Neues Gespräch"')
            ->assertSeeHtml('aria-label="AI-Assist schließen"')->assertSeeHtml('x-on:click="toggle(false)"')
            ->assertSeeHtml('data-assistant-cloud-slot="header"')->assertSeeHtml('rt-ai-assist__context--compact');
        $dom = $this->dom($component->html());
        $this->assertSame('AI-Assist', $dom->query('//*[@data-assistant-header]//h2')->item(0)->textContent);
        $this->assertSame(1, $dom->query('//*[@data-assistant-header]//*[@role="dialog" and @aria-label="Assistenz-Einstellungen"]')->length);
    }

    public function test_real_tab_actions_keep_selected_panel_and_persistent_intake_after_leaving_it(): void
    {
        $component = Livewire::test(AiAssist::class, ['page' => 'cases', 'view' => 'inbox']);
        $children = null;
        foreach (['intake', 'actions', 'activity', 'chat'] as $tab) {
            $component->call('setTab', $tab)->assertSet('tab', $tab);
            $this->assertHeaderTabs($component->html(), $tab, 'setTab');
            if ($children === null) {
                $component->assertSeeLivewire(AiIntakeInbox::class);
                $children = $component->snapshot['memo']['children'];
            } else {
                $this->assertSame($children, $component->snapshot['memo']['children']);
            }
        }
        $component->assertSet('intakeMounted', true);
    }

    public function test_permission_gating_still_hides_intake_and_its_badge(): void
    {
        AiIntake::create(['source_type' => 'manual', 'title' => 'Private synthetic pending record', 'status' => 'review', 'revision' => 1, 'source_revision' => 1]);
        $limited = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.manage', fn ($actor) => $actor->is($limited));
        Gate::define('operations.inquiries.manage', fn () => false);
        $component = Livewire::actingAs($limited)->test(AiAssist::class, ['page' => 'cases', 'view' => 'shifts']);
        $dom = $this->dom($component->html());
        $this->assertSame(3, $dom->query('//*[@data-assistant-navigation]/button')->length);
        $this->assertSame(0, $dom->query('//*[@data-assistant-navigation]/button[@data-tab="intake"]')->length);
        $this->assertSame(0, $dom->query('//*[@class="rt-assistant-nav__count"]')->length);
        $component->call('setTab', 'intake')->assertForbidden();
    }

    public function test_review_badge_keeps_full_accessible_count_and_dismissed_originals_do_not_count(): void
    {
        foreach (['review', 'review', 'not_inquiry'] as $index => $status) {
            AiIntake::create(['source_type' => 'manual', 'title' => 'Synthetic review '.$index, 'status' => $status, 'revision' => 1, 'source_revision' => 1]);
        }
        $component = Livewire::test(AiAssist::class, ['page' => 'cases', 'view' => 'inbox']);
        $dom = $this->dom($component->html());
        $button = $dom->query('//*[@data-assistant-navigation]/button[@data-tab="intake"]')->item(0);
        $this->assertSame('AI-Annahme · 2 zur Prüfung', $button->getAttribute('aria-label'));
        $this->assertSame('AI-Annahme · 2 zur Prüfung', $button->getAttribute('data-tooltip-text'));
        $badge = $dom->query('//*[@class="rt-assistant-nav__count"]')->item(0);
        $this->assertSame('2', $badge->textContent);
        $this->assertSame('true', $badge->getAttribute('aria-hidden'));
    }

    public function test_operations_chat_host_reuses_same_icon_header_with_native_tab_and_close_settings_controls(): void
    {
        $component = Livewire::test(Chatbot::class);
        $this->assertHeaderTabs($component->html(), 'chat', 'setOperationsTab');
        $component->assertSeeHtml('x-on:click="toggleSettings()"')->assertSeeHtml('x-on:click="setOpen(false)"')
            ->assertSeeHtml('wire:click="clearChat"')->assertSeeHtml('data-assistant-cloud-slot="header"');
        foreach (['intake', 'actions', 'chat'] as $tab) {
            $component->call('setOperationsTab', $tab)->assertSet('operationsTab', $tab);
            $this->assertHeaderTabs($component->html(), $tab, 'setOperationsTab');
        }
        $component->assertSet('operationsIntakeMounted', true);
    }

    public function test_shared_navigation_uses_native_positioned_tooltip_manual_arrow_activation_and_escaped_labels(): void
    {
        $html = Blade::render('<x-operations.assistant-navigation id-prefix="synthetic-header" controls="synthetic-panel" :tabs="$tabs" selected="chat" :review-count="143" />', [
            'tabs' => ['chat' => '<script>unsafe label</script>', 'intake' => 'AI-Annahme', 'unsupported' => 'Unsupported'],
        ]);
        $this->assertStringNotContainsString('<script>unsafe label</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;unsafe label&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('data-tab="unsupported"', $html);
        $this->assertStringContainsString('143 zur Prüfung', $html);
        $this->assertStringContainsString('>99+</span>', $html);
        $this->assertStringContainsString('role="tooltip"', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('window.visualViewport', $html);
        $this->assertStringContainsString('x-bind:aria-describedby="tooltipOpen', $html);
        $this->assertStringContainsString("['ArrowLeft', 'ArrowRight', 'Home', 'End']", $html);
        $this->assertStringContainsString('buttons[index].focus({ preventScroll: true })', $html);
        $this->assertStringNotContainsString('buttons[index].click()', $html);
        $this->assertStringContainsString('destroy() { this.observer?.disconnect(); }', $html);
    }

    public function test_chat_without_operations_rights_keeps_its_original_header_and_no_operations_navigation(): void
    {
        $ordinary = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('assistant.use', fn () => true);
        Gate::define('operations.manage', fn () => false);
        Gate::define('operations.inquiries.manage', fn () => false);
        $component = Livewire::actingAs($ordinary)->test(Chatbot::class);
        $dom = $this->dom($component->html());
        $this->assertSame(0, $dom->query('//*[@data-assistant-header]')->length);
        $this->assertSame(0, $dom->query('//*[@data-assistant-navigation]')->length);
        $this->assertSame(1, $dom->query('//header[@class="rt-chatbot__header"]')->length);
        $component->assertSeeHtml('x-on:click="toggleSettings()"')->assertSeeHtml('x-on:click="setOpen(false)"');
    }

    protected function tearDown(): void
    {
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
        parent::tearDown();
    }
}
