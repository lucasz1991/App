<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WelcomeIntroCatalog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WelcomeIntroMediaController extends Controller
{
    public function __invoke(
        Request $request,
        WelcomeIntroCatalog $catalog,
        string $module,
        string $asset,
    ): BinaryFileResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($catalog->canView($user, $module), 404);

        $media = $catalog->media($module, $asset);

        abort_unless($media && is_file($media['path']), 404);
        abort_unless(
            $media['expectedSize'] === null || filesize($media['path']) === $media['expectedSize'],
            404,
        );

        $response = new BinaryFileResponse(
            $media['path'],
            200,
            [
                'Content-Type' => $media['mime'],
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_INLINE,
                    $media['filename'],
                ),
                'Cache-Control' => 'private, max-age=300, must-revalidate',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Referrer-Policy' => 'no-referrer',
                'Vary' => 'Cookie',
                'X-Content-Type-Options' => 'nosniff',
            ],
            public: false,
        );

        if ($media['etag']) {
            $response->setEtag($media['etag']);
        } else {
            $response->setAutoEtag();
        }
        $response->setAutoLastModified();

        return $response;
    }
}
