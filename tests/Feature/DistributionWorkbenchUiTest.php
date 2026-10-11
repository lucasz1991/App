<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\DistributionAutoAssignment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DistributionWorkbenchUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        (require database_path('migrations/2026_10_06_100000_create_planning_enhancements.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Workbench QA', 'role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Workbench Kunde', 'is_active' => true]);
    }

    private function order(string $title = 'Workbench Leistung'): Order
    {
        return Order::create([
            'customer_id' => $this->customer->id, 'title' => $title, 'status' => 'confirmed', 'priority' => 'normal',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00',
            'required_staff' => 1, 'created_by' => $this->manager->id,
        ]);
    }

    private function shift(Order $order, string $title = 'Workbench Schicht', string $day = '2027-05-13', int $staff = 1): Shift
    {
        $start = CarbonImmutable::parse($day.'T08:00:00+02:00');

        return Shift::create([
            'order_id' => $order->id, 'title' => $title, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin',
            'starts_at' => $start, 'ends_at' => $start->addHours(8), 'required_staff' => $staff,
            'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id,
        ]);
    }

    private function panel(array $query = [])
    {
        return Livewire::actingAs($this->manager)->withQueryParams(array_merge([
            'distribution' => '1', 'from' => '2027-05-10', 'until' => '2027-05-16', 'status' => 'all',
        ], $query))->test(ShiftManagement::class)->assertSet('distributionOpen', true);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function node(\DOMXPath $xpath, string $selector, ?\DOMNode $context = null): \DOMElement
    {
        $nodes = $xpath->query($selector, $context);
        $this->assertSame(1, $nodes->length, $selector);

        return $nodes->item(0);
    }

    private function cssRule(string $css, string $selector): string
    {
        $this->assertSame(1, preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css, $matches));

        return $matches[1];
    }

    public function test_workbench_defaults_to_list_and_its_inline_auto_region_is_hidden_and_inert(): void
    {
        $panel = $this->panel()->assertSeeLivewire(DistributionAutoAssignment::class);
        $xpath = $this->xpath($panel->html());
        $side = $this->node($xpath, '//div[@data-pending-distribution]');
        $this->assertStringContainsString("distributionMode: 'list'", $side->getAttribute('x-data'));
        $this->assertSame('distributionMode', $side->getAttribute('x-bind:data-distribution-mode'));
        $auto = $this->node($xpath, './/section[@data-distribution-auto-panel]', $side);
        $this->assertTrue($auto->hasAttribute('x-cloak'));
        $this->assertSame('display: none', $auto->getAttribute('style'));
        $this->assertSame("distributionMode === 'auto'", $auto->getAttribute('x-show'));
        $this->assertSame("distributionMode !== 'auto'", $auto->getAttribute(':inert'));
        $heading = $this->node($xpath, './/h3[@x-ref="distributionAutoHeading"]', $auto);
        $this->assertSame($heading->getAttribute('id'), $auto->getAttribute('aria-labelledby'));
        $this->assertSame('-1', $heading->getAttribute('tabindex'));
        $trigger = $this->node($xpath, './/button[@data-distribution-auto]', $side);
        $this->assertSame($auto->getAttribute('id'), $trigger->getAttribute('aria-controls'));
        $ownerGuard = $this->node($xpath, './/div[contains(@class,"rt-shift-distribution__auto-owner-guard")]', $auto);
        $this->assertSame('inert', $ownerGuard->getAttribute('wire:loading.attr'));
        foreach (['applyPeriod', 'movePeriod', 'currentWeek', 'rangeFrom', 'rangeTo', 'customerFilter', 'setView'] as $target) {
            $this->assertContains($target, explode(',', $ownerGuard->getAttribute('wire:target')));
        }
        $this->assertFalse($auto->hasAttribute('wire:loading.attr'), 'Alpine mode and parent request guards must not own the same inert attribute.');
        $this->assertSame(0, $xpath->query('.//*[@role="dialog" or @aria-modal="true"]', $auto)->length);
        $tabs = $this->node($xpath, './/div[@role="tablist"]', $side);
        $this->assertSame("distributionMode === 'list'", $tabs->getAttribute('x-show'));
        $this->assertSame("distributionMode !== 'list'", $tabs->getAttribute(':inert'));
        $groups = $xpath->query('.//section[@role="tabpanel"]', $side);
        $this->assertSame(2, $groups->length);
        foreach ($groups as $group) {
            $this->assertStringContainsString("distributionMode === 'list'", $group->getAttribute('x-show'));
            $this->assertStringContainsString("distributionMode !== 'list'", $group->getAttribute(':inert'));
        }
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_auto_icon_and_back_action_change_only_local_presentation_and_restore_keyboard_focus(): void
    {
        $shift = $this->shift($this->order());
        $before = $shift->fresh()->only(['revision', 'published_revision', 'status']);
        $xpath = $this->xpath($this->panel()->html());
        $auto = $this->node($xpath, '//button[@data-distribution-auto]');
        $this->assertSame('Alle offenen Schichten automatisch zuordnen', $auto->getAttribute('aria-label'));
        $this->assertSame($auto->getAttribute('aria-label'), $auto->getAttribute('title'));
        $this->assertSame('', trim($auto->textContent));
        $this->assertSame(1, $xpath->query('./i[contains(@class,"fa-wand-magic-sparkles") and @aria-hidden="true"]', $auto)->length);
        $this->assertFalse($auto->hasAttribute('wire:click'));
        $this->assertFalse($auto->hasAttribute('disabled'));
        $this->assertStringContainsString("distributionMode = 'auto'", $auto->getAttribute('x-on:click'));
        $this->assertStringContainsString('distributionAutoHeading?.focus({ preventScroll: true })', $auto->getAttribute('x-on:click'));
        $this->assertStringNotContainsString('$wire', $auto->getAttribute('x-on:click'));
        $this->assertSame(0, $xpath->query('./@*[contains(name(),"mouseenter") or contains(name(),"pointerover") or contains(name(),"focus")]', $auto)->length);
        $back = $this->node($xpath, '//button[@data-distribution-auto-back]');
        $this->assertSame("distributionMode === 'auto'", $back->getAttribute('x-show'));
        $this->assertSame("distributionMode !== 'auto'", $back->getAttribute(':inert'));
        $this->assertSame('display: none', $back->getAttribute('style'));
        $this->assertSame('Zur Schichtliste zurück', $back->getAttribute('aria-label'));
        $this->assertStringContainsString("distributionMode = 'list'", $back->getAttribute('x-on:click'));
        $this->assertStringContainsString('distributionAutoTrigger?.focus({ preventScroll: true })', $back->getAttribute('x-on:click'));
        $this->assertFalse($back->hasAttribute('wire:click'));
        $this->assertStringNotContainsString('$wire', $back->getAttribute('x-on:click'));
        $this->assertSame($before, $shift->fresh()->only(['revision', 'published_revision', 'status']));
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_opening_and_resetting_the_native_auto_child_never_prepares_or_assigns_business_data(): void
    {
        $shift = $this->shift($this->order());
        $before = $shift->fresh()->only(['revision', 'published_revision', 'status']);
        $child = Livewire::actingAs($this->manager)->test(DistributionAutoAssignment::class, [
            'from' => '2027-05-10', 'until' => '2027-05-16', 'customerFilter' => (string) $this->customer->id,
        ])->assertSet('preview', [])->assertSet('outcome', [])
            ->assertViewHas('available', true)->assertViewHas('prepared', false)->assertViewHas('canApply', false);
        $xpath = $this->xpath($child->html());
        $prepare = $this->node($xpath, '//button[@data-distribution-auto-prepare]');
        $this->assertSame('prepare', $prepare->getAttribute('wire:click'));
        $this->assertSame('disabled', $prepare->getAttribute('wire:loading.attr'));
        $this->assertSame('prepare,apply,resetPreview', $prepare->getAttribute('wire:target'));
        $this->assertSame(0, $xpath->query('//button[@data-distribution-auto-apply]')->length);
        $loader = $this->node($xpath, '//p[contains(@class,"rt-distribution-auto__loading")]');
        $this->assertTrue($loader->hasAttribute('wire:loading.flex'));
        $this->assertSame('prepare,apply', $loader->getAttribute('wire:target'));
        $this->assertSame('status', $loader->getAttribute('role'));
        $this->assertSame('display: none', $loader->getAttribute('style'));
        $child->call('resetPreview')->assertStatus(200)->assertSet('preview', [])->assertSet('outcome', [])
            ->assertNotDispatched('operations-plan-changed');
        $this->assertSame($before, $shift->fresh()->only(['revision', 'published_revision', 'status']));
        $this->assertSame(1, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_auto_child_uses_the_full_customer_and_period_scope_not_the_current_twenty_five_item_page(): void
    {
        $order = $this->order();
        for ($index = 1; $index <= 26; $index++) {
            $this->shift($order, 'Workbench Schicht '.$index);
        }
        $panel = $this->panel(['customer' => (string) $this->customer->id])
            ->assertSet('customerFilter', (string) $this->customer->id)
            ->assertSet('rangeFrom', '2027-05-10')->assertSet('rangeTo', '2027-05-16')
            ->assertViewHas('pendingShifts', fn ($pages) => $pages->total() === 26 && $pages->count() === 25);
        $source = file_get_contents(resource_path('views/livewire/admin/operations/partials/pending-distribution.blade.php'));
        $this->assertStringContainsString('<livewire:operations.distribution-auto-assignment :from="$rangeFrom" :until="$rangeTo" :customer-filter="$customerFilter"', $source);
        $this->assertStringContainsString("'distribution-auto-'.\$this->getId().'-'.\$rangeFrom.'-'.\$rangeTo.'-'.\$customerFilter", $source);
        $this->assertStringNotContainsString(':shift-ids="$pendingShifts', $source);
        $panel->call('nextPage', 'distributionShiftsPage')
            ->assertSet('customerFilter', (string) $this->customer->id)
            ->assertSet('rangeFrom', '2027-05-10')->assertSet('rangeTo', '2027-05-16')
            ->assertViewHas('pendingShifts', fn ($pages) => $pages->total() === 26 && $pages->count() === 1);
        $this->assertSame(26, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_day_bands_show_distinct_dates_and_actual_open_seat_totals_with_decorative_calendar_icons(): void
    {
        $order = $this->order();
        $this->shift($order, 'Donnerstag zwei Plätze', '2027-05-13', 2);
        $this->shift($order, 'Donnerstag ein Platz');
        $this->shift($order, 'Freitag ein Platz', '2027-05-14');
        $xpath = $this->xpath($this->panel()->html());
        $bands = $xpath->query('//h3[contains(concat(" ",normalize-space(@class)," ")," rt-shift-distribution__day-head ")]');
        $this->assertSame(2, $bands->length);
        foreach ($bands as $index => $band) {
            $this->assertStringContainsString($index === 0 ? '13.05.' : '14.05.', $band->textContent);
            $this->assertStringContainsString($index === 0 ? '3 frei' : '1 frei', $band->textContent);
            $this->assertSame('false', $band->getAttribute('data-today'));
            $this->assertSame(1, $xpath->query('.//i[contains(@class,"fa-calendar-day") and @aria-hidden="true"]', $band)->length);
        }
    }

    public function test_selected_item_retains_its_full_title_native_clear_action_and_assignment_loading_scope(): void
    {
        $title = 'Sehr ausführlicher Einsatzname für die vollständige ausgewählte Schicht mit mehreren Ortsangaben';
        $shift = $this->shift($this->order(), $title);
        $panel = $this->panel()->call('selectDistributionShift', $shift->id);
        $xpath = $this->xpath($panel->html());
        $selected = $this->node($xpath, '//li[@data-selected="true"]');
        $this->assertSame($title, $this->node($xpath, './/span[contains(@class,"rt-shift-distribution__text")]/strong', $selected)->textContent);
        $item = $this->node($xpath, './button[contains(@class,"rt-shift-distribution__item")]', $selected);
        $this->assertStringContainsString($title, $item->getAttribute('title'));
        $this->assertStringContainsString($title, $item->getAttribute('aria-label'));
        $clear = $this->node($xpath, './/button[@data-distribution-clear]', $selected);
        $this->assertSame(1, $xpath->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " rt-shift-distribution__assign-foot ")]', $clear)->length);
        $this->assertSame(1, $xpath->query('../button[@*[name()="x-on:click" and contains(.,"operations-shift-detail-request")]]', $clear)->length);
        $this->assertSame('selectDistributionShift('.$shift->id.')', $clear->getAttribute('wire:click'));
        $this->assertSame('disabled', $clear->getAttribute('wire:loading.attr'));
        $this->assertStringContainsString('selectDistributionShift', $clear->getAttribute('wire:target'));
        $this->assertStringContainsString('assignFromDistribution', $clear->getAttribute('wire:target'));
        $this->assertStringContainsString('selected: true', $clear->getAttribute('x-on:click'));
        $this->assertSame(0, $xpath->query('//footer//button[@data-distribution-clear]')->length);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_narrow_panel_geometry_keeps_mobile_full_width_neutral_day_bands_and_green_two_line_selection(): void
    {
        $css = file_get_contents(resource_path('css/shift-distribution-panel.css'));
        $side = $this->cssRule($css, '.rt-shift-distribution-side');
        $this->assertStringContainsString('320px', $side);
        $this->assertStringNotContainsString('360px', $side);
        $this->assertStringContainsString('min-height: 0', $side);
        $this->assertStringContainsString('overflow: hidden', $side);
        $mobile = substr($css, strpos($css, '@media (max-width: 1100px)'));
        $this->assertStringContainsString('width: auto', $this->cssRule($mobile, '.rt-shift-distribution-side'));
        $day = $this->cssRule($css, '.rt-shift-distribution__day-head');
        $this->assertStringContainsString('position: sticky', $day);
        $this->assertStringContainsString('background: var(--panel-day)', $day);
        $this->assertStringContainsString('--panel-day: color-mix(in srgb, var(--panel-text)', $css);
        $this->assertStringContainsString('color: var(--panel-text)', $day);
        $selected = $this->cssRule($css, ".rt-shift-distribution__entry[data-selected='true']");
        $this->assertStringContainsString('var(--panel-selection)', $selected);
        $this->assertStringContainsString('--panel-selection: var(--rt-success, #0f9d6e)', $css);
        $this->assertStringContainsString('background:', $selected);
        $title = $this->cssRule($css, ".rt-shift-distribution__entry[data-selected='true'] > .rt-shift-distribution__item .rt-shift-distribution__text strong");
        $this->assertStringContainsString('white-space: normal', $title);
        $this->assertStringContainsString('-webkit-line-clamp: 2', $title);
    }

    public function test_duplicate_page_bottom_padding_is_removed_only_inside_the_shift_planning_workspace(): void
    {
        $css = file_get_contents(resource_path('css/shift-distribution-panel.css'));
        $this->assertMatchesRegularExpression('/[^{}]*:has\(\.rt-shift-plan\)[^{}]*\{[^}]*padding-bottom:\s*0\s*(?:!important)?\s*;/', $css);
        $this->assertDoesNotMatchRegularExpression('/(?:^|})\s*(?:body|main|\.container-fluid)\s*\{[^}]*padding-bottom:\s*0\s*(?:!important)?\s*;/', $css);
        $this->assertStringContainsString('[data-page-live-content]:has(.rt-shift-plan), .rt-shift-plan { row-gap: 0; }', $css);
    }
}
