<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Calendar;
use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsPages;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ShiftManagementViewsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Testkorridor', 'is_active' => true]);
        $this->order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Korridorleistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-10 00:00:00', 'ends_at' => '2027-05-20 23:00:00',
            'required_staff' => 3, 'created_by' => $this->admin->id,
        ]);
    }

    private function shift(string $title, array $attributes = []): Shift
    {
        return Shift::create(array_merge([
            'order_id' => $this->order->id, 'title' => $title, 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'required_staff' => 1, 'status' => 'open',
            'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function assignment(Shift $shift, string $status): void
    {
        ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => User::factory()->create(['role' => 'staff', 'status' => true])->id,
            'status' => $status, 'assigned_by' => $this->admin->id,
        ]);
    }

    public function test_header_distribution_counts_only_unallocated_active_shifts_independently_of_list_filters(): void
    {
        $open = $this->shift('Noch zwei Plätze', ['required_staff' => 4]);
        $this->assignment($open, 'requested');
        $this->assignment($open, 'confirmed');
        $this->assignment($open, 'declined');
        $this->assignment($open, 'cancelled');
        $full = $this->shift('Schon angefragt');
        $this->assignment($full, 'requested');
        $this->shift('Abgeschlossen', ['status' => 'completed']);
        $this->shift('Storniert', ['status' => 'cancelled']);
        $this->shift('Außerhalb', ['starts_at' => '2027-05-01 08:00:00', 'ends_at' => '2027-05-01 16:00:00']);
        $this->shift('Endet genau vor Zeitraum', ['starts_at' => '2027-05-09 20:00:00', 'ends_at' => '2027-05-09 22:00:00']);
        $deleted = $this->shift('Gelöscht');
        $deleted->delete();

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('toggleDistribution')
            ->set('search', 'kein Treffer')->set('statusFilter', 'draft')
            ->assertViewHas('pendingShifts', fn ($items) => $items->total() === 1 && $items->first()->id === $open->id && $items->first()->reserved_count === 2)
            ->assertViewHas('unplannedOrders', fn ($items) => $items->total() === 0)
            ->assertSee('2 frei')
            ->call('openDetails', $open->id)->assertSet('selectedShiftId', $open->id)->assertSet('detailOpen', true);
    }

    public function test_unplanned_services_exclude_closed_and_scheduled_orders_and_prefill_without_saving(): void
    {
        foreach (['completed', 'invoiced', 'cancelled'] as $status) {
            $closed = $this->order->replicate(['public_id', 'order_number']);
            $closed->status = $status;
            $closed->save();
            $this->shift('Offen an geschlossenem Auftrag', ['order_id' => $closed->id]);
        }
        $this->shift('Nur stornierte Planung', ['status' => 'cancelled']);
        $before = $this->order->fresh()->getAttributes();
        $count = Shift::count();
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->assertViewHas('unplannedOrders', fn ($items) => $items->total() === 1 && $items->first()->id === $this->order->id)
            ->assertViewHas('pendingShifts', fn ($items) => $items->total() === 0)
            ->call('prepareOrderShift', $this->order->id)
            ->assertSet('formOpen', true)->assertSet('orderId', $this->order->id)
            ->assertSet('title', $this->order->title)->assertSet('requiredStaff', 3)
            ->assertSet('startsAt', '')->assertSet('endsAt', '')
            ->call('prepareOrderShift', $closed->id)->assertStatus(422);
        $this->assertSame($count, Shift::count());
        $this->assertSame($before, $this->order->fresh()->getAttributes());
    }

    public function test_pending_distribution_is_paginated_and_resets_when_the_period_changes(): void
    {
        foreach (range(1, 27) as $number) {
            $this->shift('Offene Schicht '.$number);
        }
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('toggleDistribution')
            ->assertViewHas('pendingShifts', fn ($items) => $items->total() === 27 && $items->count() === 25)
            ->call('nextPage', 'distributionShiftsPage')
            ->assertViewHas('pendingShifts', fn ($items) => $items->currentPage() === 2 && $items->count() === 2)
            ->call('applyPeriod', '2027-05-10', '2027-05-16')
            ->assertDispatched('operations-period-changed', from: '2027-05-10', until: '2027-05-16')
            ->assertViewHas('pendingShifts', fn ($items) => $items->currentPage() === 1)
            ->call('applyPeriod', '2027-06-01', '2027-06-02')
            ->assertSee('Alle Schichten in diesem Zeitraum sind besetzt.')
            ->assertSee('Alle Leistungen in diesem Zeitraum sind geplant.');
    }

    public function test_header_uses_calendar_and_current_view_icons_with_accessible_labels(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->assertSeeHtml('far fa-calendar-alt');
        foreach (['table' => 'Tabelle', 'day' => 'Tagesübersicht', 'staffing' => 'Besetzung', 'orders' => 'Leistungsübersicht', 'timeline' => 'Zeitleiste'] as $view => $label) {
            $component->call('setView', $view)
                ->assertSeeHtml('data-current-view="'.$view.'"')
                ->assertSeeHtml('aria-label="Ansicht ändern: '.$label.'"');
        }
    }

    public function test_view_menu_exposes_grouped_services_and_native_calendar_with_current_order_context(): void
    {
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        foreach (['all', (string) $this->order->id] as $orderFilter) {
            $component->set('orderFilter', $orderFilter);
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($document);
            $options = $xpath->query('//button[@role="menuitemradio" and @*[name()="wire:click"]]');
            $this->assertSame(5, $options->length);
            $labels = [];
            foreach ($options as $option) {
                $labels[] = trim($xpath->query('.//strong', $option)->item(0)->textContent);
            }
            $this->assertSame(['Tabelle', 'Tagesübersicht', 'Besetzung', 'Leistungsübersicht', 'Zeitleiste'], $labels);
            $calendar = $xpath->query('//a[@data-shift-calendar-link]')->item(0);
            $this->assertNotNull($calendar);
            $this->assertSame('menuitem', $calendar->getAttribute('role'));
            $this->assertTrue($calendar->hasAttribute('wire:navigate'));
            $this->assertSame(OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'calendar', 'order' => $orderFilter !== 'all' ? (int) $orderFilter : null, 'from' => $component->instance()->rangeFrom, 'until' => $component->instance()->rangeTo]), $calendar->getAttribute('href'));
        }
        $component->call('setView', 'orders')->assertDispatched('shift-plan-view-changed', view: 'orders');
    }

    public function test_distribution_recovers_when_the_current_last_page_is_completed(): void
    {
        foreach (range(1, 26) as $number) {
            $last = $this->shift('Schicht '.$number);
        }
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('nextPage', 'distributionShiftsPage')
            ->assertViewHas('pendingShifts', fn ($items) => $items->currentPage() === 2 && $items->count() === 1);
        $last->update(['status' => 'completed']);
        $component->call('refreshPlan')
            ->assertViewHas('pendingShifts', fn ($items) => $items->currentPage() === 1 && $items->count() === 25);
    }

    public function test_views_share_filters_and_keep_the_existing_detail_and_edit_modals(): void
    {
        $target = $this->shift('Norddienst');
        $this->shift('Süddienst', ['status' => 'draft', 'location_name' => 'München']);

        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->assertSet('viewMode', 'timeline')
            ->assertSeeHtml('data-shift-view="timeline"')
            ->assertSee('Die Zeitleiste ist verfügbar, sobald der Mitarbeiter- und Abwesenheitsbereich eingerichtet ist.')
            ->set('rangeFrom', '2027-05-12')->set('rangeTo', '2027-05-13')
            ->set('orderFilter', (string) $this->order->id)
            ->set('statusFilter', 'open')->set('search', 'Hamburg');

        foreach (['table', 'day', 'staffing', 'orders', 'timeline'] as $view) {
            $component->call('setView', $view)
                ->assertSet('viewMode', $view)
                ->assertSet('statusFilter', $view === 'timeline' ? 'all' : 'open')
                ->assertSet('search', $view === 'timeline' ? '' : 'Hamburg')
                ->assertSet('orderFilter', $view === 'timeline' ? 'all' : (string) $this->order->id)
                ->assertViewHas('shifts', fn ($shifts) => $view === 'timeline'
                    ? $shifts->isEmpty()
                    : $shifts->modelKeys() === [$target->id])
                ->call('openDetails', $target->id)->assertSet('detailOpen', true)
                ->assertSet('selectedShiftId', $target->id)
                // Das Formular ist der zweite Zustand des Schicht-Panels: Die geöffnete
                // Schicht bleibt offen, Abbrechen führt dorthin zurück.
                ->call('editShift', $target->id)->assertSet('formOpen', true)->assertSet('detailOpen', true)
                ->call('closeShiftForm')->assertSet('formOpen', false)->assertSet('detailOpen', true)
                ->assertSet('editingShiftId', null);
        }

        $component->call('setView', 'table')->set('search', 'kein-treffer')
            ->assertViewHas('shifts', fn ($shifts) => $shifts->isEmpty())
            ->assertSee('Keine Schichten für diese Filter gefunden.');
    }

    public function test_daily_view_includes_overnight_shifts_on_both_days_but_not_at_the_ending_boundary(): void
    {
        $night = $this->shift('Nachtdienst', ['starts_at' => '2027-05-12 22:00:00', 'ends_at' => '2027-05-13 06:00:00']);
        $midnightEnd = $this->shift('Spätdienst', ['starts_at' => '2027-05-12 16:00:00', 'ends_at' => '2027-05-13 00:00:00']);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->set('rangeFrom', '2027-05-12')->set('rangeTo', '2027-05-13')
            ->call('setView', 'day')
            ->assertViewHas('dailyGroups', function ($groups) use ($night, $midnightEnd) {
                return $groups->keys()->all() === ['2027-05-12', '2027-05-13']
                    && $groups['2027-05-12']['items']->modelKeys() === [$midnightEnd->id, $night->id]
                    && $groups['2027-05-13']['items']->modelKeys() === [$night->id];
            })
            ->set('rangeFrom', '2027-05-13')
            ->assertViewHas('shifts', fn ($shifts) => $shifts->modelKeys() === [$night->id]);
    }

    public function test_staffing_groups_do_not_treat_requested_declined_or_cancelled_assignments_as_confirmed(): void
    {
        $open = $this->shift('Noch offen', ['required_staff' => 2]);
        $awaiting = $this->shift('Rückmeldung fehlt');
        $staffed = $this->shift('Vollständig bestätigt');
        $closed = $this->shift('Abgesagt', ['status' => 'cancelled']);
        $this->assignment($open, 'confirmed');
        $this->assignment($open, 'declined');
        $this->assignment($open, 'cancelled');
        $this->assignment($awaiting, 'requested');
        $this->assignment($staffed, 'confirmed');

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->set('rangeFrom', '2027-05-12')->set('rangeTo', '2027-05-12')
            ->call('setView', 'staffing')
            ->assertViewHas('staffingGroups', function ($groups) use ($open, $awaiting, $staffed, $closed) {
                return $groups['open']['items']->modelKeys() === [$open->id]
                    && $groups['awaiting']['items']->modelKeys() === [$awaiting->id]
                    && $groups['staffed']['items']->modelKeys() === [$staffed->id]
                    && $groups['closed']['items']->modelKeys() === [$closed->id];
            })
            ->assertSee('Bestätigung ausstehend')
            ->assertSee('1 Platz offen');
    }

    public function test_display_timezone_controls_midnight_filters_and_display_even_when_app_and_shift_are_utc(): void
    {
        $shift = $this->shift('UTC-Nachtdienst', [
            'timezone' => 'UTC', 'starts_at' => '2027-05-12 22:00:00', 'ends_at' => '2027-05-13 04:00:00',
        ]);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->set('rangeFrom', '2027-05-12')->set('rangeTo', '2027-05-12')
            ->call('setView', 'day')
            ->assertViewHas('shifts', fn ($shifts) => $shifts->isEmpty())
            ->set('rangeTo', '2027-05-13')->set('rangeFrom', '2027-05-13')
            ->assertViewHas('dailyGroups', fn ($groups) => $groups->keys()->all() === ['2027-05-13']
                && $groups['2027-05-13']['items']->modelKeys() === [$shift->id])
            ->assertViewHas('displayTimezone', 'Europe/Berlin')
            ->assertSee('13.05.2027 00:00')
            ->assertSee('13.05.2027 06:00');
    }

    public function test_editing_and_saving_local_schedule_without_changes_keeps_utc_storage_unchanged(): void
    {
        $shift = $this->shift('Lokaler Nachtdienst', [
            'starts_at' => '2027-05-12 23:30:00', 'ends_at' => '2027-05-13 07:30:00',
        ]);
        $beforeStart = $shift->fresh()->getRawOriginal('starts_at');
        $beforeEnd = $shift->fresh()->getRawOriginal('ends_at');
        $this->assertSame('2027-05-12 21:30:00', $beforeStart);
        $this->assertSame('2027-05-13 05:30:00', $beforeEnd);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('editShift', $shift->id)
            ->assertSet('timezone', 'Europe/Berlin')
            ->assertSet('startsAt', '2027-05-12T23:30')
            ->assertSet('endsAt', '2027-05-13T07:30')
            ->call('saveShift')->assertHasNoErrors()->assertSet('formOpen', false);

        $this->assertSame($beforeStart, $shift->fresh()->getRawOriginal('starts_at'));
        $this->assertSame($beforeEnd, $shift->fresh()->getRawOriginal('ends_at'));
    }

    public function test_view_action_rejects_unknown_modes(): void
    {
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('setView', 'unexpected')->assertStatus(422);
    }

    public function test_order_deep_link_uses_display_dates_limits_the_range_and_prefills_new_shift_context(): void
    {
        $this->order->update([
            'timezone' => 'UTC', 'starts_at' => '2027-05-12 22:00:00', 'ends_at' => '2027-12-31 22:00:00',
        ]);
        $target = $this->shift('Zielauftrag', ['starts_at' => '2027-05-13 08:00:00', 'ends_at' => '2027-05-13 16:00:00']);
        $otherOrder = $this->order->replicate(['public_id', 'order_number']);
        $otherOrder->title = 'Andere Leistung';
        $otherOrder->save();
        $this->shift('Anderer Auftrag', ['order_id' => $otherOrder->id, 'starts_at' => '2027-05-13 08:00:00', 'ends_at' => '2027-05-13 16:00:00']);

        Livewire::actingAs($this->admin)->withQueryParams(['order' => $this->order->id])->test(ShiftManagement::class)
            ->assertSet('orderFilter', (string) $this->order->id)
            ->assertSet('viewMode', 'orders')
            ->assertSet('rangeFrom', '2027-05-13')
            ->assertSet('rangeTo', '2027-08-14')
            ->assertSet('selectedShiftId', $target->id)
            ->assertViewHas('shifts', fn ($shifts) => $shifts->modelKeys() === [$target->id])
            ->call('createShift')->assertSet('orderId', $this->order->id)->assertSet('formOpen', true);
    }

    public function test_order_deep_link_rejects_unknown_deleted_and_invalid_ids(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['order' => 999999])->test(ShiftManagement::class)->assertNotFound();
        Livewire::actingAs($this->admin)->withQueryParams(['order' => 'abc'])->test(ShiftManagement::class)->assertNotFound();
        $this->order->delete();
        Livewire::actingAs($this->admin)->withQueryParams(['order' => $this->order->id])->test(ShiftManagement::class)->assertNotFound();
    }

    public function test_plan_calendar_roundtrip_retains_the_period_customer_and_filtered_native_records(): void
    {
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $wanted = $this->shift('Kundenfilter Zielschicht', ['starts_at' => '2027-05-18 08:00:00', 'ends_at' => '2027-05-18 12:00:00']);
        $foreignCustomer = Customer::create(['company_name' => 'Anderer Kunde', 'is_active' => true]);
        $foreignOrder = $this->order->replicate(['public_id', 'order_number']);
        $foreignOrder->customer_id = $foreignCustomer->id;
        $foreignOrder->save();
        $foreign = $this->shift('Fremde Schicht', ['order_id' => $foreignOrder->id, 'starts_at' => '2027-05-18 08:00:00', 'ends_at' => '2027-05-18 12:00:00']);
        $original = $wanted->fresh()->getRawOriginal();
        $period = ['from' => '2027-05-17', 'until' => '2027-05-23'];
        $component = Livewire::actingAs($this->admin)->withQueryParams(['customer' => $this->order->customer_id] + $period)
            ->test(ShiftManagement::class)->assertSet('viewMode', 'orders')->assertSet('customerFilter', (string) $this->order->customer_id)
            ->assertSet('rangeFrom', '2027-05-17')->assertSet('rangeTo', '2027-05-23')->assertSet('selectedShiftId', $wanted->id)
            ->assertViewHas('shifts', fn ($items) => $items->modelKeys() === [$wanted->id])
            ->assertViewHas('pendingShifts', fn ($items) => $items->modelKeys() === [$wanted->id])
            ->assertViewHas('orders', fn ($items) => $items->modelKeys() === [$this->order->id])
            ->assertSeeHtml('data-shift-customer-context')->assertSee('Kunde: Testkorridor')->assertDontSee('Anderer Kunde');
        $queryFor = function ($target, string $attribute): array {
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$target->html());
            $link = (new \DOMXPath($dom))->query('//a[@'.$attribute.']')->item(0);
            $this->assertNotNull($link);
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);

            return $query;
        };
        $calendarQuery = $queryFor($component, 'data-shift-calendar-link');
        $calendar = Livewire::actingAs($this->admin)->withQueryParams($calendarQuery)->test(Calendar::class)
            ->assertSet('anchorDate', '2027-05-17')->assertSet('planningRange', $period)
            ->assertSet('customerFilter', (string) $this->order->customer_id)
            ->assertViewHas('shifts', fn ($items) => $items->modelKeys() === [$wanted->id]);
        Livewire::actingAs($this->admin)->withQueryParams($queryFor($calendar, 'data-calendar-planning-return'))->test(ShiftManagement::class)
            ->assertSet('rangeFrom', '2027-05-17')->assertSet('rangeTo', '2027-05-23')->assertSet('viewMode', 'orders')
            ->assertViewHas('shifts', fn ($items) => $items->modelKeys() === [$wanted->id])
            ->call('setView', 'timeline')->assertSet('customerFilter', 'all')->assertSet('viewMode', 'timeline');
        $this->assertSame($original, $wanted->fresh()->getRawOriginal());
        $this->assertDatabaseCount('shifts', 2);
        $this->assertSame('open', $foreign->fresh()->status->value);
        Livewire::actingAs($this->admin)->withQueryParams(['customer' => $this->order->customer_id, 'shift' => $foreign->id])
            ->test(ShiftManagement::class)->assertNotFound();
    }

    public function test_plan_and_calendar_context_reject_invalid_periods_and_inconsistent_customers(): void
    {
        foreach ([ShiftManagement::class, Calendar::class] as $component) {
            foreach ([['from' => '2027-02-29', 'until' => '2027-03-01'], ['from' => '2027-05-18', 'until' => '2027-05-17'], ['from' => '2027-01-01', 'until' => '2027-12-31'], ['from' => ['2027-05-17'], 'until' => '2027-05-23'], ['from' => '2027-05-17'], ['until' => '2027-05-23']] as $query) {
                Livewire::actingAs($this->admin)->withQueryParams($query)->test($component)->assertStatus(422);
            }
            foreach ([0, 'invalid', 999999, [$this->order->customer_id]] as $customer) {
                Livewire::actingAs($this->admin)->withQueryParams(['customer' => $customer])->test($component)->assertNotFound();
            }
            $other = Customer::create(['company_name' => 'Falscher Auftragskunde', 'is_active' => true]);
            Livewire::actingAs($this->admin)->withQueryParams(['customer' => $other->id, 'order' => $this->order->id])->test($component)->assertNotFound();
            $staff = User::factory()->create(['role' => 'staff', 'status' => true]);
            Livewire::actingAs($staff)->withQueryParams(['customer' => $this->order->customer_id, 'from' => '2027-05-17', 'until' => '2027-05-23'])->test($component)->assertForbidden();
        }
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_customer_context_cannot_be_replaced_by_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin)->withQueryParams(['customer' => $this->order->customer_id])
            ->test(ShiftManagement::class)->set('customerFilter', 'all');
    }

    public function test_order_and_shift_deep_links_cannot_mix_two_different_order_contexts(): void
    {
        $otherOrder = $this->order->replicate(['public_id', 'order_number']);
        $otherOrder->title = 'Andere Leistung';
        $otherOrder->save();
        $shift = $this->shift('Andere Schicht', ['order_id' => $otherOrder->id]);

        Livewire::actingAs($this->admin)->withQueryParams(['order' => $this->order->id, 'shift' => $shift->id])
            ->test(ShiftManagement::class)->assertNotFound();
    }

    public function test_order_view_counts_active_shift_places_once_and_separates_reservation_from_confirmation(): void
    {
        $night = $this->shift('Nachtschicht', ['required_staff' => 3, 'starts_at' => '2027-05-12 22:00:00', 'ends_at' => '2027-05-13 06:00:00']);
        $day = $this->shift('Tagschicht');
        $this->assignment($night, 'requested');
        $this->assignment($night, 'confirmed');
        $this->assignment($night, 'declined');
        $this->assignment($day, 'confirmed');
        $this->shift('Storniert', ['required_staff' => 9, 'status' => 'cancelled']);
        $this->shift('Erledigt', ['required_staff' => 8, 'status' => 'completed']);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->set('rangeFrom', '2027-05-12')->set('rangeTo', '2027-05-13')->call('setView', 'orders')
            ->assertViewHas('orderGroups', function ($groups) {
                $group = $groups->get($this->order->id);

                return $groups->count() === 1 && $group['items']->count() === 4
                    && $group['summary'] === ['shifts' => 2, 'required' => 4, 'reserved' => 3, 'confirmed' => 2, 'open' => 1];
            })
            ->assertViewHas('openCount', 1)
            ->assertSee('3/4')->assertSee('2 bestätigt')->assertSee('1 Platz offen')->assertSee('4 Schichten')
            ->assertSee($this->order->order_number)->assertSee($this->order->title)->assertSee('Testkorridor');
    }

    public function test_view_state_cannot_be_directly_overwritten_by_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->set('viewMode', 'unexpected');
    }

    public function test_service_overview_uses_one_list_and_opens_native_details_without_changing_workspace_url(): void
    {
        $this->shift('Erste Schicht');
        $this->shift('Zweite Schicht');
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('setView', 'orders')
            ->assertSeeHtml('data-service-overview')
            ->assertSeeHtml('service-overview-row-'.$this->order->id)
            ->assertDontSeeHtml('data-shift-group=')
            ->call('openServiceDetails', $this->order->id)
            ->assertSet('selectedServiceId', $this->order->id)
            ->assertSet('serviceDetailVersion', 1)
            ->call('openServiceDetails', $this->order->id)
            ->assertSet('serviceDetailVersion', 2);

        Livewire::actingAs($this->admin)->test(Orders::class, [
            'initialOrderId' => $this->order->id, 'detailOnly' => true, 'consolidated' => true,
        ])->assertSet('detailOpen', true)
            ->assertDontSeeHtml('data-page-header-metrics')
            ->call('setDetailSection', 'planning')
            ->assertSee('Erste Schicht')->assertSee('Zweite Schicht')
            ->assertSeeHtml('operations-shift-detail-request')
            ->assertNotDispatched('rt-workspace-url');
    }

    public function test_service_detail_selection_respects_customer_context(): void
    {
        $other = $this->order->replicate(['public_id', 'order_number']);
        $other->customer_id = Customer::create(['company_name' => 'Andere Firma', 'customer_number' => 'OTHER', 'is_active' => true])->id;
        $other->save();
        $this->shift('Fremde Schicht', ['order_id' => $other->id]);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->admin)->withQueryParams(['customer' => $this->order->customer_id])->test(ShiftManagement::class)
            ->call('setView', 'orders')->call('openServiceDetails', $other->id);
    }

    public function test_service_columns_are_separate_and_sort_aggregates_in_both_directions(): void
    {
        $first = $this->order->replicate(['public_id', 'order_number']);
        $first->title = 'Zulu Leistung';
        $first->status = 'confirmed';
        $first->save();
        $first->customer->update(['company_name' => 'Alpha Kunde']);
        $second = $this->order->replicate(['public_id', 'order_number']);
        $second->title = 'Alpha Leistung';
        $second->status = 'planned';
        $second->customer_id = Customer::create(['company_name' => 'Zulu Kunde', 'customer_number' => 'SORT', 'is_active' => true])->id;
        $second->save();
        $this->shift('Erste', ['order_id' => $first->id]);
        $this->shift('Weitere', ['order_id' => $first->id]);
        $this->shift('Zweite', ['order_id' => $second->id, 'starts_at' => '2027-05-13 08:00:00', 'ends_at' => '2027-05-13 16:00:00']);

        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'orders')
            ->assertDontSee('Leistung auswählen, um alle Details zu öffnen.')
            ->assertDontSee('Leistung / Kunde')->assertDontSee('Zeitraum / Ort')
            ->assertSeeHtml('sortServices')->assertSeeHtml('data-rt-table-label="Ort"')
            ->assertSeeHtml('data-rt-table-label="Kunde"');
        foreach (['service' => [$second->id, $first->id], 'customer' => [$first->id, $second->id], 'period' => [$first->id, $second->id], 'status' => [$first->id, $second->id]] as $key => $ids) {
            $component->call('sortServices', $key, 'asc')->assertSet('serviceSortBy', $key)->assertSet('serviceSortDir', 'asc')
                ->assertViewHas('orderGroups', fn ($groups) => $groups->keys()->all() === $ids && $groups[$first->id]['items']->count() === 2);
            $component->call('sortServices', $key)->assertSet('serviceSortDir', 'desc')
                ->assertViewHas('orderGroups', fn ($groups) => $groups->keys()->all() === array_reverse($ids));
        }
        $component->call('sortServices', 'location')->assertStatus(422);
    }

    public function test_service_column_header_stays_in_view_without_changing_shared_tables(): void
    {
        $css = file_get_contents(resource_path('css/operations-planning.css'));
        $this->assertMatchesRegularExpression('/\[data-service-overview\] \.rt-table-head,[^{]+\{\s*position: sticky;\s*top: 0;\s*z-index: 3;/s', $css);
        $this->assertStringContainsString('[data-service-overview] .rt-table-mobile-sort', $css);
    }

    public function test_shift_views_still_require_operations_management_permission(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($employee)->withQueryParams(['order' => $this->order->id])->test(ShiftManagement::class)->assertForbidden();
    }
}
