<?php

namespace App\Livewire\Operations;

use App\Models\AiIntake;
use App\Models\User;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Questions and explicit recognition guidance for one selected intake. */
class AiIntakeAssistant extends Component
{
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

    public function mount(): void
    {
        $this->actor();
    }

    #[On('select-ai-intake-assistant')]
    public function select(int $intakeId): void
    {
        $this->actor();
        $intake = AiIntake::findOrFail($intakeId);
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
        }
    }

    public function close(): void
    {
        $this->actor();
        $this->intakeId = null;
        $this->intakeRevision = $this->sourceRevision = $this->settingsRevision = 0;
        $this->feedback = $this->prompt = '';
        $this->contextChanged = false;
        $this->resetValidation();
    }

    public function ask(): void
    {
        $this->perform(function (User $actor, AiIntake $intake): void {
            $this->prompt = trim($this->prompt);
            $this->validate(['prompt' => 'required|string|max:2000']);
            app(AiIntakeAssistantService::class)->ask($intake, $actor, $this->intakeRevision, trim($this->prompt));
            $this->prompt = '';
        });
    }

    public function review(): void
    {
        $this->perform(function (User $actor, AiIntake $intake): void {
            $this->prompt = trim($this->prompt);
            $this->validate(['prompt' => 'required|string|max:2000']);
            abort_unless($intake->source_type === 'email', 422);
            $recognized = app(AiIntakeService::class)->analyzeManually($intake, $actor, $this->intakeRevision, $this->settingsRevision, trim($this->prompt));
            $this->intakeRevision = $recognized->revision;
            $this->sourceRevision = $recognized->source_revision;
            $this->prompt = '';
            $this->feedback = 'Die Mail wurde mit deinem Hinweis erneut erkannt. Ergebnis und offene Angaben im Eingang prüfen; Anfragen und Versand bleiben persönlich freizugeben.';
            $this->dispatch('disposition-mailbox-updated');
        });
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        OperationsAccess::requireReady();
        AiIntakeSchema::requireReady();

        return $actor;
    }

    private function current(): ?AiIntake
    {
        if (! $this->intakeId) {
            return null;
        }
        $intake = AiIntake::findOrFail($this->intakeId);
        if ($intake->revision !== $this->intakeRevision || $intake->source_revision !== $this->sourceRevision
            || (int) AiDispositionSettings::all(true)['revision'] !== $this->settingsRevision) {
            $this->contextChanged = true;
            $this->prompt = $this->feedback = '';

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
        if (! $intake) {
            return;
        }
        try {
            $action($actor, $intake);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $field => $messages) {
                $this->addError($field === 'review_prompt' ? 'prompt' : $field, implode(' ', $messages));
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
        } catch (Throwable $error) {
            Log::warning('AI intake assistant processing failed.', ['exception_class' => str_contains($error::class, '@anonymous') ? (get_parent_class($error) ?: Throwable::class) : $error::class,
                'intake_id' => $this->intakeId, 'actor_id' => $actor->id]);
            $this->addError('assistant', 'Der Eingang konnte gerade nicht geprüft werden. Bitte erneut versuchen.');
        }
    }

    public function render()
    {
        $actor = $this->actor();
        $intake = $this->current();
        $history = $intake ? $intake->runs()->where('kind', 'intake_question')->where('status', 'succeeded')
            ->where('source_revision', $this->sourceRevision)->where('supervising_user_id', $actor->id)->where('settings_revision', $this->settingsRevision)->latest('id')->limit(12)->get()
            ->filter(fn ($run) => ($run->configuration['actor_id'] ?? null) === $actor->id && ($run->configuration['intake_revision'] ?? null) === $this->intakeRevision
                && ($run->configuration['extraction_version'] ?? null) === AiIntakeMessageContent::VERSION)
            ->reverse() : collect();

        return view('livewire.operations.ai-intake-assistant', ['intake' => $intake, 'history' => $history,
            'canReview' => $intake?->source_type === 'email' && ! $intake->paused_at && ! in_array($intake->status, ['paused', 'completed', 'not_inquiry', 'analyzing'], true),
            'intakeUrl' => $intake ? OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake', 'source' => 'ai-intake', 'record' => $intake->id]) : null]);
    }
}
