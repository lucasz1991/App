<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Legt die vier Basis-Teams (Rechtegruppen) an:
     *
     * - Gäste:         Assistent im Nutzerbereich
     * - Mitarbeiter:   Assistent und Basiszugriff im Nutzerbereich
     * - Verwaltung:    Systemuebersicht im Nutzerbereich
     * - Administratoren: Systemuebersicht im Nutzerbereich
     */
    public function run(): void
    {
        // Lucas bleibt der einzige globale Administrator.
        User::query()
            ->where('email', '!=', 'lucas@zacharias-net.de')
            ->where('role', 'admin')
            ->update(['role' => 'staff']);

        // Historische Namen auf die sichtbaren Teambezeichnungen vereinheitlichen.
        Team::query()
            ->where('personal_team', false)
            ->whereIn('name', ['Administration', 'Administrator'])
            ->update(['name' => 'Administratoren']);

        Team::query()
            ->where('personal_team', false)
            ->whereIn('name', ['Gast', 'Gaeste'])
            ->update(['name' => 'Gäste']);

        $all = RbacCatalog::allPermissions();

        $teams = [
            'Gäste' => [
                'assistant.use',
            ],
            'Mitarbeiter' => [
                'assistant.use',
            ],
            'Verwaltung' => [
                'assistant.use',
                'employees.view',
                'users.view',
                'users.profiles.view',
                'files.manage',
                'manage.messages',
                'users.messages.view',
                'users.messages.create',
            ],
            'Administratoren' => array_values(array_diff($all, [
                'roles.manage',
                'settings.manage',
                'employees.master-data.view',
                'employees.master-data.edit',
                'employees.compensation.view',
                'employees.compensation.edit',
            ])),
        ];

        $owner = User::query()->where('role', 'admin')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $owner) {
            return;
        }

        foreach ($teams as $name => $granted) {
            $permissions = [];
            foreach ($all as $permission) {
                $permissions[$permission] = in_array($permission, $granted, true);
            }

            $team = Team::firstOrNew(['name' => $name, 'personal_team' => false]);

            if (! $team->exists) {
                // user_id ist aus gutem Grund nicht mass-assignable; Seeder
                // setzen den System-Owner deshalb ausdruecklich.
                $team->forceFill([
                    'user_id' => $owner->id,
                    'rbac_permissions' => $permissions,
                ])->save();
            } elseif ($team->rbac_permissions !== $permissions) {
                $team->forceFill(['rbac_permissions' => $permissions])->save();
            }
        }

        // Nur der globale Admin wird automatisch dem Administratoren-Team zugeordnet.
        $administration = Team::where('name', 'Administratoren')->where('personal_team', false)->first();
        if ($administration) {
            $owner->teams()->syncWithoutDetaching([$administration->id]);
            if (! $owner->current_team_id) {
                $owner->forceFill(['current_team_id' => $administration->id])->save();
            }
        }
    }
}
