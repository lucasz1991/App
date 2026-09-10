<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeWorkplaceProvision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class EmployeeProvisionPasswordController extends Controller
{
    public function __invoke(Request $request, int $provision)
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor?->isActive() && $actor->isSuperAdmin(), 403);
        Gate::forUser($actor)->authorize('devices.accounts.manage');
        $password = DB::transaction(function () use ($provision, $actor): string {
            $order = EmployeeWorkplaceProvision::query()->whereKey($provision)->lockForUpdate()->firstOrFail();
            abort_unless($order->account_state === 'ready' && $order->initial_password && $order->password_expires_at?->isFuture(), 410);
            $value = $order->initial_password;
            $order->update(['initial_password' => null]);
            activity('device-management')->causedBy($actor)->event('employee-workplace.password-collected')->withProperties(['order_id' => $order->id])->log('Startkennwort einmalig an IT übergeben');

            return $value;
        }, 3);

        return response($password)->withHeaders(['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="RailTime-Startkennwort.txt"', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }
}
