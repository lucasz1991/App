<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\Setting;
use App\Models\User;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Context choices augment the existing four booleans; a missing row preserves legacy defaults. */
final class OutlookAddinTemplateChoicesPolicy
{
    public function preferencesForUser(User $user, ?array $automatic = null): array
    {
        return $this->value($user, Setting::getValueUncached('outlook_addin', $this->key($user)),
            $automatic ?? app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForUser($user));
    }

    public function preferencesForSession(User $user): array
    {
        return DB::transaction(fn (): array => $this->preferencesForUser($user,
            app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForSession($user)), 3);
    }

    public function preferencesForManagedUser(User $actor, User $target): array
    {
        return DB::transaction(fn (): array => $this->preferencesForUser($target,
            app(OutlookAddinAutomaticInsertionPolicy::class)->preferencesForManagedUser($actor, $target)), 3);
    }

    public function preferencesForIdentity(User $user, VerifiedEntraIdentity $identity, array $binding): array
    {
        return DB::transaction(function () use ($user, $identity, $binding): array {
            [$employee] = app(OutlookAddinOwnAccountAccess::class)->employee($user, $identity, $binding);
            return $this->preferencesForUser($employee);
        }, 3);
    }

    public function updateForSession(User $user, string $group, string $selection, string $revision): array
    {
        $automatic = app(OutlookAddinAutomaticInsertionPolicy::class);
        return $this->write($user, $user, $group, $selection, $revision,
            fn (): array => $automatic->preferencesForSession($user),
            fn (bool $enabled, string $current): array => $automatic->updateForSession($user, $group, 'template', $enabled, $current));
    }

    public function updateForManagedUser(User $actor, User $target, string $group, string $selection, string $revision): array
    {
        $automatic = app(OutlookAddinAutomaticInsertionPolicy::class);
        return $this->write($actor, $target, $group, $selection, $revision,
            fn (): array => $automatic->preferencesForManagedUser($actor, $target),
            fn (bool $enabled, string $current): array => $automatic->updateForManagedUser($actor, $target, $group, 'template', $enabled, $current));
    }

    public function updateForIdentity(User $user, VerifiedEntraIdentity $identity, array $binding, string $group, string $selection, string $revision): array
    {
        $automatic = app(OutlookAddinAutomaticInsertionPolicy::class);
        return $this->write($user, $user, $group, $selection, $revision,
            function () use ($user, $identity, $binding, $automatic): array {
                [$employee] = app(OutlookAddinOwnAccountAccess::class)->employee($user, $identity, $binding);
                return $automatic->preferencesForUser($employee);
            },
            fn (bool $enabled, string $current): array => $automatic->updateForIdentity($user, $identity, $binding, $group, 'template', $enabled, $current));
    }

    /** Request-fresh event choice; never changes reusable artifacts or a manual selection. */
    public function eventChoice(User $user, array $automatic, string $composeType): array
    {
        $choices = $this->preferencesForUser($user, $automatic);
        $group = $composeType === 'reply' ? 'replies' : 'newMessages';
        $selected = $choices[$group];
        return ['selection' => $selected['selection'],
            'enabled' => in_array($composeType, ['newMail', 'forward', 'reply'], true) && $selected['available'] && $selected['selection'] !== 'none',
            'customized' => $choices['newMessages']['selection'] !== 'default' || $choices['replies']['selection'] !== 'default'];
    }

    public function selectPayload(array $payload, string $selection): array
    {
        if ($selection === 'default') {
            return $payload;
        }
        $matches = array_filter($payload['templates'] ?? [], static fn (array $template): bool => ($template['id'] ?? null) === $selection);
        if (count($matches) !== 1) {
            throw new OutlookAddinException('Die ausgewählte Outlook-Vorlage ist nicht mehr freigegeben.', 409, 'outlook_addin_template_choice_unavailable');
        }
        $payload['automaticTemplateId'] = $selection;
        foreach ($payload['templates'] as &$template) {
            $template['isDefault'] = ($template['id'] ?? null) === $selection;
        }
        unset($template);
        return $payload;
    }

