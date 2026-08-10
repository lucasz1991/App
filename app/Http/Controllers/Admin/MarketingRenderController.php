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
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

    public function show(
        Request $request,
        MarketingRender $render,
        MarketingRenderService $renderer,
    ): JsonResponse {
        $this->marketingAdmin($request);

        $render = $render->fresh();

        return response()->json(['render' => $this->payload(
            $render,
            $render->status !== MarketingRenderStatus::Completed || $renderer->isCurrent($render),
        )]);
    }

    public function download(
        Request $request,
        MarketingRender $render,
        MarketingRenderService $renderer,
    ): StreamedResponse {
        $this->marketingAdmin($request);
        $snapshot = $renderer->downloadSnapshot($render);
        abort_unless(
            $snapshot !== null,
            409,
            'Der PNG-Export ist nicht mehr aktuell. Bitte den Export erneut starten.',
        );
        $filename = sprintf(
            'railtime-%s-%s-%s.png',
            Str::slug($snapshot['creative_title']) ?: 'motiv',
            $render->format->value,
            ($render->rendered_at ?? now())->format('Y-m-d'),
        );

        return response()->stream(static function () use ($snapshot): void {
            echo $snapshot['contents'];
        }, 200, [
            'Content-Type' => $snapshot['mime_type'],
            'Content-Length' => (string) $snapshot['size'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename,
                $filename,
            ),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(MarketingRender $render, bool $current = true): array
    {
        $stale = $render->status === MarketingRenderStatus::Completed && ! $current;

        return [
            'public_id' => $render->public_id,
            'status' => $stale ? MarketingRenderStatus::Failed->value : $render->status->value,
            'format' => $render->format->value,
            'width' => $render->width,
            'height' => $render->height,
            'error' => $stale
                ? 'Das Motiv oder ein verwendetes Medium wurde nach diesem Export geändert. Bitte den Export erneut starten.'
                : ($render->status === MarketingRenderStatus::Failed ? $render->error : null),
            'status_url' => route('admin.marketing.renders.show', $render),
            'download_url' => $render->status === MarketingRenderStatus::Completed && $current
                ? route('admin.marketing.renders.download', $render)
                : null,
        ];
    }
}
