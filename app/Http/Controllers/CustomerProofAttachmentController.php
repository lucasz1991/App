<?php

namespace App\Http\Controllers;

use App\Services\Operations\CustomerProofService;
use Illuminate\Http\Request;

class CustomerProofAttachmentController extends Controller
{
    public function __invoke(Request $request, int $id, string $attachment, CustomerProofService $service)
    {
        return $service->download($id, $attachment, $request->user());
    }
}
