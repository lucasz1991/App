<?php

namespace App\Jobs;

use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Routing\WorksiteLookup;
use App\Support\Operations\TimelineLocationPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ResolveWorksite implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $type, public int $id) {}

    public function handle(WorksiteLookup $service): void
    {
        $settings = \App\Support\Routing\TomTomSettings::all();
        if (! $settings['enabled'] || ! $settings['server_cache_enabled']) {
            return;
        }
        request()->attributes->set('worksite_shared_cache', true);
        $record = match ($this->type) {
            Shift::class => Shift::with('order')->find($this->id),
            Order::class => Order::find($this->id),
            OperationInquiry::class => OperationInquiry::find($this->id),
        };
        if (!$record) {
            return;
        }
        $local = match (true) {
            $record instanceof Shift => TimelineLocationPreview::fromShift($record),
            $record instanceof Order => TimelineLocationPreview::fromOrder($record),
            default => TimelineLocationPreview::fromInquiry($record),
        };
        $input = WorksiteLookup::forRecord($record);
        if (WorksiteLookup::needsLookup($local, $input)) {
            $service->resolve($input);
        }
    }
}
