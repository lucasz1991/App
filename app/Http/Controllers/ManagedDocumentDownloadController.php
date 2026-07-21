<?php

namespace App\Http\Controllers;

use App\Models\ManagedDocument;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagedDocumentDownloadController extends Controller
{
    public function __invoke(ManagedDocument $managedDocument): StreamedResponse
    {
        $user = auth()->user();

        abort_unless($user && $managedDocument->canBeViewedBy($user), 403);

        $version = $managedDocument->currentVersion()->with('file')->first();
        abort_unless($version?->file, 404);

        return $version->file->download($version->file->disk ?: 'private', denyExpired: false);
    }
}
