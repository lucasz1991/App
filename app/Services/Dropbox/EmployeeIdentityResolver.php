<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeIdentityResolver
{
    /** Match existing personal accounts only; never provision or merge people from a free-text label. */
    public function resolve(DropboxConnection $connection, DropboxIdentity $identity, bool $historical = false): DropboxIdentity
    {
        if ((int) $identity->connection_id !== (int) $connection->id || $identity->user_id || ! in_array($identity->kind, ['unresolved', 'employee'], true)) {
            return $identity;
        }
        $label = (string) ($identity->details['display_name'] ?? $identity->alias);
        $key = $this->key($label);
        if (! $key) {
            return $identity;
        }
        $matches = User::where('role', 'staff')->when(! $historical, fn ($query) => $query->where('status', true))->with('profile')->get()->filter(function (User $user) use ($key) {
            $names = [$user->name];
            $parts = preg_split('/\s+/u', trim($user->name));
            if (count($parts) === 2) {
                $names[] = implode(' ', array_reverse($parts));
            }
            if (trim((string) $user->profile?->first_name) !== '' && trim((string) $user->profile?->last_name) !== '') {
                $names[] = $user->profile->first_name.' '.$user->profile->last_name;
                $names[] = $user->profile->last_name.' '.$user->profile->first_name;
            }

            return in_array($key, array_filter(array_map(fn ($name) => $this->key((string) $name), $names)), true);
        });
        if ($matches->count() !== 1) {
            return $identity;
        }
        $user = $matches->first();

        return SyncContext::import(fn () => DB::transaction(function () use ($identity, $user, $historical) {
            $fresh = DropboxIdentity::lockForUpdate()->findOrFail($identity->id);
            $currentUser = $user->fresh();
            if (! $fresh->user_id && in_array($fresh->kind, ['unresolved', 'employee'], true) && ($currentUser?->status || $historical) && $currentUser?->role === 'staff') {
                $fresh->fill(['kind' => 'employee', 'user_id' => $user->id, 'revision' => $fresh->revision + 1])->save();
            }

            return $fresh;
        }, 1));
    }

    private function key(string $name): ?string
    {
        if (preg_match('#[/&+;|\r\n]|\b(?:und|oder|and|gmbh|gbr|ug|kg)\b#iu', $name)) {
            return null;
        }
        $name = WorkbookReader::normalize(Str::ascii(preg_replace('/[,.]+/u', ' ', $name)));

        return str_word_count($name) >= 2 ? $name : null;
    }
}
