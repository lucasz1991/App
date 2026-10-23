<?php

namespace Tests\Feature;

use App\Livewire\Operations\AiAssist;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AiAssistResponseRenderingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    public function createApplication(): Application
    {
        $compiled = sys_get_temp_dir().'/railtime-assist-response-'.getmypid().'-'.bin2hex(random_bytes(6));
        mkdir($compiled, 0777, true);
        putenv('VIEW_COMPILED_PATH='.$compiled);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $compiled;

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_07_010000_create_ai_disposition_intake.php'] as $migration) {
            if ($migration === '2026_10_04_120000_create_workforce_personnel_foundations.php' && ! Schema::hasColumn('shifts', 'disposition_details')) {
                Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
            }
            (require database_path('migrations/'.$migration))->up();
        }
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->actor);
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

    private function legacyCard(): array
    {
        return [
            'title' => 'Besetzungsvorschlag', 'icon' => 'fa-sparkles',
            'rows' => [['label' => 'Nürnberg · Rangierdienst · Fr 23.10. 14:00', 'value' => 'Ben Beta', 'small' => 'Keine bekannten Konflikte', 'why' => ['Verfügbar gemeldet']]],
            'note' => 'Übernommene Vorschläge werden als **Angefragt** eingeteilt. Revision und Konflikte werden erneut geprüft.',
            'payload' => [[17, 23, 9]],
            'buttons' => [['label' => 'Im Plan prüfen', 'href' => '/arbeitsplatz/ansicht/cases?view=shifts'], ['label' => 'Vorschlag übernehmen', 'act' => 'apply', 'primary' => true]],
        ];
    }

    private function standaloneHtml(array $messages): string
    {
        session(['operations.ai_assist.messages' => $messages]);

        return Livewire::actingAs($this->actor)->test(AiAssist::class, ['page' => 'cases', 'view' => 'inbox'])->html();
    }

    public function test_legacy_staffing_card_keeps_real_labels_and_reasons_with_actions_outside_the_focusable_list(): void
    {
        $html = Blade::render('<x-operations.assist-response-card :card="$card" card-id="legacy-card"><x-slot:actions><button type="button" data-test="apply">Übernehmen</button></x-slot:actions></x-operations.assist-response-card>', ['card' => $this->legacyCard()]);
        $dom = $this->dom($html);

        $this->assertSame(1, $dom->query('//section[@data-presentation="staffing"]')->length);
        $this->assertSame('Besetzungsvorschlag', $dom->query('//*[@id="legacy-card-title"]')->item(0)->textContent);
        $this->assertSame('Nürnberg · Rangierdienst', $dom->query('//div[@class="rt-ai-response-card__subject"]/strong')->item(0)->textContent);
        $this->assertSame('Fr 23.10. 14:00', $dom->query('//div[@class="rt-ai-response-card__subject"]/span')->item(0)->textContent);
        $this->assertSame(1, $dom->query('//div[@role="region" and @tabindex="0" and @aria-label="Besetzungsvorschlag – scrollbare Liste"]/ul/li')->length);
        $this->assertSame(1, $dom->query('//details[@class="rt-ai-response-card__why"]//p[contains(text(), "Keine bekannten Konflikte")]')->length);
        $this->assertSame(1, $dom->query('//details[@class="rt-ai-response-card__why"]//li[contains(text(), "Verfügbar gemeldet")]')->length);
        $this->assertSame(0, $dom->query('//details[@class="rt-ai-response-card__why"][@open]')->length);
        $this->assertSame(1, $dom->query('//section/footer/button[@data-test="apply"]')->length);
        $this->assertSame(0, $dom->query('//div[@role="region"]//button[@data-test="apply"]')->length);
        $this->assertStringContainsString('Revision und Konflikte werden erneut geprüft.', $html);
        $this->assertStringNotContainsString('Diese Woche', $html);
    }

    public function test_card_values_notes_errors_and_message_bodies_remain_escaped(): void
    {
        $unsafe = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
        $card = $this->legacyCard();
        $card['display_title'] = $unsafe;
        $card['subtitle'] = $unsafe;
        $card['rows'][0] = ['label' => $unsafe, 'heading' => $unsafe, 'detail' => $unsafe, 'value' => $unsafe, 'small' => $unsafe, 'why' => [$unsafe]];
        $card['note'] = $card['summary_note'] = $card['body'] = $unsafe;
        $card['failed'] = [$unsafe];
        $html = Blade::render('<x-operations.assist-response-card :card="$card" card-id="escaped-card" />', ['card' => $card]);
        $dom = $this->dom($html);

        $this->assertSame(0, $dom->query('//script|//img|//*[@onerror]')->length);
        $this->assertSame($unsafe, $dom->query('//*[@id="escaped-card-title"]')->item(0)->textContent);
        $this->assertSame($unsafe, $dom->query('//div[@class="rt-ai-response-card__subject"]/strong')->item(0)->textContent);
        $this->assertSame($unsafe, $dom->query('//details[@class="rt-ai-response-card__body"]/p')->item(0)->textContent);
        $this->assertStringContainsString($unsafe, $dom->query('//*[@role="alert"]')->item(0)->textContent);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_non_staffing_text_and_error_card_keeps_content_without_an_empty_scroll_region(): void
    {
        $card = ['title' => 'Kundennachricht', 'body' => "Guten Tag,\nbitte bestätigen Sie den Einsatzort.", 'rows' => [], 'note' => 'Persönliche Freigabe nötig.', 'failed' => ['Kundenzuordnung hat sich geändert.'], 'expired' => true];
        $html = Blade::render('<x-operations.assist-response-card :card="$card" card-id="text-card" empty-label="Keine Positionen" />', ['card' => $card]);
        $dom = $this->dom($html);

        $this->assertSame(1, $dom->query('//section[@data-presentation="default"]')->length);
        $this->assertSame(0, $dom->query('//div[@role="region"]')->length);
        $this->assertSame(1, $dom->query('//details[@class="rt-ai-response-card__body" and @open]')->length);
        $this->assertSame($card['body'], $dom->query('//details[@class="rt-ai-response-card__body"]/p')->item(0)->textContent);
        $this->assertStringContainsString('Keine Positionen', $html);
        $this->assertStringContainsString('Persönliche Freigabe nötig.', $html);
        $this->assertStringContainsString('Kundenzuordnung hat sich geändert.', $dom->query('//*[@role="alert"]')->item(0)->textContent);
        $this->assertStringContainsString('abgelaufen', $dom->query('//*[@role="status"]')->item(0)->textContent);
        $this->assertSame(0, $dom->query('//footer')->length);
    }

    public function test_standalone_assistant_renders_legacy_cards_and_regular_text_without_moving_apply_inside_the_list(): void
    {
        $html = $this->standaloneHtml([
            ['id' => 1, 'role' => 'user', 'text' => '<script>Frage</script>', 'time' => '09:41'],
            ['id' => 2, 'role' => 'assistant', 'lead' => '**Echte Antwort** <img src=x onerror=alert(1)>', 'time' => '09:41'],
            ['id' => 3, 'role' => 'assistant', 'lead' => 'Eine Schicht ist offen.', 'time' => '09:42', 'card' => $this->legacyCard()],
        ]);
        $dom = $this->dom($html);
        $message = '//div[contains(@class, "rt-ai-assist__messages")]';

        $this->assertSame(1, $dom->query($message.'//section[@data-presentation="staffing"]')->length);
        $this->assertSame(0, $dom->query($message.'//script|'.$message.'//img')->length);
        $this->assertSame(1, $dom->query($message.'//strong[text()="Echte Antwort"]')->length);
        $this->assertSame(1, $dom->query($message.'//section/footer//button[contains(text(), "Vorschlag übernehmen")]')->length);
        $this->assertSame(0, $dom->query($message.'//div[@role="region"]//button[contains(text(), "Vorschlag übernehmen")]')->length);
        $this->assertSame(1, $dom->query($message.'//section/footer//a[contains(text(), "Im Plan prüfen")]')->length);
    }

    public function test_standalone_done_card_only_exposes_undo_in_its_footer(): void
    {
        $card = $this->legacyCard() + ['state' => 'done', 'undo' => [71], 'done_at' => '09:43'];
        $html = $this->standaloneHtml([['id' => 4, 'role' => 'assistant', 'lead' => 'Übernommen.', 'time' => '09:43', 'card' => $card]]);
        $dom = $this->dom($html);
        $cardPath = '//section[@data-state="done" and @data-presentation="staffing"]';

        $this->assertSame(1, $dom->query($cardPath)->length);
        $this->assertSame(1, $dom->query($cardPath.'/footer//button[contains(text(), "Rückgängig")]')->length);
        $this->assertSame(0, $dom->query($cardPath.'//button[contains(text(), "Vorschlag übernehmen")]')->length);
        $this->assertSame(0, $dom->query($cardPath.'//div[@role="region"]//button')->length);
        $this->assertStringContainsString("call('act', 4, 'undo')", $html);
    }

    public function test_tools_partial_keeps_expired_mutations_disabled_but_navigation_available(): void
    {
        $card = $this->legacyCard() + ['expired' => true];
        $card['buttons'] = [['label' => 'Plan öffnen', 'href' => '/arbeitsplatz/ansicht/cases?view=shifts']];
        foreach (['apply', 'approve', 'retry', 'reanalyze', 'prepare'] as $action) {
            $card['buttons'][] = ['label' => $action, 'act' => $action];
        }
        $html = Livewire::test(AssistResponsePartialHarness::class, ['card' => $card])->html();
        $dom = $this->dom($html);

        $this->assertSame(5, $dom->query('//section/footer//button[@disabled]')->length);
        $this->assertSame(1, $dom->query('//section/footer//a[text()="Plan öffnen"]')->length);
        $this->assertSame(0, $dom->query('//div[@role="region"]//button')->length);
        $this->assertStringContainsString('actOperationsCard', $html);
        $this->assertStringContainsString('abgelaufen', $dom->query('//*[@role="status"]')->item(0)->textContent);
    }

    public function test_tools_partial_done_state_uses_only_its_existing_undo_capability(): void
    {
        $card = $this->legacyCard() + ['state' => 'done', 'canUndo' => true];
        $dom = $this->dom(Livewire::test(AssistResponsePartialHarness::class, ['card' => $card])->html());

        $this->assertSame(1, $dom->query('//section/footer//button[text()="Zurücknehmen"]')->length);
        $this->assertSame(1, $dom->query('//section/footer//button')->length);
        $this->assertSame(0, $dom->query('//section/footer//a')->length);

        $card['canUndo'] = false;
        $withoutUndo = $this->dom(Livewire::test(AssistResponsePartialHarness::class, ['card' => $card])->html());
        $this->assertSame(0, $withoutUndo->query('//section/footer//button')->length);
    }
}

class AssistResponsePartialHarness extends Component
{
    public array $card;

    public string $entryKey = 'response-fixture';

    public function render()
    {
        return view('livewire.tools.partials.operations-assist-card');
    }
}
