<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Read-only pairing resolution; never changes a default or publishes a draft. */
final class MailDocumentSignatureResolver
{
    public static function available(): bool
    {
        return Schema::hasTable('mail_documents')
            && Schema::hasColumn('mail_documents', 'signature_document_id')
            && Schema::hasColumn('mail_documents', 'published_signature_document_id');
    }

    public function assertAssignable(MailDocument $template, ?MailDocument $signature): void
    {
        if ($template->kind !== MailDocumentKind::Template) {
            throw new RuntimeException('Nur Nachrichtenvorlagen dürfen eine Signaturzuordnung besitzen.');
        }
        if ($signature !== null && ($signature->kind !== MailDocumentKind::Signature
            || ! $signature->exists || (int) $signature->getKey() < 1)) {
            throw new RuntimeException('Die gewählte Zuordnung ist keine gespeicherte Signatur.');
        }
    }

    public function draftDocument(MailDocument $document): ?MailDocument
    {
        if ($document->kind === MailDocumentKind::Signature) {
            return $document;
        }

        $this->assertAssignable($document, null);
        if ($document->signature_document_id !== null) {
            return $this->explicitDocument($document, $document->signature_document_id);
        }

        return MailDocument::query()->where('kind', MailDocumentKind::Signature->value)
            ->active()->first()
            ?? MailDocument::query()->where('kind', MailDocumentKind::Signature->value)
                ->orderBy('id')->first();
    }

    /** @return array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool}|null */
    public function draftSnapshot(MailDocument $document): ?array
    {
        $signature = $this->draftDocument($document);
        if ($signature === null) {
            return null;
        }

        $paired = $document->kind === MailDocumentKind::Template && $document->signature_document_id !== null;
        // Only an explicitly paired template previews the partner's draft.
        // Unpaired templates retain the existing published/setup fallback.
        $published = $document->kind === MailDocumentKind::Template && ! $paired
            && trim((string) $signature->published_html) !== '';

        return $this->snapshot($signature, $published, $paired);
    }

    /** @return array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool}|null */
    public function publishedSnapshot(MailDocument $document, string $channel = 'system'): ?array
    {
        if (! in_array($channel, ['system', 'outlook'], true)) {
            throw new RuntimeException('Der Mail-Ausgabekanal ist ungültig.');
        }

        $this->assertAssignable($document, null);
        if ($document->published_signature_document_id !== null) {
            $signature = $this->explicitDocument($document, $document->published_signature_document_id);
            if ($signature->published_at === null || trim((string) $signature->published_html) === '') {
                throw new RuntimeException('Die zugeordnete Signatur besitzt noch keinen veröffentlichten Stand.');
            }

            return $this->snapshot($signature, published: true, paired: true);
        }

        $query = MailDocument::query()->where('kind', MailDocumentKind::Signature->value);
        $signature = $channel === 'outlook' && MailDocumentDelivery::available()
            ? $query->withPublishedSnapshot()->where('outlook_default', true)->first()
            : $query->published()->first();

        return $signature === null ? null : $this->snapshot($signature, published: true, paired: false);
    }

    private function explicitDocument(MailDocument $template, int $id): MailDocument
    {
        $signature = $id > 0 ? MailDocument::query()->find($id) : null;
        if ($signature === null) {
            throw new RuntimeException('Die ausdrücklich zugeordnete Signatur ist nicht mehr vorhanden.');
        }
        $this->assertAssignable($template, $signature);

        return $signature;
    }

    /** @return array{id:string,document_id:int,html:string,css:string,hash:string,paired:bool} */
    private function snapshot(MailDocument $signature, bool $published, bool $paired): array
    {
        $html = trim((string) ($published ? $signature->published_html : $signature->html));
        $css = trim((string) ($published ? $signature->published_css : $signature->css));
        $id = (string) $signature->public_id;

        return [
            'id' => $id,
            'document_id' => (int) $signature->getKey(),
            'html' => $html,
            'css' => $css,
            'hash' => hash('sha256', json_encode([$id, $html, $css], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'paired' => $paired,
        ];
    }
}
