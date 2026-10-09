<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Calendar;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\CaseWorkspace;
use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\CommercialOfferService;
use App\Support\Operations\ApplicationNavigation;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PlanningTopbarNavigationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_07_18_000001_create_activity_log_table.php', '2026_07_22_000002_create_employee_document_requirements_table.php', '2026_10_04_121000_create_customer_workflow_extensions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Synthetic Navigation Customer', 'is_active' => true]);
    }

    public function test_case_navigation_has_one_shared_five_way_toggle_inside_the_topbar_teleport_with_offers_enabled(): void
    {
        $this->assertTrue(CommercialOfferService::ready());
        $this->assertArrayHasKey('offers', CaseWorkspace::availableViews($this->admin));
        $expected = ['inbox' => 'Eingang', 'offers' => 'Angebote', 'orders' => 'Aufträge', 'shifts' => 'Schichtplan', 'calendar' => 'Kalender'];
        foreach ([['inbox', 'overview'], ['orders', 'overview'], ['shifts', 'plan'], ['shifts', 'calendar'], ['offers', 'overview']] as [$view, $section]) {
            $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => $view, 'initialSection' => $section]);
            $xpath = $this->xpath($component->html());
            $teleports = $xpath->query('//template[@*[name()="x-teleport"]="[data-topbar-planning-navigation]"]');
            $this->assertSame(1, $teleports->length);
            $teleport = $teleports->item(0);
            $this->assertSame('case-topbar-navigation', $teleport->getAttribute('wire:key'));
            $groups = $xpath->query('//*[@id="case-workspace-view"]');
            $this->assertSame(1, $groups->length);
            $group = $groups->item(0);
            $this->assertSame('template', $group->parentNode->nodeName);
            $this->assertSame('[data-topbar-planning-navigation]', $group->parentNode->getAttribute('x-teleport'));
            $this->assertSame('group', $group->getAttribute('role'));
            $this->assertSame('Vorgangsansicht', $group->getAttribute('aria-label'));
            $this->assertTrue($group->hasAttribute('data-multi-toggle'));
            $this->assertSame('setPlanningView', $group->getAttribute('wire:target'));
            $buttons = $xpath->query('.//button[@data-multi-toggle-option]', $group);
            $this->assertSame(5, $buttons->length);
            foreach ($buttons as $index => $button) {
                $value = array_keys($expected)[$index];
                $this->assertSame($value, $button->getAttribute('data-toggle-value'));
                $this->assertSame($expected[$value], $button->getAttribute('aria-label'));
                $this->assertSame("setPlanningView('".$value."')", $button->getAttribute('wire:click'));
                $selected = $view === 'shifts' && $section === 'calendar' ? 'calendar' : $view;
                $this->assertSame($value === $selected ? 'true' : 'false', $button->getAttribute('aria-pressed'));
                $this->assertSame('setPlanningView', $button->getAttribute('wire:target'));
            }
            $this->assertSame(1, $xpath->query('.//button[@data-multi-toggle-option and @aria-pressed="true"]', $group)->length);
            $this->assertSame(0, $xpath->query('//*[@id="case-shift-section"]')->length);
            $this->assertSame(0, $xpath->query('//*[@id="case-workspace-view" and not(ancestor::template[@*[name()="x-teleport"]="[data-topbar-planning-navigation]"])]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-case-workspace and @*[name()="x-data"]]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-case-workspace]/header/button[@*[name()="wire:click"]="setView(\'offers\')"]')->length);
            $this->assertSame(in_array($view, ['inbox', 'orders'], true) ? 1 : 0, $xpath->query('//*[@data-case-workspace]/header')->length);
        }
        Http::assertNothingSent();
    }

    public function test_rendered_topbar_keeps_the_same_permission_filtered_shortcuts_after_sidebar_regrouping(): void
    {
        foreach ([['operations.inquiries.manage'], ['operations.manage']] as $abilities) {
            $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
            foreach (['operations.manage', 'operations.inquiries.manage', 'operations.costs.manage', 'customers.portal.manage'] as $ability) {
                Gate::define($ability, fn (User $user): bool => $user->is($actor) && in_array($ability, $abilities, true));
            }
            $planning = OperationsPages::planningShortcuts($actor);
            $expected = in_array('operations.manage', $abilities, true) ? ['offers', 'orders', 'shifts', 'calendar'] : ['inbox', 'offers'];
            $this->assertSame($expected, array_keys($planning));
            $component = Livewire::actingAs($actor)->test(CaseWorkspace::class, ['initialView' => $expected[0]])->assertSuccessful();
            $xpath = $this->xpath($component->html());
            $buttons = $xpath->query('//*[@id="case-workspace-view"]//button[@data-multi-toggle-option]');
            $this->assertSame($expected, array_map(fn (\DOMElement $button): string => $button->getAttribute('data-toggle-value'), iterator_to_array($buttons)));
            $sidebar = collect(ApplicationNavigation::sections($actor)['Disposition'] ?? [])->filter(fn (array $item): bool => ($item['parameters']['page'] ?? '') === 'cases');
            $sidebarExpected = in_array('operations.manage', $abilities, true) ? ['orders', 'offers', 'shifts', 'calendar'] : ['inbox', 'offers'];
            $this->assertSame($sidebarExpected, $sidebar->map(fn (array $item): string => ($item['parameters']['section'] ?? '') === 'calendar' ? 'calendar' : $item['parameters']['view'])->values()->all());
            $this->assertSame(array_map(fn (string $key): string => $planning[$key]['label'], $sidebarExpected), $sidebar->pluck('title')->values()->all());
            foreach ($sidebar as $item) {
                $this->assertSame(in_array($item['parameters']['view'], ['offers', 'shifts'], true) ? 'Planung' : null, $item['group']);
            }
            $forbidden = $expected === ['inbox', 'offers'] ? 'orders' : 'inbox';
            $component->call('setPlanningView', $forbidden)->assertForbidden();
            if ($expected === ['inbox', 'offers']) {
                Livewire::actingAs($actor)->test(CaseWorkspace::class, ['initialView' => 'inbox'])->call('setPlanningView', 'calendar')->assertForbidden();
            }
        }
    }

    public function test_teleported_view_actions_retain_customer_and_list_context_through_existing_redirects(): void
    {
        $context = ['customer' => $this->customer->id, 'search' => 'Nord', 'status' => 'confirmed'];
        $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => 'orders', 'context' => $context]);
        $xpath = $this->xpath($component->html());
        foreach (['orders', 'inbox', 'offers', 'shifts', 'calendar'] as $target) {
            $button = $xpath->query('//*[@id="case-workspace-view"]//button[@data-toggle-value="'.$target.'"]')->item(0);
            $this->assertSame("setPlanningView('".$target."')", $button->getAttribute('wire:click'));
            $parameters = ['view' => $target === 'calendar' ? 'shifts' : $target];
            if (in_array($target, ['shifts', 'calendar'], true)) {
                $parameters['section'] = $target === 'calendar' ? 'calendar' : 'plan';
            }
            $parameters['customer'] = $this->customer->id;
            if (in_array($target, ['inbox', 'orders'], true)) {
                $parameters['search'] = 'Nord';
            }
            if ($target === 'orders') {
                $parameters['status'] = 'confirmed';
            }
            $component->call('setPlanningView', $target)->assertRedirect(OperationsPages::url('cases', $parameters));
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('shifts', 0);
        Http::assertNothingSent();
    }

    public function test_missing_offer_schema_hides_only_offer_shortcuts_and_preserves_the_four_existing_destinations(): void
    {
        Schema::drop('commercial_offer_revisions');
        $this->assertFalse(CommercialOfferService::ready());
        $expected = ['inbox', 'orders', 'shifts', 'calendar'];
        $this->assertSame($expected, array_keys(OperationsPages::planningShortcuts($this->admin)));
        $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => 'orders'])->assertSuccessful();
        $xpath = $this->xpath($component->html());
        $this->assertSame($expected, array_map(fn (\DOMElement $button): string => $button->getAttribute('data-toggle-value'), iterator_to_array($xpath->query('//*[@id="case-workspace-view"]//button[@data-multi-toggle-option]'))));
        $links = collect(ApplicationNavigation::sections($this->admin)['Disposition'])->filter(fn (array $item): bool => ($item['parameters']['page'] ?? '') === 'cases');
        $this->assertSame($expected, $links->map(fn (array $item): string => ($item['parameters']['section'] ?? '') === 'calendar' ? 'calendar' : $item['parameters']['view'])->values()->all());
        $this->assertSame(0, $xpath->query('//*[@data-case-workspace]/header/button[@*[name()="wire:click"]="setView(\'offers\')"]')->length);
        $component->call('setPlanningView', 'offers')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('shifts', 0);
        Http::assertNothingSent();
    }

    public function test_revoked_offer_permission_is_checked_on_the_action_while_the_cost_workspace_remains_allowed(): void
    {
        (require database_path('migrations/2026_10_06_102000_create_operations_enhancements.php'))->up();
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $inquiriesAllowed = true;
        Gate::define('operations.inquiries.manage', function (User $user) use ($actor, &$inquiriesAllowed): bool {
            return $user->is($actor) && $inquiriesAllowed;
        });
        Gate::define('operations.manage', fn (): bool => false);
        Gate::define('customers.portal.manage', fn (): bool => false);
        Gate::define('operations.costs.manage', fn (User $user): bool => $user->is($actor));
        $component = Livewire::actingAs($actor)->test(CaseWorkspace::class, ['initialView' => 'orders', 'initialSection' => 'costs'])->assertSuccessful();
        $this->assertSame(['inbox', 'offers', 'orders'], array_keys(OperationsPages::planningShortcuts($actor)));
        $inquiriesAllowed = false;
        $this->assertSame(['orders'], array_keys(OperationsPages::planningShortcuts($actor)));
        $links = collect(ApplicationNavigation::sections($actor)['Disposition'] ?? [])->where('parameters.page', 'cases');
        $this->assertSame(['Aufträge'], $links->pluck('title')->values()->all());
        $component->call('setPlanningView', 'offers')->assertForbidden();
        Livewire::actingAs($actor)->test(CaseWorkspace::class, ['initialView' => 'orders', 'initialSection' => 'costs'])->assertSuccessful()->assertDontSee('data-toggle-value="offers"', false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('commercial_offer_revisions', 0);
        Http::assertNothingSent();
    }

    public function test_deactivated_actor_cannot_follow_a_previously_rendered_offer_shortcut(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => 'orders'])->assertSee('data-toggle-value="offers"', false);
        $this->admin->forceFill(['status' => false])->save();
        $this->assertSame([], OperationsPages::planningShortcuts($this->admin));
        $component->call('setPlanningView', 'offers')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('commercial_offer_revisions', 0);
        Http::assertNothingSent();
    }

    public function test_global_topbar_has_one_empty_target_immediately_after_the_burger_and_scoped_mobile_size_rules(): void
    {
        $xpath = $this->xpath(file_get_contents(resource_path('views/layouts/topbar.blade.php')));
        $targets = $xpath->query('//*[@data-topbar-planning-navigation]');
        $this->assertSame(1, $targets->length);
        $target = $targets->item(0);
        $this->assertSame('', trim($target->textContent));
        $previous = $xpath->query('preceding-sibling::*[1]', $target)->item(0);
        $this->assertSame('vertical-menu-btn', $previous->getAttribute('id'));
        $this->assertSame('button', $previous->nodeName);
        $css = file_get_contents(resource_path('css/shell-redesign.css'));
        $this->assertStringContainsString('.rt-shell-planning-navigation:empty { display: none; }', $css);
        $this->assertMatchesRegularExpression('/\.rt-shell-planning-navigation \.rt-multi-toggle \.rt-multi-toggle__button\.rt-ui-button\s*\{[^}]*min-width:\s*32px;[^}]*min-height:\s*40px;/s', $css);
        $this->assertStringContainsString('.rt-shell-topbar:has([data-topbar-planning-navigation] > *) .rt-shell-brand-mark', $css);
    }

    public function test_shift_and_calendar_shortcuts_preserve_the_current_customer_and_period_context(): void
    {
        $context = ['customer' => $this->customer->id, 'from' => '2027-05-10', 'until' => '2027-05-16'];
        foreach (['plan', 'calendar'] as $section) {
            $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => 'shifts', 'initialSection' => $section, 'context' => $context]);
            foreach (['shifts' => 'plan', 'calendar' => 'calendar'] as $target => $destination) {
                $component->call('setPlanningView', $target)->assertRedirect(OperationsPages::url('cases', ['view' => 'shifts', 'section' => $destination] + $context));
            }
            $component->call('setPlanningView', 'unrelated')->assertForbidden();
        }
        $this->assertDatabaseCount('shifts', 0);
        Http::assertNothingSent();
    }

    public function test_active_shift_search_is_teleported_to_one_shared_topbar_slot_and_bound_to_its_actual_owner(): void
    {
        $ada = User::factory()->create(['name' => 'Ada Arbor', 'role' => 'staff', 'status' => true]);
        $ben = User::factory()->create(['name' => 'Ben Birch', 'role' => 'staff', 'status' => true]);
        $timeline = Livewire::actingAs($this->admin)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'searchInHeader' => true]);
        $xpath = $this->xpath($timeline->html());
        $templates = $xpath->query('//template[@*[name()="x-teleport"]="[data-topbar-page-search]"]');
        $this->assertSame(1, $templates->length);
        $search = $xpath->query('.//*[@data-rt-search]', $templates->item(0))->item(0);
        $this->assertNotNull($search);
        $this->assertStringContainsString("window.Livewire.find('".$timeline->instance()->getId()."').entangle('search').live", $search->getAttribute('x-data'));
        $this->assertStringContainsString('isPageTopbarSearch: true', $search->getAttribute('x-data'));
        $input = $xpath->query('.//input[@type="search"]', $search)->item(0);
        $this->assertSame('search', $input->getAttribute('wire:model.live.debounce.300ms'));
        $this->assertSame('Mitarbeiter suchen', $input->getAttribute('aria-label'));
        $this->assertSame(1, $xpath->query('//input[@type="search"]')->length);
        $timeline->assertSee('staff-person-'.$ada->id, false)->assertSee('staff-person-'.$ben->id, false);
        $timeline->set('search', 'Ada')->assertSet('search', 'Ada')->assertSee('staff-person-'.$ada->id, false)->assertDontSee('staff-person-'.$ben->id, false);
        $timeline->set('search', 'Synthetic absent person')->assertSet('search', 'Synthetic absent person')->assertDontSee('staff-person-');
        $timeline->set('search', '')->assertSee('staff-person-'.$ada->id, false)->assertSee('staff-person-'.$ben->id, false);

        $list = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'table');
        $xpath = $this->xpath($list->html());
        $templates = $xpath->query('//template[@*[name()="x-teleport"]="[data-topbar-page-search]"]');
        $this->assertSame(1, $templates->length);
        $search = $xpath->query('.//*[@data-rt-search]', $templates->item(0))->item(0);
        $this->assertStringContainsString("window.Livewire.find('".$list->instance()->getId()."').entangle('search').live", $search->getAttribute('x-data'));
        $this->assertSame(0, $xpath->query('//*[@data-shift-plan-timeline-search or @data-page-list-search]')->length);

        $topbar = file_get_contents(resource_path('views/layouts/topbar.blade.php'));
        $this->assertSame(1, substr_count($topbar, 'data-topbar-page-search'));
        $this->assertStringContainsString('<div data-topbar-global-search><livewire:tools.global-search /></div>', $topbar);
        $css = file_get_contents(resource_path('css/shell-redesign.css'));
        $this->assertStringContainsString('[data-topbar-page-search]:empty { display: none; }', $css);
        $this->assertStringContainsString('.rt-shell-topbar-controls:has([data-topbar-page-search] > *) [data-topbar-global-search] { display: none; }', $css);
        Http::assertNothingSent();
    }

    public function test_calendar_search_uses_the_same_topbar_owner_in_all_views_and_preserves_native_filtering_and_reset(): void
    {
        config(['operations.display_timezone' => 'Europe/Berlin']);
        $order = Order::create(['customer_id' => $this->customer->id, 'title' => 'Synthetic Search Order', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-10T06:00', 'ends_at' => '2027-05-16T20:00', 'required_staff' => 1, 'created_by' => $this->admin->id]);
        $east = Shift::create(['order_id' => $order->id, 'title' => 'Synthetic East', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T12:00', 'required_staff' => 1, 'status' => 'draft', 'created_by' => $this->admin->id]);
        $west = Shift::create(['order_id' => $order->id, 'title' => 'Synthetic West', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T14:00', 'ends_at' => '2027-05-12T18:00', 'required_staff' => 1, 'status' => 'draft', 'created_by' => $this->admin->id]);
        $calendar = Livewire::actingAs($this->admin)->test(Calendar::class)->set('anchorDate', '2027-05-12');
        foreach (['day', 'week', 'month', 'list'] as $view) {
            $calendar->call('switchView', $view);
            $xpath = $this->xpath($calendar->html());
            $templates = $xpath->query('//template[@*[name()="x-teleport"]="[data-topbar-page-search]"]');
            $this->assertSame(1, $templates->length);
            $this->assertSame('calendar-topbar-search-'.$calendar->instance()->getId(), $templates->item(0)->getAttribute('wire:key'));
            $search = $xpath->query('.//*[@data-rt-search]', $templates->item(0))->item(0);
            $this->assertStringContainsString("window.Livewire.find('".$calendar->instance()->getId()."').entangle('search').live", $search->getAttribute('x-data'));
            $this->assertStringContainsString('isPageTopbarSearch: true', $search->getAttribute('x-data'));
            $this->assertSame(1, $xpath->query('//input[@type="search"]')->length);
            // Der Seitenkopf trägt die Steuerzeile; die Suche bleibt allein in der Topbar.
            $header = $xpath->query('//template[@*[name()="x-teleport"]="[data-page-header-search]"]');
            $this->assertSame(1, $header->length);
            $this->assertSame(1, $xpath->query('.//*[@data-calendar-header-controls]', $header->item(0))->length);
            $this->assertSame(0, $xpath->query('.//*[@data-rt-search]', $header->item(0))->length);
            $calendar->set('search', 'Synthetic East')->assertViewHas('shifts', fn ($shifts) => $shifts->pluck('id')->all() === [$east->id]);
            $calendar->set('search', 'Unmatched search')->assertViewHas('shifts', fn ($shifts) => $shifts->isEmpty());
            $calendar->call('resetFilters')->assertSet('search', '')->assertViewHas('shifts', fn ($shifts) => $shifts->pluck('id')->all() === [$east->id, $west->id]);
        }
        $this->assertDatabaseCount('shifts', 2);
        Http::assertNothingSent();
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
