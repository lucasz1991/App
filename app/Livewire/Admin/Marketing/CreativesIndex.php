<?php

namespace App\Livewire\Admin\Marketing;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\MarketingCreative;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CreativesIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    public string $mediaFolderId = '';

    public string $mediaSourceFingerprint = '';

    public function mount(MarketingFileSourceService $media): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->syncMediaFolderSelection($media);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(string $type, MarketingStudioService $studio): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $creativeType = MarketingCreativeType::tryFrom($type);
        abort_unless($creativeType, 404);

        $creative = $studio->createFromTemplate($creativeType, auth()->user());

        $this->redirectRoute(
            'admin.marketing.creatives.editor',
            ['creative' => $creative, 'open' => 1],
            navigate: true,
        );
    }

    public function duplicate(string $creativeId, MarketingStudioService $studio): void
    {
        $copy = $studio->duplicate($this->creative($creativeId), auth()->user());

        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: sprintf('„%s“ wurde als Entwurf dupliziert.', $copy->title),
        );
    }

    public function approve(string $creativeId, MarketingStudioService $studio): void
    {
        $studio->approve($this->creative($creativeId), auth()->user());

        $this->dispatch('swal:toast', type: 'success', text: 'Motiv wurde zur Veröffentlichung freigegeben.');
    }

    public function archive(string $creativeId, MarketingStudioService $studio): void
    {
        $studio->archive($this->creative($creativeId), auth()->user());

        $this->dispatch('swal:toast', type: 'success', text: 'Motiv wurde archiviert.');
    }

    public function deleteCreative(string $creativeId, MarketingStudioService $studio): void
    {
        $creative = $this->creative($creativeId);
        $title = $creative->title;

        $studio->delete($creative, auth()->user());
        $this->resetPage();

        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: sprintf('"%s" wurde aus dem Marketing-Studio entfernt.', $title),
        );
    }

    public function saveMediaFolder(MarketingFileSourceService $media): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $value = trim($this->mediaFolderId);
        abort_unless($value === '' || ctype_digit($value), 422);

        try {
            $media->setSelectedFolder(
                $value === '' ? null : (int) $value,
                auth()->user(),
                $this->mediaSourceFingerprint,
            );
        } catch (ValidationException $exception) {
            $this->syncMediaFolderSelection($media);

            throw $exception;
        }

        $this->syncMediaFolderSelection($media);
        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: 'Bildquelle für neue und bestehende Motive gespeichert.',
        );
    }

    public function render(MarketingFileSourceService $media)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $creatives = MarketingCreative::query()
            ->with('variants')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where('title', 'like', '%'.trim($this->search).'%');
            })
            ->when(MarketingCreativeType::tryFrom($this->type), function (Builder $query, MarketingCreativeType $type): void {
                $query->where('type', $type->value);
            })
            ->when(MarketingCreativeStatus::tryFrom($this->status), function (Builder $query, MarketingCreativeStatus $status): void {
                $query->where('status', $status->value);
            })
            ->latest('updated_at')
            ->paginate(12);

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

        return view('livewire.admin.marketing.creatives-index', [
            'creatives' => $creatives,
            'previewFormats' => collect(MarketingCreativeFormat::cases())
                ->mapWithKeys(function (MarketingCreativeFormat $format): array {
                    $dimensions = $format->dimensions();

                    return [$format->value => [
                        'label' => ucfirst($format->value),
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ]];
                })
                ->all(),
            'mediaFolderTree' => $folderTree,
            'mediaSourceInvalid' => $mediaSourceInvalid,
            'mediaSourcePath' => $mediaSourceInvalid
                ? 'Ausgewählter Ordner nicht mehr verfügbar'
                : ($selectedFolderNode['path'] ?? 'Firmendateien / Grundverzeichnis'),
            'mediaAssetCount' => $assetLibrary['total'],
            'mediaAssetVisibleCount' => count($assetLibrary['assets']),
            'mediaAssetTruncated' => $assetLibrary['truncated'],
            'mediaFilesUrl' => route(
                'admin.files',
                $selectedFolder && ! $mediaSourceInvalid ? ['folder' => $selectedFolder->getKey()] : [],
            ),
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    private function creative(string $creativeId): MarketingCreative
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return MarketingCreative::query()->where('public_id', $creativeId)->firstOrFail();
    }

    private function syncMediaFolderSelection(MarketingFileSourceService $media): void
    {
        $selectedFolderId = $media->selectedFolderId(uncached: true);
        $this->mediaFolderId = $selectedFolderId === null ? '' : (string) $selectedFolderId;
        $this->mediaSourceFingerprint = $media->selectionFingerprint();
    }
}
