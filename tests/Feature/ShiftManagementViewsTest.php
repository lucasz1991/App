<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
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
            ->assertViewHas('pendingShifts', fn ($items) => $items->total() === 27 && $items->count() === 25)
            ->call('nextPage', 'distributionShiftsPage')
            ->assertViewHas('pendingShifts', fn ($items) => $items->currentPage() === 2 && $items->count() === 2)
            ->call('applyPeriod', '2027-05-10', '2027-05-16')
            ->assertViewHas('pendingShifts', fn ($items) => $items->currentPage() === 1)
            ->call('applyPeriod', '2027-06-01', '2027-06-02')
            ->assertSee('Alle Schichten in diesem Zeitraum sind besetzt.')
            ->assertSee('Alle Leistungen in diesem Zeitraum sind geplant.');
    }

    public function test_header_uses_calendar_and_current_view_icons_with_accessible_labels(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->assertSeeHtml('far fa-calendar-alt');
        foreach (['table' => 'Tabelle', 'day' => 'Tagesübersicht', 'staffing' => 'Besetzung', 'orders' => 'Leistungen', 'timeline' => 'Zeitleiste'] as $view => $label) {
            $component->call('setView', $view)
                ->assertSeeHtml('data-current-view="'.$view.'"')
                ->assertSeeHtml('aria-label="Ansicht ändern: '.$label.'"');
        }
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
                    ? $shifts->contains('id', $target->id)
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
            ->assertSee('4 Einsatzplätze · 3 eingeplant · 2 bestätigt · 1 offen')
            ->assertSee($this->order->order_number)->assertSee($this->order->title)->assertSee('Testkorridor');
    }

    public function test_view_state_cannot_be_directly_overwritten_by_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->set('viewMode', 'unexpected');
    }

    public function test_shift_views_still_require_operations_management_permission(): void
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($employee)->withQueryParams(['order' => $this->order->id])->test(ShiftManagement::class)->assertForbidden();
    }
}
