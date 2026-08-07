<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Marketing\StoreMarketingAssetRequest;
use App\Models\MarketingAsset;
use App\Services\Marketing\MarketingAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MarketingAssetController extends MarketingAdminController
{
    public function store(StoreMarketingAssetRequest $request, MarketingAssetService $assets): JsonResponse
    {
        $asset = $assets->store($request->file('file'), $this->marketingAdmin($request));

        return response()->json(['asset' => $this->payload($asset)], 201);
    }

    public function update(
        StoreMarketingAssetRequest $request,
        MarketingAsset $asset,
        MarketingAssetService $assets,
    ): JsonResponse {
        $asset = $assets->replace($asset, $request->file('file'), $this->marketingAdmin($request));

        return response()->json(['asset' => $this->payload($asset)]);
    }

    public function show(Request $request, MarketingAsset $asset): StreamedResponse
    {
        $this->marketingAdmin($request);
        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->path), 404);

        return $disk->response($asset->path, $asset->original_name, [
            'Content-Type' => $asset->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="'.addslashes($asset->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(
        Request $request,
        MarketingAsset $asset,
        MarketingAssetService $assets,
    ): JsonResponse {
        $this->marketingAdmin($request);
        $assets->delete($asset);

        return response()->json([], 204);
    }

    /** @return array<string, mixed> */
    private function payload(MarketingAsset $asset): array
    {
        return [
            'public_id' => $asset->public_id,
            'original_name' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'extension' => $asset->extension,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => route('admin.marketing.assets.show', $asset).'?v='.substr($asset->sha256, 0, 16),
        ];
    }
}
