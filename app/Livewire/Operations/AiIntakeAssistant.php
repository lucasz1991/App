<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\User;
use App\Services\Ai\AssistantSpeechException;
use App\Services\Ai\Attachments\AssistantAttachmentException;
use App\Services\Ai\Attachments\AssistantAttachmentProcessor;
use App\Services\Ai\OpenRouterChatException;
use App\Services\Operations\AiIntakeAssistantService;
use App\Services\Operations\AiIntakeMessageContent;
use App\Services\Operations\AiIntakeService;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Questions and explicit recognition guidance for one selected intake. */
class AiIntakeAssistant extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $intakeId = null;

    #[Locked]
    public int $intakeRevision = 0;

    #[Locked]
    public int $sourceRevision = 0;

    #[Locked]
    public int $settingsRevision = 0;

    #[Locked]
    public bool $contextChanged = false;

    #[Locked]
    public string $feedback = '';

    public string $prompt = '';

    public string $promptMode = 'question';

    public array $uploads = [];

    public $audioUpload = null;

    #[Locked]
    public string $uploadContext = '';

    #[Locked]
    public ?int $contextActorId = null;

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public bool $overview = false;

    #[Locked]
    public ?string $hostComponentId = null;

    #[Locked]
    public string $overviewFingerprint = '';

    private bool $manualAttempt = false;

    public function mount(?int $initialIntakeId = null, ?int $customerId = null, bool $overview = false, ?string $hostComponentId = null): void
    {
        $this->contextActorId = $this->actor()->id;
        $this->customerId = $customerId;
        $this->overview = $overview;
        $this->hostComponentId = $hostComponentId;
        if ($initialIntakeId) {
            $this->select($initialIntakeId);
        } elseif ($overview) {
            $this->loadOverview();
        }
    }

    #[On('select-ai-intake-assistant')]
    public function select(int $intakeId, ?string $componentId = null): void
    {
        if ($componentId !== null && $componentId !== $this->hostComponentId) {
            return;
        }
        $this->actor();
        $intake = AiIntake::when($this->customerId, fn ($query) => $query->where('customer_id', $this->customerId))->findOrFail($intakeId);
        $this->discardTemporaryUploads();
        $this->intakeId = $intake->id;
        $this->intakeRevision = $intake->revision;
        $this->sourceRevision = $intake->source_revision;
        $this->settingsRevision = (int) AiDispositionSettings::all(true)['revision'];
        $this->contextChanged = false;
        $this->feedback = $this->prompt = '';
        $this->resetValidation();
        $this->dispatch('ai-intake-assistant-selected', componentId: $this->getId());
    }

    public function reloadContext(): void
    {
        $this->actor();
        if ($this->intakeId) {
            $this->select($this->intakeId);
        } elseif ($this->overview) {
            $this->discardTemporaryUploads();
            $this->prompt = '';
            $this->loadOverview();
        }
    }

    public function close(): void
    {
        $this->actor();
        $this->discardTemporaryUploads();
        $this->intakeId = null;
        $this->intakeRevision = $this->sourceRevision = $this->settingsRevision = 0;
        $this->feedback = $this->prompt = '';
        $this->contextChanged = false;
        $this->resetValidation();
        if ($this->overview) {
            $this->loadOverview();
        }
    }

    #[On('ai-intake-assistant-overview')]
    public function returnToOverview(?int $customerId = null, ?string $componentId = null): void
    {
        if ($componentId !== null && $componentId !== $this->hostComponentId) {
            return;
        }
        abort_unless($customerId === $this->customerId, 403);
        $this->close();
    }

    private function loadOverview(): void
    {
        $actor = $this->actor();
        $this->settingsRevision = (int) AiDispositionSettings::all(true)['revision'];
        $this->overviewFingerprint = app(AiIntakeAssistantService::class)->overviewFingerprint($actor, $this->customerId);
        $this->contextChanged = false;
        $this->promptMode = 'question';
        $this->resetValidation();
    }

    public function beginContextUpload(): void
    {
        $this->actor();
        $intake = $this->current();
        abort_unless($intake || ($this->overview && ! $this->intakeId && ! $this->contextChanged), 409, 'Den aktuellen Eingang vor dem Hochladen erneut auswählen.');
        abort_if(($this->uploads !== [] || $this->audioUpload) && $this->uploadContext !== $this->contextKey(), 409, 'Alte zusätzliche Dateien zuerst entfernen.');
        $this->uploadContext = $this->contextKey();
    }

    public function updatedUploads(): void
    {
        $this->actor();
        if (! $this->uploadsCurrent()) {
            return;
        }
        try {
            $this->validate(['uploads' => 'array|max:3', 'uploads.*' => 'file|max:10240']);
            // Audio is handled by the configured speech router only on explicit submission.
            $documents = array_filter($this->uploads, fn ($file) => ! in_array(strtolower($file->getClientOriginalExtension()), ['wav', 'mp3', 'ogg', 'webm', 'm4a', 'mp4', 'aac', 'flac'], true));
            app(AssistantAttachmentProcessor::class)->validate(array_values($documents));
        } catch (AssistantAttachmentException $error) {
            $this->addError('uploads', $error->userMessage);
        }
    }

    public function updatedAudioUpload(): void
    {
        $this->actor();
        if ($this->uploadsCurrent()) {
            $this->validate(['audioUpload' => 'nullable|file|max:8192']);
        }
    }

    public function removeUpload(int $index): void
    {
        $this->actor();
        if (isset($this->uploads[$index])) {
            if (! $this->uploads[$index] instanceof TemporaryUploadedFile || ! $this->uploads[$index]->delete()) {
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
        if ($this->audioUpload && (! $this->audioUpload instanceof TemporaryUploadedFile || ! $this->audioUpload->delete())) {
            $this->addError('audioUpload', 'Die temporäre Aufnahme konnte nicht entfernt werden. Bitte erneut versuchen.');

            return;
        }
        $this->audioUpload = null;
        $this->resetValidation('audioUpload');
        $this->dispatch('ai-intake-composer-audio-removed', componentId: $this->getId());
    }

    public function discardUploads(): void
    {
        $this->actor();
        $this->discardTemporaryUploads();
    }

    public function submitPrompt(): void
    {
        $this->validate(['promptMode' => 'required|in:question,review']);
        $this->promptMode === 'review' ? $this->review() : $this->ask();
    }

    public function ask(): void
    {
        $this->perform(function (User $actor, ?AiIntake $intake): void {
            $this->prompt = trim($this->prompt);
            $this->validatePrompt();
            if ($intake) {
                app(AiIntakeAssistantService::class)->ask($intake, $actor, $this->intakeRevision, $this->prompt, $this->uploads, $this->audioUpload, $this->settingsRevision);
            } else {
                app(AiIntakeAssistantService::class)->askOverview($actor, $this->customerId, $this->settingsRevision, $this->overviewFingerprint, $this->prompt, $this->uploads, $this->audioUpload);
            }
            $this->prompt = '';
            $this->discardTemporaryUploads();
            $this->dispatch('ai-intake-composer-submitted', componentId: $this->getId());
        });
    }

    public function review(): void
    {
        $this->perform(function (User $actor, ?AiIntake $intake): void {
            $this->prompt = trim($this->prompt);
            $this->validatePrompt();
            abort_unless($intake?->source_type === 'email', 422);
            $context = $this->uploads !== [] || $this->audioUpload
                ? app(AiIntakeAssistantService::class)->prepareReviewContext($intake, $actor, $this->intakeRevision, $this->settingsRevision, $this->uploads, $this->audioUpload)
                : null;
            $this->manualAttempt = true;
            $recognized = $context
                ? app(AiIntakeService::class)->analyzeManually($intake, $actor, $this->intakeRevision, $this->settingsRevision, $this->prompt, $context)
                : app(AiIntakeService::class)->analyzeManually($intake, $actor, $this->intakeRevision, $this->settingsRevision, $this->prompt);
            $this->intakeRevision = $recognized->revision;
            $this->sourceRevision = $recognized->source_revision;
            $this->prompt = '';
            $this->discardTemporaryUploads();
            $this->feedback = 'Die Mail wurde mit deinem Hinweis erneut erkannt. Ergebnis und offene Angaben im Eingang prüfen; Anfragen und Versand bleiben persönlich freizugeben.';
            $this->dispatch('disposition-mailbox-updated');
            $this->dispatch('ai-intake-composer-submitted', componentId: $this->getId());
        });
    }

    private function validatePrompt(): void
    {
        $this->validate(['prompt' => ($this->uploads !== [] || $this->audioUpload ? 'nullable' : 'required').'|string|max:2000']);
        if (($this->uploads !== [] || $this->audioUpload) && ! $this->uploadsCurrent()) {
            throw ValidationException::withMessages(['uploads' => 'Die Dateien gehören nicht mehr zum ausgewählten Eingang. Bitte erneut hinzufügen.']);
        }
    }

    private function contextKey(): string
    {
        if ($this->overview && ! $this->intakeId) {
            return 'overview:'.$this->customerId.':'.$this->overviewFingerprint.':'.$this->settingsRevision;
        }

        return implode(':', [$this->intakeId, $this->intakeRevision, $this->sourceRevision, $this->settingsRevision]);
    }

    private function uploadsCurrent(): bool
    {
        $intake = $this->current();
        if ((! $intake && (! $this->overview || $this->intakeId || $this->contextChanged)) || $this->uploadContext === '' || $this->uploadContext !== $this->contextKey()) {
            $this->discardTemporaryUploads();
            $this->addError('uploads', 'Der Eingang wurde geändert. Bitte die zusätzlichen Hinweise erneut auswählen.');

            return false;
        }

        return true;
    }

    private function discardTemporaryUploads(): void
    {
        $remaining = [];
        foreach ($this->uploads as $file) {
            if ($file instanceof TemporaryUploadedFile && ! $file->delete()) {
                $remaining[] = $file;
            }
        }
        $this->uploads = $remaining;
        if ($this->audioUpload instanceof TemporaryUploadedFile && ! $this->audioUpload->delete()) {
            $this->addError('audioUpload', 'Die temporäre Aufnahme konnte nicht entfernt werden. Bitte erneut entfernen.');
        } else {
            $this->audioUpload = null;
        }
        $this->uploadContext = '';
        if ($remaining !== []) {
            $this->addError('uploads', 'Eine temporäre Datei konnte nicht entfernt werden. Bitte erneut entfernen.');
        }
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $actor = User::findOrFail($actor->id);
        abort_if($this->contextActorId !== null && $this->contextActorId !== $actor->id, 403);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        OperationsAccess::requireReady();
        AiIntakeSchema::requireReady();

        return $actor;
    }

    private function current(): ?AiIntake
    {
        if (! $this->intakeId) {
            if ($this->overview && ((int) AiDispositionSettings::all(true)['revision'] !== $this->settingsRevision
                || ! hash_equals($this->overviewFingerprint, app(AiIntakeAssistantService::class)->overviewFingerprint($this->actor(), $this->customerId)))) {
                $this->contextChanged = true;
                $this->prompt = $this->feedback = '';
                $this->discardTemporaryUploads();
            }

            return null;
        }
        $intake = AiIntake::findOrFail($this->intakeId);
        if ($intake->revision !== $this->intakeRevision || $intake->source_revision !== $this->sourceRevision
            || ($this->customerId && $intake->customer_id !== $this->customerId)
            || (int) AiDispositionSettings::all(true)['revision'] !== $this->settingsRevision) {
            $this->contextChanged = true;
            $this->prompt = $this->feedback = '';
            $this->discardTemporaryUploads();

            return null;
        }

        return $intake;
    }

    private function perform(callable $action): void
    {
        $actor = $this->actor();
        $this->resetValidation();
        $this->feedback = '';
        $intake = $this->current();
        if (! $intake && (! $this->overview || $this->intakeId || $this->contextChanged)) {
            return;
        }
        try {
            $action($actor, $intake);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $field => $messages) {
                $target = $field === 'review_prompt' ? 'prompt' : (str_starts_with($field, 'uploads') ? 'uploads' : (in_array($field, ['prompt', 'promptMode', 'audioUpload'], true) ? $field : 'assistant'));
                $this->addError($target, implode(' ', $messages));
            }
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
            $this->addError('assistant', match ($error->getStatusCode()) {
                409 => 'Der Eingang wird bearbeitet oder hat sich geändert. Den aktuellen Stand laden. Bestehende Anfrage- und Versandvorgänge im Eingang persönlich prüfen.',
                429 => 'Die zulässige Anzahl an AI-Aufrufen ist erreicht. Bitte später erneut versuchen.',
                default => 'Diese Prüfung ist für den aktuellen Eingang nicht verfügbar. Original, Zustand und offene Angaben im Eingang prüfen.',
            });
        } catch (OpenRouterChatException $error) {
            $this->addError('assistant', match (true) {
                in_array($error->reasonCode, ['not_configured', 'disposition_not_configured', 'endpoint_not_allowed'], true) => 'Die AI-Verbindung ist nicht vollständig eingerichtet. Die AI-Einstellungen prüfen.',
                in_array($error->reasonCode, ['rate_limit', 'disposition_rate_limit'], true) || $error->upstreamStatus === 429 => 'Die AI-Prüfung ist derzeit ausgelastet. Bitte später erneut versuchen.',
                $error->upstreamStatus === 404 => 'Der Anbieter unterstützt das eingestellte AI-Modell oder die angeforderten Funktionen derzeit nicht. Die AI-Verbindung in den Einstellungen prüfen.',
                in_array($error->upstreamStatus, [401, 403], true) => 'Der AI-Anbieter hat den Zugriff abgelehnt. Die AI-Zugangsdaten und Modellfreigabe prüfen.',
                $error->upstreamStatus === 402 => 'Das Guthaben beim AI-Anbieter reicht für diese Prüfung nicht aus. Guthaben oder Abrechnung prüfen.',
                default => 'Die AI-Prüfung konnte gerade nicht abgeschlossen werden. Bitte erneut versuchen.',
            });
        } catch (AssistantAttachmentException $error) {
            $this->addError('uploads', $error->userMessage);
        } catch (AssistantSpeechException $error) {
            $this->addError('audioUpload', 'Die Spracheingabe konnte gerade nicht vollständig erkannt werden. Bitte erneut versuchen oder Text eingeben.');
        } catch (Throwable $error) {
            Log::warning('AI intake assistant processing failed.', ['exception_class' => str_contains($error::class, '@anonymous') ? (get_parent_class($error) ?: Throwable::class) : $error::class,
                'intake_id' => $this->intakeId, 'actor_id' => $actor->id]);
            $this->addError('assistant', 'Der Eingang konnte gerade nicht geprüft werden. Bitte erneut versuchen.');
        }
        if ($this->manualAttempt) {
            $fresh = AiIntake::find($this->intakeId);
            $failed = $fresh?->runs()->where('kind', 'mailbox_test')->where('status', 'failed')->latest('id')->first();
            if ($fresh && $fresh->source_revision === $this->sourceRevision && $fresh->revision === $this->intakeRevision + 1
                && $fresh->status === 'review' && $fresh->error_code === 'mailbox_test_review'
                && $failed?->supervising_user_id === $actor->id && $failed->source_revision === $this->sourceRevision
                && $failed->settings_revision === $this->settingsRevision && ($failed->configuration['intake_revision'] ?? 0) === $fresh->revision
                && (int) AiDispositionSettings::all(true)['revision'] === $this->settingsRevision) {
                $this->intakeRevision = $fresh->revision;
                if ($this->uploads !== [] || $this->audioUpload) {
                    $this->uploadContext = $this->contextKey();
                }
            }
        }
    }

    public function render()
    {
        $actor = $this->actor();
        $intake = $this->current();
        $history = $intake ? $intake->runs()->where('kind', 'intake_question')->where('status', 'succeeded')
            ->where('source_revision', $this->sourceRevision)->where('supervising_user_id', $actor->id)->where('settings_revision', $this->settingsRevision)->latest('id')->limit(12)->get()
            ->filter(fn ($run) => ($run->configuration['actor_id'] ?? null) === $actor->id && ($run->configuration['intake_revision'] ?? null) === $this->intakeRevision
                && ($run->configuration['purpose'] ?? 'question') === 'question'
                && ($run->configuration['extraction_version'] ?? null) === AiIntakeMessageContent::VERSION)
            ->reverse() : collect();

        $overviewHistory = $this->overview && ! $this->intakeId && ! $this->contextChanged
            ? app(AiIntakeAssistantService::class)->overviewHistory($actor, $this->customerId, $this->settingsRevision, $this->overviewFingerprint) : [];

        return view('livewire.operations.ai-intake-assistant', ['intake' => $intake, 'history' => $history, 'overviewHistory' => $overviewHistory,
            'canReview' => $intake?->source_type === 'email' && ! $intake->paused_at && ! in_array($intake->status, ['paused', 'completed', 'not_inquiry', 'analyzing'], true),
            'intakeUrl' => $intake ? OperationsPages::intakePanelUrl($intake->id, $this->customerId) : null]);
    }
}
