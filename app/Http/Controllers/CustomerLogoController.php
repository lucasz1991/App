<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPortalIdentity;
use App\Models\User;
use App\Services\Operations\CustomerLogoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CustomerLogoController extends Controller
{
    public function internal(int $customer, CustomerLogoService $service)
    {
        $actor = Auth::guard('web')->user();
        abort_unless($actor instanceof User, 401);

        return $this->image($service->customer($actor, $customer));
    }

    public function portal(int $customer, CustomerLogoService $service)
    {
        $actor = Auth::guard('customer_portal')->user();
        abort_unless($actor instanceof CustomerPortalIdentity, 401);

        return $this->image($service->customer($actor, $customer));
    }

    private function image(Customer $customer)
    {
        abort_unless($customer->hasValidLogoPath() && Storage::disk('private')->exists($customer->logo_path), 404);

        return Storage::disk('private')->response($customer->logo_path, 'customer-logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}
