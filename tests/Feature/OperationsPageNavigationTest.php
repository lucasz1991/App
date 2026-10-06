<?php

namespace Tests\Feature;

use App\Livewire\Operations\PageWorkspace;
use App\Livewire\Operations\Workspace;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\OperationsDutyMonitorService;
use App\Support\Operations\ApplicationNavigation;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsPageNavigationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private array $permissions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description')->nullable();
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        config(['customer_portal.delivery_enabled' => false]);
        Mail::fake();
        Bus::fake();
        $this->withoutVite();
        foreach (['employees.view', 'employees.master-data.view', 'employees.master-data.edit', 'employees.compensation.view', 'employees.compensation.edit', 'employees.recruiting.manage', 'employees.development.manage', 'employees.emergency.access', 'operations.manage', 'operations.inquiries.manage', 'operations.qualifications.manage', 'operations.absences.review', 'operations.time.review', 'operations.time.export', 'operations.rules.manage', 'operations.costs.manage', 'operations.terminal.manage', 'operations.inbox.view', 'customers.portal.manage', 'customers.portal.publish', 'customers.portal.automation', 'devices.view'] as $ability) {
            Gate::define($ability, fn ($user) => $user instanceof User && in_array($ability, $this->permissions[$user->id] ?? [], true));
        }
    }

    private function actor(array $abilities): User
    {
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->permissions[$actor->id] = $abilities;

        return $actor;
    }

    private function operations(): void
    {
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_101000_create_personnel_enhancements.php', '2026_10_06_102000_create_operations_enhancements.php', '2026_10_06_110000_create_customer_portal_access.php', '2026_10_06_111000_create_customer_portal_workflows.php', '2026_10_06_112000_create_customer_portal_intake_and_capacity.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_dashboard_is_alone_at_top_personal_at_bottom_and_wagon_list_is_own_work_equipment(): void
    {
        $this->operations();
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage', 'employees.view', 'employees.master-data.view', 'operations.time.review', 'operations.time.export', 'operations.absences.review', 'operations.qualifications.manage', 'operations.rules.manage']);
        $sections = ApplicationNavigation::sections($actor);
        $this->assertSame('', array_key_first($sections));
        $this->assertCount(1, $sections['']);
        $this->assertSame('Dashboard', $sections[''][0]['title']);
        $this->assertSame('Persönlich', array_key_last($sections));
        $this->assertSame(['Meine Geräte', 'Profil'], array_column($sections['Persönlich'], 'title'));
        $wagon = collect($sections['Mein Arbeitsplatz'])->firstWhere('title', 'Wagenliste');
        $this->assertNotNull($wagon);
        $this->assertSame('Arbeitsmittel', $wagon['group']);
        $this->assertFalse(collect($sections['Disposition'])->contains('title', 'Wagenliste'));
        $this->assertFalse(collect($sections['Kunden'])->contains('title', 'Wagenliste'));
    }

    public function test_customers_have_only_customer_hub_and_time_management_is_under_personal(): void
    {
        $this->operations();
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage', 'employees.view', 'employees.master-data.view', 'operations.time.review', 'operations.time.export', 'operations.absences.review']);
        $sections = ApplicationNavigation::sections($actor);
        $this->assertSame(['customers'], array_column(array_column($sections['Kunden'], 'parameters'), 'page'));
        foreach ($sections['Kunden'] as $link) {
            $this->assertNotContains($link['title'], ['Anfragen', 'Angebote', 'Aufträge', 'Vorgänge & Aufträge']);
        }
        $groups = ApplicationNavigation::groups($sections['Personal']);
        $this->assertArrayHasKey('Zeitwirtschaft', $groups);
        $pages = array_column(array_column($groups['Zeitwirtschaft']['links'], 'parameters'), 'page');
        $this->assertContains('leave', $pages);
        $this->assertContains('time-review', $pages);
        $this->assertContains('payroll', $pages);
        foreach ($groups as $group) {
            $this->assertNotEmpty($group['links']);
        }
    }

    public function test_empty_segments_and_groups_are_removed_for_unprivileged_employee(): void
    {
        $actor = $this->actor([]);
        $sections = ApplicationNavigation::sections($actor);
        $this->assertArrayNotHasKey('Disposition', $sections);
        $this->assertArrayNotHasKey('Kunden', $sections);
        $this->assertArrayNotHasKey('Personal', $sections);
        $this->assertSame([], ApplicationNavigation::groups([]));
        foreach ($sections as $links) {
            $this->assertNotEmpty($links);
            foreach (ApplicationNavigation::groups($links) as $group) {
                $this->assertNotEmpty($group['links']);
            }
        }
    }

    public function test_employee_base_page_remains_available_without_operations_schema(): void
    {
        $actor = $this->actor(['employees.view']);
        $this->assertFalse(OperationsAccess::ready());
        $this->assertSame(['employees' => 'Mitarbeiter'], OperationsPages::views($actor, 'people'));
        $this->assertArrayHasKey('people', OperationsPages::availableFor($actor));
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'people'])->assertSet('page', 'people')->assertSee('data-personal-page="people"', false);
        $this->assertFalse(Schema::hasTable('work_time_entries'));
        Mail::assertNothingSent();
    }

    public function test_page_mount_and_later_render_recheck_server_rights(): void
    {
        $actor = $this->actor([]);
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'customers'])->assertForbidden();
        $actor = $this->actor(['operations.manage']);
        Customer::create(['company_name' => 'Navigation customer', 'is_active' => true]);
        $component = Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'customers'])->assertSet('page', 'customers');
        $this->permissions[$actor->id] = [];
        $component->call('$refresh')->assertForbidden();
    }

    public function test_inactive_actor_and_unknown_page_cannot_mount(): void
    {
        $actor = $this->actor(['operations.manage']);
        $actor->update(['status' => false]);
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'customers'])->assertForbidden();
        $actor->update(['status' => true]);
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'unknown'])->assertNotFound();
    }

    #[DataProvider('malformedViewParameters')]
    public function test_malformed_view_or_section_is_rejected_before_child_mount(array $query): void
    {
        $actor = $this->actor(['operations.manage']);
        Livewire::actingAs($actor)->withQueryParams($query)->test(PageWorkspace::class, ['page' => 'customers'])->assertStatus(422);
    }

    public static function malformedViewParameters(): array
    {
        return [
            'array-view' => [['view' => ['master']]], 'array-section' => [['section' => ['access']]],
            'long-view' => [['view' => str_repeat('a', 41)]], 'long-section' => [['section' => str_repeat('a', 41)]],
        ];
    }

    #[DataProvider('lockedPageParameters')]
    public function test_page_and_initial_context_parameters_cannot_be_client_mutated(string $property, mixed $value): void
    {
        $actor = $this->actor(['operations.manage']);
        Customer::create(['company_name' => 'Locked navigation customer', 'is_active' => true]);
        $component = Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'customers']);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set($property, $value);
    }

    public static function lockedPageParameters(): array
    {
        return [['page', 'documents'], ['initialView', 'portal'], ['initialSection', 'automation'], ['context', ['customer' => 999]]];
    }

    public function test_cases_hub_without_view_query_uses_authorized_default(): void
    {
        $this->operations();
        $actor = $this->actor(['operations.inquiries.manage']);
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'cases'])->assertSet('initialView', '')->assertSee('Vorgänge und Aufträge');
    }

    public function test_rules_only_entry_does_not_require_time_review_permission(): void
    {
        $this->operations();
        $actor = $this->actor(['operations.rules.manage']);
        $this->assertSame([], OperationsPages::views($actor, 'time-review'));
        $this->assertArrayHasKey('rules', OperationsPages::sections($actor, 'time-review'));
        $this->assertArrayHasKey('time-review', OperationsPages::availableFor($actor));
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'time-review'])->assertSet('page', 'time-review')->assertSee('Fachregeln');
        Livewire::actingAs($actor)->test(Workspace::class, ['module' => 'rules'])->assertRedirect(OperationsPages::moduleUrl('rules'));
    }

    public function test_typed_context_normalizes_ids_and_aliases_without_losing_source_revision(): void
    {
        $request = Request::create('/arbeitsplatz/ansicht/cases', 'GET', ['inquiry' => '12', 'order_id' => '34', 'customer' => 56, 'user_id' => '78', 'shift' => '90', 'revision' => '2', 'record_id' => '45', 'source' => 'submission', 'record_type' => 'proof', 'search' => 'Rail', 'from' => '2026-10-01', 'until' => '2026-10-31', 'status' => 'review']);
        $this->assertSame(['inquiry' => 12, 'order' => 34, 'customer' => 56, 'user' => 78, 'shift' => 90, 'revision' => 2, 'record' => 45, 'source' => 'submission', 'record_type' => 'proof', 'search' => 'Rail', 'from' => '2026-10-01', 'until' => '2026-10-31', 'status' => 'review', 'user_id' => 78, 'record_id' => 45], OperationsPages::context($request));
        $this->assertSame(['user' => 7, 'user_id' => 7], OperationsPages::context(Request::create('/test', 'GET', ['user' => '7', 'user_id' => '99'])));
    }

    #[DataProvider('malformedContext')]
    public function test_malformed_context_is_rejected_before_child_mount(array $query): void
    {
        try {
            OperationsPages::context(Request::create('/test', 'GET', $query));
            $this->fail('Malformed context must not be coerced into a valid record reference.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public static function malformedContext(): array
    {
        return [
            'array-id' => [['customer' => ['1']]], 'zero-id' => [['customer' => '0']], 'negative-id' => [['order' => '-1']],
            'decimal-id' => [['inquiry' => '1.5']], 'mixed-id' => [['user' => '12oops']], 'overflow-id' => [['record' => str_repeat('9', 40)]],
            'boolean-id' => [['shift' => true]], 'float-id' => [['revision' => 1.0]], 'false-id' => [['customer' => false]],
            'array-source' => [['source' => ['submission']]], 'long-source' => [['source' => str_repeat('a', 101)]], 'array-search' => [['search' => ['term']]],
        ];
    }

    #[DataProvider('legacyModules')]
    public function test_all_twenty_legacy_modules_map_to_their_canonical_page(string $module, string $page, string $view, string $section): void
    {
        $this->assertSame(['page' => $page, 'view' => $view, 'section' => $section, 'customer' => '12', 'record' => '34', 'revision' => '2'], OperationsPages::legacyTarget($module, ['customer' => '12', 'record' => '34', 'revision' => '2', 'page' => 'documents', 'view' => 'invalid', 'section' => 'invalid']));
    }

    public static function legacyModules(): array
    {
        return [
            ['inquiries', 'cases', 'inbox', ''], ['orders', 'cases', 'orders', ''], ['shift-management', 'shifts', 'plan', ''], ['calendar', 'shifts', 'calendar', ''],
            ['customers', 'customers', 'master', ''], ['customer-portal', 'customers', 'portal', 'access'], ['qualifications', 'people', 'qualifications', ''], ['absences', 'leave', 'requests', ''],
            ['times', 'time-review', 'times', ''], ['exports', 'payroll', 'export', ''], ['rules', 'time-review', '', 'rules'], ['workforce-accounts', 'leave', 'leave-accounts', ''],
            ['personnel-processes', 'personnel-processes', 'tasks', ''], ['workforce-planning', 'planning', 'staff', 'pools'], ['plan-variants', 'planning', 'tools', 'variants'],
            ['planning-enhancements', 'planning', 'capacity', 'capacity'], ['personnel-enhancements', 'personnel-processes', 'workflows', ''], ['operations-enhancements', 'cases', 'orders', 'proofs'],
            ['attention-center', 'attention', 'inbox', ''], ['duty-monitor', 'duty', 'board', ''],
        ];
    }

    public function test_portal_requests_move_to_disposition_preserving_customer_source_and_record(): void
    {
        $this->assertSame(['page' => 'cases', 'view' => 'inbox', 'section' => 'portal', 'customer' => '42', 'source' => 'submission', 'record' => '3', 'revision' => '4'], OperationsPages::legacyTarget('customer-portal', ['tab' => 'requests', 'customer' => '42', 'source' => 'submission', 'record' => '3', 'revision' => '4']));
        $this->assertSame(['page' => 'customers', 'view' => 'portal', 'section' => 'access'], OperationsPages::legacyTarget('customer-portal', ['tab' => 'contacts']));
    }

    public function test_legacy_workforce_tasks_training_and_rule_tools_keep_authorized_destination(): void
    {
        $this->assertSame(['page' => 'personnel-processes', 'view' => 'tasks', 'section' => '', 'user' => '7'], OperationsPages::legacyTarget('workforce-accounts', ['tab' => 'tasks', 'user' => '7']));
        $this->assertSame(['page' => 'people', 'view' => 'training', 'section' => '', 'user' => '7'], OperationsPages::legacyTarget('workforce-accounts', ['tab' => 'training', 'user' => '7']));
        $this->assertSame(['page' => 'leave', 'view' => 'requests', 'section' => ''], OperationsPages::legacyTarget('workforce-accounts', ['tab' => 'absences']));
        $this->assertSame(['page' => 'time-review', 'view' => '', 'section' => 'rules'], OperationsPages::legacyTarget('operations-enhancements', ['tab' => 'rules']));
        $this->assertSame(['page' => 'time-review', 'view' => '', 'section' => 'terminal'], OperationsPages::legacyTarget('operations-enhancements', ['tab' => 'terminal']));
    }

    public function test_mobile_and_sidebar_navigation_share_central_entries_and_active_legacy_mapping(): void
    {
        $this->operations();
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage']);
        $this->actingAs($actor);
        $request = Request::create('/arbeitsplatz/orders');
        $route = new Route('GET', 'arbeitsplatz/{module}', fn () => response(''));
        $route->name('operations.workspace');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        app()->instance('request', $request);
        $links = collect(ApplicationNavigation::sections($actor))->flatten(1);
        $cases = $links->first(fn ($link) => ($link['parameters']['page'] ?? null) === 'cases');
        $customers = $links->first(fn ($link) => ($link['parameters']['page'] ?? null) === 'customers');
        $this->assertTrue(ApplicationNavigation::active($cases));
        $this->assertFalse(ApplicationNavigation::active($customers));
        $mobile = view('components.operations.navigation', ['current' => 'orders', 'modules' => []])->render();
        $sidebar = view('layouts.application-navigation')->render();
        $options = $this->mobileOptions($mobile);
        $destinations = $links->map(fn ($link) => route($link['route'], $link['parameters']))->all();
        $this->assertSame($destinations, array_column($options, 'value'));
        $this->assertEqualsCanonicalizing($destinations, $this->sidebarDestinations($sidebar));
        $this->assertSame([OperationsPages::url('cases')], array_column(array_filter($options, fn ($option) => $option['selected']), 'value'));
        foreach ([array_column($options, 'value'), $this->sidebarDestinations($sidebar)] as $targets) {
            $this->assertContains(OperationsPages::url('cases'), $targets);
            $this->assertContains(OperationsPages::url('customers'), $targets);
            $this->assertNotContains(route('operations.workspace', ['module' => 'inquiries']), $targets);
        }
        $this->assertNotContains('Stammdaten & Geräte', array_column($options, 'label'));
        $this->assertStringNotContainsString('Stammdaten & Geräte', html_entity_decode(strip_tags($sidebar), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_sidebar_keeps_dashboard_then_disposition_worklist_cases_planning_and_duty_order(): void
    {
        $this->operations();
        (require database_path('migrations/2026_10_06_103000_create_operations_attention_tables.php'))->up();
        $this->assertTrue(app(OperationsDutyMonitorService::class)->ready());
        $actor = $this->actor(['operations.manage', 'operations.inquiries.manage', 'operations.inbox.view']);
        $team = Team::forceCreate(['user_id' => $actor->id, 'name' => 'Verwaltung', 'personal_team' => false]);
        $actor->teams()->attach($team, ['role' => 'member']);
        $actor->forceFill(['current_team_id' => $team->id])->save();
        $actor->unsetRelation('currentTeam');
        $this->actingAs($actor);
        $this->assertArrayHasKey('board', OperationsPages::views($actor, 'duty'));
        $sections = ApplicationNavigation::sections($actor);
        $this->assertSame(['', 'Disposition'], array_slice(array_keys($sections), 0, 2));
        $this->assertSame(['attention', 'cases', 'shifts', 'planning', 'duty'], array_column(array_column($sections['Disposition'], 'parameters'), 'page'));
        $sidebar = view('layouts.application-navigation')->render();
        $destinations = $this->sidebarDestinations($sidebar);
        $this->assertSame(route('dashboard'), $destinations[0]);
        $expected = array_map(fn ($page) => OperationsPages::url($page), ['attention', 'cases', 'shifts', 'planning', 'duty']);
        $this->assertSame($expected, array_values(array_filter($destinations, fn ($url) => in_array($url, $expected, true))));
    }

    private function navigationXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }

    private function sidebarDestinations(string $html): array
    {
        $destinations = [];
        foreach ($this->navigationXPath($html)->query('//a[@data-rt-sidebar-link]') as $link) {
            $destinations[] = $link->getAttribute('href');
        }

        return $destinations;
    }

    private function mobileOptions(string $html): array
    {
        $controls = $this->navigationXPath($html)->query('//*[@data-rt-custom-select]');
        $this->assertCount(1, $controls);
        $state = $controls->item(0)->getAttribute('x-data');
        $this->assertSame(1, preg_match("/\\boptions:\\s*JSON\\.parse\\('([^']*)'\\)/s", $state, $matches), 'The shared select must expose its safely serialized options.');
        // Decode the JavaScript string and its JSON payload; never evaluate an Alpine expression.
        $payload = json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR);

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
