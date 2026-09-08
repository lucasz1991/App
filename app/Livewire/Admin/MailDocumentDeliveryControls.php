<?php

namespace App\Livewire\Admin;

use App\Models\MailDocument;
use App\Support\Mail\MailDocumentDelivery;
use App\Support\OutlookAddin\OutlookTemplateLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class MailDocumentDeliveryControls extends Component
{
    #[Locked]
    public string $documentId;

    #[Locked]
    public string $presentation = 'full';

    public bool $open = false;

    #[Locked]
    public array $pending = [];

    public string $notice = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(): void
    {
        abort_unless(in_array($this->presentation, ['badges', 'menu', 'full'], true), 422);
    }

    #[On('mail-delivery-changed')]
    #[On('mail-document-library-changed')]
    public function refreshStatus(): void {}

    #[On('mail-editor-delivery-refresh')]
    public function refreshEditorStatus(string $documentId): void
    {
        if ($documentId !== $this->documentId) {
            $this->skipRender();
        }
    }

    public function prepare(string $action, string $hash, string $state): void
    {
        abort_unless($action !== 'publish' && array_key_exists($action, self::actions()), 422);
        $this->prepareConfirmation($action, $hash, $state);
    }

    public function preparePublish(string $hash, string $state): void
    {
        abort_unless(MailDocumentDelivery::available(), 409);
        $this->prepareConfirmation('publish', $hash, $state);
    }

    private function prepareConfirmation(string $action, string $hash, string $state): void
    {
        $this->resetValidation();
        $this->notice = '';
        $this->pending = compact('action', 'hash', 'state');
        $this->open = true;
    }

    public function confirm(MailDocumentDelivery $delivery): void
    {
        abort_unless($this->open && isset($this->pending['action']), 403);
        $publishing = $this->pending['action'] === 'publish';
        try {
            if ($publishing) {
                $this->publishSavedDraft($delivery);
            } else {
                $delivery->change(auth()->user(), $this->document(), $this->pending['action'], $this->pending['hash'], $this->pending['state']);
            }
        } catch (ValidationException $exception) {
            $this->addError('delivery', collect($exception->errors())->flatten()->implode(' '));

            return;
        }
        $this->notice = $publishing
            ? 'Gespeicherter Stand veröffentlicht. Die Verwendungsbereiche bleiben unverändert.'
            : 'Zuordnung gespeichert. Bereits eingefügte E-Mails bleiben unverändert.';
        $this->pending = [];
        $this->open = false;
        $this->dispatch('mail-delivery-changed');
        if ($publishing) {
            $this->dispatch('mail-document-library-changed');
        }
    }

    private function publishSavedDraft(MailDocumentDelivery $delivery): void
    {
        // Without separated delivery channels the legacy publisher may also
        // activate a default. The compact action must never do that implicitly.
        abort_unless(MailDocumentDelivery::available(), 409);
        $document = $this->document();
        DB::transaction(function () use ($document, $delivery): void {
            $locked = MailDocument::query()->where('kind', $document->kind->value)
                ->orderBy('id')->lockForUpdate()->get()->firstWhere('id', $document->id);
            abort_unless($locked instanceof MailDocument, 404);
            if (! $locked->matchesContentHash($this->pending['hash']) || ! hash_equals($delivery->token($locked->kind), $this->pending['state'])) {
                throw ValidationException::withMessages(['delivery' => 'Inhalt oder Zuordnung wurde inzwischen geändert. Bitte neu prüfen und erneut bestätigen.']);
            }
            if (! $locked->hasUnpublishedChanges()) {
                throw ValidationException::withMessages(['delivery' => 'Dieser gespeicherte Stand ist bereits veröffentlicht.']);
            }
            app(OutlookTemplateLibrary::class)->publish(auth()->user(), $locked, $this->pending['hash']);
        });
    }

    public static function actions(): array
    {
        return [
            'system' => ['Als Systemmail-Standard', 'Nur die veröffentlichte Fassung wird Standard für App-Systemmails. Outlook-Standard und Mitarbeiterauswahl bleiben unverändert.'],
            'outlook' => ['Als Outlook-Standard', 'Nur die veröffentlichte Fassung wird im zugehörigen Firmenpostfach automatisch verwendet. Der Systemmail-Standard bleibt unverändert.'],
            'outlook-off' => ['Outlook-Standard aufheben', 'Keine automatische Nachrichtenvorlage mehr. Die Signatur und die manuelle Vorlagenauswahl bleiben erhalten.'],
            'offer' => ['Im Add-in verfügbar machen', 'Mitarbeitende können die veröffentlichte Vorlage im RailTime-Add-in auswählen. Kein Standard wird geändert.'],
            'hide' => ['Im Add-in ausblenden', 'Die Vorlage verschwindet aus der Mitarbeiterauswahl. Systemmails, Entwurf und bereits eingefügte Inhalte bleiben erhalten.'],
            'publish' => ['Gespeicherten Stand veröffentlichen', 'Die zuletzt gespeicherte Fassung wird geprüft und veröffentlicht. Bestehende Zuordnungen nutzen danach diesen Stand; es wird kein neuer Standard oder Verwendungsbereich ausgewählt.'],
        ];
    }

    private function availableActions(MailDocument $document): array
    {
        if (! $document->isPublished()) {
            return [];
        }
        $isTemplate = $document->kind->value === 'template';

        return array_filter(self::actions(), fn (string $action): bool => ($action === 'system' && ! $document->isActive())
            || ($action === 'outlook' && ! $document->outlook_default && (! $isTemplate || $document->outlook_released))
            || ($action === 'outlook-off' && $isTemplate && $document->outlook_default)
            || ($action === 'offer' && $isTemplate && ! $document->outlook_released)
            || ($action === 'hide' && $isTemplate && $document->outlook_released && ! $document->outlook_default), ARRAY_FILTER_USE_KEY);
    }

    private function badges(MailDocument $document, array $availableActions): array
    {
        $isTemplate = $document->kind->value === 'template';
        $unpublished = $document->hasUnpublishedChanges();
        $outlookAction = $document->outlook_default ? 'outlook-off' : 'outlook';
        $addinAction = $document->outlook_released ? 'hide' : 'offer';

        return [
            ['kind' => 'system', 'icon' => 'far fa-server', 'label' => 'Systemmail: '.($document->isActive() ? 'Standard' : 'Nein'), 'state' => $document->isActive() ? 'active' : 'inactive', 'action' => isset($availableActions['system']) ? 'system' : null],
            ['kind' => 'outlook', 'icon' => 'far fa-envelope', 'label' => 'Outlook: '.($document->outlook_default ? 'Standard' : 'Nein'), 'state' => $document->outlook_default ? 'active' : 'inactive', 'action' => isset($availableActions[$outlookAction]) ? $outlookAction : null],
            ['kind' => 'addin', 'icon' => $isTemplate ? 'far fa-puzzle-piece' : 'far fa-signature', 'label' => $isTemplate ? 'Add-in: '.($document->outlook_released ? 'Verfügbar' : 'Ausgeblendet') : ($document->outlook_default ? 'Automatische Mitarbeitersignatur' : 'Nicht im Add-in verwendet'), 'state' => ($isTemplate ? $document->outlook_released : $document->outlook_default) ? 'active' : 'inactive', 'action' => $isTemplate && isset($availableActions[$addinAction]) ? $addinAction : null],
            ['kind' => 'publication', 'icon' => $unpublished ? 'far fa-file-edit' : 'far fa-check-circle', 'label' => $unpublished ? ($document->published_at ? 'Neuer Entwurf' : 'Entwurf') : 'Stand veröffentlicht', 'state' => $unpublished ? 'draft' : 'active', 'action' => $unpublished && MailDocumentDelivery::available() ? 'publish' : null],
        ];
    }

    private function document(): MailDocument
    {
        return MailDocument::query()->where('public_id', $this->documentId)->firstOrFail();
    }

    public function render()
    {
        $document = $this->document();
        $availableActions = $this->availableActions($document);

        return view('livewire.admin.mail-document-delivery-controls', [
            'document' => $document,
            'token' => app(MailDocumentDelivery::class)->token($document->kind),
            'actions' => self::actions(),
            'availableActions' => $availableActions,
            'badges' => $this->badges($document, $availableActions),
            'isTemplate' => $document->kind->value === 'template',
        ]);
    }
}
