<?php

namespace Tests\Feature;

use App\Livewire\Operations\CaseWorkspace;
use App\Models\Customer;
use App\Models\User;
use App\Services\Operations\CommercialOfferService;
use App\Support\Operations\ApplicationNavigation;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
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

    public function test_case_navigation_has_one_shared_three_way_toggle_inside_the_topbar_teleport_even_with_offers_enabled(): void
    {
        $this->assertTrue(CommercialOfferService::ready());
        $this->assertArrayHasKey('offers', CaseWorkspace::availableViews($this->admin));
        $expected = ['inbox' => 'Eingang', 'orders' => 'Aufträge', 'shifts' => 'Schichtplan'];
        foreach (['inbox', 'orders', 'shifts', 'offers'] as $view) {
            $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => $view]);
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
            $this->assertSame('setView', $group->getAttribute('wire:target'));
            $buttons = $xpath->query('.//button[@data-multi-toggle-option]', $group);
            $this->assertSame(3, $buttons->length);
            foreach ($buttons as $index => $button) {
                $value = array_keys($expected)[$index];
                $this->assertSame($value, $button->getAttribute('data-toggle-value'));
                $this->assertSame($expected[$value], $button->getAttribute('aria-label'));
                $this->assertSame("setView('".$value."')", $button->getAttribute('wire:click'));
                $this->assertSame($value === $view ? 'true' : 'false', $button->getAttribute('aria-pressed'));
                $this->assertSame('setView', $button->getAttribute('wire:target'));
            }
            $this->assertSame(0, $xpath->query('//*[@id="case-workspace-view" and not(ancestor::template[@*[name()="x-teleport"]="[data-topbar-planning-navigation]"])]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-case-workspace and @*[name()="x-data"]]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-case-workspace]/header/button[@*[name()="wire:click"]="setView(\'offers\')"]')->length);
        }
        Http::assertNothingSent();
    }

    public function test_rendered_topbar_and_sidebar_share_the_same_permission_filtered_planning_views(): void
    {
        foreach ([['operations.inquiries.manage'], ['operations.manage']] as $abilities) {
            $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
            foreach (['operations.manage', 'operations.inquiries.manage', 'operations.costs.manage', 'customers.portal.manage'] as $ability) {
                Gate::define($ability, fn (User $user): bool => $user->is($actor) && in_array($ability, $abilities, true));
            }
            $planning = OperationsPages::planningViews($actor);
            $expected = in_array('operations.manage', $abilities, true) ? ['orders', 'shifts'] : ['inbox'];
            $this->assertSame($expected, array_keys($planning));
            $component = Livewire::actingAs($actor)->test(CaseWorkspace::class, ['initialView' => $expected[0]])->assertSuccessful();
            $xpath = $this->xpath($component->html());
            $buttons = $xpath->query('//*[@id="case-workspace-view"]//button[@data-multi-toggle-option]');
            $this->assertSame($expected, array_map(fn (\DOMElement $button): string => $button->getAttribute('data-toggle-value'), iterator_to_array($buttons)));
            $sidebar = collect(ApplicationNavigation::sections($actor)['Disposition'] ?? [])->where('group', 'Planung')->reject(fn (array $item): bool => ($item['parameters']['section'] ?? '') === 'calendar');
            $this->assertSame($expected, $sidebar->pluck('parameters.view')->values()->all());
            $this->assertSame(array_column($planning, 'label'), $sidebar->pluck('title')->values()->all());
            $forbidden = $expected === ['inbox'] ? 'orders' : 'inbox';
            $component->call('setView', $forbidden)->assertForbidden();
        }
    }

    public function test_teleported_view_actions_retain_customer_and_list_context_through_existing_redirects(): void
    {
        $context = ['customer' => $this->customer->id, 'search' => 'Nord', 'status' => 'confirmed'];
        $component = Livewire::actingAs($this->admin)->test(CaseWorkspace::class, ['initialView' => 'orders', 'context' => $context]);
        $xpath = $this->xpath($component->html());
        foreach (['orders', 'inbox', 'shifts'] as $target) {
            $button = $xpath->query('//*[@id="case-workspace-view"]//button[@data-toggle-value="'.$target.'"]')->item(0);
            $this->assertSame("setView('".$target."')", $button->getAttribute('wire:click'));
            $parameters = ['view' => $target, 'customer' => $this->customer->id];
            if ($target !== 'shifts') {
                $parameters['search'] = 'Nord';
            }
            if ($target === 'orders') {
                $parameters['status'] = 'confirmed';
            }
            $component->call('setView', $target)->assertRedirect(OperationsPages::url('cases', $parameters));
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('shifts', 0);
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
        $this->assertMatchesRegularExpression('/\.rt-shell-planning-navigation \.rt-multi-toggle \.rt-multi-toggle__button\.rt-ui-button\s*\{[^}]*min-width:\s*40px;[^}]*min-height:\s*40px;/s', $css);
        $this->assertStringContainsString('.rt-shell-topbar:has([data-topbar-planning-navigation] > *) .rt-shell-brand-mark', $css);
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
