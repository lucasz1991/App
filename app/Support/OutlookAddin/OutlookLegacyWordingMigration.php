<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit operator migration, never a side effect of reading preferences. */
final class OutlookLegacyWordingMigration
{
    public function preview(User $actor, string $legacyId, string $standardId, bool $initializeWording = true): array
    {
        return $this->prepare($actor, $legacyId, $standardId, false, $initializeWording)['receipt'];
    }

    /** May be nested in the operator's publication/archive transaction. */
    public function apply(User $actor, string $legacyId, string $standardId, string $expectedPlan, bool $initializeWording = true): array
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $expectedPlan) !== 1) {
            throw new RuntimeException('Ein geprüfter Migrationsplan ist erforderlich.');
        }

        return DB::transaction(function () use ($actor, $legacyId, $standardId, $expectedPlan, $initializeWording): array {
            $plan = $this->prepare($actor, $legacyId, $standardId, true, $initializeWording);
            if (! hash_equals($expectedPlan, $plan['receipt']['planHash'])) {
                throw new RuntimeException('Der Migrationsstand wurde inzwischen geändert. Bitte den Dry-run wiederholen.');
            }
            foreach ($plan['changes'] as $change) {
                foreach (['choice', 'wording'] as $type) {
                    if ($change[$type] === null) {
                        continue;
                    }
                    Setting::setValue('outlook_addin', $change[$type]['key'],
                        array_replace($change[$type]['value'], ['nonce' => Str::random(32)]));
                }
            }
            if ($plan['changes'] !== []) {
                activity('outlook-addin')->causedBy($actor)->withProperties([
                    'plan_hash' => $expectedPlan, 'legacy_document' => $legacyId, 'standard_document' => $standardId,
                    'accounts' => count($plan['changes']),
                ])->log('outlook_addin_legacy_wording_migrated');
            }

            return $plan['receipt'] + ['applied' => true];
        }, 3);
    }

    private function prepare(User $actor, string $legacyId, string $standardId, bool $lock, bool $initializeWording): array
    {
        if (! Str::isUuid($legacyId) || ! Str::isUuid($standardId) || $legacyId === $standardId) {
            throw new RuntimeException('Zwei unterschiedliche Vorlagen-IDs sind erforderlich.');
        }
        $operator = User::query()->whereKey($actor->getKey())->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $operator instanceof User || $operator->role !== 'admin' || ! $operator->isActive() || $operator->email_verified_at === null) {
            throw new RuntimeException('Die Migration benötigt einen aktiven, bestätigten Administrator.');
        }
        $documents = MailDocument::withoutGlobalScopes()->whereIn('public_id', [$legacyId, $standardId])->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($documents->count() !== 2 || $documents->contains(fn (MailDocument $document): bool => $document->kind !== MailDocumentKind::Template || ! is_string($document->published_html) || $document->published_html === '')) {
            throw new RuntimeException('Beide veröffentlichten Vorlagen müssen vorhanden sein.');
        }
        // All account-setting rows participate in CAS; unrelated preferences are never written.
        $settings = Setting::query()->where('type', 'outlook_addin')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $byKey = $settings->keyBy('key');
        $changes = $accounts = [];
        foreach ($settings as $setting) {
            if (preg_match('/\Atemplate_choices_user_([1-9][0-9]*)\z/', $setting->key, $match) !== 1) {
                continue;
            }
            if ($settings->where('key', $setting->key)->count() !== 1) {
                throw new RuntimeException('Eine Vorlagenauswahl ist nicht eindeutig gespeichert.');
            }
            $choice = $setting->value;
            if (! is_array($choice) || ($choice['schema'] ?? null) !== 1) {
                throw new RuntimeException('Eine Vorlagenauswahl besitzt ein unbekanntes Format.');
            }
            $groups = [];
            foreach (['newMessages', 'replies'] as $group) {
                if (($choice[$group]['mode'] ?? null) === 'document' && ($choice[$group]['documentId'] ?? null) === $legacyId) {
                    $choice[$group]['documentId'] = $standardId;
                    $groups[] = $group;
                }
            }
            if ($groups === []) {
                continue;
            }
            $user = User::query()->whereKey((int) $match[1])->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if (! $user instanceof User) {
                throw new RuntimeException('Eine betroffene Vorlagenauswahl besitzt kein Benutzerkonto.');
            }
            $wordingKey = 'template_wording_user_'.$user->getKey();
            if ($settings->where('key', $wordingKey)->count() > 1) {
                throw new RuntimeException('Ein persönlicher Textstand ist nicht eindeutig gespeichert.');
            }
            $raw = $byKey->get($wordingKey)?->value;
            $wording = is_array($raw) ? $raw : ['schema' => 1, 'greeting' => OutlookAddinWordingPolicy::DEFAULT_GREETING, 'farewell' => OutlookAddinWordingPolicy::DEFAULT_FAREWELL];
            if (($wording['schema'] ?? null) !== 1) {
                throw new RuntimeException('Ein persönlicher Textstand besitzt ein unbekanntes Format.');
            }
            foreach (['greeting', 'farewell'] as $field) {
                $text = $wording[$field] ?? null;
                if (! is_string($text) || trim($text) === '' || mb_strlen($text) > OutlookAddinWordingPolicy::MAX_LENGTH
                    || preg_match('/[\x00-\x1F\x7F-\x9F<>]|\{\{|\}\}/u', $text)) {
                    throw new RuntimeException('Ein persönlicher Textstand ist ungültig.');
                }
            }
            $initialize = [];
            foreach (['showGreeting', 'showFarewell'] as $field) {
                if (array_key_exists($field, $wording) && ! is_bool($wording[$field])) {
                    throw new RuntimeException('Ein persönlicher Anzeigestand ist ungültig.');
                }
                if ($initializeWording && ! array_key_exists($field, $wording)) {
                    $wording[$field] = true;
                    $initialize[] = $field;
                }
            }
            $changes[] = ['choice' => ['key' => $setting->key, 'value' => $choice],
                'wording' => $initialize === [] ? null : ['key' => $wordingKey, 'value' => $wording]];
            $accounts[] = ['userId' => (int) $user->getKey(), 'groups' => $groups, 'initializeVisibility' => $initialize];
        }
        $hash = hash('sha256', json_encode([
            'revision' => 'legacy-wording-migration-v1', 'legacy' => $legacyId, 'standard' => $standardId, 'initializeWording' => $initializeWording,
            'documents' => $documents->map(fn ($document) => $document->getRawOriginal())->all(),
            'settings' => $settings->map(fn ($setting) => $setting->getRawOriginal())->all(), 'accounts' => $accounts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['receipt' => ['revision' => 'legacy-wording-migration-v1', 'planHash' => $hash,
            'legacyDocument' => $legacyId, 'standardDocument' => $standardId, 'initializeWording' => $initializeWording, 'accounts' => $accounts,
            'accountCount' => count($accounts)], 'changes' => $changes];
    }
}
