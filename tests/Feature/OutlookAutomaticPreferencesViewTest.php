<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

final class OutlookAutomaticPreferencesViewTest extends TestCase
{
    public function test_native_taskpane_contains_four_labelled_disabled_self_service_switches(): void
    {
        $this->withoutMiddleware();
        config()->set('outlook_addin.base_url', 'https://example.test');
        $response = $this->get('/outlook-addin/taskpane');
        $response->assertOk()->assertSee('Automatische Einfügung')->assertSee('Offene Entwürfe');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $switches = $xpath->query('//dialog[@id="outlook-automatic-dialog"]//input[@role="switch"]');
        self::assertCount(4, $switches);
        foreach ($switches as $switch) {
            self::assertTrue($switch->hasAttribute('disabled'));
            self::assertNotSame('', $switch->getAttribute('aria-label'));
            self::assertContains($switch->getAttribute('data-automatic-group'), ['newMessages', 'replies']);
            self::assertContains($switch->getAttribute('data-automatic-field'), ['signature', 'template']);
        }
        self::assertSame(1, $xpath->query('//button[@data-outlook-dialog-open="automatic"]')->length);
        self::assertSame(1, $xpath->query('//dialog[@id="outlook-automatic-dialog"]//*[@data-automatic-feedback and @role="status"]')->length);
        self::assertSame(1, $xpath->query('//button[@data-outlook-action="template"]')->length);
        self::assertSame(1, $xpath->query('//button[@data-outlook-action="signature"]')->length);
    }
}
