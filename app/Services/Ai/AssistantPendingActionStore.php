<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Str;

final class AssistantPendingActionStore
{
    private const MAX_ACTIONS = 12;

    private const EXPIRES_AFTER_SECONDS = 600;

    /** @param array<string, mixed> $effect */
    public function create(User $user, string $routeName, array $effect): array
    {
        $token = Str::random(48);
        $actions = $this->pending($user);
        $actions[$token] = [
            'user_id' => (int) $user->getAuthIdentifier(),
            'route_name' => $routeName,
            'effect' => $effect,
            'created_at' => now()->getTimestamp(),
            'expires_at' => now()->addSeconds(self::EXPIRES_AFTER_SECONDS)->getTimestamp(),
        ];
        $actions = array_slice($actions, -self::MAX_ACTIONS, null, true);
        session()->put($this->pendingKey($user), $actions);

        return [
            'kind' => 'pending_tool',
            'token' => $token,
            'label' => $this->label($effect),
        ];
    }

    /** @return array<string, mixed>|null */
    public function consume(User $user, string $routeName, string $token): ?array
    {
        $actions = $this->pending($user);
        $action = $actions[$token] ?? null;

        if (
            ! is_array($action)
            || (int) ($action['user_id'] ?? 0) !== (int) $user->getAuthIdentifier()
            || ! hash_equals((string) ($action['route_name'] ?? ''), $routeName)
            || (int) ($action['expires_at'] ?? 0) < now()->getTimestamp()
            || ! is_array($action['effect'] ?? null)
        ) {
            unset($actions[$token]);
            session()->put($this->pendingKey($user), $actions);

            return null;
        }

        unset($actions[$token]);
        session()->put($this->pendingKey($user), $actions);

        $effect = $action['effect'];
        if (($effect['type'] ?? null) === 'wagon_list') {
            $receipts = $this->receipts($user);
            $receipts[$token] = [
                'effect' => $effect,
                'expires_at' => now()->addSeconds(90)->getTimestamp(),
            ];
            session()->put($this->receiptKey($user), array_slice($receipts, -self::MAX_ACTIONS, null, true));
        }

        return $effect;
    }

    /** @return array<string, mixed>|null */
    public function acceptReceipt(User $user, string $token, string $status): ?array
    {
        $receipts = $this->receipts($user);
        $receipt = $receipts[$token] ?? null;
        unset($receipts[$token]);
        session()->put($this->receiptKey($user), $receipts);

        if (
            ! is_array($receipt)
            || (int) ($receipt['expires_at'] ?? 0) < now()->getTimestamp()
            || ! is_array($receipt['effect'] ?? null)
            || ! in_array($status, ['applied', 'rejected', 'stale_context', 'storage_error'], true)
        ) {
            return null;
        }

        return ['effect' => $receipt['effect'], 'status' => $status];
    }

    public function forget(User $user): void
    {
        session()->forget([$this->pendingKey($user), $this->receiptKey($user)]);
    }

    /** @return array<string, array<string, mixed>> */
    private function pending(User $user): array
    {
        return $this->fresh(session()->get($this->pendingKey($user), []));
    }

    /** @return array<string, array<string, mixed>> */
    private function receipts(User $user): array
    {
        return $this->fresh(session()->get($this->receiptKey($user), []));
    }

    /** @return array<string, array<string, mixed>> */
    private function fresh(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        $now = now()->getTimestamp();

        return array_filter(
            $records,
            fn (mixed $record): bool => is_array($record) && (int) ($record['expires_at'] ?? 0) >= $now,
        );
    }

    /** @param array<string, mixed> $effect */
    private function label(array $effect): string
    {
        $german = app()->getLocale() === 'de';

        if (($effect['type'] ?? null) === 'navigate') {
            $title = trim((string) ($effect['label'] ?? 'RailTime'));

            return $german ? $title.' öffnen' : 'Open '.$title;
        }

        return match ((string) ($effect['command'] ?? '')) {
            'start' => $german ? 'Sprachführung starten' : 'Start voice guidance',
            'next' => $german ? 'Weiter' : 'Continue',
            'previous' => $german ? 'Zurück' : 'Back',
            'select_wagon' => $german
                ? 'Wagen '.(int) ($effect['wagon_index'] ?? 1).' öffnen'
                : 'Open wagon '.(int) ($effect['wagon_index'] ?? 1),
            'save' => $german ? 'Lokal speichern' : 'Save locally',
            'set_field' => $german ? 'Übernehmen & weiter' : 'Apply & continue',
            default => $german ? 'Aktion bestätigen' : 'Confirm action',
        };
    }

    private function pendingKey(User $user): string
    {
        return 'railtime_assistant_pending_actions_'.(int) $user->getAuthIdentifier();
    }

    private function receiptKey(User $user): string
    {
        return 'railtime_assistant_action_receipts_'.(int) $user->getAuthIdentifier();
    }
}
