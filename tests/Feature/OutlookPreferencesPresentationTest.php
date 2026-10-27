<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OutlookPreferencesPresentationTest extends TestCase
{
    private string $runtimePath;

    private array $viewEnvironment;

    public function createApplication(): Application
    {
        $this->runtimePath = sys_get_temp_dir().'/railtime-outlook-preferences-ui-'.bin2hex(random_bytes(8));
        mkdir($this->runtimePath.'/views', 0700, true);
        $this->viewEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        putenv('VIEW_COMPILED_PATH='.$this->runtimePath.'/views');
        $_ENV['VIEW_COMPILED_PATH'] = $this->runtimePath.'/views';
        $_SERVER['VIEW_COMPILED_PATH'] = $this->runtimePath.'/views';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
        DB::connection()->enableQueryLog();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$original, $env, $server] = $this->viewEnvironment;
        putenv($original === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$original);
        if ($env === null) {
            unset($_ENV['VIEW_COMPILED_PATH']);
        } else {
            $_ENV['VIEW_COMPILED_PATH'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['VIEW_COMPILED_PATH']);
        } else {
            $_SERVER['VIEW_COMPILED_PATH'] = $server;
        }
        $resolved = str_replace('\\', '/', (string) realpath($this->runtimePath));
        $expected = rtrim(str_replace('\\', '/', (string) realpath(sys_get_temp_dir())), '/').'/railtime-outlook-preferences-ui-';
        if (str_starts_with($resolved, $expected)) {
            (new Filesystem)->deleteDirectory($this->runtimePath);
        }
    }

    public static function contexts(): array
    {
        return ['own profile' => ['self'], 'employee profile' => ['employee']];
    }

    #[DataProvider('contexts')]
    public function test_both_profile_contexts_keep_flat_paired_native_controls_and_all_wire_contracts(string $context): void
    {
        $html = $this->render(['context' => $context]);
        $xpath = $this->xpath($html);
        $section = $xpath->query('//*[@data-testid="profile-outlook-preferences"]')->item(0);
        self::assertSame($context, $section->getAttribute('data-outlook-preferences-context'));
        self::assertStringNotContainsString('shadow-', $section->getAttribute('class'));
        self::assertCount(2, $xpath->query('//*[@data-rt-custom-select]'));
        self::assertCount(2, $xpath->query('//input[@role="switch"]'));
        foreach (['newMessages' => true, 'replies' => false] as $group => $checked) {
            $fieldset = $xpath->query('//fieldset[@*[name()="wire:key"]="test-outlook-'.$group.'"]')->item(0);
            self::assertNotNull($fieldset);
            self::assertStringNotContainsString('rounded', $fieldset->getAttribute('class'));
            self::assertStringNotContainsString('border', $fieldset->getAttribute('class'));
            self::assertSame('disabled', $fieldset->getAttribute('wire:loading.attr'));
            self::assertSame('updatePreference,updateTemplateChoice,reloadPreferences', $fieldset->getAttribute('wire:target'));
            $input = $xpath->query('//input[@id="test-outlook-'.$group.'-signature"]')->item(0);
            self::assertSame($checked, $input->hasAttribute('checked'));
            self::assertSame("updatePreference('".$group."', 'signature', \$event.target.checked)", $input->getAttribute('wire:change'));
            self::assertSame('test-outlook-shared-hint', $input->getAttribute('aria-describedby'));
            self::assertCount(2, $xpath->query('//label[@for="test-outlook-'.$group.'-signature"]'), 'The visible label and native switch label target the same input.');
        }
        self::assertStringContainsString('Weiterleitungen verwenden die Einstellungen für neue Nachrichten.', $html);
        self::assertCount(1, $xpath->query('//button[@*[name()="wire:click"]="reloadPreferences"]'));
        $this->assertPresentationOnly();
    }

    public function test_unavailable_and_stale_choices_keep_the_stored_selection_without_a_silent_default_and_rotate_the_native_key(): void
    {
        $props = ['choiceSelections' => ['newMessages' => 'default', 'replies' => 'removed-template'], 'templateOptions' => []];
        $html = $this->render($props);
        $xpath = $this->xpath($html);
        $selects = $xpath->query('//*[@data-rt-select-options]');
        foreach ([0 => 'default', 1 => 'removed-template'] as $index => $selectedValue) {
            self::assertMatchesRegularExpression('~\bselected:\s*\x27'.preg_quote($selectedValue, '~').'\x27~', $selects->item($index)->getAttribute('x-data'));
            $options = json_decode($selects->item($index)->getAttribute('data-rt-select-options'), true, 512, JSON_THROW_ON_ERROR);
            $selected = array_values(array_filter($options, static fn (array $option): bool => $option['selected']));
            self::assertCount(1, $selected);
            self::assertSame($selectedValue, $selected[0]['value']);
            self::assertTrue($selected[0]['disabled']);
        }
        self::assertCount(2, $xpath->query('//p[contains(@id,"-unavailable")]'));
        $withConflict = $this->xpath($this->render($props, ['templateChoices' => 'Inzwischen geändert.']));
        $key = '//*[@*[name()="wire:key"] and contains(@*[name()="wire:key"],"-choice-newMessages-")]';
        self::assertNotSame($xpath->query($key)->item(0)->getAttribute('wire:key'), $withConflict->query($key)->item(0)->getAttribute('wire:key'));
        self::assertCount(1, $withConflict->query('//*[@role="alert" and contains(.,"Inzwischen geändert.")]'));
        $this->assertPresentationOnly();
    }

    public function test_personal_wording_stays_visible_deferred_escaped_and_explicitly_saved_with_accessible_validation(): void
    {
        $html = $this->render(['context' => 'employee', 'targetName' => '<em>Testkonto</em>', 'greeting' => 'Hallo <Team> & alle,'], ['greeting' => 'Bitte nur Text verwenden.']);
        $xpath = $this->xpath($html);
        self::assertCount(0, $xpath->query('//em|//script'));
        self::assertStringContainsString('&lt;em&gt;Testkonto&lt;/em&gt;', $html);
        self::assertCount(1, $xpath->query('//form[@data-outlook-wording-save="manual" and @*[name()="wire:submit"]="saveWording"]'));
        self::assertCount(0, $xpath->query('//details'));
        foreach (['greeting', 'farewell'] as $field) {
            $input = $xpath->query('//input[@id="test-outlook-'.$field.'"]')->item(0);
            self::assertSame($field, $input->getAttribute('wire:model'));
            self::assertFalse($input->hasAttribute('wire:model.live'));
            self::assertSame('120', $input->getAttribute('maxlength'));
            self::assertCount(1, $xpath->query('//label[@for="test-outlook-'.$field.'"]'));
        }
        $greeting = $xpath->query('//input[@id="test-outlook-greeting"]')->item(0);
        self::assertSame('Hallo <Team> & alle,', $greeting->getAttribute('value'));
        self::assertSame('true', $greeting->getAttribute('aria-invalid'));
        self::assertStringContainsString('test-outlook-greeting-error', $greeting->getAttribute('aria-describedby'));
        self::assertCount(1, $xpath->query('//p[@id="test-outlook-greeting-error" and @role="alert"]'));
        self::assertCount(1, $xpath->query('//button[@type="submit" and contains(@class,"min-h-11")]'));
        self::assertCount(1, $xpath->query('//button[@*[name()="wire:click"]="reloadWording"]'));
        self::assertCount(1, $xpath->query('//*[@*[name()="wire:dirty"] and @*[name()="wire:target"]="greeting,farewell"]'));
        self::assertStringContainsString('Diese Texte werden erst mit „Texte speichern“ übernommen.', $html);
        $this->assertPresentationOnly();
    }

    public function test_missing_automatic_access_keeps_wording_independent_and_status_errors_visible(): void
    {
        $html = $this->render(['available' => false, 'choicesAvailable' => false, 'notice' => 'Microsoft-Zuordnung fehlt.', 'wordingNotice' => 'Texte geladen.'], ['preferences' => 'Freigabe prüfen.']);
        $xpath = $this->xpath($html);
        self::assertCount(0, $xpath->query('//input[@role="switch"]|//*[@data-rt-custom-select]'));
        self::assertCount(1, $xpath->query('//form[@data-profile-outlook-wording]'));
        self::assertCount(2, $xpath->query('//*[@role="status" and @aria-live="polite"]'));
        self::assertStringContainsString('Microsoft-Zuordnung fehlt.', $html);
        self::assertStringContainsString('Texte geladen.', $html);
        self::assertCount(1, $xpath->query('//*[@role="alert" and contains(.,"Freigabe prüfen.")]'));
        $this->assertPresentationOnly();
    }

    private function render(array $overrides = [], array $messages = []): string
    {
        $props = array_replace([
            'instancePrefix' => 'test-outlook', 'context' => 'self', 'targetName' => 'Synthetisches Konto',
            'preferences' => ['newMessages' => ['signature' => true], 'replies' => ['signature' => false]],
            'available' => true, 'notice' => '', 'choiceSelections' => ['newMessages' => 'default', 'replies' => 'template-one'],
            'templateOptions' => [['id' => 'template-one', 'name' => 'Standard – mit Anrede und Gruß', 'isDefault' => true]],
            'choicesAvailable' => true, 'choicesNotice' => '', 'choicesRevision' => 'synthetic-revision',
            'greeting' => 'Guten Tag,', 'farewell' => 'Mit freundlichen Grüßen,', 'wordingAvailable' => true, 'wordingNotice' => '',
            'errors' => (new ViewErrorBag)->put('default', new MessageBag($messages)),
        ], $overrides);
        View::share('errors', $props['errors']);

        return Blade::render(<<<'BLADE'
<x-outlook.preferences :instance-prefix="$instancePrefix" :context="$context" :target-name="$targetName" :preferences="$preferences" :available="$available" :notice="$notice" :choice-selections="$choiceSelections" :template-options="$templateOptions" :choices-available="$choicesAvailable" :choices-notice="$choicesNotice" :choices-revision="$choicesRevision" :greeting="$greeting" :farewell="$farewell" :wording-available="$wordingAvailable" :wording-notice="$wordingNotice" />
BLADE, $props);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function assertPresentationOnly(): void
    {
        self::assertSame([], DB::connection()->getQueryLog());
        Http::assertNothingSent();
    }
}
