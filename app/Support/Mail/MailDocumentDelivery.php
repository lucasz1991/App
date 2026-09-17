<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Assigns published snapshots, never publishes a pending draft. */
final class MailDocumentDelivery
{
    public static function available(): bool
    {
        return Schema::hasColumn('mail_documents', 'delivery_revision');
    }

    public function token(MailDocumentKind $kind): string
    {
        return hash('sha256', MailDocument::query()->where('kind', $kind->value)->orderBy('id')
            ->get(['id', 'content_hash', 'published_at', 'is_active', 'outlook_released', 'outlook_default', 'delivery_revision'])->toJson());
    }

    public function change(User $actor, MailDocument $document, string $action, string $expectedHash, string $expectedState): void
    {
        abort_unless($actor->isAdmin(), 403);
        abort_unless(self::available(), 409);
        abort_unless(in_array($action, ['system', 'outlook', 'outlook-off', 'offer', 'hide', 'withdraw'], true), 422);

        DB::transaction(function () use ($actor, $document, $action, $expectedHash, $expectedState): void {
            $slots = MailDocument::query()->where('kind', $document->kind->value)->orderBy('id')->lockForUpdate()->get();
            $locked = $slots->firstWhere('id', $document->id);
            abort_unless($locked instanceof MailDocument, 404);
            if (! $locked->matchesContentHash($expectedHash) || ! hash_equals($this->token($locked->kind), $expectedState)) {
                throw ValidationException::withMessages(['delivery' => 'Inhalt oder Zuordnung wurde inzwischen geändert. Bitte neu prüfen und erneut bestätigen.']);
            }
            if ($locked->published_at === null || $locked->publishedHtml() === null) {
                throw ValidationException::withMessages(['delivery' => 'Zuerst einen geprüften Stand veröffentlichen. Ein Entwurf kann nicht zugeordnet werden.']);
            }
            if (in_array($action, ['offer', 'hide', 'withdraw'], true)) {
                abort_unless($locked->kind === MailDocumentKind::Template, 422);
            }
            if ($action === 'hide' && $locked->outlook_default) {
                throw ValidationException::withMessages(['delivery' => 'Zuerst den Outlook-Standard aufheben oder eine andere Vorlage wählen.']);
            }
            if ($action === 'outlook' && $locked->kind === MailDocumentKind::Template && ! $locked->outlook_released) {
                throw ValidationException::withMessages(['delivery' => 'Die Vorlage zuerst für Mitarbeitende im Add-in verfügbar machen.']);
            }
            if ($action === 'outlook-off' && $locked->kind === MailDocumentKind::Signature) {
                throw ValidationException::withMessages(['delivery' => 'Bitte eine andere veröffentlichte Outlook-Signatur auswählen.']);
            }

            $attributes = match ($action) {
                'system' => ['is_active' => true, 'status' => MailDocumentStatus::Published],
                'outlook' => ['outlook_default' => true],
                'outlook-off' => ['outlook_default' => null],
                'offer' => ['outlook_released' => true],
                'hide' => ['outlook_released' => false],
                'withdraw' => ['outlook_released' => false, 'outlook_default' => null],
            };
            if (in_array($action, ['system', 'outlook'], true)) {
                $field = $action === 'system' ? 'is_active' : 'outlook_default';
                foreach ($slots as $other) {
                    if ($other->id !== $locked->id && $other->getAttribute($field)) {
                        $other->forceFill([$field => null, 'delivery_revision' => $other->delivery_revision + 1, 'updated_by' => $actor->id])->save();
                        app(MailDocumentVersionStore::class)->capture($other, $actor, $action.'_replaced');
                    }
                }
            }
            $locked->forceFill($attributes + ['delivery_revision' => $locked->delivery_revision + 1, 'updated_by' => $actor->id])->save();
            app(MailDocumentVersionStore::class)->capture($locked, $actor, 'delivery_'.$action);
        });
        app(PublishedMailDocumentSnapshotStore::class)->forget($document->kind);
        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleAll();
    }
}
