<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class OutlookUniversalDiagnosticFixtureController extends Controller
{
    public function __invoke(Request $request, string $user, string $fixture, OutlookUniversalDiagnosticFixtureStore $store): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);
        abort_unless($request->query() === [] && ctype_digit($user) && preg_match('/\A[0-9a-f]{32}\z/', $fixture), 404);
        $subject = User::query()->findOrFail($user);
        $artifact = $store->get($subject, $fixture);
        abort_if($artifact === null, 404);

        return response(json_encode(['schema' => 1, 'kind' => 'u2e-redacted-compose-signature', ...$artifact], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="RailTime-U2e-redacted-'.$fixture.'.json"',
            'Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none';",
        ]);
    }
}
