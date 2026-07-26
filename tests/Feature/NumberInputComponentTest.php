<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Die gemeinsame Zahlen-Eingabe ersetzt <input type="number"> vollstaendig:
 * eigene Schritt-Tasten, Alpine-Mask statt Browser-Spinner.
 */
class NumberInputComponentTest extends TestCase
{
    public function test_component_replaces_the_native_number_input(): void
    {
        $html = Blade::render('<x-ui.forms.number-input min="1" max="365" :nullable="false" unit="Tage" wire:model="days" />');

        // Kein nativer Number-Input und damit keine Browser-Spinner.
        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('type="text"', $html);

        // Ziffern-Tastatur auf Mobilgeraeten + Spinbutton-Semantik.
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('role="spinbutton"', $html);
        $this->assertStringContainsString('aria-valuemin="1"', $html);
        $this->assertStringContainsString('aria-valuemax="365"', $html);

        // Alpine-Mask uebernimmt die Eingabepruefung.
        $this->assertStringContainsString('x-mask:dynamic', $html);
        $this->assertStringContainsString('rtNumberInput(', $html);

        // Bindung und Einheit werden durchgereicht.
        $this->assertStringContainsString('wire:model="days"', $html);
        $this->assertStringContainsString('Tage', $html);
    }

    public function test_mask_never_adds_thousands_separators(): void
    {
        $html = Blade::render('<x-ui.forms.number-input step="0.01" :decimals="2" x-model="value" />');

        $this->assertStringContainsString('inputmode="decimal"', $html);

        // Der dritte $money-Parameter MUSS leer bleiben. Mit Tausenderpunkt
        // wuerde aus 1234,5 "1.234,5" — die Weiterverarbeitung im Frontend
        // ersetzt nur das Komma und wuerde Werte ab 1000 still verfaelschen
        // (siehe numeric() in resources/js/wagon-list-prototype.js). Geprueft
        // wird in der Quelle, weil Blade die Quotes im Attribut maskiert.
        $component = File::get(resource_path('views/components/ui/forms/number-input.blade.php'));
        $this->assertStringContainsString("\$money(\$input, separator, \\'\\', decimals)", $component);
    }

    public function test_stepper_controls_are_not_labelable_elements(): void
    {
        $html = Blade::render('<x-ui.forms.number-input min="0" x-model="value" />');

        // Die Komponente wird auch in <label> verwendet. Ein <button> waere ein
        // labelable element — ein Klick auf den Beschriftungstext wuerde dann
        // den Wert veraendern. Deshalb role="button" an einem <span>.
        $this->assertSame(0, substr_count($html, '<button'));
        $this->assertSame(2, substr_count($html, 'role="button"'));
    }

    public function test_compact_variant_renders_only_the_masked_field(): void
    {
        $html = Blade::render('<x-ui.forms.number-input :stepper="false" min="0" x-model="value" class="cell" />');

        $this->assertSame(0, substr_count($html, 'role="button"'));
        $this->assertStringContainsString('x-mask:dynamic', $html);
        // x-data sitzt direkt am Feld, damit im Tabellenraster kein
        // zusaetzliches Element entsteht.
        $this->assertMatchesRegularExpression('/<input[^>]*x-data="rtNumberInput\(/', $html);
        // Die uebergebene Klasse bleibt erhalten (merge stellt die
        // Komponenten-Klasse voran), damit Rasterzellen ihr Layout behalten.
        $this->assertMatchesRegularExpression('/class="[^"]*\bcell\b[^"]*"/', $html);
        $this->assertStringContainsString('tabular-nums', $html);
    }

    public function test_no_blade_view_uses_a_native_number_input_anymore(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Die Komponente selbst erklaert im Kommentar, was sie ersetzt.
            if ($file->getFilename() === 'number-input.blade.php') {
                continue;
            }

            if (str_contains(File::get($file->getPathname()), 'type="number"')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Diese Views nutzen noch einen nativen Number-Input: '.implode(', ', $offenders));
    }

    public function test_invitation_expiry_uses_the_component_and_cannot_be_emptied(): void
    {
        $settings = File::get(resource_path('views/livewire/admin/settings.blade.php'));

        $this->assertStringContainsString('<x-ui.forms.number-input', $settings);
        // Das Property ist als int typisiert — ein leeres Feld wuerde beim
        // Speichern einen Typfehler ausloesen, deshalb nullable=false.
        $this->assertStringContainsString(':nullable="false"', $settings);
        $this->assertStringContainsString('wire:model="invitationExpiryDays"', $settings);
    }

    public function test_wagon_list_uses_the_component_everywhere(): void
    {
        $grid = File::get(resource_path('views/livewire/operations/partials/wagon-sheet-grid.blade.php'));
        $prototype = File::get(resource_path('views/livewire/operations/wagon-list-prototype.blade.php'));

        // Im dichten Raster ohne Schritt-Tasten ...
        $this->assertStringContainsString(':stepper="false"', $grid);
        $this->assertGreaterThanOrEqual(9, substr_count($grid, '<x-ui.forms.number-input'));
        // ... in der Mobilansicht mit.
        $this->assertGreaterThanOrEqual(15, substr_count($prototype, '<x-ui.forms.number-input'));
    }
}
