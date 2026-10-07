<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ShiftDetailDrawerTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Testkorridor', 'is_active' => true]);
        $order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Korridorleistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-10 00:00:00', 'ends_at' => '2027-05-20 23:00:00',
            'required_staff' => 3, 'created_by' => $this->admin->id,
        ]);
        $this->shift = Shift::create([
            'order_id' => $order->id, 'title' => 'Detaildienst', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 08:00:00',
            'ends_at' => '2027-05-12 16:00:00', 'required_staff' => 1, 'status' => 'open',
            'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_opening_details_keeps_the_current_planning_context_without_redirect_or_data_changes(): void
    {
        $original = $this->shift->fresh()->getRawOriginal();

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('setView', 'table')
            ->set('rangeFrom', '2027-05-17')->set('rangeTo', '2027-05-18')
            ->set('orderFilter', (string) $this->shift->order_id)
            ->set('statusFilter', 'draft')->set('attentionFilter', 'unpublished')
            ->set('search', 'Nicht sichtbarer Dienst')
            ->call('openDetails', $this->shift->id)
            ->assertNoRedirect()
            ->assertSet('detailOpen', true)
            ->assertSet('selectedShiftId', $this->shift->id)
            ->assertSet('viewMode', 'table')
            ->assertSet('rangeFrom', '2027-05-17')->assertSet('rangeTo', '2027-05-18')
            ->assertSet('orderFilter', (string) $this->shift->order_id)
            ->assertSet('statusFilter', 'draft')->assertSet('attentionFilter', 'unpublished')
            ->assertSet('search', 'Nicht sichtbarer Dienst')
            ->assertViewHas('selectedShift', fn ($selected) => $selected->is($this->shift));

        $this->assertSame($original, $this->shift->fresh()->getRawOriginal());
    }

    public function test_detail_requests_reauthorize_after_the_component_was_mounted(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($employee);

        $component->call('openDetails', $this->shift->id)->assertForbidden();
    }

    public function test_disabled_administrators_cannot_request_shift_details(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);
        $this->admin->update(['status' => false]);
        $this->actingAs($this->admin->fresh());

        $component->call('openDetails', $this->shift->id)->assertForbidden();
    }

    public function test_missing_shift_is_not_treated_as_successfully_loaded_details(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $this->shift->id + 999);
    }

    public function test_deleted_shift_is_not_treated_as_successfully_loaded_details(): void
    {
        $id = $this->shift->id;
        $this->shift->delete();
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $id);
    }

    public function test_existing_shift_deep_link_still_opens_the_detail_panel(): void
    {
        Livewire::actingAs($this->admin)->withQueryParams(['shift' => $this->shift->id])
            ->test(ShiftManagement::class)
            ->assertNoRedirect()
            ->assertSet('detailOpen', true)
            ->assertSet('selectedShiftId', $this->shift->id)
            ->assertSet('viewMode', 'timeline');
    }

    public function test_table_and_card_details_use_the_locally_controlled_drawer_and_its_own_loader(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class);

        foreach (['table', 'day'] as $view) {
            $component->call('setView', $view);
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML($component->html());
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($document);
            $root = $xpath->query('//*[@data-operations-shift-management]')->item(0);
            $this->assertSame('rtShiftDetailDrawer', $root->getAttribute('x-data'));
            $this->assertStringContainsString('openShiftDetail(', $root->getAttribute('x-on:operations-shift-detail-request.window'));

            $drawer = $xpath->query("//*[starts-with(@id, 'shift-plan-detail-') and @data-rt-modal-shell]")->item(0);
            $this->assertNotNull($drawer);
            $wrapper = $xpath->query('ancestor::*[@x-init][1]', $drawer)->item(0);
            $this->assertNotNull($wrapper, substr($document->saveHTML($drawer->parentNode->parentNode), 0, 900));
            $this->assertStringContainsString("\$watch('detailVisible'", $wrapper->getAttribute('x-init'));
            $this->assertStringContainsString("\$watch('show'", $wrapper->getAttribute('x-init'));
            $content = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' rt-modal-content ')]", $drawer)->item(0);
            $this->assertNotNull($content);
            $this->assertFalse($content->hasAttribute('data-rt-modal-body'));
            $this->assertSame(1, $xpath->query('.//*[@data-shift-detail-loading]', $content)->length);
            $this->assertSame(1, $xpath->query('.//*[@data-shift-detail-error]', $content)->length);

            $triggerClass = $view === 'table' ? 'rt-table-record__title' : 'rt-disposition-shift';
            $trigger = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' {$triggerClass} ')]")->item(0);
            $this->assertNotNull($trigger);
            $this->assertStringContainsString('operations-shift-detail-request', $trigger->getAttribute('x-on:click'));
            $this->assertFalse($trigger->hasAttribute('wire:click'));
        }
    }

    public function test_editing_from_the_detail_panel_keeps_the_shift_open_and_returns_after_cancel_and_save(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $this->shift->id)
            ->call('editShift', $this->shift->id)
            ->assertSet('formOpen', true)->assertSet('detailOpen', true)
            ->assertSeeHtml('data-panel-mode="form"')
            ->call('closeShiftForm')
            ->assertSet('formOpen', false)->assertSet('detailOpen', true)
            ->assertSet('editingShiftId', null)
            ->assertDontSeeHtml('data-panel-mode="form"');

        $component->call('editShift', $this->shift->id)
            ->set('title', 'Detaildienst geändert')
            ->call('saveShift')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false)->assertSet('detailOpen', true)
            ->assertSet('selectedShiftId', $this->shift->id)
            ->assertSee('Detaildienst geändert');

        $this->assertSame('Detaildienst geändert', $this->shift->fresh()->title);
    }

    public function test_closing_a_new_shift_form_does_not_open_a_detail(): void
    {
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('createShift')
            ->assertSet('formOpen', true)->assertSet('detailOpen', false)
            ->assertSeeHtml('data-panel-mode="form"')
            ->call('closeShiftForm')
            ->assertSet('formOpen', false)->assertSet('detailOpen', false);
    }

    public function test_form_errors_mark_their_tab_and_switch_to_it(): void
    {
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $this->shift->id)
            ->call('editShift', $this->shift->id)
            ->set('endsAt', '2027-05-12T07:00')
            ->call('saveShift')
            ->assertHasErrors('endsAt')
            ->assertSet('formOpen', true)->assertSet('detailOpen', true)
            ->assertSeeHtml("x-init=\"tab = 'time'\"")
            ->assertSeeHtml('rt-ops-panel__alert');
    }

    public function test_opening_a_shift_drops_validation_errors_left_by_a_closed_form(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('createShift')
            ->call('saveShift')
            ->assertHasErrors('title');

        // Clientseitig per Esc geschlossen: der Server erfährt formOpen=false erst mit der nächsten Anfrage.
        $component->set('formOpen', false)
            ->call('openDetails', $this->shift->id)
            ->assertHasNoErrors();
    }

    public function test_overview_reuses_calendar_and_map_for_the_selected_shift_in_display_timezone(): void
    {
        $this->shift->update([
            // The UTC date is still the 12th, while the complete shift falls on the 13th in Berlin.
            'starts_at' => CarbonImmutable::parse('2027-05-12 22:30:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2027-05-13 22:00:00', 'UTC'),
            'location_name' => null,
        ]);
        $this->shift->order->update(['location_name' => 'Betriebshof', 'city' => 'München', 'country' => 'DE']);

        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('openDetails', $this->shift->id)
            ->assertViewHas('selectedShift', fn (Shift $shift) => $shift->relationLoaded('order'));
        $xpath = $this->panelXPath($component->html());
        $overview = $xpath->query('//*[@data-panel-mode="detail"]//*[@data-panel-section="overview"]')->item(0);
        $this->assertNotNull($overview);
        $previews = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " rt-ops-panel__previews ")]', $overview)->item(0);
        $this->assertNotNull($previews);
        $this->assertSame(1, $xpath->query('.//figure[contains(concat(" ", normalize-space(@class), " "), " rt-event-mini-calendar ")]', $previews)->length);
        $selectedDays = $xpath->query('.//*[@data-date and contains(concat(" ", normalize-space(@class), " "), " is-selected ")]', $previews);
        $this->assertCount(1, $selectedDays);
        $this->assertSame('2027-05-13', $selectedDays->item(0)->getAttribute('data-date'));
        $this->assertSame(1, $xpath->query('.//figure[@data-map-state="located"]//*[@data-location-marker]', $previews)->length);
        $this->assertStringContainsString('München', $previews->textContent);
        $this->assertStringContainsString('Stadtmitte · ungefähr', $previews->textContent);
    }

    public function test_overview_does_not_invent_a_map_marker_for_an_unknown_shift_location(): void
    {
        $this->shift->update(['location_name' => 'Einsatzstelle ohne Ortsangabe']);
        $this->shift->order->update(['location_name' => 'Betriebshof', 'city' => 'Hamburg', 'country' => 'DE']);

        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('openDetails', $this->shift->id);
        $xpath = $this->panelXPath($component->html());
        $map = $xpath->query('//*[@data-panel-mode="detail"]//*[@data-panel-section="overview"]//figure[@data-map-state]')->item(0);
        $this->assertNotNull($map);
        $this->assertSame('unknown', $map->getAttribute('data-map-state'));
        $this->assertSame(0, $xpath->query('.//*[@data-location-marker]', $map)->length);
        $this->assertStringContainsString('Einsatzstelle ohne Ortsangabe', $map->textContent);
    }

    public function test_native_staffing_and_feedback_share_one_tab_with_existing_assignment_actions(): void
    {
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $this->shift->forceFill(['revision' => 1, 'published_revision' => 1])->save();
        $assignments = collect(['requested', 'confirmed', 'declined', 'cancelled'])->mapWithKeys(function (string $status): array {
            $assignment = ShiftAssignment::create([
                'shift_id' => $this->shift->id,
                'user_id' => User::factory()->create(['role' => 'staff', 'status' => true])->id,
                'status' => $status, 'assigned_by' => $this->admin->id,
            ]);
            $assignment->forceFill(['plan_revision' => 1])->save();

            return [$status => $assignment];
        });

        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('openDetails', $this->shift->id);
        $xpath = $this->panelXPath($component->html());
        $tabs = $xpath->query('//*[@data-panel-mode="detail"]//*[@role="tablist"]')->item(0);
        $this->assertNotNull($tabs);
        $staffingTab = $xpath->query('.//*[@data-panel-tab="staffing"]', $tabs)->item(0);
        $this->assertNotNull($staffingTab);
        $this->assertStringContainsString('Besetzung & Rückmeldungen', $staffingTab->textContent);
        $this->assertStringContainsString('2/1', $staffingTab->textContent);
        $this->assertSame(0, $xpath->query('.//*[@data-panel-tab="feedback"]', $tabs)->length);
        $this->assertSame(0, $xpath->query('//*[@data-panel-mode="detail"]//*[@data-panel-section="feedback"]')->length);

        $staffing = $xpath->query('//*[@data-panel-mode="detail"]//*[@data-panel-section="staffing"]')->item(0);
        $this->assertNotNull($staffing);
        $this->assertStringContainsString('Rückmeldungen', $staffing->textContent);
        $this->assertStringContainsString('2 von 1 Plätzen reserviert', $staffing->textContent);
        foreach (['Angefragt', 'Bestätigt', 'Abgelehnt', 'Noch nicht geöffnet', 'Mitarbeitende finden'] as $label) {
            $this->assertStringContainsString($label, $staffing->textContent);
        }
        foreach (['requested', 'confirmed'] as $status) {
            $this->assertSame(1, $xpath->query('.//button[@*[name()="wire:click"]="removeAssignment('.$assignments[$status]->id.')"]', $staffing)->length);
        }
        foreach (['declined', 'cancelled'] as $status) {
            $this->assertSame(0, $xpath->query('.//button[@*[name()="wire:click"]="removeAssignment('.$assignments[$status]->id.')"]', $staffing)->length);
        }
        $this->assertSame(0, $xpath->query('.//button[@*[name()="wire:click"]="assignEmployee"]', $staffing)->length);
        $this->assertSame(0, $xpath->query('.//*[@id="assignment-employee"]', $staffing)->length);
        $this->assertSame(1, $xpath->query('.//button[@*[name()="wire:click"]="loadStaffingCandidates"]', $staffing)->length);
        $this->assertStringContainsString('Eignung wird geprüft', $staffing->textContent);
    }

    public function test_legacy_staffing_keeps_its_single_label_and_removal_action_without_native_feedback(): void
    {
        // The minimal isolated fixture deliberately omits the native operations extension.
        $assignment = ShiftAssignment::create([
            'shift_id' => $this->shift->id,
            'user_id' => User::factory()->create(['role' => 'staff', 'status' => true])->id,
            'status' => 'confirmed', 'assigned_by' => $this->admin->id,
        ]);

        $component = Livewire::actingAs($this->admin)->test(ShiftManagement::class)
            ->call('setView', 'table')->call('openDetails', $this->shift->id)
            ->assertViewHas('nativeOperations', false);
        $xpath = $this->panelXPath($component->html());
        $detail = $xpath->query('//*[@data-panel-mode="detail"]')->item(0);
        $this->assertNotNull($detail);
        $tab = $xpath->query('.//*[@data-panel-tab="staffing"]', $detail)->item(0);
        $this->assertNotNull($tab);
        $this->assertStringContainsString('Besetzung', $tab->textContent);
        $this->assertStringNotContainsString('Rückmeldungen', $detail->textContent);
        $this->assertSame(0, $xpath->query('.//*[@data-panel-tab="feedback" or @data-panel-section="feedback"]', $detail)->length);
        $staffing = $xpath->query('.//*[@data-panel-section="staffing"]', $detail)->item(0);
        $this->assertNotNull($staffing);
        $this->assertSame(1, $xpath->query('.//button[@*[name()="wire:click"]="removeAssignment('.$assignment->id.')"]', $staffing)->length);
        $this->assertSame(1, $xpath->query('.//button[@*[name()="wire:click"]="assignEmployee"]', $staffing)->length);
    }

    private function panelXPath(string $html): \DOMXPath
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
