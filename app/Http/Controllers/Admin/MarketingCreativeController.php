<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MarketingCreativeType;
use App\Http\Requests\Marketing\StoreMarketingCreativeRequest;
use App\Http\Requests\Marketing\RedesignMarketingCreativeRequest;
use App\Http\Requests\Marketing\UpdateMarketingCreativeRequest;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeVariant;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketingCreativeController extends MarketingAdminController
{
    public function store(StoreMarketingCreativeRequest $request, MarketingStudioService $studio): JsonResponse
    {
        $creative = $studio->createFromTemplate(
            MarketingCreativeType::from($request->validated('type')),
            $this->marketingAdmin($request),
        );

        return response()->json(['creative' => $this->creativePayload($creative)], 201);
    }

    public function update(
        UpdateMarketingCreativeRequest $request,
        MarketingCreative $creative,
        MarketingStudioService $studio,
    ): JsonResponse {
        $validated = $request->validated();
        $creative = $studio->updateSharedContent(
            $creative,
            $validated['shared_content'],
            $this->marketingAdmin($request),
            $validated['expected_hashes'],
            $validated['title'],
        );

        return response()->json([
            'creative' => $this->creativePayload($creative),
            'variants' => $creative->variants
                ->mapWithKeys(fn (MarketingCreativeVariant $variant): array => [
                    $variant->format->value => $this->variantPayload($variant),
                ]),
        ]);
    }

    public function duplicate(
        Request $request,
        MarketingCreative $creative,
        MarketingStudioService $studio,
    ): JsonResponse {
        $copy = $studio->duplicate($creative, $this->marketingAdmin($request));

        return response()->json(['creative' => $this->creativePayload($copy)], 201);
    }

    public function redesign(
        RedesignMarketingCreativeRequest $request,
        MarketingCreative $creative,
        MarketingStudioService $studio,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $studio->redesignFromPreset(
            $creative,
            $validated['preset'],
            $validated['expected_hashes'],
            $this->marketingAdmin($request),
        );

        return response()->json([
            'changed' => $result['changed'],
            'preset' => $result['preset'],
            'creative' => $this->creativePayload($result['creative']),
            'variants' => $result['creative']->variants
                ->mapWithKeys(fn (MarketingCreativeVariant $variant): array => [
                    $variant->format->value => $this->variantPayload($variant),
                ]),
        ]);
    }

    public function approve(
        Request $request,
        MarketingCreative $creative,
        MarketingStudioService $studio,
    ): JsonResponse {
        $creative = $studio->approve($creative, $this->marketingAdmin($request));

        return response()->json(['creative' => $this->creativePayload($creative)]);
    }

    public function archive(
        Request $request,
        MarketingCreative $creative,
        MarketingStudioService $studio,
    ): JsonResponse {
        $creative = $studio->archive($creative, $this->marketingAdmin($request));

        return response()->json(['creative' => $this->creativePayload($creative)]);
    }

    /** @return array<string, mixed> */
    private function creativePayload(MarketingCreative $creative): array
    {
        $creative->loadMissing('variants');

        return [
            'public_id' => $creative->public_id,
            'type' => $creative->type->value,
            'status' => $creative->status->value,
            'title' => $creative->title,
            'shared_content' => $creative->shared_content,
            'approved_by' => $creative->approved_by,
            'approved_at' => $creative->approved_at?->toIso8601String(),
            'variants' => $creative->variants->map(fn (MarketingCreativeVariant $variant): array => $this->variantPayload($variant))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function variantPayload(MarketingCreativeVariant $variant): array
    {
        return [
            'format' => $variant->format->value,
            'builder_data' => $variant->builder_data,
            'html' => $variant->html,
            'css' => $variant->css,
            'content_hash' => $variant->content_hash,
            'version' => $variant->version,
        ];
    }
}
