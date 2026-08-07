<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Models\MarketingAsset;
use App\Models\MarketingCreative;
use BackedEnum;
use Livewire\Component;

class CreativeEditor extends Component
{
    public MarketingCreative $creative;

    public string $format = 'story';

    public function mount(MarketingCreative $creative): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->creative = $creative;
        $requestedFormat = (string) request()->query('format', 'story');
        $this->format = MarketingCreativeFormat::tryFrom($requestedFormat)?->value ?? MarketingCreativeFormat::Story->value;
    }

    public function render()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $creative = $this->creative->fresh(['variants']);
        abort_unless($creative, 404);

        $variants = $creative->variants
            ->mapWithKeys(function ($variant): array {
                $format = $variant->format instanceof BackedEnum ? $variant->format->value : (string) $variant->format;

                return [$format => [
                    'builderData' => $variant->builder_data ?: [],
                    'contentHash' => (string) $variant->content_hash,
                    'version' => (int) $variant->version,
                ]];
            })
            ->all();

        $assets = MarketingAsset::query()
            ->latest('created_at')
            ->limit(250)
            ->get()
            ->map(fn (MarketingAsset $asset): array => [
                'public_id' => $asset->public_id,
                'src' => route('admin.marketing.assets.show', $asset),
                'name' => $asset->original_name,
                'type' => $asset->mime_type,
                'width' => $asset->width,
                'height' => $asset->height,
            ])
            ->values()
            ->all();

        $editorConfig = [
            'creativeId' => $creative->public_id,
            'currentFormat' => $this->format,
            'status' => $creative->status instanceof BackedEnum ? $creative->status->value : (string) $creative->status,
            'variants' => $variants,
            'assets' => $assets,
            'sharedContent' => $creative->shared_content ?: [],
            'vendor' => [
                'builderJs' => asset('vendor/lmz-builder/2.4.5/lmz-builder.js'),
                'builderCss' => asset('vendor/lmz-builder/2.4.5/lmz-builder.css'),
                'grapesJs' => asset('vendor/lmz-builder/2.4.5/grapesjs.js'),
                'grapesCss' => asset('vendor/lmz-builder/2.4.5/grapesjs.css'),
            ],
            'logoUrl' => asset('rt-brand/rt-logo.svg'),
            'endpoints' => [
                'creativeUpdate' => route('admin.marketing.creatives.update', $creative),
                'variantUpdate' => route('admin.marketing.variants.update', [$creative, '__FORMAT__']),
                'assetUpload' => route('admin.marketing.assets.store'),
                'renderStore' => route('admin.marketing.renders.store', $creative),
                'renderShow' => route('admin.marketing.renders.show', '__RENDER__'),
                'renderDownload' => route('admin.marketing.renders.download', '__RENDER__'),
            ],
        ];

        return view('livewire.admin.marketing.creative-editor', [
            'creativeRecord' => $creative,
            'editorConfig' => $editorConfig,
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
