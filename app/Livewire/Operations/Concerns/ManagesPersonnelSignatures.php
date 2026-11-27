<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\PersonnelDocumentTemplate;
use App\Models\PersonnelSignatureRequest;
use App\Models\User;
use App\Services\Operations\PersonnelEnhancementService;
use App\Support\Exports\CsvDownload;

/**
 * PersonnelEnhancements – Fachbereich Unterlagen & Unterschriften:
 * Dokumentvorlagen laden, Unterzeichnungen prüfen und Dateien ausliefern.
 */
trait ManagesPersonnelSignatures
{
    public function downloadDocumentTemplate(int $id)
    {
        $this->access();
        $this->authorizeProfileAction('document_download');
        abort_if($this->personal, 403);
        $text = app(PersonnelEnhancementService::class)->renderDocument(PersonnelDocumentTemplate::findOrFail($id), User::findOrFail($this->userId), auth()->user());

        return CsvDownload::streamContent($text, 'Personalvorlage.txt', CsvDownload::TEXT);
    }

    public function reviewSignature(int $id, int $revision, bool $accept): void
    {
        $this->access();
        $this->authorizeProfileAction('signature_review');
        abort_if($this->personal, 403);
        $record = PersonnelSignatureRequest::findOrFail($id);
        $this->authorizeProfileRecord($record);
        app(PersonnelEnhancementService::class)->reviewSignatureResult($record, $revision, $accept, auth()->user());
    }

    public function downloadSignature(int $id)
    {
        $this->access();
        $this->authorizeProfileAction('signature_download');
        $record = PersonnelSignatureRequest::findOrFail($id);
        $this->authorizeProfileRecord($record);

        return app(PersonnelEnhancementService::class)->signatureResult($record, auth()->user());
    }

    public function downloadSignatureSource(int $id)
    {
        $this->access();
        $this->authorizeProfileAction('signature_download');
        $record = PersonnelSignatureRequest::findOrFail($id);
        $this->authorizeProfileRecord($record);

        return app(PersonnelEnhancementService::class)->signatureSource($record, auth()->user());
    }
}
