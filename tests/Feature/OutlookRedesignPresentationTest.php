<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Presentation only: no schema, account, message or preference writes. */
final class OutlookRedesignPresentationTest extends TestCase
{
    private ?string $isolatedViews = null;

    public function createApplication(): Application
    {
        $this->isolatedViews = sys_get_temp_dir().DIRECTORY_SEPARATOR.'railtime-outlook-redesign-'.bin2hex(random_bytes(12));
        mkdir($this->isolatedViews, 0700, true);
        $previous = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        putenv('VIEW_COMPILED_PATH='.$this->isolatedViews);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $this->isolatedViews;
        try {
            return parent::createApplication();
        } finally {
            $previous[0] === false ? putenv('VIEW_COMPILED_PATH') : putenv('VIEW_COMPILED_PATH='.$previous[0]);
            foreach ([1 => '_ENV', 2 => '_SERVER'] as $index => $global) {
                if ($previous[$index] === null) {
                    unset($GLOBALS[$global]['VIEW_COMPILED_PATH']);
                } else {
                    $GLOBALS[$global]['VIEW_COMPILED_PATH'] = $previous[$index];
                }
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $path = $this->isolatedViews !== null ? realpath($this->isolatedViews) : false;
            if ($path !== false && dirname($path) === realpath(sys_get_temp_dir())
                && preg_match('/\Arailtime-outlook-redesign-[0-9a-f]{24}\z/', basename($path)) === 1) {
                (new Filesystem)->deleteDirectory($path);
            }
        }
    }

    public static function modes(): array
    {
        return ['compose' => [false], 'own account settings' => [true]];
    }

    #[DataProvider('modes')]
    public function test_redesign_preserves_actual_rendered_controls_and_the_account_mode_boundary(bool $settings): void
    {
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        self::assertSame($this->isolatedViews, config('view.compiled'));
        Http::preventStrayRequests();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->withoutMiddleware();
        config()->set('outlook_addin.base_url', 'https://example.test');
        $response = $this->get('/outlook-addin/taskpane'.($settings ? '?context=settings' : ''))->assertOk();
        self::assertSame([], $queries, 'Rendering this public surface must not read or alter employee data.');
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $root = $this->one($xpath, '//main[@data-outlook-addin-taskpane]');
        self::assertSame($settings ? 'settings' : 'compose', $root->getAttribute('data-outlook-context'));
        self::assertSame('true', $root->getAttribute('aria-busy'));
        $heading = $this->one($xpath, '//header[contains(concat(" ",normalize-space(@class)," ")," pane-heading ")]//h1[@id="outlook-addin-heading"]');
        self::assertSame($settings ? 'Outlook-Einstellungen' : 'RailTime', trim($heading->textContent));
        $this->one($xpath, '//section[@aria-labelledby="outlook-addin-heading"]');
        $this->one($xpath, '//h2[@id="outlook-settings-heading"]');
        self::assertSame(1, $xpath->query('//*[@data-outlook-automatic-region]')->length);
        self::assertSame(1, $xpath->query('//*[@data-outlook-choices-region]')->length);
        self::assertSame(2, $xpath->query('//input[@data-automatic-setting and @type="checkbox" and @role="switch"]')->length);
        self::assertSame(2, $xpath->query('//select[@data-template-choice-group]')->length);
        foreach (['newMessages', 'replies'] as $group) {
            $switch = $this->one($xpath, '//input[@data-automatic-setting and @data-automatic-group="'.$group.'" and @data-automatic-field="signature"]');
            $choice = $this->one($xpath, '//select[@data-template-choice-group="'.$group.'"]');
            foreach ([$switch, $choice] as $control) {
                self::assertTrue($control->hasAttribute('disabled'));
                self::assertNotSame('', $control->getAttribute('aria-label'));
                $this->one($xpath, '//label[@for="'.$control->getAttribute('id').'"]', allowMultiple: true);
            }
            $fieldset = $this->one($xpath, '//fieldset[.//input[@data-automatic-group="'.$group.'"] and .//select[@data-template-choice-group="'.$group.'"]]');
            self::assertSame(1, $xpath->query('./legend', $fieldset)->length);
            self::assertSame(1, $xpath->query('ancestor::*[@data-outlook-automatic-region]', $fieldset)->length);
            self::assertSame(1, $xpath->query('ancestor::*[@data-outlook-choices-region]', $fieldset)->length);
        }
        foreach (['data-automatic-feedback', 'data-template-choice-feedback'] as $hook) {
            $feedback = $this->one($xpath, '//*[@'.$hook.']');
            self::assertSame('status', $feedback->getAttribute('role'));
            self::assertSame('polite', $feedback->getAttribute('aria-live'));
        }
        $wording = $this->one($xpath, '//section[@data-outlook-wording-region]');
        $wordingDetails = $this->one($xpath, '//section[@data-outlook-wording-region]/details');
        self::assertFalse($wordingDetails->hasAttribute('open'), 'Personal texts start collapsed in both entry points.');
        $summary = $this->one($xpath, '//section[@data-outlook-wording-region]/details/summary[@id="outlook-wording-heading"]');
        self::assertSame('Persönliche Texte', trim($summary->textContent));
        self::assertSame($summary->getAttribute('id'), $wording->getAttribute('aria-labelledby'));
        foreach (['greeting', 'farewell'] as $field) {
            $input = $this->one($xpath, '//section[@data-outlook-wording-region]//input[@data-wording-field="'.$field.'"]');
            self::assertSame('text', $input->getAttribute('type'));
            self::assertSame('120', $input->getAttribute('maxlength'));
            self::assertTrue($input->hasAttribute('disabled'));
            $this->one($xpath, '//label[@for="'.$input->getAttribute('id').'"]');
        }
        self::assertTrue($this->one($xpath, '//button[@data-wording-save]')->hasAttribute('disabled'));
        self::assertTrue($this->one($xpath, '//button[@data-wording-reload]')->hasAttribute('hidden'));
        $this->one($xpath, '//*[@data-wording-feedback and @role="status" and @aria-live="polite"]');
        foreach (['template', 'login', 'signature'] as $action) {
            $buttons = $xpath->query('//button[@data-outlook-action="'.$action.'"]');
            self::assertSame($settings ? 0 : 1, $buttons->length);
            if (! $settings) {
                self::assertTrue($buttons->item(0)->hasAttribute('disabled'));
                self::assertSame('false', $buttons->item(0)->getAttribute('data-available'));
                self::assertSame('false', $buttons->item(0)->getAttribute('data-busy'));
            }
        }
        foreach ([
            'data-outlook-pane-feedback', 'data-outlook-template-region', 'data-outlook-template-count',
            'data-outlook-template-select', 'data-outlook-template-meta', 'data-outlook-template-name',
            'data-outlook-template-version', 'data-outlook-template-active', 'data-outlook-template-action-detail',
            'data-outlook-template-loading', 'data-outlook-template-ready', 'data-outlook-template-empty',
            'data-outlook-template-locked', 'data-outlook-template-error', 'data-outlook-status',
            'data-outlook-status-symbol', 'data-outlook-status-title', 'data-outlook-status-detail',
            'data-outlook-account', 'data-outlook-account-label', 'data-outlook-maintenance-actions',
            'data-outlook-login-title', 'data-outlook-login-detail', 'data-outlook-signature-detail',
            'data-outlook-diagnostics', 'data-outlook-diagnostics-run', 'data-outlook-diagnostics-copy',
            'data-outlook-diagnostics-output', 'data-outlook-diagnostics-feedback',
            'data-outlook-insert-template', 'data-outlook-template-picker', 'data-outlook-template-picker-close',
            'data-outlook-template-options', 'data-outlook-template-picker-status', 'data-outlook-developer-controls',
        ] as $hook) {
            self::assertSame($settings ? 0 : 1, $xpath->query('//*[@'.$hook.']')->length, $hook);
        }
        foreach (['data-outlook-settings-status', 'data-outlook-settings-connect', 'data-outlook-profile-settings'] as $hook) {
            self::assertSame($settings ? 1 : 0, $xpath->query('//*[@'.$hook.']')->length, $hook);
        }
        self::assertSame(0, $xpath->query('//*[@data-outlook-dialog-open]')->length);
        self::assertSame($settings ? 0 : 1, $xpath->query('//*[@role="tablist"]')->length);
        self::assertSame($settings ? 0 : 2, $xpath->query('//button[@role="tab" and @data-outlook-pane-tab]')->length);
        self::assertSame($settings ? 0 : 2, $xpath->query('//*[@role="tabpanel" and @data-outlook-pane-panel]')->length);
        self::assertSame($settings ? 0 : 1, $xpath->query('//dialog')->length);
        if ($settings) {
            $settingsSection = $this->one($xpath, '//section[@aria-labelledby="outlook-settings-heading"]');
            self::assertFalse($settingsSection->hasAttribute('hidden'), 'Account settings remain usable without compose tabs.');
            self::assertSame(0, $xpath->query('ancestor::*[@hidden or @inert]', $settingsSection)->length);
        } else {
            foreach (['insert', 'automatic'] as $pane) {
                $tab = $this->one($xpath, '//button[@role="tab" and @data-outlook-pane-tab="'.$pane.'"]');
                $panel = $this->one($xpath, '//*[@role="tabpanel" and @data-outlook-pane-panel="'.$pane.'"]');
                self::assertSame($panel->getAttribute('id'), $tab->getAttribute('aria-controls'));
                self::assertSame($tab->getAttribute('id'), $panel->getAttribute('aria-labelledby'));
                self::assertSame($pane === 'insert' ? 'true' : 'false', $tab->getAttribute('aria-selected'));
                self::assertSame($pane === 'insert' ? '0' : '-1', $tab->getAttribute('tabindex'));
                self::assertSame($pane === 'automatic', $panel->hasAttribute('hidden'));
            }
            $action = $this->one($xpath, '//button[@data-outlook-insert-template and @data-outlook-action="template"]');
            self::assertStringContainsString('Signatur & Vorlage einfügen', $action->textContent);
            self::assertSame('dialog', $action->getAttribute('aria-haspopup'));
            $picker = $this->one($xpath, '//dialog[@data-outlook-template-picker]');
            self::assertSame($picker->getAttribute('id'), $action->getAttribute('aria-controls'));
            self::assertFalse($picker->hasAttribute('open'), 'Loading the task pane must not open a selection or imply insertion.');
            foreach (['aria-labelledby', 'aria-describedby'] as $reference) {
                $this->one($xpath, '//*[@id="'.$picker->getAttribute($reference).'"]');
            }
            $close = $this->one($xpath, '//dialog[@data-outlook-template-picker]//button[@data-outlook-template-picker-close]');
            self::assertNotSame('', $close->getAttribute('aria-label'));
            self::assertSame(1, $xpath->query('//dialog[@data-outlook-template-picker]//button')->length, 'The sheet has no second confirmation action.');
            $this->one($xpath, '//dialog[@data-outlook-template-picker]//*[@data-outlook-template-options]');
            $feedback = $this->one($xpath, '//*[@data-outlook-pane-feedback]');
            self::assertSame(0, $xpath->query('ancestor::*[@data-outlook-pane-panel]', $feedback)->length, 'Authentication and action feedback must remain reachable on both tabs.');
            self::assertTrue($feedback->hasAttribute('hidden'));
            self::assertSame('status', $feedback->getAttribute('role'));
            self::assertSame('polite', $feedback->getAttribute('aria-live'));
            self::assertSame('', trim($feedback->textContent), 'No successful insertion may be claimed before an action.');
            $status = $this->one($xpath, '//*[@data-outlook-status]');
            self::assertTrue($status->hasAttribute('hidden'));
            self::assertTrue($status->hasAttribute('inert'));
            self::assertSame('true', $status->getAttribute('aria-hidden'));
            self::assertFalse($status->hasAttribute('aria-live'), 'Technical status must not duplicate the compact feedback announcement.');
            foreach (['data-outlook-template-select', 'data-outlook-template-meta', 'data-outlook-diagnostics-run', 'data-outlook-diagnostics-copy'] as $hook) {
                $control = $this->one($xpath, '//*[@'.$hook.']');
                self::assertGreaterThanOrEqual(1, $xpath->query('ancestor::*[@hidden and @inert and @aria-hidden="true"]', $control)->length, $hook.' must stay outside the everyday UI.');
            }
            $signature = $this->one($xpath, '//button[@data-outlook-action="signature"]');
            self::assertSame(1, $xpath->query('ancestor::*[@data-outlook-developer-controls and @hidden and @inert]', $signature)->length);
            $login = $this->one($xpath, '//button[@data-outlook-action="login"]');
            self::assertSame(1, $xpath->query('ancestor::*[@data-outlook-pane-panel="automatic"]', $login)->length);
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            self::assertArrayNotHasKey($element->getAttribute('id'), $ids, 'Responsive layouts must not duplicate controls.');
            $ids[$element->getAttribute('id')] = true;
        }
        $this->one($xpath, '//meta[@name="railtime-outlook-config-url" and @content="https://example.test/outlook-addin/config.json"]');
        self::assertSame(2, $xpath->query('//script')->length);
        self::assertSame(1, $xpath->query('//script[@src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js"]')->length);
        $this->one($xpath, '//script[@defer and starts-with(@src,"https://example.test/outlook-addin/taskpane.js")]');
        self::assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("form-action 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-eval', $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('Cache-Control', 'max-age=0, no-store, public');
    }

    private function one(DOMXPath $xpath, string $expression, bool $allowMultiple = false): DOMElement
    {
        $nodes = $xpath->query($expression);
        self::assertNotFalse($nodes);
        $allowMultiple ? self::assertGreaterThanOrEqual(1, $nodes->length, $expression) : self::assertSame(1, $nodes->length, $expression);
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }
}
