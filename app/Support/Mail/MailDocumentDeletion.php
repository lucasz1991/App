<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Deletes an unused mail design without weakening delivery invariants. */
final class MailDocumentDeletion
{
    public function delete(MailDocument $document, string $expectedHash): MailDocument
    {
        $fallback = DB::transaction(function () use ($document, $expectedHash): MailDocument {
            $slots = MailDocument::query()
                ->where('kind', $document->kind->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $slots->firstWhere($document->getKeyName(), $document->getKey());
            abort_unless($locked instanceof MailDocument, 404);

            if (! $locked->matchesContentHash($expectedHash)) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'Der Entwurf wurde zwischenzeitlich geändert. Bitte lade die Übersicht neu.',
                ]);
            }
            if ($locked->isActive()) {
                throw ValidationException::withMessages([
                    'slot' => 'Das aktive, veröffentlichte Design kann nicht gelöscht werden. Aktiviere zuerst einen anderen Entwurf.',
                ]);
            }
            if ($locked->kind === MailDocumentKind::Signature && $locked->outlook_default) {
                throw ValidationException::withMessages([
                    'slot' => 'Bitte wähle zuerst eine andere Outlook-Standardsignatur.',
                ]);
            }
            if ($slots->count() <= 1) {
                throw ValidationException::withMessages([
                    'slot' => 'Die letzte Vorlage oder Signatur dieser Dokumentart kann nicht gelöscht werden.',
                ]);
            }
            if ($locked->kind === MailDocumentKind::Signature
                && MailDocumentSignatureResolver::available()
                && MailDocument::query()->where(static fn ($query) => $query
                    ->where('signature_document_id', $locked->getKey())
                    ->orWhere('published_signature_document_id', $locked->getKey()))->exists()) {
                throw ValidationException::withMessages([
                    'slot' => 'Diese Signatur ist noch einer Vorlage zugeordnet. Löse die Zuordnung zuerst in der Vorlagenübersicht.',
                ]);
            }

            $fallback = $slots->first(fn (MailDocument $slot): bool => $slot->getKey() !== $locked->getKey() && $slot->isActive())
                ?? $slots->first(fn (MailDocument $slot): bool => $slot->getKey() !== $locked->getKey());
            abort_unless($fallback instanceof MailDocument, 409);
            // Deleting the row atomically removes its Outlook release and
            // default assignment too; a separate withdrawal must not commit
            // before the remaining deletion guards have succeeded.
            $locked->delete();

            return $fallback;
        });

        app(PublishedMailDocumentSnapshotStore::class)->forget($document->kind);
        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleAll();

        return $fallback;
    }
}
