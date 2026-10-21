<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FloatingWindowTabsTest extends TestCase
{
    private function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_alpine_tabs_keep_the_native_feature_scope_and_visible_labels(): void
    {
        $html = Blade::render('<x-ui.floating.window-tabs id-prefix="communication" label="Bereiche" client-state="tab" client-action="selectTab" client-keyboard-action="tabKey" tab-data-attribute="data-comm-tab" :tabs="$tabs" />', [
            'tabs' => [
                'chats' => ['icon' => 'fa-comments', 'label' => 'Chats', 'controls' => 'communication-chats'],
                'calls' => ['icon' => 'fa-phone', 'label' => 'Anrufe', 'controls' => 'communication-calls'],
                'meetings' => ['icon' => 'fa-video', 'label' => 'Meetings', 'controls' => 'communication-meetings'],
            ],
        ]);
        $dom = $this->dom($html);
        $nav = $dom->query('//nav[@data-window-tabs]')->item(0);
        $this->assertNotNull($nav);
        $this->assertFalse($nav->hasAttribute('x-data'), 'The native controller keeps its original $root and refs.');
        $this->assertSame('tabKey($event)', $nav->getAttribute('x-on:keydown'));
        $this->assertSame(3, $dom->query('//nav/button[@role="tab"]')->length);
        foreach (['chats' => 'Chats', 'calls' => 'Anrufe', 'meetings' => 'Meetings'] as $key => $label) {
            $button = $dom->query('//button[@data-comm-tab="'.$key.'"]')->item(0);
            $this->assertSame('communication-tab-'.$key, $button->getAttribute('id'));
            $this->assertSame('communication-'.$key, $button->getAttribute('aria-controls'));
            $this->assertSame("selectTab('".$key."')", $button->getAttribute('x-on:click'));
            $this->assertSame("(tab === '".$key."').toString()", $button->getAttribute('x-bind:aria-selected'));
            $this->assertSame($label, $dom->query('.//span[@class="rt-window-tabs__label"]', $button)->item(0)->textContent);
            $this->assertFalse($button->hasAttribute('wire:click'));
        }
    }

    public function test_assistant_adapter_preserves_allowed_tabs_badges_actions_and_escaped_visible_labels(): void
    {
        $html = Blade::render('<x-operations.assistant-navigation appearance="window" id-prefix="synthetic" controls="synthetic-panel" :tabs="$tabs" selected="intake" :review-count="143" />', [
            'tabs' => ['chat' => '<unsafe>', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'unsupported' => 'Unsupported'],
        ]);
        $dom = $this->dom($html);
        $this->assertSame(1, $dom->query('//nav[@data-window-tabs and @data-assistant-navigation]')->length);
        $this->assertSame(3, $dom->query('//nav/button[@data-assistant-nav-tab]')->length);
        $this->assertSame(0, $dom->query('//button[@data-tab="unsupported"]')->length);
        $this->assertStringNotContainsString('<unsafe>', $html);
        $this->assertStringContainsString('&lt;unsafe&gt;', $html);
        $this->assertSame('<unsafe>', $dom->query('//button[@data-tab="chat"]/span[1]')->item(0)->textContent);
        $button = $dom->query('//button[@data-tab="intake"]')->item(0);
        $this->assertSame('true', $button->getAttribute('aria-selected'));
        $this->assertSame('0', $button->getAttribute('tabindex'));
        $this->assertSame('synthetic-panel', $button->getAttribute('aria-controls'));
        $this->assertSame("setTab('intake')", $button->getAttribute('wire:click'));
        $this->assertSame('AI-Annahme · 143 zur Prüfung', $button->getAttribute('aria-label'));
        $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
        $this->assertSame('99+', $dom->query('./span[contains(@class,"rt-window-tabs__count")]', $button)->item(0)->textContent);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('buttons[index].focus({ preventScroll: true })', $html);
        $this->assertStringNotContainsString('buttons[index].click()', $html);
    }

    public function test_adapter_rejects_actions_outside_its_native_action_contract(): void
    {
        $html = Blade::render('<x-operations.assistant-navigation action="deleteRecord" :tabs="$tabs" />', ['tabs' => ['chat' => 'Chat']]);
        $dom = $this->dom($html);
        $button = $dom->query('//button[@role="tab"]')->item(0);
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertFalse($button->hasAttribute('wire:click'));
        $this->assertSame('-1', $button->getAttribute('tabindex'));
    }
}
