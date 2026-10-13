<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileTabMotionUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();

        foreach ([
            '2026_07_22_000002_create_employee_document_requirements_table.php',
            '2026_07_18_000001_create_activity_log_table.php',
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_09_17_190000_create_employee_payroll_references.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_101000_create_personnel_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php',
            '2026_08_17_070000_create_device_management_tables.php',
            '2026_10_11_120200_create_employee_location_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'name' => 'Tab Motion Synthetic Fixture',
            'email' => 'tab-motion@example.invalid',
        ]);
    }

    #[DataProvider('profileModes')]
    public function test_both_native_navigation_rows_have_one_decorative_marker_and_no_category_counts(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, [
            'userId' => $this->employee->id,
            'embedded' => $embedded,
        ])->assertSuccessful()->assertSet('profileTab', 'userDetails');

        $xpath = $this->xpath($component->html());
        $main = $xpath->query('//*[@data-profile-navigation]/nav[contains(@class,"employee-profile__groups")]');
        $sub = $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]');
        $this->assertCount(1, $main);
        $this->assertCount(1, $sub);
        $this->assertMotionRow($xpath, $main->item(0), 'main');
        $this->assertMotionRow($xpath, $sub->item(0), 'sub');

        $groups = $xpath->query('./button[@data-profile-group]', $main->item(0));
        $this->assertSame(['Profil', 'Betrieb', 'Personalakte', 'Entwicklung', 'Arbeitszeit & Regeln'], array_map(
            static fn (DOMElement $group): string => trim(preg_replace('/\s+/u', ' ', $group->textContent)),
            iterator_to_array($groups),
        ));
        $this->assertSame('Profil & Stammdaten', $groups->item(0)->getAttribute('data-profile-group'));
        $this->assertSame('Betrieb', $groups->item(1)->getAttribute('data-profile-group'));
        $this->assertCount(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," employee-profile__group-count ")]'));
        foreach ($groups as $group) {
            $this->assertFalse($group->hasAttribute('x-bind:aria-current'));
            $this->assertFalse($group->hasAttribute('x-on:focus'));
            $this->assertSame('disabled', $group->getAttribute('wire:loading.attr'));
            $this->assertSame('setProfileTab', $group->getAttribute('wire:target'));
        }
        $this->assertConfirmedSelection($xpath, 'userDetails', 'Profil & Stammdaten');
        $this->assertCount(0, $xpath->query('//*[@data-profile-navigation]//*[@data-profile-motion-ready]'));
        Http::assertNothingSent();
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    #[DataProvider('confirmedSections')]
    public function test_selection_visuals_follow_only_native_confirmed_tab_and_authorized_group(string $tab, string $group): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $before = $this->employee->fresh()->getRawOriginal();
        if ($tab !== 'userDetails') {
            $component->call('setProfileTab', $tab)->assertSuccessful();
        }
        $component->assertSet('profileTab', $tab);
        $xpath = $this->xpath($component->html());
        $this->assertConfirmedSelection($xpath, $tab, $group);
        $this->assertMotionRow($xpath, $xpath->query('//*[@data-profile-navigation]/nav')->item(0), 'main');
        $this->assertMotionRow($xpath, $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]')->item(0), 'sub');
        $this->assertCount(0, $xpath->query('//*[@data-profile-navigation]//*[contains(@class,"employee-profile__group-count")]'));
        $this->assertSame($before, $this->employee->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function confirmedSections(): array
    {
        return [
            'profile overview' => ['userDetails', 'Profil & Stammdaten'],
            'personnel master data' => ['masterData', 'Personalakte'],
            'operational overview' => ['operationsOverview', 'Betrieb'],
            'operational shifts' => ['shifts', 'Betrieb'],
            'personnel file' => ['qualifications', 'Personalakte'],
            'development' => ['workflows', 'Entwicklung & Aufgaben'],
            'working time' => ['accounts', 'Arbeitszeit & Regeln'],
        ];
    }

    public function test_rejected_inline_save_keeps_confirmed_profile_selection_and_operations_unloaded(): void
    {
        $email = $this->employee->email;
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
                ->set('inlineValues.email', 'not-an-email')
                ->call('setProfileTab', 'operationsOverview')
                ->assertHasErrors('inlineValues.email')->assertSet('profileTab', 'userDetails');
            $this->assertConfirmedSelection($this->xpath($component->html()), 'userDetails', 'Profil & Stammdaten');
            $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => (bool) preg_match(
                '/\b(?:from|join)\s+["`]?\b(?:shifts|shift_assignments|employee_location_points|employee_location_consents)\b/i',
                $query['query'],
            ));
            $this->assertSame([], array_values($reads));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($email, $this->employee->fresh()->email);
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-employee-operations]'));
        Http::assertNothingSent();
    }

    public function test_shared_component_without_indicator_slot_keeps_its_original_default_behavior_and_counts(): void
    {
        $html = Blade::render('<x-operations.panel.tabs :tabs="$tabs" label="Original sections" id-prefix="original-tabs" model="section" />', [
            'tabs' => [
                'first' => ['label' => 'First', 'count' => 3],
                'second' => ['label' => 'Second', 'alert' => true],
            ],
        ]);
        $xpath = $this->xpath($html);
        $lists = $xpath->query('//*[@role="tablist"]');
        $this->assertCount(1, $lists);
        $this->assertFalse($lists->item(0)->hasAttribute('x-data'));
        $this->assertCount(0, $xpath->query('//*[contains(@class,"employee-profile__selection-marker")]'));
        $this->assertCount(0, $xpath->query('//*[@*[name()="wire:ignore"]]'));
        $this->assertCount(1, $xpath->query('//*[@id="original-tabs-tab-first" and @aria-selected="true"]'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"rt-ops-panel__count") and text()="3"]'));
        $this->assertCount(1, $xpath->query('//*[contains(@class,"rt-ops-panel__alert")]'));
        foreach ($xpath->query('//*[@role="tab"]') as $tab) {
            $this->assertNotSame('', $tab->getAttribute('x-bind:aria-selected'));
            $this->assertNotSame('', $tab->getAttribute('x-on:focus'));
            $this->assertFalse($tab->hasAttribute('wire:target'));
        }
    }

    public function test_optional_indicator_slot_is_a_single_decorative_sibling_not_a_new_tab_or_state_owner(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-operations.panel.tabs :tabs="$tabs" label="Lazy sections" id-prefix="lazy-tabs" active="second" action="setProfileTab">
                <x-slot:selectionIndicator><span class="employee-profile__selection-marker" data-profile-marker="sub" wire:ignore aria-hidden="true" x-data="rtProfileTabMotion({ variant: 'sub' })"></span></x-slot:selectionIndicator>
            </x-operations.panel.tabs>
            BLADE, [
            'tabs' => ['first' => ['label' => 'First'], 'second' => ['label' => 'Second']],
        ]);
        $xpath = $this->xpath($html);
        $lists = $xpath->query('//*[@role="tablist"]');
        $this->assertCount(1, $lists);
        $this->assertMotionRow($xpath, $lists->item(0), 'sub');
        $this->assertCount(2, $xpath->query('//*[@role="tab"]'));
        $this->assertCount(1, $xpath->query('//*[@role="tab" and @aria-selected="true" and @data-panel-tab="second"]'));
        foreach ($xpath->query('//*[@role="tab"]') as $tab) {
            $this->assertFalse($tab->hasAttribute('x-on:focus'));
            $this->assertFalse($tab->hasAttribute('x-bind:aria-selected'));
            $this->assertSame('setProfileTab', $tab->getAttribute('wire:target'));
            $this->assertSame('disabled', $tab->getAttribute('wire:loading.attr'));
            $this->assertStringContainsString('await $wire.call', $tab->getAttribute('x-on:click'));
        }
    }

    public function test_motion_does_not_introduce_public_livewire_selection_state(): void
    {
        $properties = array_filter(
            (new ReflectionClass(UserProfile::class))->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === UserProfile::class,
        );
        $this->assertSame([
            'embedded', 'profileTab', 'userId', 'user', 'inlineValues', 'dirtyInlineFields',
        ], array_map(static fn (ReflectionProperty $property): string => $property->getName(), array_values($properties)));
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('profileTab', 'userDetails');
        $snapshot = $this->xpath($component->html())->query('//*[@*[name()="wire:snapshot"]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $snapshot);
        $data = json_decode($snapshot->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR)['data'];
        foreach (['activeTab', 'selectedTab', 'profileGroup', 'motionSelection', 'selectionIndicator'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }

    public function test_motion_styling_is_scoped_with_readable_native_fallback_and_transparent_ready_tabs(): void
    {
        $css = file_get_contents(resource_path('css/employee-profile-shell.css'));
        $this->assertIsString($css);
        $this->assertStringNotContainsString('employee-profile__group-count', $css);
        $this->assertStringContainsString('border-radius: 0 !important; gap: 8px;', $css);
        $this->assertStringContainsString('background: transparent !important; box-shadow: none !important;', $css);
        $this->assertStringContainsString(".employee-profile__group[aria-current='page']::after { transform: scaleX(1); }", $css);
        $this->assertStringContainsString(".employee-profile__tabs .rt-ops-panel__tab[aria-selected='true'] { color: theme('colors.white'); background: var(--profile-area-selection);", $css);
        $this->assertStringContainsString('.employee-profile__groups[data-profile-motion-ready] .employee-profile__group::after { content: none; }', $css);
        $this->assertMatchesRegularExpression(
            '/\.employee-profile\[data-profile-shell\] \.employee-profile__tabs\[data-profile-motion-ready\][^{]+\{[^}]*background:\s*transparent\s*!important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.employee-profile\[data-profile-shell\] \.employee-profile__selection-marker\s*\{[^}]*overflow:\s*hidden;[^}]*background:\s*var\(--profile-area-selection\);[^}]*pointer-events:\s*none;/s',
            $css,
        );
        $this->assertStringContainsString('.employee-profile__selection-ink { position: absolute;', $css);
        $this->assertStringContainsString('.rt-ops-panel__tab:focus-visible { outline: 2px solid var(--profile-duty-signal); outline-offset: -5px; }', $css);
        $this->assertMatchesRegularExpression('/\.rt-ops-panel__tab:focus-visible::after\s*\{[^}]*inset:\s*3px;[^}]*z-index:\s*3;[^}]*pointer-events:\s*none;/s', $css);
        $this->assertStringContainsString(".rt-ops-panel__tab[aria-selected='true']:focus-visible::after { border-color: theme('colors.white'); }", $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('.employee-profile__panel { animation: none; }', $css);
        $this->assertMatchesRegularExpression('/\.employee-profile__tabs \.rt-ops-panel__tab\s*\{[^}]*min-height:\s*44px;/s', $css);
        $this->assertStringContainsString("Alpine.data('rtProfileTabMotion', profileTabMotion);", file_get_contents(resource_path('js/app.js')));
    }

    private function assertMotionRow(DOMXPath $xpath, DOMElement $row, string $variant): void
    {
        $markers = $xpath->query('./span[contains(concat(" ",normalize-space(@class)," ")," employee-profile__selection-marker ")]', $row);
        $this->assertCount(1, $markers);
        $marker = $markers->item(0);
        $this->assertStringContainsString('rtProfileTabMotion', $marker->getAttribute('x-data'));
        $this->assertMatchesRegularExpression('/variant\s*:\s*[\'"]'.preg_quote($variant, '/').'[\'"]/', $marker->getAttribute('x-data'));
        $this->assertSame($variant, $marker->getAttribute('data-profile-marker'));
        $this->assertStringNotContainsString('$wire', $marker->getAttribute('x-data'));
        $this->assertStringNotContainsString('profileTab', $marker->getAttribute('x-data'));
        $this->assertSame('true', $marker->getAttribute('aria-hidden'));
        $this->assertTrue($marker->hasAttribute('wire:ignore'));
        $this->assertFalse($marker->hasAttribute('role'));
        $this->assertFalse($marker->hasAttribute('tabindex'));
        $this->assertFalse($marker->hasAttribute('x-on:click'));
        $this->assertCount(0, $xpath->query('.//*[@role="tab" or @data-profile-group or @tabindex or @*[name()="wire:click"]]', $marker));
    }

    private function assertConfirmedSelection(DOMXPath $xpath, string $tabKey, string $group): void
    {
        $selectedGroups = $xpath->query('//*[@data-profile-navigation]//*[@data-profile-group and @aria-current="page"]');
        $this->assertCount(1, $selectedGroups);
        $this->assertSame($group, $selectedGroups->item(0)->getAttribute('data-profile-group'));
        $lists = $xpath->query('//*[@data-profile-navigation]//*[@role="tablist"]');
        $this->assertCount(1, $lists);
        foreach (['arrow-right', 'arrow-left', 'home', 'end'] as $key) {
            $this->assertNotSame('', $lists->item(0)->getAttribute('x-on:keydown.'.$key.'.prevent'));
        }
        $selectedTabs = $xpath->query('//*[@data-profile-navigation]//*[@role="tab" and @aria-selected="true"]');
        $this->assertCount(1, $selectedTabs);
        $this->assertSame($tabKey, $selectedTabs->item(0)->getAttribute('data-panel-tab'));
        foreach ($xpath->query('//*[@data-profile-navigation]//*[@role="tab"]') as $tab) {
            $this->assertSame('employee-profile-'.$this->employee->id.'-tab-'.$tab->getAttribute('data-panel-tab'), $tab->getAttribute('id'));
            $panels = $xpath->query('//*[@id="'.$tab->getAttribute('aria-controls').'"]');
            $this->assertCount(1, $panels);
            $this->assertSame('tabpanel', $panels->item(0)->getAttribute('role'));
            $this->assertSame($tab->getAttribute('id'), $panels->item(0)->getAttribute('aria-labelledby'));
            $this->assertSame($tab->getAttribute('data-panel-tab') === $tabKey ? '0' : '-1', $tab->getAttribute('tabindex'));
            $this->assertSame('setProfileTab', $tab->getAttribute('wire:target'));
            $this->assertFalse($tab->hasAttribute('x-on:focus'));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