    private function write(User $actor, User $target, string $group, string $selection, string $revision, Closure $reader, Closure $writer): array
    {
        if (! in_array($group, ['newMessages', 'replies'], true)
            || (! in_array($selection, ['default', 'none'], true) && ! Str::isUuid($selection))
            || preg_match('/\A[0-9a-f]{64}\z/', $revision) !== 1) {
            throw ValidationException::withMessages(['templateChoices' => 'Bitte eine gültige Outlook-Vorlage auswählen.']);
        }
        return DB::transaction(function () use ($actor, $target, $group, $selection, $revision, $reader, $writer): array {
            $automatic = $reader();
            $setting = Setting::query()->where('type', 'outlook_addin')->where('key', $this->key($target))->lockForUpdate()->first();
            $current = $this->value($target, $setting?->value, $automatic);
            if (! hash_equals($current['revision'], $revision)) {
                throw new OutlookAddinException('Die Outlook-Auswahl wurde inzwischen geändert.', 409, 'outlook_addin_template_choices_conflict');
            }
            if (! in_array($selection, ['default', 'none'], true) && ! in_array($selection, array_column($current['templates'], 'id'), true)) {
                throw ValidationException::withMessages(['templateChoices' => 'Diese Outlook-Vorlage ist nicht mehr freigegeben.']);
            }
            if ($current[$group]['selection'] === $selection && $current[$group]['available']) {
                return $current;
            }
            $nextAutomatic = $writer($selection !== 'none', $automatic['revision']);
            $stored = array_replace((array) $setting?->value, ['schema' => 1, 'nonce' => Str::random(32)]);
            $stored[$group] = array_replace(is_array($stored[$group] ?? null) ? $stored[$group] : [], [
                'mode' => in_array($selection, ['default', 'none'], true) ? $selection : 'document',
                'documentId' => in_array($selection, ['default', 'none'], true) ? null : $selection,
            ]);
            Setting::setValue('outlook_addin', $this->key($target), $stored);
            activity('outlook-addin')->causedBy($actor)->performedOn($target)->withProperties([
                'employee_id' => (int) $target->getKey(), 'group' => $group,
                'selection_before' => $current[$group]['selection'], 'selection_after' => $selection,
                'revision_before' => $current['revision'],
            ])->log('outlook_addin_template_choice_changed');
            return $this->value($target, $stored, $nextAutomatic);
        }, 3);
    }

    private function value(User $user, mixed $stored, array $automatic): array
    {
        if ($stored !== null && (! is_array($stored) || ($stored['schema'] ?? null) !== 1)) {
            throw new RuntimeException('Die Outlook-Vorlagenauswahl ist ungültig.');
        }
        $templates = array_map(static fn (array $snapshot): array => ['id' => $snapshot['id'], 'name' => $snapshot['name']],
            app(PublishedMailDocumentSnapshotStore::class)->freshTemplateSnapshots());
        $ids = array_column($templates, 'id');
        $result = ['schema' => 1];
        foreach (['newMessages', 'replies'] as $group) {
            $choice = $stored[$group] ?? ['mode' => 'default', 'documentId' => null];
            if (! is_array($choice) || ! in_array($choice['mode'] ?? null, ['default', 'document', 'none'], true)
                || (($choice['mode'] === 'document') ? ! is_string($choice['documentId'] ?? null) || ! Str::isUuid($choice['documentId']) : ($choice['documentId'] ?? null) !== null)) {
                throw new RuntimeException('Die Outlook-Vorlagenauswahl ist ungültig.');
            }
            $selection = ! $automatic[$group]['template'] ? 'none'
                : ($choice['mode'] === 'document' ? $choice['documentId'] : 'default');
            $result[$group] = ['selection' => $selection, 'available' => in_array($selection, ['none', 'default'], true) || in_array($selection, $ids, true)];
        }
        return $result + ['templates' => $templates,
            'revision' => hash('sha256', json_encode([(int) $user->getKey(), $stored, $automatic['revision']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    private function key(User $user): string
    {
        if ((int) $user->getKey() < 1) {
            throw new RuntimeException('Die Outlook-Auswahl benötigt ein bestehendes Mitarbeiterkonto.');
        }
        return 'template_choices_user_'.(int) $user->getKey();
    }
}
