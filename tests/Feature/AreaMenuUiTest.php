<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AreaMenuUiTest extends TestCase
{
    public function test_reusable_area_menu_keeps_native_links_labels_and_one_authoritative_active_destination(): void
    {
        $html = Blade::render('<x-ui.navigation.area-menu id="test-area" label="Personalbereich" :options="$options" value="files" action="setView" :navigate="true" />', ['options' => [
            ['value' => 'people', 'label' => 'Mitarbeiter', 'icon' => 'fa-users', 'href' => '/people'],
            ['value' => 'files', 'label' => 'Nachweise', 'icon' => 'fa-folder', 'href' => '/files'],
            ['value' => 'blocked', 'label' => 'Gesperrt', 'icon' => 'fa-lock', 'href' => '/blocked', 'disabled' => true],
        ]]);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@data-area-menu and @data-multi-toggle and @id="test-area"]')->length);
        $this->assertSame(1, $xpath->query('//*[@x-data="rtAreaMenu" and @aria-hidden="true"]')->length);
        $this->assertSame(1, $xpath->query('//a[@aria-current="page" and @href="/files"]')->length);
        $this->assertSame(2, $xpath->query('//a[@*[name()="wire:navigate"]]')->length);
        $this->assertSame(1, $xpath->query('//button[@disabled and @data-toggle-value="blocked"]')->length);
        $this->assertSame(3, $xpath->query('//span[contains(@class,"rt-multi-toggle__label")]')->length);
        $this->assertStringNotContainsString('@js', $html);
    }

    public function test_plain_multi_toggle_does_not_opt_into_docking_or_indicator_and_server_actions_remain_available(): void
    {
        $options = [['value' => 'a', 'label' => 'A', 'icon' => 'fa-users']];
        $plain = Blade::render('<x-ui.buttons.multi-toggle :options="$options" value="a" action="setView" />', compact('options'));
        $this->assertStringNotContainsString('data-area-menu', $plain);
        $this->assertStringNotContainsString('rtAreaMenu', $plain);
        $area = Blade::render('<x-ui.navigation.area-menu :options="$options" value="a" action="setView" />', compact('options'));
        $this->assertStringContainsString('wire:click="setView(', $area);
        $this->assertStringContainsString('aria-pressed="true"', $area);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $area);
    }

    public function test_mobile_dock_reserves_content_space_with_reduced_motion_and_keeps_tablet_placement(): void
    {
        $css = file_get_contents(resource_path('css/area-menu.css'));
        $this->assertStringContainsString('@media (max-width: 767.98px)', $css);
        $this->assertStringContainsString('inset: auto 0 0;', $css);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $css);
        $this->assertStringContainsString('body:has([data-area-menu]) #main-content', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('transition: transform 220ms', $css);
    }
}
