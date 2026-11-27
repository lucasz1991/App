<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** An explicit supplier contact is role evidence. A planning label alone is not. */
final class WorkbookContactRoles
{
    public function __construct(private EmployeeIdentityResolver $names, private SyncGuard $guard) {}

    public function prepare(DropboxConnection $connection, array $parsed, bool $preview): array
    {
        if (! in_array('contacts', $connection->option('domains'), true)) {
            return [$parsed, 0];
        }

        $contacts = collect($parsed['rows'])->filter(fn ($row) => $row['domain'] === 'contacts');
        $suppliers = $contacts->filter(fn ($row) => ($row['locator']['kind'] ?? null) === 'provider' && filled($row['locator']['subject'] ?? null));
        if ($suppliers->isEmpty()) {
            return [$parsed, 0];
        }

        $employeeKeys = [];
        foreach ($contacts->where('locator.kind', 'employee') as $contact) {
            $employeeKeys += array_fill_keys($this->keys($contact['locator']['subject']), true);
        }
        foreach (DropboxIdentity::where('connection_id', $connection->id)->where('kind', 'employee')->get() as $employee) {
            $employeeKeys += array_fill_keys([...$this->keys($employee->alias), ...$this->keys($employee->details['display_name'] ?? '')], true);
        }
        foreach (User::where('role', 'staff')->with('profile')->get() as $user) {
            $employeeKeys += array_fill_keys($this->keys($user->name), true);
            if (filled($user->profile?->first_name) && filled($user->profile?->last_name)) {
                $employeeKeys += array_fill_keys([...$this->keys($user->profile->first_name.' '.$user->profile->last_name), ...$this->keys($user->profile->last_name.' '.$user->profile->first_name)], true);
            }
        }

        $safe = [];
        foreach ($suppliers as $index => $supplier) {
            $label = $supplier['locator']['subject'];
            if (array_intersect_key($employeeKeys, array_fill_keys($this->keys($label), true))) {
                $parsed['rows'][$index]['locator']['contact_role_conflict'] = true;
            } else {
                $safe[WorkbookReader::normalize($label)] = $label;
            }
        }
        if ($preview || $connection->isLocalImport() || ! $safe) {
            return [$parsed, 0];
        }

        $changed = SyncContext::import(fn () => DB::transaction(function () use ($connection, $safe) {
            $this->guard->current($connection, lock: true);
            $changed = 0;
            foreach ($safe as $alias => $label) {
                $identity = DropboxIdentity::where('connection_id', $connection->id)->where('alias', $alias)->lockForUpdate()->first();
                if ($identity && ($identity->kind !== 'unresolved' || $identity->user_id)) {
                    continue;
                }
                if ($identity && DropboxRecord::where('connection_id', $connection->id)->where('domain', 'contacts')->where('model_type', 'DropboxIdentity')
                    ->where('model_id', $identity->id)->where('metadata->kind', 'employee')->exists()) {
                    continue; // An accepted employee role must be reviewed, even before a user is linked.
                }
                $identity ??= new DropboxIdentity(['connection_id' => $connection->id, 'alias' => $alias, 'details' => ['display_name' => $label]]);
                $identity->forceFill(['kind' => 'provider', 'revision' => $identity->revision + 1])->save();
                $changed++;
            }

            return $changed;
        }, 3));

        return [$parsed, $changed];
    }

    private function keys(string $label): array
    {
        return array_values(array_filter([WorkbookReader::normalize($label), ...$this->names->nameKeys($label)]));
    }
}
