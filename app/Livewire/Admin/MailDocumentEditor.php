<?php

namespace App\Livewire\Admin;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Throwable;

/**
 * Editorseite der beiden Maildokumente (Nachrichtenschale und Signaturblock).
 *
 * Es gibt bewusst keine Uebersichtsseite: mehr als diese zwei Dokumente kann
 * es nicht geben (mail_documents.kind ist unique). Gewechselt wird deshalb
 * ueber ?dokument=template|signature.
 *
 * Die Rollenpruefung steht in mount() UND render(): eine Livewire-Komponente
 * lebt ueber viele Anfragen, und die Rolle kann sich zwischendurch aendern.
 */
class MailDocumentEditor extends Component
{
    public string $kind = MailDocumentKind::Template->value;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $requested = (string) request()->query('dokument', MailDocumentKind::Template->value);
        $this->kind = MailDocumentKind::tryFrom($requested)?->value ?? MailDocumentKind::Template->value;
    }

    public function render()
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $documents = $this->documents();
        $current = $documents[$this->kind] ?? null;

        return view('livewire.admin.mail-document-editor', [
            'currentKind' => $this->kind,
            'documents' => $documents,
            'currentDocument' => $current,
            'editorConfig' => $current === null ? null : $this->editorConfig($documents),
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    /**
     * Die vorhandenen Dokumente, nach Art abgelegt.
     *
     * Fehlt die Tabelle (Migration noch nicht eingespielt), ist das kein
     * Fehlerfall: die Seite erklaert dann, was noch fehlt, statt mit einer
     * Ausnahme abzubrechen.
     *
     * @return array<string, MailDocument>
     */
    private function documents(): array
    {
        try {
            if (! Schema::hasTable('mail_documents')) {
                return [];
            }

            return MailDocument::query()
                ->orderBy('id')
                ->get()
                ->keyBy(fn (MailDocument $document): string => $document->kind->value)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, MailDocument>  $documents
     * @return array<string, mixed>
     */
    private function editorConfig(array $documents): array
    {
        $payload = [];

        foreach ($documents as $key => $document) {
            $published = $document->publishedHtml();

            $payload[$key] = [
                'id' => $document->public_id,
                'label' => $document->kind->label(),
                'builderData' => $document->builder_data ?: [],
                'css' => (string) $document->css,
                'contentHash' => (string) $document->content_hash,
                'version' => (int) $document->version,
                'status' => $document->status->value,
                'publishedLabel' => $document->published_at?->translatedFormat('d.m.Y H:i'),
                'hasUnpublishedChanges' => $published === null
                    || $published !== trim((string) $document->html),
                'endpoints' => [
                    'update' => route('admin.mail-documents.update', $document),
                    'publish' => route('admin.mail-documents.publish', $document),
                ],
            ];
        }

        return [
            'currentDocument' => $this->kind,
            'documents' => $payload,
            'vendor' => [
                'builderJs' => asset('vendor/lmz-builder/2.4.5/lmz-builder.js'),
                'builderCss' => asset('vendor/lmz-builder/2.4.5/lmz-builder.css'),
                'grapesJs' => asset('vendor/lmz-builder/2.4.5/grapesjs.js'),
                'grapesCss' => asset('vendor/lmz-builder/2.4.5/grapesjs.css'),
            ],
        ];
    }
}
