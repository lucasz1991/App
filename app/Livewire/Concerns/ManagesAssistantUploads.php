<?php

namespace App\Livewire\Concerns;

use App\Services\Ai\Attachments\AssistantAttachmentException;
use App\Services\Ai\Attachments\AssistantAttachmentProcessor;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Gemeinsame Ablage der temporären Anhänge für die AI-Komponenten
 * (AI-Assist, AI-Annahme-Assistent, AI-Eingang).
 *
 * Erwartet in der Komponente: WithFileUploads, public array $uploads,
 * public $audioUpload und eine Methode actor(), die den frischen,
 * berechtigten Akteur liefert (oder abbricht).
 *
 * Livewire-Hooks (updatedUploads/updatedAudioUpload) bleiben bewusst in den
 * Komponenten, damit Komponenten ohne Live-Prüfung (AI-Eingang) keine
 * zusätzliche Prüfung erben; sie rufen validateAssistantUploads() bzw.
 * validateAssistantAudioUpload() auf.
 */
trait ManagesAssistantUploads
{
    protected const ASSISTANT_UPLOAD_MAX_FILES = 3;

    protected const ASSISTANT_UPLOAD_MAX_KB = 10240;

    protected const ASSISTANT_AUDIO_MAX_KB = 8192;

    /** @return array{uploads: list<string>, 'uploads.*': list<string>, audioUpload: list<string>} */
    protected function assistantUploadRules(): array
    {
        return [
            'uploads' => ['array', 'max:'.self::ASSISTANT_UPLOAD_MAX_FILES],
            'uploads.*' => ['file', 'max:'.self::ASSISTANT_UPLOAD_MAX_KB],
            'audioUpload' => ['nullable', 'file', 'max:'.self::ASSISTANT_AUDIO_MAX_KB],
        ];
    }

    /** Hook: false verwirft die Auswahl (z. B. weil der Kontext gewechselt hat) und überspringt die Prüfung. */
    protected function assistantUploadsCurrent(): bool
    {
        return true;
    }

    /** Hook: Dateien, die der Anhang-Prozessor prüfen soll (Standard: alle Dateien). */
    protected function assistantDocumentUploads(): array
    {
        return array_values($this->uploads);
    }

    /** Live-Prüfung nach der Dateiauswahl. */
    protected function validateAssistantUploads(): void
    {
        $this->actor();
        $this->resetValidation(['uploads', 'uploads.*']);
        if (! $this->assistantUploadsCurrent()) {
            return;
        }
        $rules = $this->assistantUploadRules();
        $this->validate(['uploads' => $rules['uploads'], 'uploads.*' => $rules['uploads.*']]);
        try {
            app(AssistantAttachmentProcessor::class)->validate($this->assistantDocumentUploads());
        } catch (AssistantAttachmentException $error) {
            $this->addError('uploads', $error->userMessage);
        }
    }

    /** Live-Prüfung nach einer Sprachaufnahme. */
    protected function validateAssistantAudioUpload(): void
    {
        $this->actor();
        $this->resetValidation('audioUpload');
        if (! $this->assistantUploadsCurrent()) {
            return;
        }
        $this->validate(['audioUpload' => $this->assistantUploadRules()['audioUpload']]);
    }

    /**
     * Entfernt eine Datei; ein bereits fehlender Index ist kein Fehler (doppelter Klick, verworfene Auswahl).
     * Werte, die keine temporäre Datei sind (manipulierte Anfrage), werden ohne Löschversuch verworfen,
     * damit die Ansicht nicht dauerhaft an ihnen scheitert.
     */
    public function removeUpload(int $index): void
    {
        $this->actor();
        if ($index >= 0 && array_key_exists($index, $this->uploads)) {
            if ($this->uploads[$index] instanceof TemporaryUploadedFile && ! $this->uploads[$index]->delete()) {
                $this->addError('uploads', 'Die temporäre Datei konnte nicht entfernt werden. Bitte erneut versuchen.');

                return;
            }
            unset($this->uploads[$index]);
            $this->uploads = array_values($this->uploads);
        }
        $this->resetValidation(['uploads', 'uploads.*']);
    }

    public function removeAudio(): void
    {
        $this->actor();
        if (! $this->forgetAssistantAudio()) {
            return;
        }
        $this->resetValidation('audioUpload');
        $this->dispatch('ai-intake-composer-audio-removed', componentId: $this->getId());
    }

    /** Verwirft alle temporären Dateien und die Aufnahme; nur echte, nicht löschbare Dateien bleiben mit Fehlermeldung stehen. */
    protected function discardAssistantUploads(): void
    {
        $remaining = [];
        foreach ($this->uploads as $file) {
            if ($file instanceof TemporaryUploadedFile && ! $file->delete()) {
                $remaining[] = $file;
            }
        }
        $this->uploads = $remaining;
        if ($remaining !== []) {
            $this->addError('uploads', 'Eine temporäre Datei konnte nicht entfernt werden. Bitte erneut entfernen.');
        }
        $this->forgetAssistantAudio();
    }

    /** @return bool true, wenn keine Aufnahme mehr anhängt */
    protected function forgetAssistantAudio(): bool
    {
        if ($this->audioUpload instanceof TemporaryUploadedFile && ! $this->audioUpload->delete()) {
            $this->addError('audioUpload', 'Die temporäre Aufnahme konnte nicht entfernt werden. Bitte erneut versuchen.');

            return false;
        }
        $this->audioUpload = null;

        return true;
    }
}
