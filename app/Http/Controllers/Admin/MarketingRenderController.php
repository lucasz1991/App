<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingRenderStatus;
use App\Http\Requests\Marketing\QueueMarketingRenderRequest;
use App\Models\MarketingCreative;
use App\Models\MarketingRender;
use App\Services\Marketing\MarketingRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MarketingRenderController extends MarketingAdminController
{
    public function store(
        QueueMarketingRenderRequest $request,
        MarketingCreative $creative,
        MarketingRenderService $renderer,
    ): JsonResponse {
        $render = $renderer->queue(
            $creative,
            MarketingCreativeFormat::from($request->validated('format')),
            $this->marketingAdmin($request),
        );

        return response()->json(
            ['render' => $this->payload($render)],
            $render->status === MarketingRenderStatus::Completed ? 200 : 202,
        );
    }

    public function show(Request $request, MarketingRender $render): JsonResponse
    {
        $this->marketingAdmin($request);

        return response()->json(['render' => $this->payload($render->fresh())]);
    }

    public function download(Request $request, MarketingRender $render): StreamedResponse
    {
        $this->marketingAdmin($request);
        abort_unless(
            $render->status === MarketingRenderStatus::Completed
            && $render->path
            && Storage::disk($render->disk)->exists($render->path),
            409,
            'Der PNG-Export ist noch nicht verfügbar.',
        );
        $render->loadMissing('creative');
        $filename = sprintf(
            'railtime-%s-%s-%s.png',
            Str::slug($render->creative->title),
            $render->format->value,
            ($render->rendered_at ?? now())->format('Y-m-d'),
        );

        return Storage::disk($render->disk)->download($render->path, $filename, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(MarketingRender $render): array
    {
        return [
            'public_id' => $render->public_id,
            'status' => $render->status->value,
            'format' => $render->format->value,
            'width' => $render->width,
            'height' => $render->height,
            'error' => $render->status === MarketingRenderStatus::Failed ? $render->error : null,
            'status_url' => route('admin.marketing.renders.show', $render),
            'download_url' => $render->status === MarketingRenderStatus::Completed
                ? route('admin.marketing.renders.download', $render)
                : null,
        ];
    }
}
