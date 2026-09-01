<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinManifest;
use App\Support\OutlookAddin\OutlookDeploymentPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Read-only downloads for a manually reviewed Microsoft 365 deployment.
 *
 * Neither action talks to Microsoft Graph, Entra or Exchange Online. The
 * generated PowerShell fallback remains inert until an administrator edits
 * its explicit safety switches after downloading it.
 */
final class OutlookAddinDeploymentController extends Controller
{
    public function manifest(Request $request, OutlookAddinManifest $manifest): Response
    {
        $this->mailAdmin($request);
        $this->assertAvailable($manifest);

        return response($manifest->render(), 200, $this->downloadHeaders(
            'application/xml; charset=UTF-8',
            'RailTime-Outlook-Addin.xml',
        ));
    }

    public function package(
        Request $request,
        OutlookAddinManifest $manifest,
        OutlookDeploymentPackage $package,
    ): Response {
        $actor = $this->mailAdmin($request);
        $this->assertAvailable($manifest);
        $archive = $package->build($actor);

        return response($archive['content'], 200, $this->downloadHeaders(
            $archive['mime'],
            $archive['filename'],
        ));
    }

    private function assertAvailable(OutlookAddinManifest $manifest): void
    {
        abort_unless($manifest->enabled(), 404);

        if (! $manifest->ready()) {
            abort(503, implode(' ', $manifest->issues()));
        }
    }

    /** @return array<string, string> */
    private function downloadHeaders(string $contentType, string $filename): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function mailAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
