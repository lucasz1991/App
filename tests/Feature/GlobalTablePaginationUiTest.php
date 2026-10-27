<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class GlobalTablePaginationUiTest extends TestCase
{
    public function test_global_pager_keeps_named_native_actions_and_accessible_current_page(): void
    {
        $pages = $this->pages(80, 50, 1, 'servicesPage');
        $html = $this->render($pages);
        $xpath = $this->xpath($html);

        $this->assertCount(1, $xpath->query('//nav[@role="navigation" and @aria-label="Leistungsseiten" and @data-table-pagination-key="service-overview" and @data-table-page-name="servicesPage"]'));
        $this->assertStringContainsString('1–50', $html);
        $this->assertStringContainsString('von 80', $html);
        $this->assertSame('gotoPage(2, "servicesPage")', $xpath->query('//button[contains(@class,"rt-table-pagination__page-number") and normalize-space(.)="2"]')->item(0)->getAttribute('wire:click'));
        $this->assertCount(1, $xpath->query('//button[@aria-current="page" and @disabled]'));
        $this->assertSame('1', trim($xpath->query('//button[@aria-current="page"]')->item(0)->textContent));
        $this->assertCount(4, $xpath->query('//button[@type="button" and @data-table-page-action and @*[name()="wire:loading.attr" and .="disabled"]]'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"rt-table-pagination__range") and @aria-live="polite" and @aria-atomic="true"]'));
        $this->assertCount(2, $xpath->query('//button/i[@aria-hidden="true"]'));
        $this->assertStringNotContainsString('scrollIntoView', $html);
    }

    public function test_first_and_last_pages_have_real_disabled_boundaries(): void
    {
        $first = $this->xpath($this->render($this->pages(80, 50, 1, 'servicesPage')));
        $last = $this->xpath($this->render($this->pages(80, 50, 2, 'servicesPage')));

        $this->assertCount(1, $first->query('//button[contains(@*[name()="wire:click"],"previousPage") and @disabled]'));
        $this->assertCount(1, $first->query('//button[contains(@*[name()="wire:click"],"nextPage") and not(@disabled)]'));
        $this->assertCount(1, $last->query('//button[contains(@*[name()="wire:click"],"previousPage") and not(@disabled)]'));
        $this->assertCount(1, $last->query('//button[contains(@*[name()="wire:click"],"nextPage") and @disabled]'));
        $this->assertStringContainsString('51–80', $this->render($this->pages(80, 50, 2, 'servicesPage')));
    }

    public function test_zero_and_single_pages_keep_the_range_without_fake_page_controls(): void
    {
        foreach ([$this->pages(0, 50, 1), $this->pages(12, 50, 1)] as $pages) {
            $html = $this->render($pages);
            $xpath = $this->xpath($html);
            $this->assertCount(1, $xpath->query('//nav[@data-table-pagination]'));
            $this->assertCount(1, $xpath->query('//*[contains(@class,"rt-table-pagination__range")]'));
            $this->assertCount(0, $xpath->query('//button'));
            $this->assertCount(0, $xpath->query('//*[@data-table-page-menu or @data-table-page-indicator-option]'));
            $this->assertCount(0, $xpath->query('//*[contains(@class,"rt-table-pagination__indicator")]'));
            $this->assertStringContainsString(($pages->total() === 0 ? '0–0' : '1–12'), $html);
            $this->assertStringContainsString('von '.$pages->total(), $html);
        }
    }

    public function test_large_page_windows_remain_bounded_and_expose_both_endpoints(): void
    {
        $html = $this->render($this->pages(5000, 50, 50, 'servicesPage'));
        $xpath = $this->xpath($html);
        $numbers = [];
        foreach ($xpath->query('//button[contains(@class,"rt-table-pagination__page-number")]') as $button) {
            $numbers[] = (int) trim($button->textContent);
        }

        $this->assertSame([1, 49, 50, 51, 100], $numbers);
        $this->assertCount(2, $xpath->query('//*[contains(@class,"rt-table-pagination__ellipsis") and @aria-hidden="true"]'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"rt-table-pagination__mobile-page") and normalize-space(.)="50 / 100"]'));
        $this->assertCount(1, $xpath->query('//span[contains(@class,"sr-only") and contains(text(),"50")]'));
        $this->assertLessThanOrEqual(7, $xpath->query('//button')->length);
    }

    public function test_page_keys_are_stable_but_unique_between_tables_and_named_paginators(): void
    {
        $keys = fn ($html) => $this->xpath($html)->query('//button[contains(@class,"rt-table-pagination__page-number") and normalize-space(.)="2"]')->item(0)->getAttribute('wire:key');
        $first = $keys($this->render($this->pages(80, 50, 1, 'servicesPage')));
        $second = $keys($this->render($this->pages(80, 50, 2, 'servicesPage')));
        $otherName = $keys($this->render($this->pages(80, 50, 1, 'ordersPage')));
        $pages = $this->pages(80, 50, 1, 'servicesPage');
        $otherTable = $keys(Blade::render('<x-tables.pagination :paginator="$pages" table-key="other-table" />', compact('pages')));

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $otherName);
        $this->assertNotSame($first, $otherTable);
    }

    public function test_default_scope_uses_page_name_and_untrusted_attributes_are_escaped(): void
    {
        $pages = $this->pages(80, 50, 1, 'tasks\'"Page');
        $label = '<img src=x onerror=alert(1)>';
        $html = Blade::render('<x-tables.pagination :paginator="$pages" :label="$label" />', compact('pages', 'label'));
        $xpath = $this->xpath($html);

        $this->assertCount(0, $xpath->query('//img'));
        $this->assertSame($label, $xpath->query('//nav')->item(0)->getAttribute('aria-label'));
        $this->assertSame($pages->getPageName(), $xpath->query('//nav')->item(0)->getAttribute('data-table-pagination-key'));
        $expression = $xpath->query('//button[contains(@class,"rt-table-pagination__page-number") and normalize-space(.)="2"]')->item(0)->getAttribute('wire:click');
        $this->assertSame(1, preg_match('/^gotoPage\(2, (.*)\)$/', $expression, $matches));
        $this->assertSame($pages->getPageName(), json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_one_decorative_area_indicator_reuses_motion_without_owning_native_actions(): void
    {
        $xpath = $this->xpath($this->render($this->pages(150, 50, 2, 'servicesPage')));
        $controls = $xpath->query('//*[@data-table-page-menu]')->item(0);
        $indicator = $xpath->query('//*[@data-table-page-menu]/span[contains(@class,"rt-table-pagination__indicator")]')->item(0);

        $this->assertCount(1, $xpath->query('//*[@data-table-page-menu]'));
        $this->assertSame('aria-busy', $controls->getAttribute('wire:loading.attr'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"rt-area-menu__indicator") and contains(@class,"rt-table-pagination__indicator")]'));
        $this->assertSame('true', $indicator->getAttribute('aria-hidden'));
        $this->assertTrue($indicator->hasAttribute('wire:ignore'));
        $this->assertStringContainsString('rtAreaMenu(', $indicator->getAttribute('x-data'));
        $this->assertStringContainsString("optionSelector: '[data-table-page-indicator-option]'", $indicator->getAttribute('x-data'));
        $this->assertStringContainsString("visualSelector: '[data-table-page-label]'", $indicator->getAttribute('x-data'));
        $this->assertStringContainsString('allowDisabledActive: true', $indicator->getAttribute('x-data'));
        $this->assertCount(0, $xpath->query('//*[@data-area-menu]'));
        $this->assertCount(0, $xpath->query('//*[@data-table-page-menu and contains(concat(" ",normalize-space(@class)," ")," rt-area-menu ")]'));
        $this->assertCount(0, $xpath->query('//*[@data-table-page-indicator-option and @*[name()="x-on:click"]]'));

        $this->assertCount(5, $xpath->query('//button[@data-table-page-action]'));
        $this->assertCount(3, $xpath->query('//button[@data-table-page-indicator-option]/span[@data-table-page-label]'));
        $this->assertCount(0, $xpath->query('//button[contains(@*[name()="wire:click"],"previousPage") or contains(@*[name()="wire:click"],"nextPage")][@data-table-page-indicator-option]'));
        $this->assertCount(1, $xpath->query('//button[@aria-current="page" and @disabled and normalize-space(.)="2"]'));
        $this->assertCount(0, $xpath->query('//*[@data-table-page-label]/*'));
        $this->assertCount(1, $xpath->query('//span[contains(@class,"rt-table-pagination__mobile-page") and @aria-hidden="true" and @aria-current="page" and @data-table-page-indicator-option]/span[@data-table-page-label and normalize-space(.)="2 / 3"]'));
        $this->assertCount(0, $xpath->query('//span[contains(@class,"rt-table-pagination__mobile-page") and @data-table-page-action]'));
    }

    public function test_two_pagers_have_separate_indicators_and_escape_their_native_scope(): void
    {
        $pages = $this->pages(150, 50, 2, 'tasks\'"Page');
        $tableKey = '<svg onload="alert(1)">';
        $label = '<img src=x onerror=alert(1)>';
        $html = Blade::render('<x-tables.pagination :paginator="$pages" :table-key="$tableKey" :label="$label" /><x-tables.pagination :paginator="$pages" table-key="other-table" />', compact('pages', 'tableKey', 'label'));
        $xpath = $this->xpath($html);

        $this->assertCount(2, $xpath->query('//nav[@data-table-pagination]'));
        $this->assertCount(2, $xpath->query('//*[@data-table-page-menu]/span[contains(@class,"rt-table-pagination__indicator")]'));
        $this->assertSame($tableKey, $xpath->query('//nav')->item(0)->getAttribute('data-table-pagination-key'));
        $this->assertSame($label, $xpath->query('//nav')->item(0)->getAttribute('aria-label'));
        $this->assertCount(0, $xpath->query('//svg|//img|//script'));
        foreach ($xpath->query('//*[@data-table-page-label]') as $visualLabel) {
            $this->assertSame(1, preg_match('/^\d+(?: \/ \d+)?$/', trim($visualLabel->textContent)));
        }
        $keys = [];
        foreach ($xpath->query('//button[@data-table-page-action]') as $button) {
            $keys[] = $button->getAttribute('wire:key');
        }
        $this->assertCount(count($keys), array_unique($keys));
    }

    public function test_legacy_personal_adapter_delegates_to_the_same_component_and_reset_scope(): void
    {
        $pages = $this->pages(30, 15, 1);
        $html = (string) $pages->links('livewire.admin.employees.directory-pagination', ['scrollTo' => false]);
        $xpath = $this->xpath($html);
        $employee = file_get_contents(resource_path('views/livewire/admin/employees.blade.php'));
        $adapter = file_get_contents(resource_path('views/livewire/admin/employees/directory-pagination.blade.php'));

        $this->assertCount(1, $xpath->query('//nav[@data-table-pagination and @data-table-pagination-key="employee-directory" and contains(@class,"employee-directory__pager")]'));
        $this->assertStringContainsString('<x-tables.pagination', $adapter);
        $this->assertStringNotContainsString('previousPage(', $adapter);
        $this->assertStringContainsString('data-table-pagination-key="employee-directory"', $employee);
        $this->assertStringContainsString('data-employee-pagination', $employee);
        $handler = $xpath->query('//nav')->item(0)->getAttribute('x-on:click');
        $this->assertStringContainsString('button[data-table-page-action]:not(:disabled)', $handler);
        $this->assertStringContainsString('candidate.closest', $handler);
        $this->assertStringContainsString('candidate.dataset.tablePaginationKey === key', $handler);
        $this->assertStringContainsString('[data-table-scroll-body]', $handler);
        $this->assertStringContainsString("behavior: 'instant'", $handler);
        $this->assertStringNotContainsString('window.scroll', $handler);
    }

    public function test_global_styles_keep_native_targets_tokens_and_mobile_reduced_motion_contracts(): void
    {
        $css = file_get_contents(resource_path('css/table-pagination.css'));

        foreach (['min-height: 44px', 'min-width: 44px', 'var(--rt-shell-accent)', 'var(--rt-shell-text)',
            'var(--rt-shell-muted)', 'font-variant-numeric: tabular-nums', '@media (max-width: 767px)',
            '@container table-pagination (max-width: 620px)', '@media (prefers-reduced-motion: reduce)',
            ':focus-visible', ':disabled:not([aria-current=', 'transition: none'] as $contract) {
            $this->assertStringContainsString($contract, $css);
        }
        $this->assertStringNotContainsString('position: fixed', $css);
        $this->assertStringNotContainsString('transition: all', $css);
        $this->assertStringNotContainsString('employee-directory', $css);
    }

    public function test_active_red_tile_and_white_ink_share_area_motion_without_fixed_mobile_docking(): void
    {
        $css = file_get_contents(resource_path('css/table-pagination.css'));
        $areaCss = file_get_contents(resource_path('css/area-menu.css'));

        $this->assertMatchesRegularExpression('/\.rt-table-pagination__button\[aria-current=\'page\'\]\s*\{[^}]*color:\s*#fff/', $css);
        $this->assertMatchesRegularExpression('/\.rt-table-pagination__button\[aria-current=\'page\'\]::before\s*\{[^}]*background:\s*var\(--rt-red,\s*#e4002b\)/', $css);
        $this->assertMatchesRegularExpression('/\.rt-table-pagination__controls\s+\.rt-table-pagination__indicator\s*\{[^}]*clip-path:\s*inset\(8px\s+4px\s+round\s+7px\)/', $css);
        $this->assertMatchesRegularExpression('/\.rt-table-pagination__controls\s+\.rt-area-menu__ink\s*\{[^}]*color:\s*#fff/', $css);
        $this->assertStringContainsString('[data-area-indicator-ready] :is(.rt-table-pagination__button, .rt-table-pagination__mobile-page) { color: var(--rt-shell-muted); }', $css);
        $this->assertMatchesRegularExpression('/\[data-area-indicator-ready\][^{}]*::before\s*\{[^}]*background:\s*transparent/', $css);
        $this->assertStringContainsString('pointer-events: none', $css);
        $this->assertMatchesRegularExpression('/@media\s*\(max-width:\s*767px\)\s*\{\s*\.rt-table-pagination__page-number,\s*\.rt-table-pagination__ellipsis\s*\{\s*display:\s*none;\s*\}\s*\.rt-table-pagination__mobile-page\s*\{\s*display:\s*inline-flex;/', $css);
        $this->assertStringContainsString('@container table-pagination (max-width: 300px)', $css);
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\.rt-table-pagination__indicator,\s*\.rt-table-pagination__controls\s+\.rt-area-menu__ink\s*\{\s*transition:\s*none;/', $css);
        $this->assertMatchesRegularExpression('/\.rt-area-menu__indicator\s*\{[^}]*transition:\s*transform\s+280ms\s+cubic-bezier\(\.22,\s*\.8,\s*\.25,\s*1\)/s', $areaCss);
        $this->assertMatchesRegularExpression('/\.rt-area-menu__ink\s*\{[^}]*transition:\s*transform\s+280ms\s+cubic-bezier\(\.22,\s*\.8,\s*\.25,\s*1\)/s', $areaCss);
        $this->assertStringNotContainsString('position: fixed', $css);
        $this->assertStringNotContainsString('data-area-menu]', $css);
    }

    private function pages(int $total, int $perPage, int $currentPage, string $pageName = 'page'): LengthAwarePaginator
    {
        $count = min($perPage, max(0, $total - (($currentPage - 1) * $perPage)));

        return new LengthAwarePaginator(array_fill(0, $count, (object) ['id' => 1]), $total, $perPage, $currentPage, ['pageName' => $pageName]);
    }

    private function render(LengthAwarePaginator $pages): string
    {
        return Blade::render('<x-tables.pagination :paginator="$pages" label="Leistungsseiten" table-key="service-overview" />', compact('pages'));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }
}
