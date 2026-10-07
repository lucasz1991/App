<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CompactStaffTimelineUiTest extends TestCase
{
    public function test_planning_timeline_loading_is_local_delayed_and_never_replaces_the_scrollport(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringContainsString('data-timeline-motion="{{ $planningEnabled && !$absencesOnly ? \'true\' : \'false\' }}"', $view);
        $this->assertStringContainsString('wire:loading.delay.flex wire:target="search,loadMore,refreshPlanning"', $view);
        $this->assertStringContainsString('class="rt-timeline-loading-indicator" style="display: none"', $view);
        $this->assertStringContainsString('role="status" aria-live="polite"', $view);
        $this->assertStringContainsString('pointer-events: none', $this->cssDeclarationsFor($css, '.rt-timeline-loading-indicator'));
        $this->assertStringContainsString('animation: none', $this->cssDeclarationsFor($css, '.rt-timeline-loading-indicator > i'));
        $this->assertStringContainsString('overflow: auto', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-body'));
        $this->assertDoesNotMatchRegularExpression('/<div[^>]+class="rt-personnel-timeline(?:-body)?(?: [^"]*)?"[^>]+wire:loading.remove/', $view);
    }

    public function test_timeline_header_uses_quiet_theme_surfaces_while_duties_have_clearer_state_colors(): void
    {
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $workspace = file_get_contents(resource_path('css/disposition-workspace.css'));
        $header = $this->cssDeclarationsFor($workspace, '.rt-shift-plan .rt-personnel-timeline-header');
        $this->assertStringContainsString('background: var(--dispo-subtle', $header);
        foreach (['#991d38', '#851930', '#682137', '#581c2e'] as $oldHeaderColor) {
            $this->assertStringNotContainsString($oldHeaderColor, $workspace);
        }
        $this->assertStringContainsString('var(--timeline-state-color) 19%', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-time'));
        $this->assertStringContainsString('var(--timeline-state-color) 17%', $this->cssDeclarationsFor($css, '.dark .rt-personnel-timeline-time'));
        $this->assertStringContainsString('opacity: .95', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-mark'));
        foreach (['confirmed', 'requested', 'in_progress', 'completed'] as $state) {
            $this->assertStringContainsString('[data-state="'.$state.'"]', $css);
        }
    }

    public function test_shift_workspace_wrappers_pass_the_bounded_height_to_both_planner_entry_points(): void
    {
        $workspace = file_get_contents(resource_path('views/livewire/operations/page-workspace.blade.php'));
        $planning = file_get_contents(resource_path('views/livewire/operations/planning-page-workspace.blade.php'));
        $css = str_replace("'", '"', file_get_contents(resource_path('css/disposition-workspace.css')));

        $this->assertMatchesRegularExpression('/<div\b[^>]*data-page-workspace-content[^>]*>/', $workspace);
        $this->assertStringContainsString('data-planning-workspace="{{ $page }}"', $planning);
        $this->assertStringContainsString('<livewire:admin.operations.shift-management', $planning);

        // The hub adds two ancestors that the original direct workspace did not have.
        // Every one must shrink inside the viewport instead of growing to all staff rows.
        foreach ([
            '[data-page-workspace-content]:has(.rt-shift-plan)',
            '[data-planning-workspace="shifts"]:has(> .rt-shift-plan)',
        ] as $selector) {
            $declarations = $this->cssDeclarationsFor($css, $selector);
            foreach (['display: flex', 'flex-direction: column', 'flex: 1 1 auto', 'min-height: 0', 'overflow: hidden'] as $declaration) {
                $this->assertStringContainsString($declaration, $declarations, $selector.' must pass the bounded planner height');
            }
        }

        foreach ([
            '[data-page-live-content]:has(> .rt-shift-plan) > .rt-shift-plan',
            '[data-planning-workspace="shifts"] > .rt-shift-plan',
        ] as $selector) {
            $declarations = $this->cssDeclarationsFor($css, $selector);
            foreach (['min-height: 0', 'overflow: hidden', 'flex: 1 1 auto'] as $declaration) {
                $this->assertStringContainsString($declaration, $declarations, $selector.' must keep the planner inside its parent');
            }
        }
    }

    public function test_timeline_preserves_native_two_axis_scrolling_and_synchronized_header_and_footer(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $scrollport = $this->cssDeclarationsFor($css, '.rt-personnel-timeline-body');

        foreach (['min-height: 0', 'flex: 1 1 auto', 'overflow: auto', 'overscroll-behavior: contain'] as $declaration) {
            $this->assertStringContainsString($declaration, $scrollport);
        }
        $this->assertMatchesRegularExpression('/class="rt-personnel-timeline-body snap-x snap-mandatory"[^>]+x-ref="timelineBody"[^>]+x-on:scroll.passive="syncHorizontal\(\$event.target\)"/', $view);
        $this->assertStringContainsString('x-ref="timelineHeader"', $view);
        $this->assertStringContainsString('x-ref="timelineScrollbar" x-on:scroll="syncHorizontal($event.target)"', $view);
        $this->assertStringContainsString('data-no-sidebar-swipe', $view);
        $this->assertStringNotContainsString('x-on:wheel.prevent', $view);
        $this->assertStringNotContainsString('x-on:touchmove.prevent', $view);
    }

    public function test_personnel_header_keeps_a_named_keyboard_toggle_and_icon_when_its_visible_label_is_hidden(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertSame(1, preg_match('/<button\b[^>]*data-timeline-person-toggle[^>]*>.*?<\/button>/s', $view, $toggle));
        foreach ([
            'type="button"', 'x-on:click="togglePersonnelColumn()"',
            'x-bind:aria-expanded="personnelCompact ? \'false\' : \'true\'"',
            'Mitarbeiterspalte erweitern', 'Mitarbeiterspalte kompakt anzeigen',
            'aria-label="Mitarbeiterspalte kompakt anzeigen"', 'aria-expanded="true"',
            '<i class="far fa-users" aria-hidden="true"></i>',
            '<span class="rt-personnel-timeline-person-label">Mitarbeiter</span>',
        ] as $contract) {
            $this->assertStringContainsString($contract, $toggle[0]);
        }
        foreach ([
            'x-effect="applyPersonnelMode()"',
            'x-on:pointerover.window="pointerPersonnel($event)"',
            'x-on:pointerout.window="pointerPersonnel($event)"',
            'x-on:focusin.window="focusPersonnel($event.target)"',
            'x-on:focusout.window="focusPersonnel($event.relatedTarget)"',
            'data-timeline-person-column wire:key="staff-person-',
        ] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $button = $this->cssDeclarationsFor($css, '.rt-personnel-timeline-person-toggle');
        foreach (['min-width: 44px', 'min-height: 44px', 'outline: 2px solid'] as $declaration) {
            $this->assertStringContainsString($declaration, $button);
        }
    }

    public function test_compact_personnel_retains_avatar_identity_preview_and_inactive_status_layout_space(): void
    {
        $user = (object) ['name' => 'Marcel Schaarschmidt', 'profile' => null, 'person' => null,
            'email' => '', 'currentTeam' => null, 'profile_photo_url' => '/avatar.png'];
        $html = Blade::render('<x-user.public-info :user="$user" :size="6" :show-presence="false" name-format="initial-surname" class="rt-timeline-person-identity" />', compact('user'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"rt-timeline-person-identity")]/span[1]/img[@alt="Marcel Schaarschmidt"]')->length);
        $this->assertSame(2, $xpath->query('//div[contains(@class,"rt-timeline-person-identity")]/span')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"rt-timeline-person-identity")]/span[last()]//*[@title="Marcel Schaarschmidt"]')->length);
        $this->assertStringContainsString('M. Schaarschmidt', strip_tags($html));

        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        foreach (['<x-user.person-anchor-preview', 'class="rt-timeline-person-identity"',
            "@include('livewire.operations.partials.timeline-workload'", 'ops-muted rt-timeline-person-status',
            "{{ __('app.open_person_preview') }}: {{ \$row['user']->name }}"] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        foreach (['.rt-personnel-timeline-person-label', '.rt-timeline-person-identity > span:last-child', '.rt-timeline-workload-anchor'] as $selector) {
            $this->assertStringContainsString('display: none', $this->cssDeclarationsFor($css, "[data-personnel-compact='true'] ".$selector));
        }
        $this->assertStringContainsString('display: none !important', $this->cssDeclarationsFor($css, "[data-personnel-compact='true'] .rt-timeline-workload-anchor"));
        $status = $this->cssDeclarationsFor($css, "[data-personnel-compact='true'] .rt-timeline-person-status");
        $this->assertStringContainsString('visibility: hidden', $status);
        $this->assertStringContainsString('white-space: nowrap', $status);
        $this->assertStringNotContainsString('display: none', $status);
        $this->assertStringContainsString('min-height: 48px', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-name'));
        $this->assertStringContainsString('min-height: max(48px, calc(var(--timeline-lanes, 1) * var(--timeline-lane-height) + var(--timeline-lane-padding)))', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-track'));
    }

    public function test_tablet_default_and_directional_toggle_cue_do_not_add_another_scroll_owner_or_listener(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $script = file_get_contents(resource_path('js/staff-timeline.js'));
        $this->assertStringContainsString('--timeline-personnel-default-compact: 0', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 1024px\)\s*\{\s*\.rt-personnel-timeline\s*\{\s*--timeline-personnel-default-compact: 1;/', $css);
        $this->assertStringContainsString('--timeline-name-width: var(--timeline-name-compact-width)',
            $this->cssDeclarationsFor($css, '.rt-personnel-timeline:not([data-personnel-compact])'));
        $this->assertStringContainsString('class="rt-personnel-timeline-person-cue" aria-hidden="true"', $view);
        $this->assertStringContainsString('focusable="false"', $view);
        $cue = $this->cssDeclarationsFor($css, '.rt-personnel-timeline-person-cue');
        $this->assertStringContainsString('pointer-events: none', $cue);
        $this->assertStringContainsString('transform: rotate(180deg)', $cue);
        $this->assertStringContainsString('transform: rotate(0)', $cue);
        $this->assertStringContainsString('transition: none', $cue);
        $this->assertStringContainsString("target.matches(':focus-visible')", $script);
        $this->assertStringContainsString('this.refreshPersonnelPreference(style)', $script);
        $this->assertStringContainsString('this.personnelExplicit = true', $script);
        $this->assertSame(1, substr_count($script, 'new ResizeObserver('));
        $this->assertSame(1, substr_count($script, 'new MutationObserver('));
        $this->assertStringNotContainsString('window.addEventListener(', $script);
        $this->assertStringNotContainsString('preventDefault(', $script);
        $this->assertStringNotContainsString('x-on:touchmove.prevent', $view);
    }

    public function test_two_compact_lanes_fit_48px_without_shrinking_the_time_text_or_hiding_additional_duties(): void
    {
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        preg_match('/\.rt-personnel-timeline-track\s*\{([^}]+)\}/', $css, $normal);
        preg_match('/\.rt-personnel-timeline-track\[data-timeline-density=\'compact\'\]\s*\{([^}]+)\}/', $css, $compact);
        $values = function (string $declarations): array {
            preg_match_all('/--timeline-(lane-height|lane-padding|badge-height|mark-top):\s*(\d+)px/', $declarations, $matches, PREG_SET_ORDER);

            return array_column(array_map(fn ($match) => [$match[1], (int) $match[2]], $matches), 1, 0);
        };
        $normal = $values($normal[1]);
        $compact = $values($compact[1]);
        $this->assertSame(['lane-height' => 32, 'lane-padding' => 12, 'badge-height' => 28, 'mark-top' => 27], $normal);
        $this->assertSame(['lane-height' => 22, 'lane-padding' => 4, 'badge-height' => 18, 'mark-top' => 17], $compact);
        foreach ([1 => 48, 2 => 48, 3 => 70, 4 => 92] as $lanes => $expectedHeight) {
            $layout = $lanes > 1 ? $compact : $normal;
            $rowHeight = max(48, $lanes * $layout['lane-height'] + $layout['lane-padding']);
            $this->assertSame($expectedHeight, $rowHeight);
            $previousEnd = 0;
            for ($lane = 0; $lane < $lanes; $lane++) {
                $eventTop = ($rowHeight - $lanes * $layout['lane-height']) / 2 + $lane * $layout['lane-height'];
                $this->assertGreaterThanOrEqual($previousEnd, $eventTop, 'Adjacent duty hit areas must not overlap');
                $this->assertLessThanOrEqual($rowHeight, $eventTop + $layout['lane-height']);
                $this->assertLessThanOrEqual($layout['lane-height'], 2 + $layout['badge-height']);
                $previousEnd = $eventTop + $layout['lane-height'];
            }
        }
        preg_match('/\.rt-personnel-timeline-event\s*\{([^}]+)\}/', $css, $eventRule);
        $event = preg_replace('/\s+/', ' ', $eventRule[1]);
        $this->assertStringContainsString('top: calc((100% - var(--timeline-lanes, 1) * var(--timeline-lane-height)) / 2', $event);
        $this->assertStringContainsString('height: var(--timeline-lane-height)', $event);
        $this->assertStringNotContainsString('overflow: hidden', $event);
        preg_match('/\.rt-personnel-timeline-bar\s*\{([^}]+)\}/', $css, $bar);
        $this->assertStringContainsString('height: 100%', $bar[1]);
        preg_match('/\.rt-personnel-timeline-time\s*\{([^}]+)\}/', $css, $badgeRule);
        $badge = preg_replace('/\s+/', ' ', $badgeRule[1]);
        foreach (['height: var(--timeline-badge-height)', 'font-size: 11px', 'line-height: 16px', 'padding: 0 8px'] as $declaration) {
            $this->assertStringContainsString($declaration, $badge);
        }
        preg_match('/\.rt-personnel-timeline-day\s*\{([^}]+)\}/', $css, $dayRule);
        $day = preg_replace('/\s+/', ' ', $dayRule[1]);
        $this->assertStringContainsString('min-height: 48px', $day);
        $this->assertStringNotContainsString('--timeline-lanes', $day, 'The existing grid row, not a per-day lane override, owns the shared height');
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertStringContainsString('data-timeline-density="{{ $row[\'lane_count\'] > 1 ? \'compact\' : \'normal\' }}"', $view);
        $this->assertStringNotContainsString('style="--timeline-lanes:{{ max(1, $cell[\'lane_count\']) }}"', $view);
    }

    public function test_compact_column_width_is_shared_by_native_scroll_surfaces_and_preserves_mobile_full_widths(): void
    {
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        foreach (['--timeline-name-full-width: 180px', '--timeline-name-compact-width: 52px',
            '--timeline-name-width: var(--timeline-name-full-width)', '--timeline-name-full-width: 124px'] as $declaration) {
            $this->assertStringContainsString($declaration, $css);
        }
        $this->assertStringContainsString('--timeline-name-width: var(--timeline-name-compact-width)',
            $this->cssDeclarationsFor($css, ".rt-personnel-timeline[data-personnel-compact='true']"));
        foreach (['.rt-personnel-timeline-header', '.rt-personnel-timeline-scrollbar-row'] as $selector) {
            $this->assertStringContainsString('grid-template-columns: var(--timeline-name-width) minmax(0, 1fr)', $this->cssDeclarationsFor($css, $selector));
        }
        $this->assertStringContainsString('width: calc(var(--timeline-name-width) + var(--timeline-days) * var(--timeline-day-width))', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-grid'));
        $this->assertStringContainsString('scroll-padding-left: var(--timeline-name-width)', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-body'));
        $planningCss = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $this->assertStringContainsString('--timeline-name-full-width: 170px', $planningCss);
        $this->assertStringNotContainsString('--timeline-name-width: 170px', $planningCss);
    }

    public function test_column_motion_clips_only_personnel_and_keeps_passive_native_input_and_reduced_motion(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringContainsString('x-on:wheel.passive="wheelPersonnel($event)"', $view);
        $this->assertStringNotContainsString('x-on:wheel.prevent', $view);
        $this->assertStringNotContainsString('x-on:touchmove.prevent', $view);
        $column = $this->cssDeclarationsFor($css, "[data-personnel-animating='true'] [data-timeline-person-column]");
        $this->assertStringContainsString('overflow: clip', $column);
        $this->assertStringNotContainsString('height:', $column);
        $this->assertStringNotContainsString('transform:', $column);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('animation: none', $this->cssDeclarationsFor($css, "[data-personnel-animating='true'] .rt-timeline-person-identity > span:last-child"));
    }

    private function cssDeclarationsFor(string $css, string $selector): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $declarations = '';
        foreach ($rules as $rule) {
            if (str_contains($rule[1], $selector)) {
                $declarations .= $rule[2];
            }
        }

        return preg_replace('/\s+/', ' ', $declarations);
    }

    public function test_event_details_open_only_on_click_without_removing_workload_hover(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertMatchesRegularExpression('/<x-ui\.dropdown\.anchor-dropdown[^>]+:open-on-hover="false"[^>]+data-timeline-event-dropdown>/', $view);
        $this->assertStringContainsString("querySelector('[data-timeline-detail-focus]')?.focus({ preventScroll: true })", $view);
        $workload = file_get_contents(resource_path('views/livewire/operations/partials/timeline-workload.blade.php'));
        $this->assertStringContainsString(':open-on-hover="true"', $workload);
    }

    public function test_shared_dropdown_keeps_its_default_height_and_bounds_the_opt_in_height(): void
    {
        foreach ([['', 448], [':max-height="560"', 560], [':max-height="9999"', 960], [':max-height="0"', 160]] as [$attribute, $height]) {
            $html = Blade::render('<x-ui.dropdown.anchor-dropdown '.$attribute.'><x-slot:trigger><button>Test</button></x-slot:trigger><x-slot:content>Details</x-slot:content></x-ui.dropdown.anchor-dropdown>');
            $this->assertStringContainsString('maximumHeight: '.$height, $html);
            $this->assertStringContainsString('Math.min(this.maximumHeight, availableHeight)', $html);
        }
    }

    public function test_event_detail_reuses_full_person_identity_and_keeps_all_operational_fields(): void
    {
        $html = $this->renderEventDetail();
        foreach (['Dienstdetails schließen', 'close(true)', 'Marcel Schaarschmidt', 'title="Marcel Schaarschmidt"', 'min-w-0 truncate text-sm', 'Beginn', 'Ende', '05.10.2026', '06.10.2026', 'Kunde', 'Test Rail', 'Tätigkeit', 'Tf', 'Einsatzort', 'Bremen', 'Planstatus', 'Geplante Pause', '30 Minuten', 'Nicht hinterlegt', 'Eingeplant · KW 41', '8,5 h', 'Schicht öffnen', 'data-shift-detail-open="42"'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('&lt;Dienst&gt;', $html);
        $this->assertStringNotContainsString('<Dienst>', $html);
        $this->assertStringNotContainsString('Zeitumstellung', $html);
    }

    public function test_event_card_uses_shared_accessible_tabs_between_header_and_non_scrolling_content(): void
    {
        foreach ([false, true] as $absence) {
            $html = $this->renderEventDetail($absence);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//article/header')->length);
            $this->assertSame(1, $xpath->query('//article/footer')->length);
            $this->assertSame(1, $xpath->query('//article/header/following-sibling::*[1][@role="tablist"]')->length);
            $this->assertSame(1, $xpath->query('//article/div[@role="tablist"][@data-rt-dropdown-keep-open]/following-sibling::*[1][contains(@class,"detail-panels")]')->length);
            $this->assertSame(2, $xpath->query('//article/div[contains(@class,"detail-panels")]/section[@role="tabpanel"]')->length);
            $this->assertSame(0, $xpath->query('//section[@role="tabpanel"]//footer | //section[@role="tabpanel"]//header[contains(@class,"detail-header")]')->length);
            foreach (['period', 'assignment'] as $key) {
                $tab = $xpath->query('//article/div[@role="tablist"]/button[@data-panel-tab="'.$key.'"]')->item(0);
                $panel = $xpath->query('//article/div/section[@data-detail-tab="'.$key.'"]')->item(0);
                $this->assertNotNull($tab);
                $this->assertNotNull($panel);
                $this->assertSame('tab', $tab->getAttribute('role'));
                $this->assertSame($tab->getAttribute('aria-controls'), $panel->getAttribute('id'));
                $this->assertSame($tab->getAttribute('id'), $panel->getAttribute('aria-labelledby'));
                $this->assertSame($key === 'period' ? 'true' : 'false', $tab->getAttribute('aria-selected'));
                $this->assertSame($key === 'period' ? '0' : '-1', $tab->getAttribute('tabindex'));
                $this->assertSame("detailTab === '".$key."'", $panel->getAttribute('x-show'));
                $this->assertSame("detailTab !== '".$key."'", $panel->getAttribute(':inert'));
            }
            $this->assertSame(1, $xpath->query('//section[@data-detail-tab="period"]//*[@role="group" and @aria-label="Zeitraum"]')->length);
            $this->assertSame(1, $xpath->query('//section[@data-detail-tab="assignment"]//*[contains(@class,"detail-person")]')->length);
            $this->assertDoesNotMatchRegularExpression('/detailPages|goToDetailPage|@scroll|x-on:scroll|@wheel|x-on:wheel|snap-y|snap-start|overflow-y-auto/', $html);
        }
        $partial = file_get_contents(resource_path('views/livewire/operations/partials/timeline-event-detail.blade.php'));
        $this->assertStringContainsString('<x-operations.panel.tabs', $partial);
        $this->assertStringContainsString('model="detailTab"', $partial);
        $source = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertStringContainsString(':fixed-height="true"', $source);
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringContainsString('display: grid', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-detail-panels'));
        $this->assertStringContainsString('overflow: hidden', $this->cssDeclarationsFor($css, '.rt-personnel-timeline-detail-tabpanel'));
        $this->assertDoesNotMatchRegularExpression('/\.rt-personnel-timeline-detail-footer \{[^}]*position: sticky/', $css);
    }

    public function test_detail_tab_ids_are_unique_per_employee_event_and_event_kind(): void
    {
        $ids = [];
        foreach ([[false, 23, 42], [false, 24, 42], [false, 23, 43], [true, 23, 42]] as [$absence, $userId, $shiftId]) {
            $html = $this->renderEventDetail($absence, $userId, $shiftId);
            preg_match_all('/\bid="(timeline-detail-[^"]+)"/', $html, $matches);
            $this->assertCount(4, $matches[1]);
            $ids = [...$ids, ...$matches[1]];
        }
        $this->assertCount(16, array_unique($ids));
    }

    public function test_detail_footer_is_flush_primary_and_tab_motion_respects_reduced_motion(): void
    {
        foreach ([false, true] as $absence) {
            $html = $this->renderEventDetail($absence);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//article/footer/button[contains(@class,"rt-ui-button-primary")]')->length);
            $this->assertSame(0, $xpath->query('//article/footer//*[@role="tab"]')->length);
            $this->assertStringContainsString($absence ? 'operations-open-absence' : 'operations-shift-detail-request', $html);
        }
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $footer = $this->cssDeclarationsFor($css, '.rt-personnel-timeline-detail-footer');
        $this->assertStringContainsString('padding: 0', $footer);
        $this->assertStringContainsString('background: var(--rt-primary', $footer);
        $button = $this->cssDeclarationsFor($css, '.rt-personnel-timeline-detail-footer .rt-ui-button');
        foreach (['width: 100%', 'margin: 0', 'border-radius: 0'] as $declaration) {
            $this->assertStringContainsString($declaration, $button);
        }
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.rt-timeline-detail-enter\s*\{[^}]*transition: opacity/', $css);
        $this->assertMatchesRegularExpression('/\.rt-timeline-detail-enter-from, \.rt-timeline-detail-enter-to\s*\{\s*transform: none/', $css);
        $partial = file_get_contents(resource_path('views/livewire/operations/partials/timeline-event-detail.blade.php'));
        $this->assertSame(2, substr_count($partial, 'x-transition:enter="rt-timeline-detail-enter"'));
        $this->assertStringNotContainsString('x-transition:leave', $partial);
    }

    public function test_only_opted_in_dropdowns_use_a_fixed_non_scrolling_outer_shell(): void
    {
        foreach ([false, true] as $fixed) {
            $html = Blade::render('<x-ui.dropdown.anchor-dropdown :fixed-height="$fixed"><x-slot:trigger><button>Test</button></x-slot:trigger><x-slot:content>Details</x-slot:content></x-ui.dropdown.anchor-dropdown>', compact('fixed'));
            $this->assertStringContainsString('fixedHeight: '.($fixed ? 'true' : 'false'), $html);
            $this->assertMatchesRegularExpression('/class="rt-ui-surface rt-ui-dropdown-panel[^"\r\n]*'.($fixed ? 'overflow-hidden' : 'overflow-y-auto').'/', $html);
        }
    }

    public function test_all_day_absence_uses_inclusive_dates_without_inventing_midnight_shifts_or_hours(): void
    {
        $html = $this->renderEventDetail(true);
        foreach (['Ganztägig', '05.10.2026', '06.10.2026', 'Abwesenheit öffnen'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['07.10.2026', '00:00', 'Regelarbeitszeit', 'Eingeplant', 'Schicht öffnen', 'Kunde', 'Geplante Pause'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertStringContainsString('rt-event-mini-calendar', $html);
        $this->assertStringNotContainsString('rt-event-mini-map', $html);
    }

    public function test_event_previews_mount_on_open_and_unknown_locations_never_get_a_fake_pin(): void
    {
        $html = $this->renderEventDetail();
        foreach (['template x-if="open"', 'rt-event-mini-calendar', 'rt-event-mini-map', 'Standort nicht verortet'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('GeoNames', $html);
        $this->assertStringNotContainsString('rt-event-mini-map__sources', $html);
        $this->assertStringNotContainsString('data-location-marker', $html);
        $source = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertStringContainsString('width="96" :max-height="560" :open-on-hover="false"', $source);
    }

    public function test_map_marks_only_a_resolved_locality_and_explains_its_precision(): void
    {
        $html = Blade::render('<x-operations.timeline-mini-map :preview="$preview" location="Treuchlingen Gbf." />', [
            'preview' => ['state' => 'located', 'label' => 'Ortslage · ungefähr', 'place' => 'Treuchlingen', 'x' => 80.5, 'y' => 120.3],
        ]);
        foreach (['data-location-marker', 'translate(80.5 120.3)', 'Ortslage · ungefähr', 'keine genaue Einsatzadresse', 'title="Treuchlingen Gbf."'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $unknown = Blade::render('<x-operations.timeline-mini-map :preview="$preview" :location="$location" />', [
            'preview' => ['state' => 'unknown', 'label' => 'Standort nicht verortet'], 'location' => '<script>alert(1)</script>',
        ]);
        $this->assertStringNotContainsString('<script>', $unknown);
        $this->assertStringNotContainsString('data-location-marker', $unknown);
    }

    public function test_map_credits_remain_accessible_in_help_instead_of_each_shift_dropdown(): void
    {
        $help = file_get_contents(resource_path('views/livewire/help-center.blade.php'));
        foreach (['data-map-credits', 'GeoNames und Mitwirkende', 'https://www.geonames.org/', 'https://creativecommons.org/licenses/by/4.0/', 'Natural Earth', 'lokal gefiltert'] as $credit) {
            $this->assertStringContainsString($credit, $help);
        }
        $this->assertStringContainsString("route('help')", file_get_contents(resource_path('views/components/ui/info-modal.blade.php')));
    }

    public function test_station_location_displays_city_centre_pin_without_inline_sources(): void
    {
        $preview = TimelineLocationPreview::fromShift(new Shift(['location_name' => 'München Milbertshofen']));
        $html = Blade::render('<x-operations.timeline-mini-map :preview="$preview" location="München Milbertshofen" />', compact('preview'));

        foreach (['data-location-marker', 'Stadtmitte · ungefähr', 'München', 'title="München Milbertshofen"', 'keine genaue Einsatzadresse'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('GeoNames', $html);
        $this->assertStringNotContainsString('rt-event-mini-map__sources', $html);
    }

    private function renderEventDetail(bool $absence = false, int $userId = 23, int $shiftId = 42): string
    {
        $detailStart = CarbonImmutable::parse($absence ? '2026-10-05T00:00:00+02:00' : '2026-10-05T22:00:00+02:00');
        $detailEnd = CarbonImmutable::parse($absence ? '2026-10-07T00:00:00+02:00' : '2026-10-06T06:00:00+02:00');
        $user = (object) ['id' => $userId, 'name' => 'Marcel Schaarschmidt', 'profile' => null, 'person' => null, 'email' => '', 'currentTeam' => null, 'profile_photo_url' => '/avatar.png'];

        return view('livewire.operations.partials.timeline-event-detail', [
            'detailStart' => $detailStart, 'detailEnd' => $detailEnd, 'detailDstChanged' => false,
            'state' => 'confirmed', 'zone' => 'Europe/Berlin', 'absencesOnly' => $absence,
            'row' => ['user' => $user, 'weekly_working_hours' => null, 'planned_hours_by_week' => ['2026-41' => 8.5]],
            'event' => ['kind' => $absence ? 'absence' : 'shift', 'title' => $absence ? 'Urlaub' : '<Dienst>', 'status' => 'Bestätigt',
                'detail' => $absence ? '' : 'Test Rail', 'role_name' => $absence ? null : 'Tf', 'location_name' => $absence ? null : 'Bremen',
                'shift_status_label' => $absence ? null : 'Veröffentlicht', 'planned_break_minutes' => $absence ? 0 : 30,
                'iso_week' => '2026-41', 'visible_start' => $detailStart, 'shift_id' => $absence ? null : $shiftId, 'absence_id' => 12],
        ])->render();
    }

    public function test_compact_names_preserve_surnames_and_full_avatar_labels_without_changing_the_default(): void
    {
        foreach ([
            ['Abdullah Demir', null, 'A. Demir'],
            ['Marcel Schaarschmidt', null, 'M. Schaarschmidt'],
            ['Ömer von der Linden', null, 'Ö. von der Linden'],
            ['Meyer, Anna Maria', null, 'A. Meyer'],
            ['Cher', null, 'Cher'],
            ['', null, 'test@example.test'],
            ['Account label', (object) ['first_name' => 'Anna Maria', 'last_name' => 'von der Linden-Stein'], 'A. von der Linden-Stein'],
        ] as [$name, $profile, $expected]) {
            $user = (object) ['name' => $name, 'profile' => $profile, 'person' => null, 'email' => 'test@example.test', 'currentTeam' => null, 'profile_photo_url' => '/avatar.png'];
            $html = Blade::render('<x-user.public-info :user="$user" :show-presence="false" name-format="initial-surname" />', compact('user'));
            $this->assertStringContainsString($expected, strip_tags($html));
            $this->assertStringContainsString('alt="'.e($name ?: 'test@example.test').'"', $html);
            $this->assertStringContainsString('min-w-0 truncate text-sm', $html);
            $this->assertStringNotContainsString('whitespace-normal break-words', $html);
            $this->assertStringContainsString('title="'.e($name ?: 'test@example.test').'"', $html);
            $full = Blade::render('<x-user.public-info :user="$user" :show-presence="false" />', compact('user'));
            $this->assertStringContainsString($name ?: 'test@example.test', strip_tags($full));
        }
        $person = (object) ['vorname' => 'Élodie', 'nachname' => 'de la Cruz', 'email' => '', 'user' => null];
        $html = Blade::render('<x-user.public-info :person="$person" name-format="initial-surname" />', compact('person'));
        $this->assertStringContainsString('É. de la Cruz', strip_tags($html));
        $this->assertStringContainsString('alt="de la Cruz, Élodie"', $html);
    }

    public function test_timeline_has_neutral_names_and_only_day_grid_lines(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertStringContainsString('name-format="initial-surname"', $view);
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringNotContainsString('calc(25% - 1px)', $css);
        $this->assertStringContainsString('border-right: 1px solid', $css);
        $this->assertStringContainsString('border-bottom: 1px solid', $css);
        $disposition = file_get_contents(resource_path('css/disposition-workspace.css'));
        $this->assertStringContainsString('background: var(--dispo-subtle, #f5f6f7)', $disposition);
        $this->assertStringNotContainsString('#faf0f3', $disposition);
    }

    public function test_navigation_remains_touch_sized_and_loading_has_a_separate_animated_state(): void
    {
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertMatchesRegularExpression('/\.rt-personnel-timeline-direction \{[^}]*width: 44px; height: 44px;[^}]*background: transparent/', $css);
        $this->assertStringContainsString('.rt-personnel-timeline-direction::before', $css);
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        $this->assertStringContainsString('x-show.important="plannerReady && !plannerLoading && !plannerError"', $view);
        $this->assertStringContainsString('Schichten werden geladen', $view);
        $this->assertStringContainsString('far fa-spinner-third', $view);
    }

    public function test_distribution_pagination_uses_the_shared_dropdown_keep_open_contract(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/operations/partials/pending-distribution.blade.php'));
        $this->assertStringContainsString('class="rt-shift-distribution__pagination" data-rt-dropdown-keep-open', $view);
    }

    public function test_timeline_uses_shared_people_compact_rows_and_native_snapping_without_empty_markers(): void
    {
        $view = file_get_contents(resource_path('views/livewire/operations/staff-timeline.blade.php'));
        foreach (['rtStaffTimeline', 'snap-x snap-mandatory', 'snap-start', 'data-no-sidebar-swipe', 'canScrollLeft', 'canScrollRight', 'x-user.public-info', 'x-user.person-anchor-preview', 'x-intersect.once="$wire.loadMore()"'] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        $this->assertStringNotContainsString('rt-personnel-timeline-no-entry', $view);
        $this->assertStringNotContainsString('Kein Eintrag', $view);
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertStringNotContainsString('min-height: 74px', $css);
        $this->assertStringContainsString('min-height: 48px', $css);
        $this->assertStringContainsString('scroll-padding-left: var(--timeline-name-width)', $css);
    }

    public function test_period_trigger_has_no_timezone_subtitle_and_shell_uses_global_drawer(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/operations/shift-management.blade.php'));
        $this->assertStringNotContainsString('class="rt-calendar-timezone"', $view);
        $shell = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $this->assertStringContainsString('data-sidebar-drawer="true"', $shell);
        $this->assertStringContainsString('data-sidebar-collapsible="false"', $shell);
        $topbar = file_get_contents(resource_path('views/layouts/topbar.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/class="vertical-menu-btn[^"\r\n]*lg:hidden/', $topbar);
    }
}
