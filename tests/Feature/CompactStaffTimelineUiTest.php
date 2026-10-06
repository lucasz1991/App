<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CompactStaffTimelineUiTest extends TestCase
{
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

    private function renderEventDetail(bool $absence = false): string
    {
        $detailStart = CarbonImmutable::parse($absence ? '2026-10-05T00:00:00+02:00' : '2026-10-05T22:00:00+02:00');
        $detailEnd = CarbonImmutable::parse($absence ? '2026-10-07T00:00:00+02:00' : '2026-10-06T06:00:00+02:00');
        $user = (object) ['name' => 'Marcel Schaarschmidt', 'profile' => null, 'person' => null, 'email' => '', 'currentTeam' => null, 'profile_photo_url' => '/avatar.png'];

        return view('livewire.operations.partials.timeline-event-detail', [
            'detailStart' => $detailStart, 'detailEnd' => $detailEnd, 'detailDstChanged' => false,
            'state' => 'confirmed', 'zone' => 'Europe/Berlin', 'absencesOnly' => $absence,
            'row' => ['user' => $user, 'weekly_working_hours' => null, 'planned_hours_by_week' => ['2026-41' => 8.5]],
            'event' => ['kind' => $absence ? 'absence' : 'shift', 'title' => $absence ? 'Urlaub' : '<Dienst>', 'status' => 'Bestätigt',
                'detail' => $absence ? '' : 'Test Rail', 'role_name' => $absence ? null : 'Tf', 'location_name' => $absence ? null : 'Bremen',
                'shift_status_label' => $absence ? null : 'Veröffentlicht', 'planned_break_minutes' => $absence ? 0 : 30,
                'iso_week' => '2026-41', 'visible_start' => $detailStart, 'shift_id' => $absence ? null : 42, 'absence_id' => 12],
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
