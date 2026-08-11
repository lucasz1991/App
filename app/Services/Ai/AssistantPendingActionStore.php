<?php

namespace App\Services\Ai;

use App\Models\File;
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

        $pending = [
            'kind' => 'pending_tool',
            'token' => $token,
            'label' => $this->label($effect),
        ];
        $detail = $this->detail($effect);
        if ($detail !== '') {
            $pending['detail'] = $detail;
        }

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $wagonContext
     * @param  array<string, mixed>  $pageBuilderContext
     * @return array<string, mixed>|null
     */
    public function consume(
        User $user,
        string $routeName,
        string $token,
        array $wagonContext = [],
        array $pageBuilderContext = [],
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
            in_array(($effect['type'] ?? null), ['wagon_list', AssistantPageBuilderTools::EFFECT_TYPE], true)
            && ! app(AssistantApplicationTools::class)->browserEffectMatchesContext(
                $user,
                $routeName,
                $effect,
                $wagonContext,
                $pageBuilderContext,
            )
        ) {
            unset($actions[$token]);
            session()->put($this->pendingKey($user), $actions);

            return null;
        }

        unset($actions[$token]);
        session()->put($this->pendingKey($user), $actions);

        if (in_array(($effect['type'] ?? null), ['wagon_list', AssistantPageBuilderTools::EFFECT_TYPE], true)) {
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

        if (
            ($receipt['effect']['type'] ?? null) === AssistantPageBuilderTools::EFFECT_TYPE
            && ! isset($receipt['browser_claimed_at'])
        ) {
            return null;
        }

        unset($receipts[$token]);
        session()->put($this->receiptKey($user), $receipts);

        return ['effect' => $receipt['effect'], 'status' => $status];
    }

    /**
     * Claims a previously confirmed PageBuilder effect exactly once for the
     * authenticated browser. The generic client event receives only the
     * opaque token; the actual allowlisted effect never trusts event payloads.
     *
     * @return array<string, mixed>|null
     */
    public function claimPageBuilderEffect(User $user, string $token): ?array
    {
        $token = trim($token);
        if (! $this->validToken($token)) {
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
            || isset($receipt['browser_claimed_at'])
        ) {
            return null;
        }

        $storedRoute = (string) ($receipt['route_name'] ?? '');
        if (! $this->validRouteName($storedRoute)) {
            unset($receipts[$token]);
            session()->put($this->receiptKey($user), $receipts);

            return null;
        }

        $effect = app(AssistantApplicationTools::class)->normalizeBrowserEffect(
            $user,
            $storedRoute,
            $receipt['effect'],
        );
        if (! is_array($effect) || ($effect['type'] ?? null) !== AssistantPageBuilderTools::EFFECT_TYPE) {
            unset($receipts[$token]);
            session()->put($this->receiptKey($user), $receipts);

            return null;
        }

        $effect['action_token'] = $token;
        $receipt['effect'] = $effect;
        $receipt['browser_claimed_at'] = now()->getTimestamp();
        $receipts[$token] = $receipt;
        session()->put($this->receiptKey($user), $receipts);

        return $effect;
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

        if (($effect['type'] ?? null) === AssistantPageBuilderTools::EFFECT_TYPE) {
            return $this->pageBuilderLabel($effect, $german);
        }

        return match ((string) ($effect['command'] ?? '')) {
            'start' => $german ? 'Sprachführung starten' : 'Start voice guidance',
            'next' => $german ? 'Weiter' : 'Continue',
            'previous' => $german ? 'Zurück' : 'Back',
            'select_wagon' => $german
                ? 'Wagen '.(int) ($effect['wagon_index'] ?? 1).' öffnen'
                : 'Open wagon '.(int) ($effect['wagon_index'] ?? 1),
            'open_fullscreen' => $german ? 'Vollbildeditor öffnen' : 'Open full-screen editor',
            'open_panel' => $german ? 'Editor-Bereich öffnen' : 'Open editor panel',
            'focus_selection' => $german ? 'Auswahl fokussieren' : 'Focus selection',
            'edit_text' => $german ? 'Text ändern' : 'Change text',
            'set_style' => $german ? 'Gestaltung ändern' : 'Change styling',
            'replace_image' => $german ? 'Bild ersetzen' : 'Replace image',
            'add_block' => $german ? 'Block einfügen' : 'Insert block',
            'undo' => $german ? 'Rückgängig machen' : 'Undo',
            'redo' => $german ? 'Wiederholen' : 'Redo',
            'preview', 'restart_gif' => $german ? 'Vorschau ändern' : 'Change preview',
            'set_animation' => $german ? 'Animation ändern' : 'Change animation',
            'save' => ($effect['type'] ?? null) === AssistantPageBuilderTools::EFFECT_TYPE
                ? ($german ? 'Arbeitsstand speichern' : 'Save working draft')
                : ($german ? 'Lokal speichern' : 'Save locally'),
            'set_field' => $german ? 'Übernehmen & weiter' : 'Apply & continue',
            default => $german ? 'Aktion bestätigen' : 'Confirm action',
        };
    }

    /** @param array<string, mixed> $effect */
    private function pageBuilderLabel(array $effect, bool $german): string
    {
        $command = (string) ($effect['command'] ?? '');
        $quote = static fn (mixed $value, int $limit = 68): string => '„'.Str::limit(
            Str::squish(strip_tags((string) $value)),
            $limit,
            '…',
        ).'“';

        return match ($command) {
            'open_fullscreen' => $german ? 'Vollbildeditor öffnen' : 'Open full-screen editor',
            'open_panel' => $german
                ? 'Editor-Bereich '.$quote((string) ($effect['panel'] ?? '')).' öffnen'
                : 'Open editor panel '.$quote((string) ($effect['panel'] ?? '')),
            'focus_selection' => $german ? 'Ausgewähltes Segment fokussieren' : 'Focus selected segment',
            'edit_text' => $german
                ? 'Vollständig angezeigte Textänderung bestätigen'
                : 'Confirm the fully displayed text change',
            'set_style' => $german
                ? $quote($effect['property'] ?? '').' auf '.$quote($effect['value'] ?? '').' setzen'
                : 'Set '.$quote($effect['property'] ?? '').' to '.$quote($effect['value'] ?? ''),
            'replace_image' => $german
                ? 'Bild durch '.$this->pageBuilderFileLabel($effect['file_id'] ?? null, $quote).' ersetzen'
                : 'Replace image with '.$this->pageBuilderFileLabel($effect['file_id'] ?? null, $quote),
            'add_block' => $german
                ? 'Baustein '.$quote($effect['block_id'] ?? '').' '.$quote($effect['position'] ?? '').' einfügen'
                : 'Insert block '.$quote($effect['block_id'] ?? '').' '.$quote($effect['position'] ?? ''),
            'undo' => $german ? 'Letzte Editoränderung rückgängig machen' : 'Undo last editor change',
            'redo' => $german ? 'Editoränderung wiederholen' : 'Redo editor change',
            'preview' => $german
                ? 'Vorschau '.$quote($effect['state'] ?? '').' schalten'
                : 'Set preview '.$quote($effect['state'] ?? ''),
            'restart_gif' => $german ? 'GIF-Vorschau neu starten' : 'Restart GIF preview',
            'set_animation' => $german
                ? 'Animation '.$quote($effect['field'] ?? '').' auf '.$quote($effect['value'] ?? '').' setzen'
                : 'Set animation '.$quote($effect['field'] ?? '').' to '.$quote($effect['value'] ?? ''),
            'save' => $german ? 'Aktuellen Arbeitsstand speichern' : 'Save current working draft',
            default => $german ? 'Editor-Aktion bestätigen' : 'Confirm editor action',
        };
    }

    /** @param array<string, mixed> $effect */
    private function detail(array $effect): string
    {
        if (($effect['type'] ?? null) !== AssistantPageBuilderTools::EFFECT_TYPE) {
            return '';
        }

        return match ((string) ($effect['command'] ?? '')) {
            'edit_text' => "Neuer Text:\n".(string) ($effect['text'] ?? ''),
            'set_style' => 'Stil: '.(string) ($effect['property'] ?? '').' = '.(string) ($effect['value'] ?? ''),
            'replace_image' => 'Datei: '.$this->pageBuilderFileName($effect['file_id'] ?? null),
            'add_block' => 'Baustein: '.(string) ($effect['block_id'] ?? '').' · Position: '.(string) ($effect['position'] ?? ''),
            'set_animation' => 'Animation: '.(string) ($effect['field'] ?? '').' = '.(string) ($effect['value'] ?? ''),
            default => '',
        };
    }

    /**
     * @param callable(mixed, int): string $quote
     */
    private function pageBuilderFileLabel(mixed $fileId, callable $quote): string
    {
        return $quote($this->pageBuilderFileName($fileId), 72);
    }

    private function pageBuilderFileName(mixed $fileId): string
    {
        $id = filter_var($fileId, FILTER_VALIDATE_INT);
        $name = is_int($id) && $id > 0 ? File::query()->find($id)?->name : null;

        return $name ?: ('Datei #'.(is_int($id) ? $id : '?'));
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
