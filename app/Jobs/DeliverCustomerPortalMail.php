<?php

namespace App\Jobs;

use App\Services\CustomerPortal\CustomerPortalDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverCustomerPortalMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(CustomerPortalDeliveryService $service): void
    {
        $service->deliver($this->deliveryId);
    }
}
