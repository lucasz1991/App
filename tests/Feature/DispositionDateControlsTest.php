<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispositionDateControlsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    public function test_shift_and_order_forms_reject_incomplete_local_datetimes(): void
    {
        $this->buildMinimalRailTimeSchema();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        foreach ([ShiftManagement::class => 'saveShift', Orders::class => 'saveOrder'] as $component => $action) {
            foreach (['2026-09-17T', 'T08:00'] as $partial) {
                Livewire::actingAs($admin)->test($component)
                    ->set('startsAt', $partial)
                    ->set('endsAt', '2026-09-18T08:00')
                    ->call($action)
                    ->assertHasErrors(['startsAt' => 'date_format']);
            }
        }
    }

    public function test_datetime_composes_shared_datepicker_and_native_time_with_one_model(): void
    {
        $html = html_entity_decode(Blade::render('<x-ui.forms.date-time-field id="start" wire:model.live="form.starts_at" aria-label="Beginn" required />'), ENT_QUOTES);
        $this->assertStringContainsString('rtDateTimeField', $html);
        $this->assertSame(1, substr_count($html, '$wire.entangle'));
        $this->assertStringContainsString("entangle('form.starts_at').live", $html);
        $this->assertStringContainsString('data-rt-date-field', $html);
        $this->assertStringContainsString('type="time"', $html);
        $this->assertStringContainsString('aria-label="Beginn · Datum"', $html);
        $this->assertStringContainsString('aria-label="Beginn · Uhrzeit"', $html);
        $this->assertStringNotContainsString('type="datetime-local"', $html);
        $this->assertStringContainsString('wire:ignore', $html);
    }

    public function test_operations_fields_delegate_date_and_datetime_including_nested_models(): void
    {
        view()->share('errors', new ViewErrorBag);
        foreach (['date' => 'data-rt-date-field', 'datetime-local' => 'data-rt-date-time-field'] as $type => $marker) {
            $html = html_entity_decode(Blade::render('<x-operations.field label="Beginn" model="form.starts_at" type="'.$type.'" required />'), ENT_QUOTES);
            $this->assertStringContainsString($marker, $html);
            $this->assertStringContainsString("entangle('form.starts_at')", $html);
            $this->assertStringNotContainsString('type="'.$type.'"', $html);
        }
    }

    public function test_plain_form_and_alpine_model_contracts_remain_available(): void
    {
        $html = Blade::render('<x-ui.forms.date-time-field id="visit" x-model="visit" name="visit_at" value="2026-09-17T08:00" :disabled="true" :readonly="true" />');
        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('x-model="visit"', $html);
        $this->assertStringContainsString('name="visit_at"', $html);
        $this->assertStringContainsString('2026-09-17T08:00', $html);
        $this->assertMatchesRegularExpression('/type="time"\s+disabled\s+readonly/s', $html);
    }

    public function test_disposition_templates_use_shared_dates_and_anchor_dropdowns(): void
    {
        foreach (['shift-management', 'orders'] as $page) {
            $source = file_get_contents(resource_path('views/livewire/admin/operations/'.$page.'.blade.php'));
            $this->assertDoesNotMatchRegularExpression('/<x-ui\.forms\.input[^>]+type="(?:date|datetime-local)"/', $source);
            $this->assertStringContainsString('x-ui.forms.date-time-field', $source);
        }
        $source = file_get_contents(resource_path('views/livewire/admin/operations/shift-management.blade.php'));
        $this->assertStringContainsString('content-label="Planungszeitraum anpassen"', $source);
        $this->assertStringContainsString('content-label="Schichtplanansicht auswählen"', $source);
        $this->assertStringContainsString("'timeline' => ['Zeitleiste', 'fa-clock']", $source);
        $this->assertStringNotContainsString('x-ui.buttons.multi-toggle id="shift-plan-view-toggle"', $source);
        $this->assertStringNotContainsString('<details class="rt-disposition-range">', $source);
        $this->assertStringContainsString('<template x-teleport="[data-page-header-actions]">', $source);
        $this->assertStringContainsString('<livewire:operations.shift-series-planner :show-trigger="false" />', $source);
        $this->assertStringContainsString('x-ui.forms.date-range-picker from-model="rangeFrom" until-model="rangeTo" apply-action="applyPeriod"', $source);
        $this->assertStringNotContainsString('wire:click="currentWeek"', $source);
        $this->assertStringNotContainsString('wire:click="movePeriod(', $source);
    }

    public function test_planning_range_is_applied_together_and_invalid_input_keeps_the_previous_range(): void
    {
        $this->buildMinimalRailTimeSchema();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $component = Livewire::actingAs($admin)->test(ShiftManagement::class)
            ->call('applyPeriod', '2026-09-28', '2026-10-04')
            ->assertHasNoErrors()->assertSet('rangeFrom', '2026-09-28')->assertSet('rangeTo', '2026-10-04');

        foreach ([['2026-02-30', '2026-03-01', 'rangeFrom'], ['2026-10-04', '2026-09-28', 'rangeTo'], ['2026-01-01', '2026-04-05', 'rangeTo']] as [$from, $until, $error]) {
            $component->call('applyPeriod', $from, $until)->assertHasErrors($error)
                ->assertSet('rangeFrom', '2026-09-28')->assertSet('rangeTo', '2026-10-04');
        }
        $component->call('applyPeriod', '2026-10-01', '2026-10-01')->assertHasNoErrors()
            ->assertSet('rangeFrom', '2026-10-01')->assertSet('rangeTo', '2026-10-01');
    }

    public function test_range_picker_supports_a_plain_reusable_contract_without_livewire(): void
    {
        $html = html_entity_decode(Blade::render('<x-ui.forms.date-range-picker from="2026-09-28" until="2026-10-04" :max-days="94" />'), ENT_QUOTES);
        $this->assertStringContainsString('rtDateRangePicker', $html);
        $this->assertStringContainsString('data-range-calendar="0"', $html);
        $this->assertStringContainsString('data-range-calendar="1"', $html);
        $this->assertStringNotContainsString('$wire.', $html);
    }

    public function test_shared_date_field_can_keep_an_anchored_parent_open_while_using_its_calendar(): void
    {
        $html = Blade::render('<x-ui.forms.date-field id="range" :keep-dropdown-open="true" />');

        $this->assertStringContainsString('data-rt-dropdown-keep-open', $html);
    }
}
