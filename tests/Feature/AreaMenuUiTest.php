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
            ['value' => 'files', 'label' => 'Nachweise', 'compactLabel' => 'Akte', 'icon' => 'fa-folder', 'href' => '/files'],
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
        $this->assertSame(4, $xpath->query('//span[contains(@class,"rt-multi-toggle__label")]')->length);
        $this->assertSame(1, $xpath->query('//span[@aria-hidden="true" and contains(@class,"rt-multi-toggle__label--compact")]')->length);
        $this->assertSame(1, $xpath->query('//a[@aria-label="Nachweise"]')->length);
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
        $this->assertStringContainsString('transition: transform 280ms', $css);
        $this->assertStringContainsString('height: calc(56px + env(safe-area-inset-bottom', $css);
        $this->assertStringContainsString('min-height: 44px;', $css);
    }

    public function test_native_sidebar_no_longer_depends_on_the_legacy_planning_stylesheet(): void
    {
        $css = file_get_contents(resource_path('css/shell-redesign.css'));
        $this->assertStringContainsString('.rt-shell-sidebar .metismenu .mm-collapse:not(.mm-show) { display: none; }', $css);
        $this->assertStringContainsString('.rt-shell-sidebar .metismenu .mm-collapse.mm-show { display: block; }', $css);
        $this->assertStringContainsString('margin-inline-start: 12px;', $css);
        $this->assertStringContainsString('transition: height 220ms ease-out;', $css);
    }

    public function test_resource_workspace_reuses_the_component_without_replacing_existing_section_filters(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/planning-page-workspace.blade.php'));
        $this->assertStringContainsString('x-teleport="[data-area-menu-host]"', $view);
        $this->assertStringContainsString('<x-ui.navigation.area-menu id="resources-workspace-view"', $view);
        $this->assertStringContainsString('action="selectView"', $view);
        $this->assertStringContainsString('change="$wire.selectSection($event.target.value)"', $view);
        $this->assertStringContainsString("!\$embedded && \$page === 'planning'", $view);
    }

    public function test_area_hover_has_no_fixed_surface_and_ink_uses_the_same_transform_timing(): void
    {
        $css = file_get_contents(resource_path('css/area-menu.css'));
        $this->assertStringContainsString('.rt-area-menu.rt-multi-toggle .rt-multi-toggle__button.rt-ui-button:hover { background: transparent !important; }', $css);
        $this->assertStringContainsString('.rt-area-menu__ink {', $css);
        $this->assertStringContainsString('overflow: hidden;', $css);
        $this->assertSame(2, substr_count($css, 'transform 280ms cubic-bezier(.22, .8, .25, 1)'));
        $this->assertStringContainsString('color: #fff;', $css);
    }
}
