<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class OperationsNumberInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        View::share('errors', new ViewErrorBag);
    }

    public function test_integer_operations_fields_use_the_existing_number_component(): void
    {
        $html = Blade::render('<x-operations.field label="Personalbedarf" model="form.required_staff" type="number" min="1" max="999" required />');

        $this->assertStringContainsString('data-rt-number-input', $html);
        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('role="spinbutton"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('wire:model="form.required_staff"', $html);
        $this->assertStringContainsString('for="ops-form-required_staff"', $html);
        $this->assertStringContainsString('id="ops-form-required_staff"', $html);
        $this->assertStringContainsString('aria-valuemin="1"', $html);
        $this->assertStringContainsString('aria-valuemax="999"', $html);
        $this->assertStringContainsString('required', $html);
    }

    public function test_decimal_precision_is_derived_from_step_with_a_laravel_compatible_separator(): void
    {
        $html = str_replace('\\u0022', '"', html_entity_decode(Blade::render('<x-operations.field label="Betrag" model="amount" type="number" min="0" step="0.01" />'), ENT_QUOTES));

        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('"step":0.01', $html);
        $this->assertStringContainsString('"decimals":2', $html);
        $this->assertStringContainsString('"separator":"."', $html);
        $this->assertStringContainsString('wire:model="amount"', $html);
    }

    public function test_explicit_precision_and_disabled_readonly_state_are_preserved(): void
    {
        $html = str_replace('\\u0022', '"', html_entity_decode(Blade::render('<x-operations.field label="Dauer" model="duration" type="number" step="0.5" :decimals="3" :disabled="true" :readonly="true" />'), ENT_QUOTES));

        $this->assertStringContainsString('"decimals":3', $html);
        $this->assertStringContainsString('"step":0.5', $html);
        $this->assertMatchesRegularExpression('/<input\b[^>]*\bdisabled\b[^>]*\breadonly\b/s', $html);
        $this->assertStringContainsString('@beforeinput="acceptDecimalSeparator($event)"', $html);
        $this->assertStringContainsString('@paste="acceptDecimalSeparator($event)"', $html);
    }

    public function test_compact_number_fields_keep_keyboard_and_spinbutton_semantics(): void
    {
        $html = Blade::render('<x-ui.forms.number-input :stepper="false" min="0" max="60" wire:model.live="minutes" />');

        $this->assertStringContainsString('role="spinbutton"', $html);
        $this->assertStringContainsString('aria-valuemin="0"', $html);
        $this->assertStringContainsString('aria-valuemax="60"', $html);
        $this->assertStringContainsString('wire:model.live="minutes"', $html);
        $this->assertStringContainsString('@keydown.up.prevent="nudge(1)"', $html);
        $this->assertStringContainsString('@keydown.down.prevent="nudge(-1)"', $html);
    }

    public function test_operations_forms_do_not_bypass_the_shared_number_component(): void
    {
        foreach (['livewire/admin/operations', 'livewire/operations'] as $directory) {
            foreach (File::allFiles(resource_path('views/'.$directory)) as $file) {
                $this->assertDoesNotMatchRegularExpression('/<(?:input|x-ui\.forms\.input)\b[^>]*\btype=["\']number["\']/s', File::get($file->getPathname()), $file->getRelativePathname());
            }
        }

        $orders = File::get(resource_path('views/livewire/admin/operations/orders.blade.php'));
        $this->assertStringContainsString('<x-ui.forms.number-input id="order-required-staff" min="1" max="999" :nullable="false"', $orders);
    }
}
