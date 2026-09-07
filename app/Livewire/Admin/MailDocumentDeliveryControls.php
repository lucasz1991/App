<?php

namespace App\Livewire\Admin;

use App\Models\MailDocument;
use App\Support\Mail\MailDocumentDelivery;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class MailDocumentDeliveryControls extends Component
{
    #[Locked]
    public string $documentId;

    public bool $open = false;

    #[Locked]
    public array $pending = [];

    public string $notice = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[On('mail-delivery-changed')]
    public function refreshStatus(): void {}

    public function prepare(string $action, string $hash, string $state): void
    {
        abort_unless(array_key_exists($action, self::actions()), 422);
        $this->resetValidation();
        $this->notice = '';
        $this->pending = compact('action', 'hash', 'state');
        $this->open = true;
    }

    public function confirm(MailDocumentDelivery $delivery): void
    {
        abort_unless($this->open && isset($this->pending['action']), 403);
        try {
            $delivery->change(auth()->user(), $this->document(), $this->pending['action'], $this->pending['hash'], $this->pending['state']);
        } catch (ValidationException $exception) {
            $this->addError('delivery', collect($exception->errors())->flatten()->implode(' '));
            return;
        }
        $this->notice = 'Zuordnung gespeichert. Bereits eingefügte E-Mails bleiben unverändert.';
        $this->pending = [];
        $this->open = false;
        $this->dispatch('mail-delivery-changed');
    }

    public static function actions(): array
    {
        return [
            'system' => ['Als Systemmail-Standard', 'Nur die veröffentlichte Fassung wird Standard für App-Systemmails. Outlook-Standard und Mitarbeiterauswahl bleiben unverändert.'],
            'outlook' => ['Als Outlook-Standard', 'Nur die veröffentlichte Fassung wird im zugehörigen Firmenpostfach automatisch verwendet. Der Systemmail-Standard bleibt unverändert.'],
            'outlook-off' => ['Outlook-Standard aufheben', 'Keine automatische Nachrichtenvorlage mehr. Die Signatur und die manuelle Vorlagenauswahl bleiben erhalten.'],
            'offer' => ['Im Add-in verfügbar machen', 'Mitarbeitende können die veröffentlichte Vorlage im RailTime-Add-in auswählen. Kein Standard wird geändert.'],
            'hide' => ['Im Add-in ausblenden', 'Die Vorlage verschwindet aus der Mitarbeiterauswahl. Systemmails, Entwurf und bereits eingefügte Inhalte bleiben erhalten.'],
        ];
    }

    private function document(): MailDocument
    {
        return MailDocument::query()->where('public_id', $this->documentId)->firstOrFail();
    }

    public function render()
    {
        $document = $this->document();
        return view('livewire.admin.mail-document-delivery-controls', [
            'document' => $document,
            'token' => app(MailDocumentDelivery::class)->token($document->kind),
            'actions' => self::actions(),
            'isTemplate' => $document->kind->value === 'template',
        ]);
    }
}
