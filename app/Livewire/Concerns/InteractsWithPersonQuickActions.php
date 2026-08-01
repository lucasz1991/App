<?php

namespace App\Livewire\Concerns;

use App\Models\Chat;
use App\Models\User;
use App\Services\Calls\CallInfrastructureUnavailable;
use App\Services\Calls\CallInvitationService;
use App\Services\Calls\RoomLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Gemeinsame Aktionen der Personen-Schnellansicht (x-user.person-anchor-preview).
 *
 * Die Vorschau erscheint in der Mitarbeiterliste, im Chat-Kopf und in der
 * Mitgliederauswahl. Damit dort ueberall dieselben Schaltflaechen wirken,
 * liegen die Aktionen hier statt mehrfach in den einzelnen Komponenten.
 *
 * Die Dienste werden bewusst ueber app() geholt und nicht per Methoden-
 * Injection: sonst muessten die Klassen vor den Aufrufparametern stehen und
 * liessen sich aus dem View nicht mehr sauber uebergeben.
 */
trait InteractsWithPersonQuickActions
{
    /**
     * Oeffnet den bestehenden Direktchat oder legt ihn einmalig an.
     */
    public function openDirectChat(int $userId)
    {
        $other = $this->quickActionTarget($userId);

        $chat = DB::transaction(
            fn (): Chat => Chat::directBetween(auth()->user(), $other)
        );

        return $this->redirectRoute('chat', ['chat' => $chat->id], navigate: true);
    }

    /**
     * Startet einen direkten Sprach- oder Videoanruf aus der Personenkarte.
     */
    public function startDirectCall(int $userId, string $mode)
    {
        abort_unless(in_array($mode, ['voice', 'video'], true), 422);

        $viewer = auth()->user();
        abort_unless($viewer->isAdmin() || $viewer->hasRbacPermission('calls.start'), 403);

        $other = $this->quickActionTarget($userId);
        abort_unless($other->isAdmin() || $other->hasRbacPermission('calls.join'), 403);

        $lifecycle = app(RoomLifecycleService::class);
        $invitations = app(CallInvitationService::class);

        $chat = DB::transaction(
            fn (): Chat => Chat::directBetween($viewer, $other)
        );

        // Pruefen und Anlegen unter einer Sperre auf der Chat-Zeile: ohne sie
        // erzeugen zwei gleichzeitige Klicks zwei konkurrierende Raeume.
        try {
            $result = DB::transaction(function () use ($chat, $viewer, $lifecycle, $mode): array {
                Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();

                $existing = $chat->activeRoom()->first();

                if ($existing) {
                    return ['room' => $existing, 'created' => false];
                }

                return [
                    'room' => $lifecycle->createForChat($chat, $viewer, $mode === 'video'),
                    'created' => true,
                ];
            });
        } catch (CallInfrastructureUnavailable) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.calls_unavailable'));

            return null;
        }

        if ($result['created']) {
            $invitations->invite($result['room'], $viewer, collect([$other]));
        }

        return redirect()->route('calls.window', $result['room']);
    }

    /**
     * Interne Nachricht an eine einzelne Person verfassen.
     */
    public function openMessage(int $userId): void
    {
        Gate::authorize('users.messages.create');

        $this->dispatch('openMailModal', payload: $userId)
            ->to(\App\Livewire\Admin\Users\Messages\MessageForm::class);
    }

    /**
     * Zielperson aufloesen: aktiv, existent und nicht man selbst.
     */
    protected function quickActionTarget(int $userId): User
    {
        $other = User::query()
            ->with('currentTeam')
            ->where('status', true)
            ->findOrFail($userId);

        abort_if($other->is(auth()->user()), 422);

        return $other;
    }
}
