<?php

namespace App\Livewire\Admin;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\OutlookAddin\OutlookTemplateLibrary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Lightweight administration before opening an individual mail editor. */
class MailDocumentLibrary extends Component
{
    public string $kind = 'template';

    public string $search = '';

    public string $filter = 'all';

    public string $name = '';

    public bool $createOpen = false;

    public bool $confirmOpen = false;

    #[Locked]
    public ?string $historyId = null;

    /** @var array<string, string> */
    #[Locked]
    public array $pending = [];

    /** @var array<string, string> */
    #[Locked]
    public array $source = [];

    #[Locked]
    public string $notice = '';

    public function boot(): void
    {
        $this->admin();
    }

    public function mount(string $initialKind = 'template'): void
    {
        $this->admin();
        $this->kind = MailDocumentKind::tryFrom($initialKind)?->value ?? 'template';
    }

    public function selectKind(string $kind): void
    {
        $this->admin();
        abort_unless(MailDocumentKind::tryFrom($kind) !== null, 422);
        $this->kind = $kind;
        $this->historyId = null;
        $this->filter = 'all';
        $this->resetValidation();
    }

    public function selectFilter(string $filter): void
    {
        $this->admin();
        abort_unless(in_array($filter, ['all', 'draft', 'released', 'default'], true), 422);
        $this->filter = $filter;
    }

    public function updatedSearch(): void
    {
        $this->admin();
        $this->search = mb_substr($this->search, 0, 120);
    }

    public function toggleHistory(string $documentId): void
    {
        $this->admin();
        $document = $this->document($documentId);
        $this->historyId = $this->historyId === $document->public_id ? null : $document->public_id;
    }

    public function openCreate(?string $sourceId = null, ?string $expectedHash = null): void
    {
        $this->admin();
        $this->resetValidation();
        $this->name = '';
        $this->source = [];
        if ($sourceId !== null) {
            $document = $this->document($sourceId);
            $this->assertCurrent($document, (string) $expectedHash);
            $this->source = [
                'id' => $document->public_id,
                'hash' => (string) $document->content_hash,
                'name' => (string) $document->name,
                'kind' => $document->kind->value,
            ];
            $this->name = mb_substr((string) $document->name, 0, 68).' – Kopie';
        } else {
            // New reusable Outlook templates never replace the system default.
            abort_unless($this->kind === MailDocumentKind::Template->value, 422);
        }
        $this->createOpen = true;
    }

