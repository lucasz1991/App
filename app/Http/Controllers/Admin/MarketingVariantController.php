<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MarketingCreativeFormat;
use App\Http\Requests\Marketing\SaveMarketingVariantRequest;
use App\Models\MarketingCreative;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Http\JsonResponse;

final class MarketingVariantController extends MarketingAdminController
{
    public function update(
        SaveMarketingVariantRequest $request,
        MarketingCreative $creative,
        string $format,
        MarketingStudioService $studio,
    ): JsonResponse {
        $creativeFormat = MarketingCreativeFormat::tryFrom($format);
        abort_unless($creativeFormat, 404);
        $validated = $request->validated();
        $variant = $studio->saveVariant(
            $creative,
            $creativeFormat,
            $validated['builder_data'],
            $validated['html'],
            $validated['css'],
            $validated['expected_hash'],
            $this->marketingAdmin($request),
        );

        return response()->json([
            'variant' => [
                'format' => $variant->format->value,
                'builder_data' => $variant->builder_data,
                'html' => $variant->html,
                'css' => $variant->css,
                'content_hash' => $variant->content_hash,
                'version' => $variant->version,
            ],
            'creative' => [
                'status' => $creative->fresh()->status->value,
            ],
        ]);
    }
}
