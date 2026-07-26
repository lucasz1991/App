<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Es gibt EIN globales Infomodal. Die Info-Buttons schicken ihren Inhalt per
 * Ereignis dorthin, statt je Button einen eigenen Dialog mitzubringen.
 */
class InfoModalTest extends TestCase
{
    public function test_page_header_only_dispatches_and_carries_no_dialog_of_its_own(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.page-header
                title="Mitarbeiter"
                :help="['title' => 'Hilfe Titel', 'summary' => 'Kurzfassung', 'points' => ['Punkt A', 'Punkt B']]"
            />
        BLADE);

        // Kein eigener Dialog mehr im Seitenkopf ...
        $this->assertStringNotContainsString('role="dialog"', $html);
        $this->assertStringNotContainsString('pageHelpOpen', $html);

        // ... sondern ein Ereignis mit dem Seiteninhalt.
        $this->assertStringContainsString('rt-info:open', $html);
        $this->assertStringContainsString('Hilfe Titel', $html);
        $this->assertStringContainsString('Punkt A', $html);
    }

    public function test_global_modal_can_actually_be_hidden_despite_important_display_utilities(): void
    {
        $modal = File::get(resource_path('views/components/ui/info-modal.blade.php'));

        // Kern des behobenen Fehlers: die Legacy-Datei
        // public/build/css/tailwind.min.css setzt .flex{display:flex!important}.
        // Alpines x-show schreibt ein INLINE display:none, das gegen ein
        // !important verliert — der Dialog blieb dauerhaft offen und liess sich
        // nicht schliessen. Nur x-show.important setzt display:none !important.
        $this->assertStringContainsString('x-show.important="open"', $modal);
        $this->assertStringNotContainsString('x-show="open"', $modal);

        // Vor der Alpine-Initialisierung verborgen halten.
        $this->assertStringContainsString('x-cloak', $modal);

        // Drei Wege zum Schliessen.
        $this->assertStringContainsString('x-on:keydown.escape.window="close()"', $modal);
        $this->assertStringContainsString('x-on:click.self="close()"', $modal);
        $this->assertStringContainsString('x-on:click="close()"', $modal);
    }

    public function test_legacy_stylesheet_still_makes_the_important_modifier_necessary(): void
    {
        $legacy = File::get(public_path('build/css/tailwind.min.css'));

        // Faellt diese Zusicherung, ist die Ursache verschwunden und die
        // .important-Modifier koennen ueberprueft werden. Solange sie haelt,
        // darf kein x-show mit Display-Utility ohne .important existieren.
        $this->assertStringContainsString('.flex{display:flex!important}', $legacy);
    }

    public function test_no_view_has_an_unhideable_x_show_element(): void
    {
        $displayUtilities = ['flex', 'grid', 'block', 'inline-flex', 'inline-block', 'table', 'inline'];
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = File::get($file->getPathname());

            if (! preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$tag, $offset]) {
                // x-show OHNE Modifier ...
                if (! preg_match('/x-show(?![.\w])/', $tag)) {
                    continue;
                }

                if (! preg_match('/\sclass="([^"]*)"/s', $tag, $classMatch)) {
                    continue;
                }

                // ... zusammen mit einer Display-Utility, die !important traegt.
                $classes = preg_split('/\s+/', trim(preg_replace('/\{\{.*?\}\}/s', ' ', $classMatch[1])));
                $clash = array_intersect($classes, $displayUtilities);

                if ($clash === []) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $offenders[] = $file->getRelativePathname().':'.$line.' ['.implode(',', $clash).']';
            }
        }

        $this->assertSame([], $offenders, "Diese Elemente koennen nie ausgeblendet werden (x-show.important fehlt):\n".implode("\n", $offenders));
    }

    public function test_alpine_scope_survives_real_html_attribute_parsing(): void
    {
        // Genau dieser Fehler stand live: ein doppeltes Anfuehrungszeichen in
        // einem x-data-Kommentar beendete das HTML-Attribut mitten im Objekt.
        // Der Alpine-Scope zerbrach, 'open' fiel auf window.open (immer truthy)
        // zurueck — ein leeres, unschliessbares Modal auf jeder Seite.
        // String-Assertions sehen das nicht; nur ein HTML-Parser sieht es.
        $html = Blade::render('<x-ui.info-modal />');

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($document))->query('//*[@data-rt-info-modal]')->item(0);
        $this->assertNotNull($node);

        $xData = $node->getAttribute('x-data');
        $this->assertStringContainsString('show(detail)', $xData);
        $this->assertStringContainsString('close()', $xData);
        $this->assertStringContainsString('open: false', $xData);
        $this->assertSame(substr_count($xData, '{'), substr_count($xData, '}'));
    }

    public function test_global_modal_is_rendered_once_in_every_app_shell(): void
    {
        foreach (['master', 'admin', 'app'] as $layout) {
            $source = File::get(resource_path("views/layouts/{$layout}.blade.php"));

            $this->assertSame(
                1,
                substr_count($source, '<x-ui.info-modal'),
                "Layout {$layout} muss das globale Infomodal genau einmal einbinden.",
            );
        }
    }
}
