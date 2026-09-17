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

    public function test_disposition_templates_have_no_legacy_date_inputs_and_use_shared_view_toggle(): void
    {
        foreach (['shift-management', 'orders'] as $page) {
            $source = file_get_contents(resource_path('views/livewire/admin/operations/'.$page.'.blade.php'));
            $this->assertDoesNotMatchRegularExpression('/<x-ui\.forms\.input[^>]+type="(?:date|datetime-local)"/', $source);
            $this->assertStringContainsString('x-ui.forms.date-time-field', $source);
        }
        $source = file_get_contents(resource_path('views/livewire/admin/operations/shift-management.blade.php'));
        $this->assertStringContainsString('x-ui.buttons.multi-toggle id="shift-plan-view-toggle"', $source);
        $this->assertStringContainsString('action="setView"', $source);
        $this->assertSame(2, substr_count($source, 'x-ui.forms.date-field id="shift-range-'));
    }
}
