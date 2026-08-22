<?php

namespace App\Support\Mail;

use App\Models\MailDocument;
use App\Models\MailDocumentVersion;
use App\Models\User;

/** Schreibt unveraenderliche Entwurfs-/Freigabe-Snapshots innerhalb der laufenden DB-Transaktion. */
final class MailDocumentVersionStore
{
    public function capture(MailDocument $document, ?User $actor, string $action): MailDocumentVersion
    {
        $revision = ((int) MailDocumentVersion::query()
            ->where('mail_document_id', $document->getKey())
            ->lockForUpdate()
            ->max('revision')) + 1;

        return MailDocumentVersion::query()->create([
            'mail_document_id' => $document->getKey(),
            'revision' => $revision,
            'action' => $action,
            'builder_data' => $document->builder_data ?: [],
            'html' => (string) $document->html,
            'css' => (string) $document->css,
            'content_hash' => (string) $document->content_hash,
            'was_published' => $document->isPublished() && ! $document->hasUnpublishedChanges(),
            'created_by' => $actor?->getKey(),
        ]);
    }
}
