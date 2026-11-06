<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OutlookAutomaticPreferencesViewTest extends TestCase
{
    public function test_automatic_tab_keeps_signature_switches_and_template_choices_for_the_two_stored_groups(): void
    {
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->withoutMiddleware();
        config()->set('outlook_addin.base_url', 'https://example.test');
        $response = $this->get('/outlook-addin/taskpane')->assertOk();
        self::assertSame([], $queries, 'Rendering preferences must not access or mutate employee records.');
        Http::assertNothingSent();
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $panel = $xpath->query('//*[@data-outlook-pane-panel="automatic" and @role="tabpanel"]')->item(0);
        self::assertNotNull($panel);
        self::assertTrue($panel->hasAttribute('hidden'), 'The compose start screen must not show settings alongside insertion.');
        self::assertSame(1, $xpath->query('//button[@role="tab" and @data-outlook-pane-tab="automatic" and @aria-controls="'.$panel->getAttribute('id').'"]')->length);
        $switches = $xpath->query('.//input[@role="switch" and @data-automatic-setting]', $panel);
        self::assertCount(2, $switches, 'Templates are choices, not independent boolean switches.');
        foreach ($switches as $switch) {
            self::assertTrue($switch->hasAttribute('disabled'));
            self::assertNotSame('', $switch->getAttribute('aria-label'));
            self::assertContains($switch->getAttribute('data-automatic-group'), ['newMessages', 'replies']);
            self::assertSame('signature', $switch->getAttribute('data-automatic-field'));
            self::assertSame('checkbox', $switch->getAttribute('type'));
            self::assertGreaterThanOrEqual(1, $xpath->query('//label[@for="'.$switch->getAttribute('id').'"]')->length);
        }
        self::assertSame(2, $xpath->query('.//select[@data-template-choice-group]', $panel)->length);
        foreach (['newMessages', 'replies'] as $group) {
            $choices = $xpath->query('.//select[@data-template-choice-group="'.$group.'"]', $panel);
            self::assertSame(1, $choices->length);
            $choice = $choices->item(0);
            self::assertTrue($choice->hasAttribute('disabled'));
            self::assertNotSame('', $choice->getAttribute('aria-label'));
            self::assertSame(1, $xpath->query('//label[@for="'.$choice->getAttribute('id').'"]')->length);
            self::assertSame('outlook-choice-feedback', $choice->getAttribute('aria-describedby'));
            self::assertSame(1, $xpath->query('.//fieldset[.//input[@data-automatic-group="'.$group.'"] and .//select[@data-template-choice-group="'.$group.'"]]/legend', $panel)->length);
        }
        $newMessages = $xpath->query('.//fieldset[.//input[@data-automatic-group="newMessages"]]/legend', $panel)->item(0);
        self::assertStringContainsString('Weiterleitungen', $newMessages->textContent);
        self::assertSame(0, $xpath->query('//*[@data-automatic-group="forwards" or @data-template-choice-group="forwards"]')->length, 'Forwarding shares the new-message preference; do not imply an independent setting.');
        self::assertSame(0, $xpath->query('//dialog[@id="outlook-automatic-dialog"]')->length);
        self::assertSame(0, $xpath->query('//button[@data-outlook-dialog-open="automatic"]')->length);
        foreach (['data-automatic-feedback', 'data-template-choice-feedback'] as $hook) {
            self::assertSame(1, $xpath->query('.//*[@'.$hook.' and @role="status" and @aria-live="polite"]', $panel)->length);
        }
        self::assertStringContainsString('Bereits geöffnete Nachrichten bleiben unverändert.', $panel->textContent);
        self::assertSame(1, $xpath->query('//*[@data-outlook-pane-panel="insert"]//button[@data-outlook-action="template"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-outlook-developer-controls and @hidden and @inert]//button[@data-outlook-action="signature"]')->length);
    }
}
