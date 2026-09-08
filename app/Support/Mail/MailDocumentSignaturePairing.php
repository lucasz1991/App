<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Changes only a template draft's explicit signature assignment. */
final class MailDocumentSignaturePairing
{
    public function assign(
        User $actor,
        MailDocument $template,
        ?string $signaturePublicId,
        string $expectedHash,
    ): MailDocument {
        abort_unless($actor->isAdmin(), 403);

        if (! MailDocumentSignatureResolver::available()) {
            throw ValidationException::withMessages([
                'signature' => 'Die Signaturzuordnung benötigt die aktuelle Datenbankmigration.',
            ]);
        }

        return DB::transaction(function () use ($actor, $template, $signaturePublicId, $expectedHash): MailDocument {
            $locked = MailDocument::query()->lockForUpdate()->findOrFail($template->getKey());
            if ($locked->kind !== MailDocumentKind::Template) {
                throw ValidationException::withMessages([
                    'signature' => 'Nur E-Mail-Vorlagen können mit einer Signatur verbunden werden.',
                ]);
            }
            if (! preg_match('/^[a-f0-9]{64}$/i', $expectedHash) || ! $locked->matchesContentHash($expectedHash)) {
                throw ValidationException::withMessages([
                    'signature' => 'Der Entwurf wurde zwischenzeitlich geändert. Bitte lade die Übersicht neu.',
                ]);
            }

            $signaturePublicId = trim((string) $signaturePublicId);
            $signature = $signaturePublicId === ''
                ? null
                : MailDocument::query()
                    ->where('public_id', $signaturePublicId)
                    ->lockForUpdate()
                    ->first();

            if ($signaturePublicId !== '' && ! $signature instanceof MailDocument) {
                throw ValidationException::withMessages([
                    'signature' => 'Die gewählte Signatur ist nicht mehr vorhanden.',
                ]);
            }

            try {
                app(MailDocumentSignatureResolver::class)->assertAssignable($locked, $signature);
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['signature' => $exception->getMessage()]);
            }

            $signatureId = $signature?->getKey();
            if ($locked->signature_document_id === $signatureId) {
                return $locked;
            }

            $hash = MailDocument::contentHashFor(
                $locked->builder_data ?: [],
                (string) $locked->html,
                (string) $locked->css,
                $signatureId,
            );

            $locked->forceFill([
                'signature_document_id' => $signatureId,
                'content_hash' => $hash,
                'version' => $locked->version + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            app(MailDocumentVersionStore::class)->capture(
                $locked,
                $actor,
                $signature === null ? 'signature_cleared' : 'signature_assigned',
            );

            return $locked->refresh();
        });
    }
}
