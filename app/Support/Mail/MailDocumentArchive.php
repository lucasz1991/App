<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Retires variants without deleting their drafts, published snapshots or history. */
final class MailDocumentArchive
{
    public function archive(User $actor, MailDocument $document, string $expectedHash): MailDocument
    {
        return $this->change($actor, $document, $expectedHash, true);
    }

    public function restore(User $actor, MailDocument $document, string $expectedHash, string $name): MailDocument
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        validator(['name' => $name], ['name' => ['required', 'string', 'max:80']])->validate();

        return $this->change($actor, $document, $expectedHash, false, $name);
    }

    private function change(User $actor, MailDocument $document, string $expectedHash, bool $archive, ?string $name = null): MailDocument
    {
        abort_unless($actor->isAdmin(), 403);
        abort_unless(Schema::hasColumn('mail_documents', 'archived_at'), 409);
        $updated = DB::transaction(function () use ($actor, $document, $expectedHash, $archive, $name): MailDocument {
            $slots = MailDocument::withoutGlobalScope('unarchived')->where('kind', $document->kind->value)
                ->orderBy('id')->lockForUpdate()->get();
            $locked = $slots->firstWhere('id', $document->id);
            abort_unless($locked instanceof MailDocument, 404);
            if (! $locked->matchesContentHash($expectedHash) || $locked->isArchived() === $archive) {
                throw ValidationException::withMessages(['operation' => 'Inhalt oder Archivstatus wurde inzwischen geändert. Bitte neu laden.']);
            }
            if ($archive) {
                if ($locked->isActive() || $locked->outlook_default) {
                    throw ValidationException::withMessages(['operation' => 'Bitte zuerst einen anderen System- und Outlook-Standard auswählen.']);
                }
                if ($slots->filter(fn (MailDocument $slot): bool => ! $slot->isArchived())->count() <= 1) {
                    throw ValidationException::withMessages(['operation' => 'Mindestens ein Dokument dieser Art muss erhalten bleiben.']);
                }
                if ($locked->kind === MailDocumentKind::Signature && MailDocumentSignatureResolver::available()
                    && MailDocument::query()->where(fn ($query) => $query->where('signature_document_id', $locked->id)
                        ->orWhere('published_signature_document_id', $locked->id))->exists()) {
                    throw ValidationException::withMessages(['operation' => 'Diese Signatur gehört noch zu einer nicht archivierten Vorlage. Bitte zuerst die Vorlage ablösen.']);
                }
            } else {
                if ($locked->kind === MailDocumentKind::Template && MailDocumentSignatureResolver::available()) {
                    $partners = array_filter([$locked->signature_document_id, $locked->published_signature_document_id]);
                    $paired = $partners === [] ? collect() : MailDocument::withoutGlobalScope('unarchived')
                        ->whereIn('id', $partners)->orderBy('id')->lockForUpdate()->get(['id', 'kind', 'archived_at']);
                    if ($paired->count() !== count(array_unique($partners)) || $paired->contains(
                        fn (MailDocument $partner): bool => $partner->isArchived() || $partner->kind !== MailDocumentKind::Signature,
                    )) {
                        throw ValidationException::withMessages(['operation' => 'Bitte zuerst die zugeordnete Signatur aus dem Archiv wiederherstellen.']);
                    }
                }
                if ($slots->contains(fn (MailDocument $slot): bool => ! $slot->isArchived()
                    && mb_strtolower(trim((string) $slot->name)) === mb_strtolower((string) $name))) {
                    throw ValidationException::withMessages(['operation' => 'Ein aktuelles Dokument mit diesem Namen existiert bereits. Bitte einen anderen Namen verwenden.']);
                }
            }
            // Restore never silently reactivates an old default or release.
            $locked->forceFill([
                'archived_at' => $archive ? now() : null,
                'is_active' => null,
                'outlook_default' => null,
                'outlook_released' => false,
                'updated_by' => $actor->id,
                ...($archive ? [] : ['name' => $name]),
                ...(MailDocumentDelivery::available() ? ['delivery_revision' => $locked->delivery_revision + 1] : []),
            ])->save();
            app(MailDocumentVersionStore::class)->capture($locked, $actor, $archive ? 'archived' : 'unarchived');

            return $locked;
        });
        app(PublishedMailDocumentSnapshotStore::class)->forget($document->kind);
        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleAll();

        return $updated;
    }
}