    public function createDraft(OutlookTemplateLibrary $library): void
    {
        $actor = $this->admin();
        abort_unless($this->createOpen, 403);
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:80']], [
            'name.required' => 'Bitte gib dem Entwurf einen Namen.',
            'name.max' => 'Der Name darf höchstens 80 Zeichen lang sein.',
        ]);

        try {
            $source = isset($this->source['id']) ? $this->document($this->source['id']) : null;
            $created = $source === null
                ? $library->createDraft($actor, $this->name)
                : $library->duplicateDraft($actor, $source, $this->name, $this->source['hash']);
        } catch (ValidationException $exception) {
            $this->showValidation($exception, 'name');

            return;
        }

        $this->createOpen = false;
        $this->source = [];
        $this->kind = $created->kind->value;
        $this->filter = 'all';
        $this->search = '';
        $this->notice = '„'.$created->name.'“ wurde als Entwurf angelegt. Es wurde nichts veröffentlicht.';
        $this->dispatch('mail-document-library-changed');
    }

    public function prepareAction(string $action, string $documentId, string $expectedHash, ?string $versionId = null): void
    {
        $this->admin();
        abort_unless(in_array($action, ['publish', 'default', 'withdraw', 'restore'], true), 422);
        $this->resetValidation();
        $document = $this->document($documentId);
        $this->assertCurrent($document, $expectedHash);
        if (in_array($action, ['default', 'withdraw'], true)) {
            abort_unless((bool) $document->getAttribute('is_outlook_template'), 422);
        }
        $this->pending = [
            'action' => $action,
            'document' => $document->public_id,
            'hash' => (string) $document->content_hash,
            'name' => (string) $document->name,
            'kind' => $document->kind->value,
            'library' => $document->getAttribute('is_outlook_template') ? 'true' : 'false',
        ];
        if ($action === 'restore') {
            $version = $document->versions()->where('public_id', $versionId)->firstOrFail();
            $this->pending['version'] = $version->public_id;
            $this->pending['revision'] = (string) $version->revision;
        }
        $this->confirmOpen = true;
    }

    public function confirmAction(OutlookTemplateLibrary $library): void
    {
        $actor = $this->admin();
        abort_unless($this->confirmOpen && isset($this->pending['document'], $this->pending['hash']), 403);
        $document = $this->document($this->pending['document']);
        $hash = $this->pending['hash'];
        $action = $this->pending['action'];

        try {
            match ($action) {
                'publish' => $library->publish($actor, $document, $hash),
                'default' => $library->setDefault($actor, $document, $hash),
                'withdraw' => $library->withdraw($actor, $document, $hash),
                'restore' => $library->restoreDraft(
                    $actor,
                    $document,
                    $document->versions()->where('public_id', $this->pending['version'] ?? '')->firstOrFail(),
                    $hash,
                ),
                default => abort(422),
            };
        } catch (ValidationException $exception) {
            $this->showValidation($exception, 'operation');

            return;
        }

        $this->notice = match ($action) {
            'restore' => 'Version '.$this->pending['revision'].' wurde als Entwurf wiederhergestellt. Die Freigabe bleibt unverändert.',
            'default' => 'Die Outlook-Standardvorlage wurde geändert. Systemmails bleiben unverändert.',
            'withdraw' => 'Die Vorlage wird Mitarbeitenden nicht mehr zur Auswahl angeboten.',
            default => $this->pending['library'] === 'true'
                ? 'Die geprüfte Vorlage ist jetzt für Mitarbeitende in Outlook freigegeben.'
                : 'Der geprüfte Stand wird jetzt für Systemmails verwendet.',
        };
        $this->pending = [];
        $this->confirmOpen = false;
        $this->dispatch('mail-document-library-changed');
    }

    public function render(): View
    {
        $this->admin();
        $ready = Schema::hasTable('mail_documents');
        $libraryReady = $ready && app(OutlookTemplateLibrary::class)->available();
        $historyReady = $ready && Schema::hasTable('mail_document_versions');
        $documents = $ready ? $this->readDocuments($libraryReady, $historyReady) : collect();
        $kind = MailDocumentKind::tryFrom($this->kind) ?? MailDocumentKind::Template;
        $query = mb_strtolower(trim(mb_substr($this->search, 0, 120)));
        $visible = $documents->filter(fn (array $document): bool => $document['kind'] === $kind->value
            && ($query === '' || str_contains(mb_strtolower($document['name']), $query))
            && match ($this->filter) {
                'draft' => $document['has_changes'],
                'released' => $document['released'],
                'default' => $document['is_default'],
                default => true,
            });
        $history = collect();
        if ($historyReady && $this->historyId !== null && $visible->contains('id', $this->historyId)) {
            $selected = MailDocument::query()->where('public_id', $this->historyId)->firstOrFail(['id']);
            $history = $selected->versions()->with('creator:id,name')
                ->limit(40)->get(['id', 'public_id', 'mail_document_id', 'revision', 'action', 'content_hash', 'was_published', 'created_by', 'created_at']);
        }

        return view('livewire.admin.mail-document-library', [
            'ready' => $ready,
            'libraryReady' => $libraryReady,
            'historyReady' => $historyReady,
            'documents' => $visible->values(),
            'history' => $history,
            'currentKind' => $kind,
            'kindCounts' => $documents->countBy('kind'),
            'filterLabel' => match ($this->filter) {
                'draft' => 'Mit Entwurf', 'released' => 'Freigegeben', 'default' => 'Standard', default => 'Alle Stände',
            },
        ]);
    }

    /** List metadata only: no image catalog, full HTML or builder payload in Livewire state. */
    private function readDocuments(bool $libraryReady, bool $historyReady): Collection
    {
        $columns = ['id', 'public_id', 'kind', 'name', 'status', 'version', 'content_hash', 'published_at', 'updated_at', 'updated_by'];
        if (Schema::hasColumn('mail_documents', 'is_active')) {
            $columns[] = 'is_active';
        }
        if ($libraryReady) {
            array_push($columns, 'is_outlook_template', 'outlook_released', 'outlook_default');
        }
        $query = MailDocument::query()->select($columns)->with('updater:id,name')
            ->selectRaw("CASE WHEN published_at IS NOT NULL AND TRIM(COALESCE(published_html, '')) <> '' THEN 1 ELSE 0 END AS library_has_release")
            ->selectRaw("CASE WHEN TRIM(COALESCE(html, '')) <> TRIM(COALESCE(published_html, '')) OR TRIM(COALESCE(css, '')) <> TRIM(COALESCE(published_css, '')) THEN 1 ELSE 0 END AS library_has_changes")
            ->when($historyReady, fn ($query) => $query->withCount('versions'))
            ->orderBy('name')->orderBy('id');

        return $query->get()->map(static function (MailDocument $document): array {
            $library = (bool) $document->getAttribute('is_outlook_template');
            $released = (bool) $document->getAttribute('library_has_release')
                && (! $library || (bool) $document->getAttribute('outlook_released'));
            $default = $library ? (bool) $document->getAttribute('outlook_default') : $document->isActive();

            return [
                'id' => $document->public_id,
                'name' => (string) ($document->name ?: $document->kind->label()),
                'kind' => $document->kind->value,
                'library' => $library,
                'released' => $released,
                'is_default' => $default,
                'has_changes' => ! $released || (bool) $document->getAttribute('library_has_changes'),
                'hash' => (string) $document->content_hash,
                'version' => (int) $document->version,
                'versions_count' => (int) ($document->versions_count ?? 0),
                'updated' => $document->updated_at?->format('d.m.Y · H:i'),
                'updater' => $document->updater?->name,
                'editor_url' => route('admin.mail-documents.editor', ['dokument' => $document->kind->value, 'slot' => $document->public_id, 'open' => 1]),
                'preview_url' => route('admin.mail-documents.preview', [$document->public_id, 'theme' => 'light', 'static' => 1]),
            ];
        })->sortByDesc('is_default')->values();
    }

    private function document(string $id): MailDocument
    {
        return MailDocument::query()->where('public_id', $id)->firstOrFail();
    }

    private function assertCurrent(MailDocument $document, string $hash): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $hash) || ! $document->matchesContentHash($hash)) {
            throw ValidationException::withMessages(['operation' => 'Dieser Entwurf wurde inzwischen geändert. Bitte lade die Übersicht neu.']);
        }
    }

    private function showValidation(ValidationException $exception, string $field): void
    {
        $this->addError($field, collect($exception->errors())->flatten()->unique()->implode(' '));
    }

    private function admin(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);

        return $actor;
    }
}
