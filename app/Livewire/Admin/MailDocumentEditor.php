<?php

namespace App\Livewire\Admin;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
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
            'editorPreviewSources' => $current === null ? [] : [
                'light' => [
                    'label' => 'Hell',
                    'url' => route('admin.mail-documents.preview', [$current, 'theme' => 'light']),
                    'width' => 1024,
                    'height' => $current->kind === MailDocumentKind::Signature ? 620 : 820,
                ],
                'dark' => [
                    'label' => 'Dunkel',
                    'url' => route('admin.mail-documents.preview', [$current, 'theme' => 'dark']),
                    'width' => 1024,
                    'height' => $current->kind === MailDocumentKind::Signature ? 620 : 820,
                ],
            ],
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
        $contactIcons = EmailTemplateBuilder::contactIconSources(true);

        foreach ($documents as $key => $document) {
            $payload[$key] = [
                'id' => $document->public_id,
                'label' => $document->kind->label(),
                'builderData' => $document->builder_data ?: [],
                'css' => (string) $document->css,
                'contentHash' => (string) $document->content_hash,
                'version' => (int) $document->version,
                'status' => $document->status->value,
                'publishedLabel' => $document->published_at?->translatedFormat('d.m.Y H:i'),
                'hasUnpublishedChanges' => $document->hasUnpublishedChanges(),
                'endpoints' => [
                    'update' => route('admin.mail-documents.update', $document),
                    'publish' => route('admin.mail-documents.publish', $document),
                ],
            ];
        }

        return [
            'currentDocument' => $this->kind,
            'documents' => $payload,
            // Nur fuer das isolierte Editor-iframe: Die gespeicherten
            // {{...}}-Tokens bleiben unangetastet, waehrend Logo, Zug und
            // Kontakticons in Hell und Dunkel trotzdem real dargestellt
            // werden. So kann die Vorschau niemals versehentlich eine
            // lokale data:-URL in die spaetere E-Mail uebernehmen.
            'previewAssets' => [
                'light' => [
                    'logo' => EmailTemplateBuilder::inlineImage('logo-signature-light.png', 'image/png'),
                    'train' => EmailTemplateBuilder::inlineImage('zug-dampf-light.png', 'image/png'),
                ],
                'dark' => [
                    'logo' => EmailTemplateBuilder::inlineImage('logo-mail-dark.png', 'image/png'),
                    'train' => EmailTemplateBuilder::inlineImage('zug-dampf-dark.png', 'image/png'),
                ],
                'icons' => [
                    'location' => $contactIcons['ICON_LOCATION_SRC'] ?? '',
                    'phone' => $contactIcons['ICON_PHONE_SRC'] ?? '',
                    'mobile' => $contactIcons['ICON_MOBILE_SRC'] ?? '',
                    'email' => $contactIcons['ICON_EMAIL_SRC'] ?? '',
                    'web' => $contactIcons['ICON_WEB_SRC'] ?? '',
                ],
            ],
            'vendor' => [
                'builderJs' => asset('vendor/lmz-builder/2.4.5/lmz-builder.js'),
                'builderCss' => asset('vendor/lmz-builder/2.4.5/lmz-builder.css'),
                'grapesJs' => asset('vendor/lmz-builder/2.4.5/grapesjs.js'),
                'grapesCss' => asset('vendor/lmz-builder/2.4.5/grapesjs.css'),
            ],
        ];
    }
}
