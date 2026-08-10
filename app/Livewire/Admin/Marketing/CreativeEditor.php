<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Models\MarketingCreative;
use App\Services\Marketing\MarketingFileSourceService;
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

    public function render(MarketingFileSourceService $media)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $creative = $this->creative->fresh(['variants']);
        abort_unless($creative, 404);

        $variants = $creative->variants
            ->mapWithKeys(function ($variant): array {
                $format = $variant->format instanceof BackedEnum ? $variant->format->value : (string) $variant->format;

                return [$format => [
                    'builderData' => $variant->builder_data ?: [],
                    'css' => (string) $variant->css,
                    'contentHash' => (string) $variant->content_hash,
                    'version' => (int) $variant->version,
                ]];
            })
            ->all();

        $selectedFolderId = $media->selectedFolderId();
        $selectedFolder = $media->selectedFolder();
        $mediaSourceInvalid = $selectedFolderId !== null && $selectedFolder === null;
        $folderTree = $media->folderTree();
        $selectedFolderNode = collect($folderTree)->first(
            fn (array $folder): bool => (bool) ($folder['selected'] ?? false)
        );
        $assetLibrary = $mediaSourceInvalid
            ? ['assets' => [], 'total' => 0, 'limit' => 0, 'truncated' => false]
            : $media->editorAssetLibrary();
        $assets = $assetLibrary['assets'];

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
            'logoLightUrl' => asset('rt-brand/img/logo-horizontal.png'),
            'logoDarkUrl' => asset('rt-brand/img/logo-horizontal-darkbg.png'),
            'endpoints' => [
                'creativeUpdate' => route('admin.marketing.creatives.update', $creative),
                'variantUpdate' => route('admin.marketing.variants.update', [$creative, '__FORMAT__']),
                'renderStore' => route('admin.marketing.renders.store', $creative),
                'renderShow' => route('admin.marketing.renders.show', '__RENDER__'),
                'renderDownload' => route('admin.marketing.renders.download', '__RENDER__'),
            ],
        ];

        $previewSources = collect(MarketingCreativeFormat::cases())
            ->mapWithKeys(function (MarketingCreativeFormat $format) use ($creative): array {
                $dimensions = $format->dimensions();

                return [$format->value => [
                    'label' => match ($format) {
                        MarketingCreativeFormat::Story => 'Story',
                        MarketingCreativeFormat::Post => 'Post',
                        MarketingCreativeFormat::Web => 'Web',
                    },
                    'url' => route('admin.marketing.creatives.preview', [$creative, $format->value]),
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                ]];
            })
            ->all();

        return view('livewire.admin.marketing.creative-editor', [
            'creativeRecord' => $creative,
            'editorConfig' => $editorConfig,
            'mediaSourceInvalid' => $mediaSourceInvalid,
            'mediaSourcePath' => $mediaSourceInvalid
                ? 'Ausgewählter Ordner nicht mehr verfügbar'
                : ($selectedFolderNode['path'] ?? 'Firmendateien / Grundverzeichnis'),
            'mediaAssetCount' => $assetLibrary['total'],
            'mediaAssetVisibleCount' => count($assets),
            'mediaAssetTruncated' => $assetLibrary['truncated'],
            'mediaFilesUrl' => route(
                'admin.files',
                $selectedFolder && ! $mediaSourceInvalid ? ['folder' => $selectedFolder->getKey()] : [],
            ),
            'editorPreviewSources' => $previewSources,
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
