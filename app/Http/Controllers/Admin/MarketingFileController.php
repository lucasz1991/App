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

        return response()->stream(static function () use ($snapshot): void {
            echo $snapshot['contents'];
        }, 200, [
            'Content-Type' => $snapshot['mime_type'],
            'Content-Length' => (string) $snapshot['size'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $snapshot['name'],
                $fallbackName,
            ),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'ETag' => '"'.$snapshot['sha256'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
