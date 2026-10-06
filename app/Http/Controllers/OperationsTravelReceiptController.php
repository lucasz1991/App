<?php

namespace App\Http\Controllers;

use App\Services\Operations\OperationsTravelService;
use Illuminate\Http\Request;

class OperationsTravelReceiptController extends Controller
{
    public function __invoke(Request $request, int $id, string $receipt, OperationsTravelService $service)
    {
        return $service->download($id, $receipt, $request->user());
    }
}
