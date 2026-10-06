<?php

namespace App\Http\Controllers;

use App\Support\Operations\OperationsPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OperationsWorkspaceRedirectController extends Controller
{
    public function __invoke(Request $request, string $module): RedirectResponse
    {
        return new RedirectResponse(OperationsPages::legacyUrlFor($request->user(), $module, $request->query()));
    }
}
