<?php

namespace App\Http\Controllers;

use App\Services\Routing\TomTomRoutingService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Http\Request;

class RoutingTileController extends Controller
{
    public function __invoke(Request $request, int $z, int $x, int $y)
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        $tile = app(TomTomRoutingService::class)->tile($z, $x, $y);
        if (($tile['status'] ?? null) !== 'known') {
            return response('', 503, ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);
        }

        return response($tile['body'], 200, [
            'Content-Type' => $tile['content_type'],
            'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
