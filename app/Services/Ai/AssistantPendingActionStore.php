<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssistantPendingActionStore
{
    private const MAX_ACTIONS = 12;

    private const EXPIRES_AFTER_SECONDS = 600;

    /** @param array<string, mixed> $effect */
    public function create(User $user, string $routeName, array $effect): array
    {
        $routeName = trim($routeName);
        if (! $this->validRouteName($routeName)) {
            throw new InvalidArgumentException('The assistant route name is invalid.');
        }

        $effect = app(AssistantApplicationTools::class)->normalizeBrowserEffect($user, $routeName, $effect)
            ?? throw new InvalidArgumentException('The assistant effect is not allowlisted.');

        $actions = $this->pending($user);
        do {
            $token = Str::random(48);
        } while (isset($actions[$token]));

        $now = now();
        $actions[$token] = [
            'user_id' => (int) $user->getAuthIdentifier(),
            'route_name' => $routeName,
            'effect' => $effect,
            'created_at' => $now->getTimestamp(),
            'expires_at' => $now->copy()->addSeconds(self::EXPIRES_AFTER_SECONDS)->getTimestamp(),
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
    /** @param array<string, mixed> $wagonContext */
    public function consume(
        User $user,
        string $routeName,
        string $token,
        array $wagonContext = [],
    ): ?array {
        $routeName = trim($routeName);
        $token = trim($token);
        if (! $this->validRouteName($routeName) || ! $this->validToken($token)) {
            return null;
        }

        $actions = $this->pending($user);
        $action = $actions[$token] ?? null;

        if (! is_array($action)) {
            return null;
        }

        if (
            (int) ($action['user_id'] ?? 0) !== (int) $user->getAuthIdentifier()
            || (int) ($action['expires_at'] ?? 0) < now()->getTimestamp()
            || ! is_array($action['effect'] ?? null)
        ) {
            unset($actions[$token]);
            session()->put($this->pendingKey($user), $actions);

            return null;
        }

        $storedRoute = (string) ($action['route_name'] ?? '');
        if (! $this->validRouteName($storedRoute) || ! hash_equals($storedRoute, $routeName)) {
            return null;
        }

        $effect = app(AssistantApplicationTools::class)->normalizeBrowserEffect(
            $user,
            $routeName,
            $action['effect'],
        );
        if ($effect === null) {
            unset($actions[$token]);
            session()->put($this->pendingKey($user), $actions);

            return null;
        }

        if (
            ($effect['type'] ?? null) === 'wagon_list'
            && ! app(AssistantApplicationTools::class)->browserEffectMatchesContext(
                $user,
                $routeName,
                $effect,
                $wagonContext,
            )
        ) {
            unset($actions[$token]);
            session()->put($this->pendingKey($user), $actions);

            return null;
        }

        unset($actions[$token]);
        session()->put($this->pendingKey($user), $actions);

        if (($effect['type'] ?? null) === 'wagon_list') {
            $receipts = $this->receipts($user);
            $receipts[$token] = [
                'user_id' => (int) $user->getAuthIdentifier(),
                'route_name' => $routeName,
                'effect' => $effect,
                'expires_at' => now()->addSeconds(90)->getTimestamp(),
            ];
            session()->put($this->receiptKey($user), array_slice($receipts, -self::MAX_ACTIONS, null, true));
        }

        return $effect;
    }

    /** @return array<string, mixed>|null */
    public function acceptReceipt(
        User $user,
        string $token,
        string $status,
        ?string $routeName = null,
    ): ?array {
        $token = trim($token);
        $status = trim($status);
        $routeName = $routeName !== null ? trim($routeName) : null;
        if (
            ! $this->validToken($token)
            || ! in_array($status, ['applied', 'rejected', 'stale_context', 'storage_error'], true)
            || ($routeName !== null && ! $this->validRouteName($routeName))
        ) {
            return null;
        }

        $receipts = $this->receipts($user);
        $receipt = $receipts[$token] ?? null;
        if (! is_array($receipt)) {
            return null;
        }

        if (
            (int) ($receipt['user_id'] ?? 0) !== (int) $user->getAuthIdentifier()
            || (int) ($receipt['expires_at'] ?? 0) < now()->getTimestamp()
            || ! is_array($receipt['effect'] ?? null)
        ) {
            unset($receipts[$token]);
            session()->put($this->receiptKey($user), $receipts);

            return null;
        }

        $storedRoute = (string) ($receipt['route_name'] ?? '');
        if (! $this->validRouteName($storedRoute)) {
            unset($receipts[$token]);
            session()->put($this->receiptKey($user), $receipts);

            return null;
        }

        if ($routeName !== null && ! hash_equals($storedRoute, $routeName)) {
            return null;
        }

        unset($receipts[$token]);
        session()->put($this->receiptKey($user), $receipts);

        return ['effect' => $receipt['effect'], 'status' => $status];
    }

    public function forget(User $user): void
    {
        session()->forget([$this->pendingKey($user), $this->receiptKey($user)]);
    }

    /** @return array<string, array<string, mixed>> */
    private function pending(User $user): array
    {
        return $this->freshRecords($this->pendingKey($user));
    }

    /** @return array<string, array<string, mixed>> */
    private function receipts(User $user): array
    {
        return $this->freshRecords($this->receiptKey($user));
    }

    /** @return array<string, array<string, mixed>> */
    private function freshRecords(string $sessionKey): array
    {
        $stored = session()->get($sessionKey, []);
        $fresh = $this->fresh($stored);

        if (! is_array($stored) || $fresh !== $stored) {
            session()->put($sessionKey, $fresh);
        }

        return $fresh;
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

    private function validToken(string $token): bool
    {
        return preg_match('/\A[a-zA-Z0-9]{48}\z/', $token) === 1;
    }

    private function validRouteName(string $routeName): bool
    {
        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,159}\z/', $routeName) === 1;
    }
}
