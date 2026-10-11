<?php

namespace App\Http\Controllers;

use App\Services\Routing\TomTomRoutingService;
use App\Support\Operations\OperationsAccess;

class RoutingTileController extends Controller
{
    public function __invoke(int $z, int $x, int $y)
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        $tile = app(TomTomRoutingService::class)->tile($z, $x, $y);
        if (($tile['status'] ?? null) !== 'known') {
            return response('', 503, ['Cache-Control' => 'no-store']);
        }

        return response($tile['body'], 200, ['Content-Type' => $tile['content_type'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
