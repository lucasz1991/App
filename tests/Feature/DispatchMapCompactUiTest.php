<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\WidgetGrid;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Dashboard\WidgetRegistry;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DispatchMapCompactUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_140000_create_dashboard_widget_placements_table.php'))->up();
        (require database_path('migrations/2026_09_17_160000_add_rows_to_dashboard_widget_placements_table.php'))->up();
        config(['operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08T22:30:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic map customer', 'is_active' => true]);
        $this->order = Order::create([
            'customer_id' => $customer->id,
            'title' => 'Synthetic map order',
            'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-10-01 00:00:00',
            'ends_at' => '2026-11-01 00:00:00',
            'status' => 'confirmed',
            'priority' => 'normal',
            'required_staff' => 1,
        ]);
        foreach (array_keys(WidgetRegistry::availableFor($this->admin)) as $key) {
            if ($key !== 'operations_dispatch_map') {
                DashboardLayout::setHidden($this->admin, $key, true);
            }
        }
    }

    public function test_date_and_icon_controls_share_the_existing_widget_header_at_both_saved_widths(): void
    {
        $component = $this->grid();
        foreach (['sm', 'lg'] as $size) {
            $component->call('setWidgetSize', 'operations_dispatch_map', $size)->assertOk();
            $xpath = $this->xpath($component->html());
            $card = '//article[@data-widget-key="operations_dispatch_map"]';
            $header = $card.'/header';
            $this->assertSame(1, $xpath->query($header)->length);
            $this->assertSame(1, $xpath->query($header.'//h3')->length);
            $this->assertSame(1, $xpath->query($header.'//time[@datetime="2026-10-09"]')->length);
            $this->assertSame(1, $xpath->query($card.'[@data-widget-size="'.$size.'"]')->length);
            $buttons = $xpath->query($header.'//*[@data-rt-dropdown-trigger]/button');
            $this->assertSame(3, $buttons->length);
            foreach ($buttons as $button) {
                $this->assertSame('button', $button->getAttribute('type'));
                $this->assertNotSame('', trim($button->getAttribute('aria-label')));
                $this->assertSame('', trim($button->textContent), 'The primary header controls remain icon-only.');
            }
            $this->assertSame(0, $xpath->query($card.'/*[contains(@class,"widget-card-body")]//*[@data-rt-dropdown-trigger]/button[not(@data-dispatch-marker)]')->length);
            $this->assertSame(3, $xpath->query($header.'//*[@role="dialog"]')->length);
            $this->assertSame(1, $xpath->query($header.'//*[@role="dialog"]//input[@type="date"]')->length);
        }
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('operation_inquiries', 0);
    }

    public function test_map_keeps_the_outline_and_date_without_a_second_counter_or_accuracy_row(): void
    {
        $this->shift(['title' => 'Synthetic Hamburg shift']);
        $this->inquiry();
        $component = $this->grid()->call('setWidgetSize', 'operations_dispatch_map', 'sm')->assertOk();
        $xpath = $this->xpath($component->html());
        $card = '//article[@data-widget-key="operations_dispatch_map"]';
        $this->assertSame(0, $xpath->query($card.'//*[contains(concat(" ",normalize-space(@class)," ")," wv-dispatch-map__summary ")]')->length);
        $this->assertSame(1, $xpath->query($card.'//svg[contains(concat(" ",normalize-space(@class)," ")," wv-dispatch-map__canvas ")]/path[@d]')->length);
        $this->assertStringNotContainsString('Ortslagen · ungefähr', $xpath->query($card)->item(0)->textContent);
        $this->assertSame(1, $xpath->query($card.'/header//time[@datetime="2026-10-09"]')->length);
        $this->assertDatabaseCount('shifts', 1);
        $this->assertDatabaseCount('operation_inquiries', 1);
    }

    public function test_native_map_points_expose_only_their_own_escaped_details_in_hover_dialogs(): void
    {
        $hamburg = $this->shift(['title' => '<img src=x onerror=alert(1)>']);
        $berlin = $this->shift(['title' => 'Synthetic Berlin shift', 'location_name' => 'Berlin']);
        $inquiry = $this->inquiry();
        $this->inquiry(['title' => 'Unlocated synthetic request', 'location_name' => 'Unknown synthetic locality']);
        $component = $this->grid()->assertOk();
        $xpath = $this->xpath($component->html());
        $card = '//article[@data-widget-key="operations_dispatch_map"]';
        $points = $xpath->query($card.'//button[@data-dispatch-marker]');
        $this->assertSame(2, $points->length);
        $this->assertSame(0, $xpath->query($card.'//svg//button[@data-dispatch-marker]')->length);
        foreach ($points as $point) {
            $this->assertSame('button', $point->getAttribute('type'));
            $label = $point->getAttribute('aria-label');
            $this->assertNotSame('', trim($label));
            $dropdown = $xpath->query('ancestor::*[@data-rt-dropdown-root][1]', $point)->item(0);
            $this->assertNotNull($dropdown);
            $this->assertStringContainsString('openOnHover: true', $dropdown->getAttribute('x-data'));
            $this->assertStringNotContainsString('@js', $dropdown->getAttribute('x-show.important'), 'Component attributes must deliver executable Alpine expressions.');
            $dialogs = $xpath->query('.//*[@role="dialog"]', $dropdown);
            $this->assertSame(1, $dialogs->length);
            $dialog = $dialogs->item(0);
            $this->assertNotSame('', $dialog->getAttribute('aria-label'));
            $this->assertStringNotContainsString('Unlocated synthetic request', $dialog->textContent);
            if (str_contains($label, 'Hamburg')) {
                $this->assertStringContainsString($hamburg->title, $dialog->textContent);
                $this->assertStringContainsString($inquiry->title, $dialog->textContent);
                $this->assertStringNotContainsString($berlin->title, $dialog->textContent);
                $this->assertSame(2, $xpath->query('.//a[@href]', $dialog)->length);
            } else {
                $this->assertStringContainsString('Berlin', $label);
                $this->assertStringContainsString($berlin->title, $dialog->textContent);
                $this->assertStringNotContainsString($inquiry->title, $dialog->textContent);
                $this->assertSame(1, $xpath->query('.//a[@href]', $dialog)->length);
            }
            $this->assertSame(0, $xpath->query('.//img[@onerror]', $dialog)->length);
        }
        $this->assertDatabaseCount('shifts', 2);
        $this->assertDatabaseCount('operation_inquiries', 2);
    }

    public function test_edit_mode_retains_remove_move_and_keyboard_resize_controls_around_the_compact_map(): void
    {
        $component = $this->grid()->call('setWidgetSize', 'operations_dispatch_map', 'sm')
            ->call('toggleEditing')->assertOk();
        $xpath = $this->xpath($component->html());
        $card = '//article[@data-widget-key="operations_dispatch_map"]';
        $this->assertSame(1, $xpath->query($card.'[@data-editing="true" and @draggable="true"]')->length);
        $this->assertSame(2, $xpath->query($card.'//button[starts-with(@*[name()="wire:click"],"moveWidget(")]')->length);
        $this->assertSame(1, $xpath->query($card.'//button[starts-with(@*[name()="wire:click"],"hideWidget(")]')->length);
        foreach (['width' => 'horizontal', 'height' => 'vertical'] as $handle => $orientation) {
            $this->assertSame(1, $xpath->query($card.'//*[@data-widget-resize-handle="'.$handle.'" and @role="slider" and @tabindex="0" and @aria-orientation="'.$orientation.'"]')->length);
        }
        $this->assertSame(3, $xpath->query($card.'/header//*[@data-rt-dropdown-trigger]/button')->length);
        $component->call('setWidgetSize', 'operations_dispatch_map', 'lg')
            ->call('setWidgetRows', 'operations_dispatch_map', 1)->assertOk();
        $xpath = $this->xpath($component->html());
        $this->assertSame(1, $xpath->query($card.'[@data-widget-size="lg" and @data-widget-rows="1"]')->length);
        $this->assertSame(1, $xpath->query($card.'//*[@data-widget-resize-handle="width" and @aria-valuenow="1"]')->length);
        $this->assertSame(1, $xpath->query($card.'//*[@data-widget-resize-handle="height" and @aria-valuenow="1"]')->length);
    }

    private function grid()
    {
        return Livewire::actingAs($this->admin)->test(WidgetGrid::class);
    }

    private function shift(array $attributes = []): Shift
    {
        return Shift::create($attributes + [
            'order_id' => $this->order->id, 'title' => 'Synthetic Hamburg shift', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-09 06:00:00', 'ends_at' => '2026-10-09 14:00:00',
            'location_name' => 'Hamburg', 'status' => 'open', 'required_staff' => 2,
        ]);
    }

    private function inquiry(array $attributes = []): OperationInquiry
    {
        return OperationInquiry::create($attributes + [
            'customer_id' => $this->order->customer_id, 'title' => 'Synthetic Hamburg inquiry', 'channel' => 'manual',
            'original' => 'PRIVATE ORIGINAL EXCLUDED FROM MAP', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-10-09 08:00:00', 'ends_at' => '2026-10-09 16:00:00',
            'location_name' => 'Hamburg', 'status' => 'new', 'required_staff' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
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
