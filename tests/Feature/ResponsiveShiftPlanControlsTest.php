<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ResponsiveShiftPlanControlsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Http::preventStrayRequests();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['name' => 'Synthetic Controls Manager', 'role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic Controls Customer', 'is_active' => true]);
    }

    public function test_view_trigger_removes_the_eyebrow_but_retains_the_current_name_and_radio_dropdown(): void
    {
        $views = ['table' => ['Tabelle', 'fa-list-alt'], 'day' => ['Tagesübersicht', 'fa-calendar-day'], 'staffing' => ['Besetzung', 'fa-users'], 'orders' => ['Leistungen', 'fa-briefcase'], 'timeline' => ['Zeitleiste', 'fa-clock']];
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        foreach ($views as $view => [$label, $icon]) {
            $component->call('setView', $view);
            $xpath = $this->xpath($component->html());
            $trigger = $xpath->query('//button[contains(@class,"rt-shift-plan-view-trigger")]')->item(0);
            $this->assertNotNull($trigger);
            $this->assertSame('Ansicht ändern: '.$label, $trigger->getAttribute('aria-label'));
            $this->assertStringContainsString($label, $trigger->getAttribute('title'));
            $this->assertSame($label, trim($trigger->textContent));
            $this->assertSame(0, $xpath->query('.//small', $trigger)->length);
            $this->assertSame(1, $xpath->query('.//i[@data-current-view="'.$view.'" and @aria-hidden="true" and contains(@class,"'.$icon.'")]', $trigger)->length);
            $menu = $xpath->query('//*[@aria-label="Schichtplanansicht auswählen"]')->item(0);
            $this->assertNotNull($menu);
            $this->assertSame(5, $xpath->query('.//button[@role="menuitemradio"]', $menu)->length);
            $selected = $xpath->query('.//button[@role="menuitemradio" and @aria-checked="true"]', $menu);
            $this->assertSame(1, $selected->length);
            $this->assertSame("setView('".$view."')", $selected->item(0)->getAttribute('wire:click'));
            $this->assertSame('close()', $selected->item(0)->getAttribute('x-on:click'));
            $this->assertSame(1, $xpath->query('.//a[@role="menuitem" and @href="'.OperationsPages::moduleUrl('calendar').'"]', $menu)->length);
        }
        $this->assertSame(0, Shift::count());
        Http::assertNothingSent();
    }

    public function test_distribution_trigger_is_real_icon_counts_without_an_open_label_and_keeps_the_native_dialog(): void
    {
        $order = Order::create([
            'customer_id' => $this->customer->id, 'title' => 'Synthetic Demand', 'status' => 'confirmed', 'priority' => 'normal',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T06:00', 'ends_at' => '2027-05-12T22:00',
            'required_staff' => 2, 'created_by' => $this->admin->id,
        ]);
        foreach (range(1, 9) as $number) {
            Shift::create([
                'order_id' => $order->id, 'title' => 'Synthetic Open '.$number, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin',
                'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'required_staff' => 1,
                'status' => 'open', 'created_by' => $this->admin->id,
            ]);
        }
        $unplanned = $order->replicate(['public_id', 'order_number']);
        $unplanned->title = 'Synthetic Unplanned';
        $unplanned->save();
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $xpath = $this->xpath($component->html());
        $trigger = $xpath->query('//button[contains(@class,"rt-shift-plan-distribution-trigger")]')->item(0);
        $this->assertNotNull($trigger);
        $this->assertSame('Noch zu verteilen: 9 Schichten und 1 Leistungen · Seitenleiste schließen', $trigger->getAttribute('aria-label'));
        $this->assertStringNotContainsString('Offen', $trigger->textContent);
        $this->assertSame(0, $xpath->query('.//*[contains(@class,"rt-shift-plan-distribution-label")]', $trigger)->length);
        foreach (['shifts' => ['9', 'fa-clock'], 'orders' => ['1', 'fa-briefcase']] as $kind => [$count, $icon]) {
            $counter = $xpath->query('.//*[@data-distribution-count="'.$kind.'"]', $trigger)->item(0);
            $this->assertNotNull($counter);
            $this->assertSame('true', $counter->getAttribute('data-has-pending'));
            $this->assertSame($count, trim($counter->textContent));
            $this->assertSame(1, $xpath->query('.//i[contains(@class,"'.$icon.'") and @aria-hidden="true"]', $counter)->length);
        }
        $total = $xpath->query('.//*[@data-distribution-count="total"]', $trigger)->item(0);
        $this->assertNotNull($total);
        $this->assertSame('10', trim($total->textContent));
        $this->assertSame('true', $total->getAttribute('aria-hidden'));
        // Seitenpanel neben dem Plan statt Aufklapp-Dialog.
        $side = $xpath->query('//aside[contains(@class,"rt-shift-distribution-side") and @aria-label="Noch zu verteilen"]')->item(0);
        $this->assertNotNull($side);
        $this->assertSame($trigger->getAttribute('aria-controls'), $side->getAttribute('id'));
        $this->assertSame(1, $xpath->query('.//*[@data-pending-distribution]', $side)->length);
        $this->assertSame(2, $xpath->query('.//button[@data-panel-tab]', $side)->length);
        $component->assertViewHas('pendingShifts', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 9);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_short_date_range_remains_fully_named_and_uses_the_existing_date_picker(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('applyPeriod', '2027-12-30', '2028-01-03');
        $xpath = $this->xpath($component->html());
        $trigger = $xpath->query('//button[contains(@class,"rt-shift-plan-period-trigger")]')->item(0);
        $this->assertNotNull($trigger);
        foreach (['30.12.2027', '03.01.2028'] as $date) {
            $this->assertStringContainsString($date, $trigger->getAttribute('aria-label'));
            $this->assertStringContainsString($date, $trigger->getAttribute('title'));
        }
        $picker = $xpath->query('//*[@data-rt-date-range-picker]')->item(0);
        $this->assertNotNull($picker);
        $this->assertTrue($picker->hasAttribute('data-rt-dropdown-keep-open'));
        foreach (["entangle('rangeFrom')", "entangle('rangeTo')", "call('applyPeriod'", 'maxDays', '94', 'resetOnOpen', 'true'] as $contract) {
            $this->assertStringContainsString($contract, $picker->getAttribute('x-data'));
        }
        $this->assertSame('close(true)', $picker->getAttribute('x-on:date-range-applied'));
        $this->assertSame('close(true)', $picker->getAttribute('x-on:date-range-cancel'));
        $component->call('applyPeriod', '2028-01-03', '2027-12-30')->assertHasErrors('rangeTo');
        $this->assertSame(0, Shift::count());
        Http::assertNothingSent();
    }

    public function test_proposals_follow_the_distribution_sidebar_without_a_header_switch(): void
    {
        $component = Livewire::actingAs($this->admin)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'searchInHeader' => true]);
        foreach ([true, false, true] as $open) {
            $component->dispatch('operations-timeline-distribution', open: $open, shiftId: null)->assertSet('showSuggestions', $open);
            $xpath = $this->xpath($component->html());
            // Vorschläge hängen am offenen Seitenpanel; ein eigener Schalter im Kopf entfällt.
            $this->assertSame(0, $xpath->query('//input[@data-timeline-suggestions-switch]')->length);
            $owner = $xpath->query('//*[@aria-label="Mitarbeiter-Zeitleiste"]')->item(0);
            $this->assertSame("rtTimelinePlanning('".$component->instance()->getId()."')", $owner->getAttribute('x-data'));
            $this->assertStringNotContainsString('$wire.showSuggestions', $component->html());
        }
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_shared_plain_switch_and_creation_actions_keep_their_original_semantics(): void
    {
        $html = Blade::render('<x-ui.forms.toggle-button id="plain-qa" label="Einstellung" model="enabled" change="persist($event)" :checked="true" aria-label="Einstellung" />');
        $xpath = $this->xpath($html);
        $label = $xpath->query('//label[@for="plain-qa"]')->item(0);
        $this->assertNotNull($label);
        $this->assertSame('Einstellung', trim($label->textContent));
        $this->assertSame(0, $xpath->query('.//i', $label)->length);
        $switch = $xpath->query('.//input[@role="switch"]', $label)->item(0);
        $this->assertSame('enabled', $switch->getAttribute('wire:model.live'));
        $this->assertStringContainsString('@change="persist($event)"', $html);
        $this->assertTrue($switch->hasAttribute('checked'));
        $this->assertFalse($switch->hasAttribute('disabled'));
        $this->assertSame('enabled', $switch->getAttribute('data-autosave-model'));
        $control = $xpath->query('.//*[@data-toggle-control]', $label)->item(0);
        $this->assertSame('true', $control->getAttribute('aria-hidden'));
        $this->assertSame('plain-qa', $control->getAttribute('data-autosave-field-id'));
        $this->assertSame('', trim($control->textContent));
        $create = $this->xpath(Blade::render('<x-operations.create-action module="shift-management" />'));
        $button = $create->query('//button[@data-operations-create]')->item(0);
        $this->assertSame('Neue Schicht', $button->getAttribute('aria-label'));
        $this->assertSame("\$dispatchTo('admin.operations.shift-management', 'operations-create')", $button->getAttribute('wire:click'));
        $this->assertSame('', trim($button->textContent));
        $sharedCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('transform: translate(var(--rt-toggle-travel), -50%)', $sharedCss);
        $this->assertStringNotContainsString('rt-timeline-suggestions-toggle', $sharedCss);
        $this->assertSame(0, Shift::count());
        Http::assertNothingSent();
    }

    public function test_compact_controls_do_not_expand_permissions_or_lose_navigation_context(): void
    {
        $limited = User::factory()->create(['name' => 'Synthetic Limited Planner', 'role' => 'staff', 'status' => true]);
        Gate::define('operations.manage', fn (User $user): bool => $user->is($limited));
        Gate::define('operations.inquiries.manage', fn (): bool => false);
        $component = Livewire::actingAs($limited)->test(ShiftManagement::class)->assertSuccessful();
        $xpath = $this->xpath($component->html());
        $this->assertSame(0, $xpath->query('//*[@data-pending-distribution]//a')->length);
        $component->call('setView', 'unknown')->assertStatus(422);
        $disabled = Livewire::actingAs($limited)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => false, 'searchInHeader' => true]);
        $this->assertSame(0, $this->xpath($disabled->html())->query('//input[@data-timeline-suggestions-switch]')->length);
        $disabled->call('toggleSuggestions')->assertForbidden();
        $context = ['customer' => $this->customer->id, 'from' => '2027-05-10', 'until' => '2027-05-16'];
        $case = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => 'shifts', 'initialSection' => 'plan', 'context' => $context]);
        $case->call('setPlanningView', 'calendar')->assertRedirect(OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'calendar'] + $context));
        $case->call('setPlanningView', 'shifts')->assertRedirect(OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan'] + $context));
        $denied = User::factory()->create(['name' => 'Synthetic Denied User', 'role' => 'staff', 'status' => true]);
        Livewire::actingAs($denied)->test(ShiftManagement::class)->assertForbidden();
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_overflow_actions_keep_the_existing_form_navigation_help_and_explicit_planning_dispatches(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $xpath = $this->xpath($component->html());
        $menu = $xpath->query('//*[@role="menu" and @aria-label="Schichtplan-Optionen"]')->item(0);
        $this->assertNotNull($menu);
        $create = $xpath->query('.//button[contains(@class,"rt-shift-plan-compact-create") and @role="menuitem"]', $menu)->item(0);
        $this->assertNotNull($create);
        $this->assertSame('Neue Schicht', trim($create->textContent));
        $this->assertSame('createShift', $create->getAttribute('wire:click'));
        $this->assertSame('close()', $create->getAttribute('x-on:click'));
        $help = $xpath->query('.//button[contains(@class,"rt-shift-plan-compact-action") and contains(@*[name()="x-on:click"],"rt-info:open")]', $menu)->item(0);
        $this->assertNotNull($help);
        $this->assertSame('Seitenhilfe', trim($help->textContent));
        $this->assertStringContainsString('close()', $help->getAttribute('x-on:click'));
        $back = $xpath->query('.//button[contains(@class,"rt-shift-plan-compact-action") and contains(@*[name()="x-on:click"],"backToPreviousPage")]', $menu)->item(0);
        $this->assertNotNull($back);
        $this->assertSame('Zurück', trim($back->textContent));
        $this->assertStringContainsString('window.RTNavigation', $back->getAttribute('x-on:click'));
        $this->assertStringContainsString('window.location.assign', $back->getAttribute('x-on:click'));
        foreach (['open-shift-series-planner', 'open-ai-period-planning'] as $event) {
            $dispatch = $xpath->query('.//button[@role="menuitem" and contains(@*[name()="x-on:click"],"'.$event.'")]', $menu)->item(0);
            $this->assertNotNull($dispatch);
            $this->assertStringContainsString('close()', $dispatch->getAttribute('x-on:click'));
        }
        $component->call('createShift')->assertSet('formOpen', true)->assertSet('editingShiftId', null);
        $this->assertSame(0, Shift::count());
        Http::assertNothingSent();
    }

    public function test_overflow_create_rechecks_permissions_and_never_creates_a_duty_just_by_opening(): void
    {
        $planner = User::factory()->create(['name' => 'Synthetic Permitted Planner', 'role' => 'staff', 'status' => true]);
        Gate::define('operations.manage', fn (User $user): bool => $user->is($planner));
        $component = Livewire::actingAs($planner)->test(ShiftManagement::class)->call('createShift')->assertSet('formOpen', true);
        $this->assertSame(0, Shift::count());
        Gate::define('operations.manage', fn (): bool => false);
        $component->call('createShift')->assertForbidden();
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_narrow_distribution_total_is_bounded_but_accessible_counts_remain_exact(): void
    {
        $order = Order::create([
            'customer_id' => $this->customer->id, 'title' => 'Synthetic Large Demand', 'status' => 'confirmed', 'priority' => 'normal',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T06:00', 'ends_at' => '2027-05-12T22:00',
            'required_staff' => 1, 'created_by' => $this->admin->id,
        ]);
        foreach (range(1, 102) as $number) {
            Shift::create([
                'order_id' => $order->id, 'title' => 'Synthetic Demand '.$number, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin',
                'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'required_staff' => 1,
                'status' => 'open', 'created_by' => $this->admin->id,
            ]);
        }
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $xpath = $this->xpath($component->html());
        $trigger = $xpath->query('//button[contains(@class,"rt-shift-plan-distribution-trigger")]')->item(0);
        $this->assertSame('Noch zu verteilen: 102 Schichten und 0 Leistungen · Seitenleiste schließen', $trigger->getAttribute('aria-label'));
        $total = $xpath->query('.//*[@data-distribution-count="total"]', $trigger)->item(0);
        $this->assertNotNull($total);
        $this->assertSame('99+', trim($total->textContent));
        $this->assertSame('true', $total->getAttribute('data-has-pending'));
        $full = $xpath->query('.//*[@data-distribution-count="shifts"]', $trigger)->item(0);
        $this->assertSame('102', trim($full->textContent));
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_legacy_optional_schema_absence_does_not_remove_compact_navigation_help_or_creation(): void
    {
        $this->buildMinimalRailTimeSchema();
        $admin = User::factory()->create(['name' => 'Synthetic Legacy Manager', 'role' => 'admin', 'status' => true]);
        $component = Livewire::actingAs($admin)->test(ShiftManagement::class)->assertViewHas('nativeOperations', false);
        $xpath = $this->xpath($component->html());
        $trigger = $xpath->query('//button[contains(@class,"rt-shift-plan-options-trigger")]')->item(0);
        $this->assertNotNull($trigger);
        $this->assertSame('Schichtplan-Optionen', $trigger->getAttribute('aria-label'));
        $menu = $xpath->query('//*[@role="menu" and @aria-label="Schichtplan-Optionen"]')->item(0);
        $this->assertNotNull($menu);
        $this->assertSame(1, $xpath->query('.//button[contains(@class,"rt-shift-plan-compact-create") and @*[name()="wire:click"]="createShift"]', $menu)->length);
        $this->assertSame(1, $xpath->query('.//button[contains(@class,"rt-shift-plan-compact-action") and contains(@*[name()="x-on:click"],"rt-info:open")]', $menu)->length);
        $this->assertSame(1, $xpath->query('.//button[contains(@class,"rt-shift-plan-compact-action") and contains(@*[name()="x-on:click"],"backToPreviousPage")]', $menu)->length);
        $this->assertSame(0, $xpath->query('.//button[contains(@*[name()="x-on:click"],"open-shift-series-planner") or contains(@*[name()="x-on:click"],"open-ai-period-planning")]', $menu)->length);
        $this->assertSame(0, $xpath->query('//*[@data-shift-plan-timeline-suggestions]')->length);
        $component->call('createShift')->assertSet('formOpen', true);
        $this->assertSame(0, Shift::count());
        Http::assertNothingSent();
    }

    public function test_responsive_rules_use_one_command_row_icon_views_and_accessible_secondary_action_overflow(): void
    {
        $css = file_get_contents(resource_path('css/disposition-workspace.css'));
        $start = strpos($css, '/* One command row, with only secondary actions moving to the existing menu. */');
        $this->assertNotFalse($start);
        $commands = substr($css, $start);
        $this->assertMatchesRegularExpression('/\.rt-disposition-page:has\(\.rt-shift-plan\) > \[data-page-header\]\s*\{[^}]*display:\s*grid !important;[^}]*grid-template-columns:\s*44px minmax\(0, 1fr\) auto;/s', $commands);
        $this->assertStringNotContainsString('grid-row: 2', $commands);
        $this->assertStringNotContainsString('flex-wrap: wrap', $commands);
        $this->assertStringContainsString('.rt-shift-plan-header-controls { flex-wrap: nowrap; }', $commands);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-period-controls\s*\{\s*min-width:\s*44px;\s*flex:\s*0 1 clamp\(12\.5rem, 15vw, 17rem\);\s*\}/s', $commands);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-period-controls > \[data-rt-dropdown-root\],\s*\.rt-shift-plan-period-controls \.rt-ui-dropdown-trigger\s*\{\s*width:\s*100%;\s*min-width:\s*0;\s*max-width:\s*100%;\s*\}/s', $commands);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-period-controls \.rt-shift-plan-period-trigger\s*\{\s*width:\s*100%;\s*min-width:\s*44px;\s*max-width:\s*100%;\s*\}/s', $commands);
        $tablet = $this->mediaBody($commands, 1024);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-view-trigger\.rt-ui-button\s*\{[^}]*width:\s*44px;[^}]*min-width:\s*44px !important;[^}]*justify-content:\s*center;/s', $tablet);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-view-trigger \.rt-shift-plan-control__label,\s*\.rt-shift-plan-view-trigger \.rt-shift-plan-control__chevron\s*\{\s*display:\s*none;\s*\}/s', $tablet);
        $mobile = $this->mediaBody($commands, 600);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto', $mobile);
        $this->assertStringContainsString('[data-page-header-control]:first-child', $mobile);
        $this->assertStringContainsString('[data-page-info-button] { display: none !important; }', $mobile);
        $this->assertStringContainsString('.rt-shift-plan-compact-action { display: flex; }', $mobile);
        $narrow = $this->mediaBody($commands, 480);
        $this->assertStringContainsString('.rt-shift-plan-compact-create { display: flex; }', $narrow);
        $this->assertStringContainsString('.rt-shift-plan-create', $narrow);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-period-controls \.rt-shift-plan-period-trigger\s*\{[^}]*width:\s*44px;/s', $narrow);
        $this->assertStringContainsString('.rt-shift-plan-period-trigger > span,', $narrow);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-distribution-trigger\.rt-ui-button\s*\{[^}]*width:\s*56px;[^}]*min-width:\s*56px;/s', $narrow);
        $this->assertStringContainsString('.rt-shift-plan-distribution-counters,', $narrow);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-distribution-total\s*\{[^}]*display:\s*inline-flex;/s', $narrow);
        $this->assertMatchesRegularExpression('/\.rt-shift-plan-distribution-total,\s*\.rt-shift-plan-compact-action,\s*\.rt-shift-plan-compact-create\s*\{\s*display:\s*none;\s*\}/s', $css);
        $toggleCss = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $this->assertMatchesRegularExpression('/\.rt-timeline-suggestions-toggle\s*\{[^}]*width:\s*76px;/s', $toggleCss);
        $this->assertStringContainsString('--rt-toggle-knob: 18px', $toggleCss);
        $this->assertStringContainsString('--rt-toggle-travel: 16px', $toggleCss);
        $this->assertStringContainsString('input:focus-visible + .rt-ui-toggle-control', $toggleCss);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $toggleCss);
        $this->assertStringContainsString(".dark .rt-timeline-suggestions-toggle[data-error='true']", $toggleCss);
        $this->assertStringNotContainsString('timelineBody', $commands);
        $this->assertStringNotContainsString('scroll-snap', $commands);
    }

    public function test_compact_secondary_actions_override_legacy_important_display_without_changing_global_utilities(): void
    {
        $legacyCss = file_get_contents(public_path('build/css/tailwind.min.css'));
        $this->assertMatchesRegularExpression('/\.inline-flex\s*\{\s*display:\s*inline-flex\s*!important\s*;?\s*\}/', $legacyCss);
        $head = file_get_contents(resource_path('views/layouts/head-css.blade.php'));
        $this->assertStringContainsString('build/css/tailwind.min.css', $head);
        $help = ['title' => 'Schichtplan', 'summary' => 'Synthetic page help', 'points' => ['Synthetic point']];
        $html = Blade::render('<x-ui.page-header title="Schichtplan" :help="$help"><x-slot:actions><x-operations.create-action module="shift-management" /></x-slot:actions></x-ui.page-header>', compact('help'));
        $xpath = $this->xpath($html);
        $back = $xpath->query('//*[@data-page-header]/*[@data-page-header-control][1]')->item(0);
        $info = $xpath->query('//*[@data-page-header-actions]/*[@data-page-info-button]')->item(0);
        $create = $xpath->query('//button[@data-operations-create]')->item(0);
        foreach ([$back, $info, $create] as $control) {
            $this->assertNotNull($control);
            $this->assertStringContainsString('inline-flex', $control->getAttribute('class'));
        }
        $css = file_get_contents(resource_path('css/disposition-workspace.css'));
        $commands = substr($css, strpos($css, '/* One command row, with only secondary actions moving to the existing menu. */'));
        $header = '.rt-disposition-page:has(.rt-shift-plan) > [data-page-header]';
        $mobile = $this->mediaBody($commands, 600);
        $this->assertMatchesRegularExpression('/'.preg_quote($header.' > [data-page-header-control]:first-child,', '/').'\s*'.preg_quote($header.' > [data-page-header-actions] > [data-page-info-button]', '/').'\s*\{\s*display:\s*none !important;\s*\}/s', $mobile);
        $narrow = $this->mediaBody($commands, 480);
        $this->assertMatchesRegularExpression('/'.preg_quote($header.' .rt-shift-plan-create,', '/').'\s*'.preg_quote($header.' > [data-page-header-actions] > div:has(> .rt-shift-plan-create)', '/').'\s*\{\s*display:\s*none !important;\s*\}/s', $narrow);
        $this->assertStringContainsString('.rt-shift-plan-compact-action { display: flex; }', $mobile);
        $this->assertStringContainsString('.rt-shift-plan-compact-create { display: flex; }', $narrow);
        $this->assertDoesNotMatchRegularExpression('/(?:^|\})\s*\.inline-flex\s*\{/', $commands);
    }

    private function mediaBody(string $css, int $maximumWidth): string
    {
        $this->assertSame(1, preg_match('/@media\s*\(max-width:\s*'.$maximumWidth.'px\)\s*\{/', $css, $matches, PREG_OFFSET_CAPTURE));
        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        for ($index = $start; $index < strlen($css); $index++) {
            if ($css[$index] === '{') {
                $depth++;
            } elseif ($css[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $start, $index - $start);
                }
            }
        }
        $this->fail('Responsive media block must be closed.');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }
}
