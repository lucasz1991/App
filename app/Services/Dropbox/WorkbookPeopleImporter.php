<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxSource;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkbookPeopleImporter
{
    /** Explicit administrator operation; creates no invitations or verified logins. */
    public function import(DropboxConnection $connection, array $entries, User $actor): array
    {
        app(ConnectionManager::class)->authorize($actor);

        return SyncContext::import(fn () => DB::transaction(function () use ($connection, $entries) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            abort_unless(! $fresh->isLocalImport() && $fresh->generation === $connection->generation && $fresh->mode === DropboxMode::Off, 409, 'Abgleich vor der Mitarbeiterübernahme ausschalten.');
            $summary = ['created' => 0, 'existing' => 0, 'mapped' => 0, 'providers' => 0, 'review' => 0];
            $staff = [];
            $providers = [];
            foreach ($entries as $entry) {
                if (($entry['domain'] ?? '') !== 'contacts') {
                    continue;
                }
                $name = trim($entry['locator']['subject'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (($entry['locator']['kind'] ?? '') === 'provider') {
                    $providers[WorkbookReader::normalize($name)] = $name;
                } elseif (($entry['locator']['kind'] ?? '') === 'employee' && trim($entry['values']['first_name'] ?? '') !== '' && trim($entry['values']['last_name'] ?? '') !== '') {
                    $staff[WorkbookReader::normalize($name)][] = $entry;
                }
            }
            $index = [];
            foreach ($staff as $alias => $rows) {
                $entry = $rows[0];
                $name = trim($entry['locator']['subject']);
                // Do not merge distinct people sharing a name or reinterpret compound labels.
                if (count($rows) !== 1 || preg_match('#[/&+]#u', $name) || isset($providers[$alias])) {
                    $summary['review']++;

                    continue;
                }
                $matches = User::where('role', 'staff')->get()->filter(fn ($u) => self::key($u->name) === self::key($name));
                if ($matches->count() > 1) {
                    $summary['review']++;

                    continue;
                }
                $user = $matches->first();
                if (! $user) {
                    $email = trim($entry['values']['contact_email'] ?? '');
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
                        $email = 'import-'.substr(hash('sha256', $alias), 0, 20).'@railtime.invalid';
                    }
                    if (User::where('email', $email)->exists()) {
                        $summary['review']++;

                        continue;
                    }
                    $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(64)), 'role' => 'staff', 'status' => true, 'locale' => 'de']);
                    UserProfile::firstOrCreate(['user_id' => $user->id], ['first_name' => $entry['values']['first_name'], 'last_name' => $entry['values']['last_name']]);
                    $summary['created']++;
                } else {
                    $summary['existing']++;
                }
                if (! $user->status) {
                    $summary['review']++;

                    continue;
                }
                foreach ([$name, trim($entry['values']['last_name'].' '.$entry['values']['first_name'])] as $variant) {
                    $index[self::key($variant)][$user->id] = $user->id;
                }
                app(DomainAdapter::class)->identity($fresh, $name);
            }
            foreach ($providers as $alias => $name) {
                $identity = app(DomainAdapter::class)->identity($fresh, $name);
                if ($identity->user_id || $identity->kind === 'employee' || isset($staff[$alias])) {
                    $summary['review']++;

                    continue;
                }
                $identity->update(['kind' => 'provider', 'user_id' => null, 'revision' => $identity->revision + 1]);
                $summary['providers']++;
            }
            foreach (DropboxIdentity::where('connection_id', $fresh->id)->get() as $identity) {
                $name = $identity->details['display_name'] ?? $identity->alias;
                if ($identity->kind === 'provider' || preg_match('#[/&+]#u', $name)) {
                    continue;
                }
                $ids = $index[self::key($name)] ?? [];
                if (count($ids) !== 1 || ($identity->user_id && ! isset($ids[$identity->user_id]))) {
                    continue;
                }
                $identity->update(['kind' => 'employee', 'user_id' => reset($ids), 'revision' => $identity->revision + 1]);
                $summary['mapped']++;
            }
            DropboxSource::where('connection_id', $fresh->id)->update(['progress' => null, 'processed_rev' => null]);

            return $summary;
        }, 1));
    }

    private static function key(string $name): string
    {
        return WorkbookReader::normalize(Str::ascii($name));
    }
}
