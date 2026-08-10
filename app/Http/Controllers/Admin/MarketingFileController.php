<?php

namespace App\Http\Controllers\Admin;

use App\Models\File;
use App\Services\Marketing\MarketingFileSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MarketingFileController extends MarketingAdminController
{
    public function __invoke(
        Request $request,
        File $file,
        MarketingFileSourceService $files,
    ): StreamedResponse {
        $this->marketingAdmin($request);
        $snapshot = $files->validatedSnapshot($file);
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '-', Str::ascii($snapshot['name'])) ?: 'railtime-bild';

        $response = response()->stream(static function () use ($snapshot): void {
            echo $snapshot['contents'];
        }, 200, [
            'Content-Type' => $snapshot['mime_type'],
            'Content-Length' => (string) $snapshot['size'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $snapshot['name'],
                $fallbackName,
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Vary' => 'Cookie',
        ]);

        $version = strtolower((string) $request->query('v', ''));
        $hasCurrentVersion = (bool) preg_match('/^[a-f0-9]{8,64}$/', $version)
            && str_starts_with($snapshot['sha256'], $version);
        $response->setPrivate();
        $response->setMaxAge($hasCurrentVersion
            ? max(60, (int) config('marketing.assets.browser_cache_seconds', 900))
            : 0);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setEtag($snapshot['sha256']);
        if ($file->updated_at) {
            $response->setLastModified($file->updated_at);
        }
        $response->isNotModified($request);

        return $response;
    }
}
