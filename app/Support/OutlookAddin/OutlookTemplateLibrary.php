<?php

namespace App\Support\OutlookAddin;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Admin\MailDocumentController;
use App\Models\MailDocument;
use App\Models\MailDocumentVersion;
use App\Models\User;
use App\Support\Mail\MailDocumentAutoRepair;
use App\Support\Mail\MailDocumentVersionStore;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Outlook releases never activate or overwrite the system-message shell. */
final class OutlookTemplateLibrary
{
    public function available(): bool
    {
        return Schema::hasTable('mail_documents')
            && Schema::hasColumn('mail_documents', 'is_outlook_template')
            && Schema::hasColumn('mail_documents', 'outlook_released')
            && Schema::hasColumn('mail_documents', 'outlook_default');
    }

    public function createDraft(User $actor, string $name, ?MailDocument $source = null, ?string $expectedHash = null): MailDocument
    {
        $this->authorize($actor);
        $name = $this->name($name);

        return DB::transaction(function () use ($actor, $name, $source, $expectedHash): MailDocument {
            $slots = MailDocument::query()->where('kind', MailDocumentKind::Template->value)
                ->orderBy('id')->lockForUpdate()->get();
            if ($slots->contains(fn (MailDocument $slot): bool => mb_strtolower(trim((string) $slot->name)) === mb_strtolower($name))) {
                throw ValidationException::withMessages(['name' => 'Eine Vorlage mit diesem Namen existiert bereits.']);
            }

            if ($source !== null) {
                $source = $slots->firstWhere('id', $source->getKey());
                abort_unless($source instanceof MailDocument, 404);
                $this->assertHash($source, (string) $expectedHash);
                $html = (string) $source->html;
                $css = (string) $source->css;
                $builderData = $source->builder_data ?: [];
            } else {
                $html = trim((string) file_get_contents(resource_path('mail-templates/email-master.html')));
                $css = '';
                $builderData = MailDocumentAutoRepair::synchronizeBuilderData(MailDocumentKind::Template, [], $html, $name);
            }

            $document = MailDocument::query()->create([
                'kind' => MailDocumentKind::Template,
                'name' => $name,
                'status' => MailDocumentStatus::Draft,
                'is_active' => null,
                'is_outlook_template' => true,
                'outlook_released' => false,
                'outlook_default' => null,
                'builder_data' => $builderData,
                'html' => $html,
                'css' => $css,
                'published_html' => null,
                'published_css' => null,
                'published_at' => null,
                'content_hash' => MailDocument::contentHashFor($builderData, $html, $css),
                'version' => 1,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            app(MailDocumentVersionStore::class)->capture($document, $actor, 'created');

            return $document;
        });
    }

    public function publish(User $actor, MailDocument $document, string $expectedHash): MailDocument
    {
        abort_unless($actor->isAdmin(), 403);
        app(MailDocumentController::class)->publishForActor($actor, $document, $expectedHash);

        return $document->fresh();
    }

    public function duplicateDraft(User $actor, MailDocument $source, string $name, string $expectedHash): MailDocument
    {
        abort_unless($actor->isAdmin(), 403);
        // A template copied from the overview belongs to the Outlook folder,
        // even if its source is the current system-message shell.
        if ($source->kind === MailDocumentKind::Template) {
            return $this->createDraft($actor, $name, $source, $expectedHash);
        }

        $response = app(MailDocumentController::class)->duplicateForActor($actor, $source, $name, $expectedHash);

        return MailDocument::query()->where('public_id', $response->getData(true)['document']['id'])->firstOrFail();
    }

    public function restoreDraft(User $actor, MailDocument $document, MailDocumentVersion $version, string $expectedHash): MailDocument
    {
        abort_unless($actor->isAdmin(), 403);
        app(MailDocumentController::class)->restoreForActor($actor, $document, $version, $expectedHash);

        return $document->fresh();
    }

    public function setDefault(User $actor, MailDocument $document, string $expectedHash): MailDocument
    {
        $this->authorize($actor);
        $updated = DB::transaction(function () use ($actor, $document, $expectedHash): MailDocument {
            $locked = $this->lockTemplates($document);
            $this->assertHash($locked, $expectedHash);
            if (! $locked->isOutlookTemplate() || ! $locked->isPublished()) {
                throw ValidationException::withMessages(['document' => 'Nur eine für Outlook veröffentlichte Vorlage kann Standard werden.']);
            }

            MailDocument::query()->where('outlook_default', true)->update(['outlook_default' => null]);
            $locked->forceFill(['outlook_default' => true, 'updated_by' => $actor->getKey()])->save();
            app(MailDocumentVersionStore::class)->capture($locked, $actor, 'outlook_default');

            return $locked;
        });
        $this->refreshSnapshots();

        return $updated;
    }

    public function withdraw(User $actor, MailDocument $document, string $expectedHash): MailDocument
    {
        $this->authorize($actor);
        $updated = DB::transaction(function () use ($actor, $document, $expectedHash): MailDocument {
            $locked = $this->lockTemplates($document);
            $this->assertHash($locked, $expectedHash);
            abort_unless($locked->isOutlookTemplate(), 422);
            $locked->forceFill([
                'outlook_released' => false,
                'outlook_default' => null,
                'status' => MailDocumentStatus::Draft,
                'updated_by' => $actor->getKey(),
            ])->save();
            app(MailDocumentVersionStore::class)->capture($locked, $actor, 'withdrawn');

            return $locked;
        });
        $this->refreshSnapshots();

        return $updated;
    }

    private function lockTemplates(MailDocument $document): MailDocument
    {
        $locked = MailDocument::query()->where('kind', MailDocumentKind::Template->value)
            ->orderBy('id')->lockForUpdate()->get()->firstWhere('id', $document->getKey());
        abort_unless($locked instanceof MailDocument, 404);

        return $locked;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);
        if (! $this->available()) {
            throw ValidationException::withMessages(['library' => 'Der Outlook-Vorlagenordner benötigt die aktuelle Datenbankmigration.']);
        }
    }

    private function name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        validator(['name' => $name], ['name' => ['required', 'string', 'max:80']])->validate();

        return $name;
    }

    private function assertHash(MailDocument $document, string $expectedHash): void
    {
        if (! $document->matchesContentHash($expectedHash)) {
            throw ValidationException::withMessages(['expected_hash' => 'Der Entwurf wurde zwischenzeitlich geändert. Bitte lade die Übersicht neu.']);
        }
    }

    private function refreshSnapshots(): void
    {
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Template);
        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleAll();
    }
}
