<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Services\Privacy\SessionPermissionService;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SessionPermissions extends Component
{
    #[Locked]
    public bool $automatic = true;

    #[Locked]
    public int $ownerUserId = 0;

    public bool $dialogOpen = false;

    #[Locked]
    public int $pushVersion = 1;

    #[Locked]
    public int $locationVersion = 1;

    public function mount(bool $automatic = true): void
    {
        $this->ownerUserId = (int) $this->actor()->id;
        $this->automatic = $automatic;
        $state = $this->state();
        $this->syncVersions($state);
        $this->dialogOpen = $automatic && $this->needsDecision($state);
    }

    public function decide(string $purpose, bool $accepted, SessionPermissionService $service): bool
    {
        $this->resetErrorBag();
        $actor = $this->actor();
        abort_unless(in_array($purpose, ['push', 'location'], true), 422);
        $state = $this->state();
        abort_unless($purpose === 'push' ? $state['push_available'] : $state['location_enabled'], 422, 'Diese Funktion ist derzeit nicht verfügbar.');

        try {
            $service->decide($actor, $purpose, $accepted, $purpose === 'push' ? $this->pushVersion : $this->locationVersion);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->syncVersions($this->state());
            $this->addError('permissions', 'Die Erklärung wurde geändert. Bitte lies sie erneut und entscheide noch einmal.');

            return false;
        }

        $this->dispatch('session-permissions-updated');
        $this->dispatch('railtime-session-permissions-changed');

        return true;
    }

    public function revoke(string $purpose, SessionPermissionService $service): void
    {
        $this->resetErrorBag();
        abort_unless(in_array($purpose, ['push', 'location'], true), 422);
        $service->revoke($this->actor(), $purpose);
        $this->dispatch('session-permissions-updated');
        $this->dispatch('railtime-session-permissions-changed');
    }

    /** Closing the optional prompt is an explicit continuation without unanswered features. */
    public function dismiss(SessionPermissionService $service): void
    {
        $this->resetErrorBag();
        $state = $this->state();
        foreach (['push', 'location'] as $purpose) {
            if (($purpose === 'push' ? $state['push_available'] : $state['location_enabled']) && $state[$purpose.'_decision'] === null) {
                $service->decide($this->actor(), $purpose, false, $state[$purpose.'_version']);
            }
        }
        $this->dialogOpen = false;
        $this->dispatch('session-permissions-updated');
        $this->dispatch('railtime-session-permissions-changed');
    }

    #[On('session-permissions-updated')]
    public function refreshStatus(): void
    {
        $this->actor();
        $this->syncVersions($this->state());
    }

    public function render(): View
    {
        $state = $this->state();

        return view('livewire.settings.session-permissions', [
            'permissions' => $state,
            'permissionClient' => [
                'vapidPublicKey' => (string) config('webpush.vapid.public_key'),
                'accountBinding' => WebPushConfiguration::accountBinding($this->actor()->id),
                'subscribeUrl' => route('push.subscriptions.store'),
                'unsubscribeUrl' => route('push.subscriptions.destroy'),
            ],
        ]);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isActive(), 403);
        abort_unless($this->ownerUserId === 0 || (int) $actor->id === $this->ownerUserId, 403);

        return $actor;
    }

    private function state(): array
    {
        $state = app(SessionPermissionService::class)->snapshot($this->actor());
        $push = WebPushConfiguration::inspect();

        return $state + ['push_available' => $state['push_enabled'] && $push['ready']];
    }

    private function syncVersions(array $state): void
    {
        $this->pushVersion = (int) $state['push_version'];
        $this->locationVersion = (int) $state['location_version'];
    }

    private function needsDecision(array $state): bool
    {
        return ($state['push_available'] && $state['push_decision'] === null)
            || ($state['location_enabled'] && $state['location_decision'] === null);
    }
}
