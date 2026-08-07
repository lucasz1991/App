<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

abstract class MarketingAdminController extends Controller
{
    protected function marketingAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
