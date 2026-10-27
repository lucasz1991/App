<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Plain personal wording only; the renderer owns the opt-in variant and canonical employee name. */
final class OutlookAddinWordingPolicy
{
    public const DEFAULT_GREETING = 'Guten Tag,';

    public const DEFAULT_FAREWELL = 'Mit freundlichen Grüßen,';

    public const MAX_LENGTH = 120;

    public function preferencesForUser(User $user): array
    {
        return $this->readForUser($user);
    }

    public function readForUser(User $user): array
    {
        return $this->value($user, Setting::getValueUncached(OutlookAddinAutomaticInsertionPolicy::GROUP, $this->key($user)));
    }

    public function readForSession(User $user): array
    {
        return DB::transaction(fn (): array => $this->readForUser($this->sessionUser($user)), 3);
    }

    public function updateForSession(User $user, mixed $greeting, mixed $farewell, string $revision): array
    {
        $texts = $this->texts($greeting, $farewell);
        if (preg_match('/\A[0-9a-f]{64}\z/', $revision) !== 1) {
            throw ValidationException::withMessages(['wording' => 'Bitte die gespeicherten Texte neu laden.']);
        }

        return DB::transaction(function () use ($user, $texts, $revision): array {
            $employee = $this->sessionUser($user);
            $setting = Setting::query()->where('type', OutlookAddinAutomaticInsertionPolicy::GROUP)
                ->where('key', $this->key($employee))->lockForUpdate()->first();
            $current = $this->value($employee, $setting?->value);
            if (! hash_equals($current['revision'], $revision)) {
                throw new OutlookAddinException('Die persönlichen Vorlagentexte wurden inzwischen geändert.', 409, 'outlook_addin_wording_conflict');
            }
            if ($current['greeting'] === $texts['greeting'] && $current['farewell'] === $texts['farewell']) {
                return $current;
            }
            $stored = array_replace((array) $setting?->value, ['schema' => 1] + $texts + ['nonce' => Str::random(32)]);
            Setting::setValue(OutlookAddinAutomaticInsertionPolicy::GROUP, $this->key($employee), $stored);
            $next = $this->value($employee, $stored);
            activity('outlook-addin')->causedBy($employee)->performedOn($employee)->withProperties([
                'employee_id' => (int) $employee->getKey(),
                'greeting_changed' => $current['greeting'] !== $texts['greeting'],
                'farewell_changed' => $current['farewell'] !== $texts['farewell'],
                'revision_before' => $current['revision'], 'revision_after' => $next['revision'],
            ])->log('outlook_addin_template_wording_changed');

            return $next;
        }, 3);
    }

    private function sessionUser(User $user): User
    {
        if (! Auth::user() instanceof User || (int) Auth::id() !== (int) $user->getKey()) {
            throw $this->sessionUnavailable();
        }
        $employee = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
        if (! $employee instanceof User || ! $employee->isActive() || $employee->email_verified_at === null
            || ! in_array($employee->role, ['admin', 'staff'], true)) {
            throw $this->sessionUnavailable();
        }

        return $employee;
    }

    private function sessionUnavailable(): OutlookAddinException
    {
        return new OutlookAddinException('Persönliche Vorlagentexte benötigen deine aktive, bestätigte Mitarbeiteranmeldung.', 403, 'outlook_addin_wording_not_allowed');
    }

    private function key(User $user): string
    {
        if ((int) $user->getKey() < 1) {
            throw new RuntimeException('Persönliche Vorlagentexte benötigen ein bestehendes Mitarbeiterkonto.');
        }

        return 'template_wording_user_'.(int) $user->getKey();
    }

    private function value(User $user, mixed $stored): array
    {
        if ($stored === null) {
            $texts = ['greeting' => self::DEFAULT_GREETING, 'farewell' => self::DEFAULT_FAREWELL];
        } else {
            if (! is_array($stored) || ($stored['schema'] ?? null) !== 1) {
                throw new RuntimeException('Die persönlichen Vorlagentexte sind ungültig.');
            }
            try {
                $texts = $this->texts($stored['greeting'] ?? null, $stored['farewell'] ?? null);
            } catch (ValidationException $error) {
                throw new RuntimeException('Die persönlichen Vorlagentexte sind ungültig.', 0, $error);
            }
        }

        return ['schema' => 1] + $texts + ['revision' => hash('sha256', json_encode([(int) $user->getKey(), $stored], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    private function texts(mixed $greeting, mixed $farewell): array
    {
        $values = ['greeting' => is_string($greeting) ? trim($greeting, ' ') : $greeting, 'farewell' => is_string($farewell) ? trim($farewell, ' ') : $farewell];
        $plain = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~[\x00-\x1f\x7f-\x9f\x{2028}\x{2029}<>]|\{\{|\}\}|\{!!|!!\}~u', $decoded) !== 0) {
                $fail('Bitte nur einen einzeiligen Text ohne HTML oder Platzhalter eingeben.');
            }
        };
        Validator::make($values, [
            'greeting' => ['bail', 'required', 'string', 'max:'.self::MAX_LENGTH, $plain],
            'farewell' => ['bail', 'required', 'string', 'max:'.self::MAX_LENGTH, $plain],
        ])->validate();

        return $values;
    }
}
