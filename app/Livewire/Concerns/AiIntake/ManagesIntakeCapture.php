<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiIntakeSchema;

/**
 * Erfassung eines neuen Eingangs: Text, Dateien und Sprachaufnahme.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakeCapture
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function actor(): \App\Models\User;
    abstract private function perform(callable $action, bool $reload = true): void;

    public function openCapture(): void
    {
        $this->actor();
        AiIntakeSchema::requireReady();
        $this->captureOpen = true;
        if ($this->embedded) {
            $this->detailOpen = false;
            $this->dispatch('ai-intake-assistant-overview', customerId: $this->customerId, componentId: $this->getId());
        }
    }

    public function closeCapture(): void
    {
        $this->actor();
        $this->captureOpen = false;
        $this->dispatch('ai-intake-capture-closed', componentId: $this->getId());
    }

    public function discardUploads(): void
    {
        $this->actor();
        $this->discardAssistantUploads();
        $this->removeAudio();
    }

    public function submit(): void
    {
        $actor = $this->actor();
        AiIntakeSchema::requireReady();
        $this->validate(['text' => ['nullable', 'string', 'max:20000'], 'contactEmail' => ['nullable', 'email', 'max:254']] + $this->assistantUploadRules());
        $files = array_values(array_filter([...$this->uploads, $this->audioUpload]));
        if (trim($this->text) === '' && $files === []) {
            $this->addError('text', 'Text eingeben oder eine Datei beziehungsweise Sprachaufnahme hinzufügen.');

            return;
        }
        $this->perform(function () use ($actor, $files): void {
            $record = app(AiIntakeService::class)->submit($actor, $this->text, $files, ['contact_email' => $this->contactEmail, 'customer_id' => $this->customerId, 'timezone' => 'Europe/Berlin']);
            foreach ($files as $file) {
                if (method_exists($file, 'delete')) {
                    $file->delete();
                }
            }
            $this->reset('text', 'contactEmail', 'uploads', 'audioUpload', 'captureOpen');
            $this->dispatch('ai-intake-submitted');
            $this->select($record->id);
        });
    }
}
