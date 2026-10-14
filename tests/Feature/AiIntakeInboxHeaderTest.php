<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiIntakeInbox;
use App\Models\AiIntake;
use App\Models\Setting;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiIntakeInboxHeaderTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    public function createApplication(): Application
    {
        $compiled = sys_get_temp_dir().'/railtime-intake-header-'.getmypid().'-'.bin2hex(random_bytes(6));
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
        Setting::setValue(AiDispositionSettings::GROUP, AiDispositionSettings::KEY, array_replace(AiDispositionSettings::DEFAULTS, ['enabled' => false, 'revision' => 5]));
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    private function intake(array $changes = []): AiIntake
    {
        $record = AiIntake::create(array_replace(['source_type' => 'manual', 'status' => 'review', 'title' => 'Very long intake title '.str_repeat('Synthetic Hamburg service ', 12),
            'summary' => 'Extended summary '.str_repeat('Original information for review. ', 16), 'revision' => 1, 'source_revision' => 1, 'analysis' => [], 'missing_fields' => [], 'created_by' => $this->actor->id], $changes));
        $message = $record->messages()->create(['direction' => 'inbound', 'body' => 'Synthetic original', 'metadata' => ['subject' => 'Synthetic intake header']]);
        $record->update(['latest_inbound_message_id' => $message->id]);

        return $record;
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

    public function test_embedded_detail_has_one_header_with_compact_identity_and_full_details_only_in_disclosure(): void
    {
        $record = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $record->id]);
        $dom = $this->dom($component->html());
        $this->assertSame(1, $dom->query('//*[@data-ai-intake-record-header]')->length);
        $this->assertSame(1, $dom->query('//*[contains(@class,"ai-intake-panel__record-navigation")]/header')->length);
        $this->assertSame(1, $dom->query('//*[@data-ai-intake-record-header]/h2')->length);
        $heading = $dom->query('//*[@data-ai-intake-record-header]/h2')->item(0);
        $this->assertSame($record->title, $heading->textContent);
        $this->assertSame($record->title, $heading->getAttribute('title'));
        $this->assertSame('intakePanelHeading', $heading->getAttribute('x-ref'));
        $this->assertSame('-1', $heading->getAttribute('tabindex'));
        $this->assertSame(1, $dom->query('//*[@data-ai-intake-record-header]/button[@*[name()="wire:click"]="closeDetail"]')->length);
        $this->assertSame(0, $dom->query('//*[@data-ai-intake-record-header]/p')->length);
        $this->assertSame(1, $dom->query('//template//*[@class="ai-intake-panel__record-summary"]')->length);
        $this->assertSame($record->summary, $dom->query('//template//*[@class="ai-intake-panel__record-summary"]')->item(0)->textContent);
    }

    public function test_info_disclosure_uses_native_hover_focus_tap_and_matching_dialog_controls(): void
    {
        $record = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $record->id]);
        $dom = $this->dom($component->html());
        $anchor = $dom->query('//*[@data-ai-intake-record-header]//*[@data-rt-dropdown-root]')->item(0);
        $this->assertNotNull($anchor);
        $this->assertStringContainsString('rtAnchorDropdown', $anchor->getAttribute('x-data'));
        $this->assertStringContainsString('openOnHover', $anchor->getAttribute('x-data'));
        $trigger = $dom->query('//*[@data-ai-intake-record-header]//*[@data-rt-dropdown-trigger]/button')->item(0);
        $this->assertSame('dialog', $trigger->getAttribute('aria-haspopup'));
        $this->assertSame('Titel, Zusammenfassung und Bearbeitungsstand anzeigen', $trigger->getAttribute('aria-label'));
        $panel = $dom->query('//*[@id="'.$trigger->getAttribute('aria-controls').'"]')->item(0);
        $this->assertNotNull($panel);
        $this->assertSame('dialog', $panel->getAttribute('role'));
        $this->assertSame('Eingangsdetails', $panel->getAttribute('aria-label'));
        // libxml strips Alpine attributes beginning with @; verify the rendered directive directly.
        $this->assertStringContainsString('@focus="if ($el.matches(\':focus-visible\') && !$event.relatedTarget?.closest(\'[data-rt-dropdown-owner]\')) openDropdown(false)"', $component->html());
        $this->assertSame(1, $dom->query('//template//button[@aria-label="Eingangsdetails schließen"]')->length);
        $this->assertStringContainsString('@click.stop="close(true)"', $component->html());
        $this->assertStringContainsString('max-width:calc(100vw - 24px)', $component->html());
    }

    public function test_every_native_status_is_named_even_when_only_its_icon_is_inline(): void
    {
        foreach (AiIntakeInbox::LABELS as $status => $label) {
            $record = $this->intake(['status' => $status, 'title' => 'Status fixture '.$status, 'summary' => '']);
            $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $record->id]);
            $dom = $this->dom($component->html());
            $icon = $dom->query('//*[@data-ai-intake-record-header]/span[@role="img"]')->item(0);
            $this->assertSame('Bearbeitung: '.$label, $icon->getAttribute('aria-label'));
            $this->assertSame($status, $icon->getAttribute('data-state'));
            $this->assertSame('', trim($icon->textContent));
            $this->assertStringContainsString($label, $dom->query('//template//*[@class="ai-intake-panel__record-details-meta"]')->item(0)->textContent);
        }
    }

    public function test_untrusted_title_and_summary_are_escaped_and_empty_summary_adds_no_header_block(): void
    {
        $record = $this->intake(['title' => '<script>untrusted title</script>', 'summary' => '<img src=x onerror="untrusted()">']);
        Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $record->id])
            ->assertSeeHtml('&lt;script&gt;untrusted title&lt;/script&gt;')->assertSeeHtml('&lt;img src=x onerror=&quot;untrusted()&quot;&gt;')
            ->assertDontSeeHtml('<script>untrusted title</script>')->assertDontSeeHtml('<img src=x onerror="untrusted()">');
        $record->update(['summary' => '']);
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true, 'initialIntakeId' => $record->id]);
        $this->assertSame(0, $this->dom($component->html())->query('//*[@class="ai-intake-panel__record-summary"]')->length);
    }

    public function test_modal_only_keeps_its_existing_full_native_detail_header(): void
    {
        $record = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['modalOnly' => true, 'initialIntakeId' => $record->id])->assertSee($record->title)->assertSee($record->summary);
        $dom = $this->dom($component->html());
        $this->assertSame(0, $dom->query('//*[@data-ai-intake-record-header]')->length);
        $this->assertSame(0, $dom->query('//*[@class="ai-intake-panel__record-info-button"]')->length);
        $this->assertStringContainsString('AI-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT), $component->html());
    }

    public function test_compact_header_preserves_the_stable_composer_wrapper_and_selected_tab_flow(): void
    {
        $record = $this->intake();
        $component = Livewire::test(AiIntakeInbox::class, ['embedded' => true]);
        $before = $this->dom($component->html())->query('//*[contains(@class,"ai-intake-panel__composer")]')->item(0)->getAttribute('wire:key');
        $this->assertStringStartsWith('intake-composer-host-', $before);
        $component->call('select', $record->id)->call('setDetailTab', 'assistant')->assertSet('selectedId', $record->id)->assertSet('detailTab', 'assistant');
        $this->assertSame($before, $this->dom($component->html())->query('//*[contains(@class,"ai-intake-panel__composer")]')->item(0)->getAttribute('wire:key'));
        $component->call('closeDetail')->assertSet('selectedId', null)->assertSet('detailOpen', false);
        $this->assertSame($before, $this->dom($component->html())->query('//*[contains(@class,"ai-intake-panel__composer")]')->item(0)->getAttribute('wire:key'));
        $this->assertSame(0, $this->dom($component->html())->query('//*[@data-ai-intake-record-header]')->length);
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
