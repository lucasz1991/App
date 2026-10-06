<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CompactStaffTimelineUiTest extends TestCase
{
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
