<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OutlookPreferencesSelectCompatibilityTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';

    private const B = '22222222-2222-4222-8222-222222222222';

    private const STALE = '33333333-3333-4333-8333-333333333333';

    // Exact production Select from release30ccb, normalized only for checkout line endings.
    private const LEGACY_HASH = '3ee096d496df1e665840db5492b432bf08d866a72bc00c2bd52436592afd7b09';

    public static function storedSelections(): array
    {
        $cases = [];
        foreach ([false, true] as $legacy) {
            foreach ([
                'default and B' => ['default', self::B, true, false],
                'none and default' => ['none', 'default', true, true],
                'B and none' => [self::B, 'none', true, false],
                'withdrawn and B' => [self::STALE, self::B, true, true],
                'unavailable default' => ['default', 'none', false, false],
            ] as $name => $data) {
                $cases[($legacy ? 'production legacy: ' : 'installed Select: ').$name] = [$legacy, ...$data];
            }
        }

        return $cases;
    }

    #[DataProvider('storedSelections')]
    public function test_stored_choices_are_selected_in_option_inventory_and_initial_alpine_state_for_both_parsers(
        bool $legacy,
        string $new,
        string $reply,
        bool $hasDefault,
        bool $employee,
    ): void {
        $html = $this->renderUsing($legacy, static function () use ($new, $reply, $hasDefault, $employee): string {
            View::share('errors', new ViewErrorBag);

            return view($employee ? 'livewire.admin.user-profile.outlook-preferences' : 'livewire.profile.outlook-preferences', [
                'instancePrefix' => 'employee-outlook-1-5-fixture', 'targetName' => 'Synthetic employee',
                'preferences' => ['newMessages' => ['signature' => true], 'replies' => ['signature' => false]],
                'available' => true, 'notice' => '', 'greeting' => '', 'farewell' => '',
                'wordingAvailable' => false, 'wordingNotice' => '',
                'choiceSelections' => ['newMessages' => $new, 'replies' => $reply],
                'templateOptions' => [['id' => self::A, 'name' => 'Standard', 'isDefault' => $hasDefault],
                    ['id' => self::B, 'name' => 'Standard – mit Anrede und Gruß', 'isDefault' => false]],
                'choicesAvailable' => true, 'choicesNotice' => '', 'choicesRevision' => str_repeat('a', 64),
            ])->render();
        });
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $controls = (new DOMXPath($document))->query('//*[@data-rt-custom-select]');
        self::assertCount(2, $controls);
        foreach ([$new, $reply] as $index => $expected) {
            $control = $controls->item($index);
            $options = json_decode($control->getAttribute('data-rt-select-options'), true, flags: JSON_THROW_ON_ERROR);
            $selected = array_values(array_filter($options, static fn (array $option): bool => $option['selected'] === true));
            self::assertCount(1, $selected, 'Exactly one stored option must be marked; first-option fallback is incorrect.');
            self::assertSame($expected, $selected[0]['value']);
            self::assertSame($expected === self::STALE || ($expected === 'default' && ! $hasDefault), $selected[0]['disabled']);
            self::assertMatchesRegularExpression('~\bselected:\s*\x27'.preg_quote($expected, '~').'\x27~', $control->getAttribute('x-data'));
        }
    }

    public function test_production_fixture_reproduces_why_value_alone_cannot_select_the_saved_default(): void
    {
        $html = $this->renderUsing(true, static fn (): string => Blade::render(
            '<x-ui.forms.select id="legacy-value-proof" value="default"><option value="none">Keine Vorlage</option><option value="default">Standard</option></x-ui.forms.select>'
        ));
        self::assertMatchesRegularExpression('~selected:\s*\x27none\x27~', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function renderUsing(bool $legacy, \Closure $render): string
    {
        $finder = View::getFinder();
        if ($legacy) {
            $fixtureRoot = base_path('tests/Fixtures/outlook-select-legacy');
            $fixture = file_get_contents($fixtureRoot.'/components/ui/forms/select.blade.php');
            self::assertSame(self::LEGACY_HASH, hash('sha256', str_replace("\r\n", "\n", $fixture)));
            View::setFinder(new FileViewFinder(app('files'), [$fixtureRoot, ...$finder->getPaths()]));
        }
        try {
            return $render();
        } finally {
            View::setFinder($finder);
        }
    }
}
