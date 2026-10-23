<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxSource;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Operations\OperationsAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class HistoricalContactProvisioner
{
    public function __construct(private EmployeeIdentityResolver $resolver) {}

    /** Explicit policy: contact evidence and a past MA cell may create an inactive historical person. */
    public function reconcile(DropboxConnection $connection, array $parsed, User $actor): array
    {
        $summary = ['created' => 0, 'existing' => 0, 'mapped' => 0, 'review' => 0];
        if ($connection->option('historical_import_contacts') !== true || $connection->isLocalImport()
            || ! in_array($connection->mode, [DropboxMode::Import, DropboxMode::Bidirectional], true)
            || array_diff(['planning', 'assignments', 'contacts'], (array) $connection->option('domains'))) {
            return $summary;
        }
        app(ConnectionManager::class)->authorize($actor);

        return SyncContext::import(fn () => DB::transaction(function () use ($connection, $parsed, $actor, $summary) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            if ($fresh->generation !== $connection->generation || $fresh->isLocalImport() || ! $fresh->refresh_token
                || $fresh->option('historical_import_contacts') !== true
                || array_diff(['planning', 'assignments', 'contacts'], (array) $fresh->option('domains'))
                || ! in_array($fresh->mode, [DropboxMode::Import, DropboxMode::Bidirectional], true)) {
                throw new DropboxApiException('configuration_changed');
            }
            $sourceId = isset($parsed['source_id']) ? (int) $parsed['source_id'] : null;
            if ($sourceId !== null && ! DropboxSource::where('connection_id', $fresh->id)->whereKey($sourceId)->exists()) {
                throw new DropboxApiException('source_unavailable');
            }
            $contacts = $past = $providers = $emails = [];
            foreach ($parsed['rows'] ?? [] as $row) {
                if (($row['domain'] ?? '') === 'planning' && $this->past($row['values'] ?? [], (string) $fresh->option('timezone'))) {
                    $alias = WorkbookReader::normalize((string) ($row['values']['employee'] ?? ''));
                    if ($alias !== '') {
                        $past[$alias][] = $this->provenance($row);
                    }
                }
                if (($row['domain'] ?? '') !== 'contacts') {
                    continue;
                }
                $alias = WorkbookReader::normalize((string) ($row['locator']['subject'] ?? ''));
                if (($row['locator']['kind'] ?? '') === 'provider') {
                    $providers[$alias] = true;
                } elseif (($row['locator']['kind'] ?? '') === 'employee') {
                    $contacts[$alias][] = $row;
                    $email = trim((string) ($row['values']['contact_email'] ?? ''));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emails[mb_strtolower($email)][$alias] = true;
                    }
                }
            }
            foreach ($contacts as $alias => $rows) {
                if (! isset($past[$alias])) {
                    continue;
                }
                $row = $rows[0];
                $name = trim((string) $row['locator']['subject']);
                if (count($rows) !== 1 || isset($providers[$alias]) || ! $this->key($name)
                    || (isset($row['values']['name']) && WorkbookReader::normalize((string) $row['values']['name']) !== $alias)) {
                    $summary['review']++;

                    continue;
                }
                $identity = DropboxIdentity::firstOrCreate(['connection_id' => $fresh->id, 'alias' => $alias],
                    ['kind' => 'unresolved', 'details' => ['display_name' => $name]]);
                if ($identity->kind === 'provider' || ! in_array($identity->kind, ['employee', 'unresolved'], true)) {
                    $summary['review']++;

                    continue;
                }
                $previousUser = $identity->user_id;
                $identity = $this->resolver->resolve($fresh, $identity, historical: true);
                if ($identity->user_id) {
                    $summary['existing']++;
                    if (! $previousUser) {
                        $summary['mapped']++;
                        $this->audit(User::findOrFail($identity->user_id), $actor, $fresh, $identity, $row, $past[$alias], false, $sourceId);
                    }

                    continue;
                }
                // Resolver ambiguity must not turn into another account. Include real profile names.
                $matches = User::with('profile')->lockForUpdate()->get()->filter(fn (User $user) => $this->matches($name, $user));
                if ($matches->isNotEmpty()) {
                    $summary['review']++;

                    continue;
                }
                $email = trim((string) ($row['values']['contact_email'] ?? ''));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    if (count($emails[mb_strtolower($email)] ?? []) > 1 || User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                        $summary['review']++;

                        continue;
                    }
                } else {
                    $email = 'import-'.substr(hash('sha256', $alias), 0, 20).'@railtime.invalid';
                    if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        $summary['review']++;

                        continue;
                    }
                }
                $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(64)),
                    'role' => 'staff', 'status' => false, 'locale' => 'de']);
                $profile = array_filter(array_intersect_key($row['values'], array_flip(['first_name', 'last_name'])),
                    fn ($value) => is_string($value) && trim($value) !== '');
                if ($profile !== []) {
                    UserProfile::create(['user_id' => $user->id, ...$profile]);
                }
                $identity->fill(['kind' => 'employee', 'user_id' => $user->id, 'revision' => $identity->revision + 1])->save();
                $this->audit($user, $actor, $fresh, $identity, $row, $past[$alias], true, $sourceId);
                $summary['created']++;
                $summary['mapped']++;
            }

            return $summary;
        }, 1));
    }

    private function audit(User $user, User $actor, DropboxConnection $connection, DropboxIdentity $identity, array $contact, array $planning, bool $created, ?int $sourceId): void
    {
        app(OperationsAuditService::class)->record($user, $actor, $created ? 'user.import_history_created' : 'user.import_history_mapped', [
            'connection_id' => $connection->id, 'identity_id' => $identity->id, 'user_id' => $user->id,
            'contact' => $this->provenance($contact), 'planning' => array_values($planning),
            'contact_fingerprint' => WorkbookReader::fingerprint($contact['values'] ?? []), 'source' => 'historical_contact_policy',
            ...($sourceId !== null ? ['source_id' => $sourceId] : []),
        ]);
    }

    private function provenance(array $row): array
    {
        return ['sheet' => (string) ($row['sheet'] ?? ''), 'slot' => (string) ($row['slot'] ?? '')];
    }

    private function key(string $name): ?string
    {
        if (preg_match('#[/&+;|@\r\n]|\b(?:und|oder|and|gmbh|gbr|ug|kg|ag|ohg|firma|dienstleister)\b#iu', $name)) {
            return null;
        }
        $key = WorkbookReader::normalize(Str::ascii(preg_replace('/[,.]+/u', ' ', $name)));

        return str_word_count($key) >= 2 ? $key : null;
    }

    private function matches(string $name, User $user): bool
    {
        $key = $this->key($name);
        $names = [$user->name];
        $parts = preg_split('/\s+/u', trim($user->name));
        if (count($parts) === 2) {
            $names[] = implode(' ', array_reverse($parts));
        }
        if (trim((string) $user->profile?->first_name) !== '' && trim((string) $user->profile?->last_name) !== '') {
            $names[] = $user->profile->first_name.' '.$user->profile->last_name;
            $names[] = $user->profile->last_name.' '.$user->profile->first_name;
        }

        return in_array($key, array_filter(array_map(fn ($value) => $this->key($value), $names)), true);
    }

    private function past(array $values, string $timezone): bool
    {
        try {
            $date = $values['date'] ?? '';
            $starts = $values['starts'] ?? '';
            $ends = $values['ends'] ?? '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $starts)
                || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $ends)) {
                return false;
            }
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$starts, $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$ends, $timezone);
            if ($start->format('Y-m-d H:i') !== $date.' '.$starts || $end->format('Y-m-d H:i') !== $date.' '.$ends) {
                return false;
            }
            $end = $end->lte($start) ? $end->addDay() : $end;

            return $end->lte(CarbonImmutable::now());
        } catch (\Throwable) {
            return false;
        }
    }
}
