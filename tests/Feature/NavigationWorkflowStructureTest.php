<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\Operations\ApplicationNavigation;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class NavigationWorkflowStructureTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private array $permissions = [];

    private const ABILITIES = [
        'employees.view', 'employees.master-data.view', 'employees.master-data.edit',
        'employees.compensation.view', 'employees.compensation.edit', 'employees.recruiting.manage',
        'employees.development.manage', 'employees.emergency.access', 'operations.manage',
        'operations.inquiries.manage', 'operations.qualifications.manage', 'operations.absences.review',
        'operations.time.review', 'operations.time.export', 'operations.rules.manage',
        'operations.costs.manage', 'operations.terminal.manage', 'operations.inbox.view',
        'customers.portal.manage', 'customers.portal.publish', 'customers.portal.automation',
        'devices.view', 'files.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        $this->withoutVite();
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        foreach (self::ABILITIES as $ability) {
            Gate::define($ability, fn (User $user): bool => in_array($ability, $this->permissions[$user->id] ?? [], true));
        }
    }

    #[DataProvider('visibilityScenarios')]
    public function test_current_menu_changes_only_explicit_employee_consolidation_offer_link_and_resource_title(string $schema, string $role, array $abilities, bool $active = true, ?string $team = null): void
    {
        $this->installSchema($schema);
        $actor = $this->actor($role, $abilities, $active, $team);
        $before = $this->originalSections($actor);
        $after = ApplicationNavigation::sections($actor);

        $this->assertSame($this->contracts($this->withRequestedPlanningUpdates($this->consolidatePeople($before), $actor)), $this->contracts($after), 'Only individual employee entries, the requested offer destination and the resource-page title may change; every other leaf contract stays unchanged.');
        $peopleLinks = collect($after)->flatten(1)->filter(fn (array $link): bool => $link['route'] === 'operations.page' && ($link['parameters']['page'] ?? '') === 'people');
        $this->assertCount(isset(OperationsPages::availableFor($actor)['people']) ? 1 : 0, $peopleLinks);
        foreach (array_diff(array_unique([...array_keys($before), ...array_keys($after)]), ['', 'Disposition', 'Personal']) as $section) {
            $this->assertSame($before[$section] ?? [], $after[$section] ?? [], 'Unrelated section changed: '.$section);
        }
        $this->assertSame('', array_key_first($after));
        $this->assertSame('Dashboard', $after[''][0]['title']);
        $this->assertSame('Persönlich', array_key_last($after));
        foreach ($after as $links) {
            $this->assertNotEmpty($links);
            foreach (ApplicationNavigation::groups($links) as $group) {
                $this->assertNotEmpty($group['links']);
            }
        }
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public static function visibilityScenarios(): array
    {
        $scenarios = [];
        foreach (['none', 'core', 'full'] as $schema) {
            $scenarios[$schema.' admin'] = [$schema, 'admin', []];
            $scenarios[$schema.' employee'] = [$schema, 'staff', []];
            $scenarios[$schema.' management'] = [$schema, 'staff', self::ABILITIES, true, 'Verwaltung'];
            $scenarios[$schema.' inactive'] = [$schema, 'staff', self::ABILITIES, false];
        }
        foreach (self::ABILITIES as $ability) {
            $scenarios['only '.$ability] = ['full', 'staff', [$ability]];
        }
        $scenarios['personnel with emergency access'] = ['full', 'staff', ['employees.master-data.view', 'employees.emergency.access']];
        $scenarios['personnel and rule assignments'] = ['full', 'staff', ['employees.master-data.view', 'operations.rules.manage']];
        $scenarios['time review with terminal and rules'] = ['full', 'staff', ['operations.time.review', 'operations.rules.manage', 'operations.terminal.manage']];

        return $scenarios;
    }

    public function test_full_menu_groups_existing_links_by_workflow_without_promoting_page_tabs(): void
    {
        $this->installSchema('full');
        $sections = ApplicationNavigation::sections($this->actor('admin'));
        $this->assertSame(['Dashboard', 'Arbeitsliste'], array_column($sections[''], 'title'));
        $this->assertSame([
            '' => ['Eingang', 'Aufträge', 'Leitstelle'],
            'Planung' => ['Angebote', 'Schichtplan', 'Kalender', 'Ressourcen & Kapazität'],
        ], $this->groupedTitles($sections['Disposition']));
        $this->assertSame(['Eingang', 'Aufträge', 'Angebote', 'Schichtplan', 'Kalender', 'Ressourcen & Kapazität', 'Leitstelle'], array_column($sections['Disposition'], 'title'));
        $this->assertSame([
            '' => ['Mitarbeiter', 'Personalprozesse', 'Regelprofile'],
            'Zeitwirtschaft' => ['Urlaub & Konten', 'Zeitprüfung', 'Monatsabschluss & Export'],
        ], $this->groupedTitles($sections['Personal']));
        $this->assertSame(['', '', 'Zeitwirtschaft', ''], array_column(array_values(ApplicationNavigation::groups($sections['Personal'])), 'label'));
        $this->assertSame(['inbox', 'offers', 'orders', 'shifts', 'calendar'], array_keys(OperationsPages::planningShortcuts($this->actor('admin'))), 'The offer shortcut follows the requested workflow without replacing any existing destination.');
    }

    public function test_sidebar_and_mobile_navigation_render_the_same_preserved_destinations(): void
    {
        $this->installSchema('full');
        $actor = $this->actor('admin');
        $this->actingAs($actor);
        $this->setPageRequest('people', ['section' => 'signatures']);
        $links = collect(ApplicationNavigation::sections($actor))->flatten(1)->all();
        $destinations = array_map(fn (array $link): string => route($link['route'], $link['parameters']), $links);
        $sidebar = $this->xpath(view('layouts.application-navigation')->render());
        $rendered = iterator_to_array($sidebar->query('//a[@data-rt-sidebar-link]'));
        $this->assertSame($destinations, array_map(fn (\DOMElement $link): string => $link->getAttribute('href'), $rendered));
        foreach ($rendered as $index => $element) {
            $this->assertSame($links[$index]['navigate'], $element->hasAttribute('wire:navigate'));
            $this->assertSame($links[$index]['title'], trim($sidebar->query('.//span[contains(@class, "sidebar-nav-link__label")]', $element)->item(0)->textContent));
        }
        $this->assertSame(['Mitarbeiter'], array_map(fn (\DOMElement $element): string => trim($sidebar->query('.//span[contains(@class, "sidebar-nav-link__label")]', $element)->item(0)->textContent), iterator_to_array($sidebar->query('//a[@data-rt-sidebar-link and @aria-current="page"]'))));
        $expanded = $sidebar->query('//a[@data-rt-sidebar-group and @aria-expanded="true"]');
        $this->assertCount(0, $expanded);

        $mobile = $this->xpath(view('components.operations.navigation', ['current' => 'qualifications', 'modules' => []])->render());
        $select = $mobile->query('//*[@data-rt-custom-select]');
        $this->assertCount(1, $select);
        $this->assertSame(1, preg_match("/\\boptions:\\s*JSON\\.parse\\('([^']*)'\\)/s", $select->item(0)->getAttribute('x-data'), $matches));
        $options = json_decode(json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($destinations, array_column($options, 'value'));
        $this->assertSame([OperationsPages::url('people')], array_column(array_filter($options, fn (array $option): bool => $option['selected']), 'value'));
    }

    #[DataProvider('activeDestinations')]
    public function test_relocated_links_keep_exact_active_destination_and_no_parent_alias(string $page, array $query, string $title): void
    {
        $this->installSchema('full');
        $actor = $this->actor('admin');
        $this->actingAs($actor);
        $this->setPageRequest($page, $query);
        $active = collect(ApplicationNavigation::sections($actor))->flatten(1)->filter(fn (array $link): bool => ApplicationNavigation::active($link))->pluck('title')->values()->all();
        $this->assertSame([$title], $active);
    }

    public static function activeDestinations(): array
    {
        return [
            ['attention', [], 'Arbeitsliste'],
            ['cases', ['view' => 'inbox', 'section' => 'ai-intake'], 'Eingang'],
            ['cases', ['view' => 'offers', 'customer' => '12'], 'Angebote'],
            ['cases', ['view' => 'orders', 'order' => '12'], 'Aufträge'],
            ['cases', ['view' => 'shifts', 'section' => 'plan'], 'Schichtplan'],
            ['cases', ['view' => 'shifts', 'section' => 'calendar'], 'Kalender'],
            ['planning', ['view' => 'staff', 'section' => 'pools'], 'Ressourcen & Kapazität'],
            ['duty', ['view' => 'board'], 'Leitstelle'],
            ['people', ['view' => 'employees'], 'Mitarbeiter'],
            ['people', ['view' => 'documents'], 'Mitarbeiter'],
            ['people', ['view' => 'qualifications'], 'Mitarbeiter'],
            ['people', ['view' => 'training'], 'Mitarbeiter'],
            ['people', ['section' => 'signatures'], 'Mitarbeiter'],
            ['people', ['section' => 'emergency'], 'Mitarbeiter'],
            ['personnel-processes', ['view' => 'workflows'], 'Personalprozesse'],
            ['leave', ['view' => 'requests'], 'Urlaub & Konten'],
            ['leave', ['section' => 'models'], 'Urlaub & Konten'],
            ['leave', ['section' => 'policies'], 'Urlaub & Konten'],
            ['leave', ['section' => 'rules'], 'Urlaub & Konten'],
            ['time-review', ['view' => 'times'], 'Zeitprüfung'],
            ['time-review', ['section' => 'rules'], 'Regelprofile'],
            ['payroll', ['view' => 'export'], 'Monatsabschluss & Export'],
        ];
    }

    private function installSchema(string $level): void
    {
        if ($level === 'none') {
            return;
        }
        $migrations = ['2026_09_15_190000_create_operations_workflow_tables.php'];
        if ($level === 'full') {
            $migrations = [...$migrations,
                '2026_07_22_000002_create_employee_document_requirements_table.php',
                '2026_09_17_180000_create_operations_planning_extensions.php',
                '2026_09_17_190000_create_employee_payroll_references.php',
                '2026_10_04_120000_create_workforce_personnel_foundations.php',
                '2026_10_04_121000_create_customer_workflow_extensions.php',
                '2026_10_04_122000_create_workforce_planning_tables.php',
                '2026_10_04_123000_extend_work_time_contexts.php',
                '2026_10_06_100000_create_planning_enhancements.php',
                '2026_10_06_101000_create_personnel_enhancements.php',
                '2026_10_06_102000_create_operations_enhancements.php',
                '2026_10_06_103000_create_operations_attention_tables.php',
                '2026_10_06_110000_create_customer_portal_access.php',
                '2026_10_06_111000_create_customer_portal_workflows.php',
                '2026_10_06_112000_create_customer_portal_intake_and_capacity.php',
            ];
        }
        foreach ($migrations as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function actor(string $role, array $abilities = [], bool $active = true, ?string $teamName = null): User
    {
        $actor = User::factory()->create(['role' => $role, 'status' => $active]);
        $this->permissions[$actor->id] = $abilities;
        if ($teamName !== null) {
            $team = Team::forceCreate(['user_id' => $actor->id, 'name' => $teamName, 'personal_team' => false]);
            $actor->teams()->attach($team, ['role' => 'member']);
            $actor->forceFill(['current_team_id' => $team->id])->save();
            $actor->unsetRelation('currentTeam');
        }

        return $actor;
    }

    private function contracts(array $sections): array
    {
        $contracts = [];
        foreach ($sections as $links) {
            foreach ($links as $link) {
                unset($link['group']);
                $contracts[] = $link;
            }
        }
        usort($contracts, fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        return $contracts;
    }

    private function consolidatePeople(array $sections): array
    {
        $hadPeople = false;
        foreach ($sections as &$links) {
            foreach ($links as &$link) {
                if ($link['route'] === 'operations.page' && $link['parameters'] === ['page' => 'leave']) {
                    $link['excludedSections'] = [];
                }
            }
            unset($link);
            $links = array_values(array_filter($links, function (array $link) use (&$hadPeople): bool {
                if ($link['route'] === 'operations.page' && ($link['parameters']['page'] ?? '') === 'leave' && in_array($link['parameters']['section'] ?? '', ['models', 'policies', 'rules'], true)) {
                    return false;
                }
                if ($link['route'] !== 'operations.page' || ($link['parameters']['page'] ?? '') !== 'people') {
                    return true;
                }
                $hadPeople = true;

                return false;
            }));
        }
        unset($links);
        if ($hadPeople) {
            $sections['Personal'][] = ['title' => 'Mitarbeiter', 'route' => 'operations.page', 'icon' => 'users', 'parameters' => ['page' => 'people'], 'navigate' => true, 'group' => null, 'excludedSections' => []];
        }

        return $sections;
    }

    private function groupedTitles(array $links): array
    {
        $groups = [];
        foreach ($links as $link) {
            $groups[$link['group'] ?? ''][] = $link['title'];
        }

        return $groups;
    }

    private function withRequestedPlanningUpdates(array $sections, User $actor): array
    {
        foreach ($sections as &$links) {
            foreach ($links as &$link) {
                if ($link['route'] === 'operations.page' && $link['parameters'] === ['page' => 'planning']) {
                    $this->assertSame('Bedarf & Planung', $link['title']);
                    $link['title'] = 'Ressourcen & Kapazität';
                }
            }
            unset($link);
        }
        unset($links);
        if (isset(OperationsPages::views($actor, 'cases')['offers'])) {
            $sections['Disposition'][] = ['title' => 'Angebote', 'route' => 'operations.page', 'icon' => 'file-text', 'parameters' => ['page' => 'cases', 'view' => 'offers'], 'navigate' => true, 'group' => 'Planung', 'excludedSections' => []];
        }

        return $sections;
    }

    private function setPageRequest(string $page, array $query): void
    {
        $request = Request::create('/arbeitsplatz/ansicht/'.$page, 'GET', $query);
        $route = new Route('GET', 'arbeitsplatz/ansicht/{page}', fn () => response(''));
        $route->name('operations.page');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        app()->instance('request', $request);
    }

    private function xpath(string $html): \DOMXPath
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

    /** Frozen pre-restructure menu assembly: deliberately independent of the production grouping implementation. */
    private function originalSections(User $user): array
    {
        $admin = $user->isAdmin();
        $sections = ['' => []];
        $add = static function (string $section, string $title, string $route, string $icon, array $parameters = [], bool $navigate = true, ?string $group = null, array $excludedSections = []) use (&$sections): void {
            $sections[$section][] = compact('title', 'route', 'icon', 'parameters', 'navigate', 'group', 'excludedSections');
        };
        $add('', 'Dashboard', $admin ? 'admin.dashboard' : 'dashboard', 'home');
        $sections += array_fill_keys($user->dashboardAudience() === 'employee'
            ? ['Mein Arbeitsplatz', 'Disposition', 'Kunden', 'Personal']
            : ['Disposition', 'Kunden', 'Personal', 'Mein Arbeitsplatz'], []);
        $ready = OperationsAccess::ready();
        if ($ready && OperationsAccess::isEmployee($user)) {
            $add('Mein Arbeitsplatz', 'Mein Arbeitstag', 'operations.mine', 'clock');
        }
        foreach (OperationsPages::availableFor($user) as $page => $definition) {
            if ($page === 'cases') {
                foreach (array_intersect_key(OperationsPages::views($user, 'cases'), array_flip(['inbox', 'orders', 'shifts'])) as $view => $entry) {
                    $parameters = ['page' => 'cases', 'view' => $view];
                    if ($view === 'shifts') {
                        $parameters['section'] = 'plan';
                    }
                    $icon = match ($view) {
                        'inbox' => 'inbox', 'orders' => 'briefcase', 'shifts' => 'clipboard',
                    };
                    $add($definition['segment'], $entry['label'], 'operations.page', $icon, $parameters, true, 'Planung');
                    if ($view === 'shifts') {
                        $add($definition['segment'], 'Kalender', 'operations.page', 'calendar', ['page' => 'cases', 'view' => 'shifts', 'section' => 'calendar'], true, 'Planung');
                    }
                }

                continue;
            }
            if ($page === 'people') {
                $views = OperationsPages::views($user, $page);
                foreach (['employees' => 'users', 'qualifications' => 'award', 'documents' => 'folder', 'training' => 'book-open'] as $view => $icon) {
                    if (isset($views[$view])) {
                        $add('Personal', $views[$view], 'operations.page', $icon, ['page' => $page, 'view' => $view], true, 'Personal');
                    }
                }
                foreach (OperationsPages::sections($user, $page) as $section => $label) {
                    $add('Personal', $label, 'operations.page', $section === 'emergency' ? 'phone' : 'file-text', ['page' => $page, 'section' => $section], true, 'Personal');
                }

                continue;
            }

            $shortcuts = match ($page) {
                'leave' => ['models' => ['Arbeitsmodelle', 'clock'], 'policies' => ['Urlaubsrichtlinien', 'book-open'], 'rules' => ['Regelzuordnungen', 'sliders']],
                'time-review' => ['rules' => ['Regelprofile', 'shield']],
                default => [],
            };
            $availableSections = $shortcuts ? OperationsPages::sections($user, $page) : [];
            $shortcuts = array_intersect_key($shortcuts, $availableSections);
            foreach ($shortcuts as $section => [$label, $icon]) {
                $add('Personal', $label, 'operations.page', $icon, ['page' => $page, 'section' => $section], true, 'Personal');
            }
            if (! $shortcuts || OperationsPages::views($user, $page) || array_diff_key($availableSections, $shortcuts)) {
                $add($definition['segment'], $page === 'planning' ? 'Bedarf & Planung' : $definition['title'], 'operations.page', $definition['icon'], ['page' => $page], true, $definition['group'] ?? null, array_keys($shortcuts));
            }
        }
        if (! $ready && $admin) {
            foreach (['orders' => 'Leistungen', 'shift-management' => 'Schichtplan', 'calendar' => 'Kalender', 'customers' => 'Kunden'] as $slug => $title) {
                $add($slug === 'customers' ? 'Kunden' : 'Disposition', $title, 'admin.operations.preview', 'calendar', ['module' => $slug]);
            }
        }
        if ($admin || in_array($user->dashboardAudience(), ['employee', 'management', 'administration'], true)) {
            $add('Mein Arbeitsplatz', 'Wagenliste', $admin ? 'admin.operations.wagon-list' : 'operations.wagon-list', 'list', [], true, 'Arbeitsmittel');
        }
        if ($user->can('employees.view') && ! $admin) {
            $add('Kommunikation', 'Anrufe', 'calls.index', 'phone');
        }
        if ($user->can('devices.view')) {
            $add('Geräte', 'Geräte & Lager', $admin ? 'admin.devices' : 'devices.index', 'monitor');
        }
        if ($admin) {
            $add('Kommunikation', 'Mailverwaltung', 'admin.mail-management', 'send');
            $add('Marketing', 'Motive', 'admin.marketing.creatives.index', 'image');
        } else {
            $add('Dokumente', 'Download-Center', 'files', 'download-cloud');
        }
        $add('Persönlich', 'Meine Geräte', 'devices.mine', 'smartphone');
        $add('Persönlich', 'Profil', 'profile.show', 'user', [], false);

        return array_filter($sections, fn (array $links): bool => count($links) > 0);
    }
}
