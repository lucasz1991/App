<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Legt die drei Basis-Teams (Rechtegruppen) an:
     *
     * - Mitarbeiter:    Basisrechte (keine Verwaltungsrechte)
     * - Verwaltung:     Benutzer-, Datei- und Nachrichtenverwaltung
     * - Administration: fast alle Rechte, aber KEINE Rollen-/Systemverwaltung
     */
    public function run(): void
    {
        // Lucas bleibt der einzige globale Administrator.
        User::query()
            ->where('email', '!=', 'lucas@zacharias-net.de')
            ->where('role', 'admin')
            ->update(['role' => 'staff']);

        $all = RbacCatalog::allPermissions();

        $teams = [
            'Mitarbeiter' => [],
            'Verwaltung' => [
                'employees.view',
                'users.view',
                'users.profiles.view',
                'files.manage',
                'manage.messages',
                'users.messages.view',
                'users.messages.create',
            ],
            'Administration' => array_values(array_diff($all, [
                'roles.manage',
                'settings.manage',
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

            $team = Team::firstOrCreate(
                ['name' => $name, 'personal_team' => false],
                [
                    'user_id' => $owner->id,
                    'rbac_permissions' => $permissions,
                ]
            );

            // Jeder Mitarbeiter ist Mitglied in jedem Default-Team. Nur Lucas
            // erhält auch auf Teamebene die Admin-Rolle.
            $employees = User::query()->whereIn('role', ['admin', 'staff'])->get();
            $team->users()->syncWithoutDetaching(
                $employees->mapWithKeys(fn (User $employee) => [
                    $employee->id => ['role' => $employee->email === 'lucas@zacharias-net.de' ? 'admin' : 'staff'],
                ])->all()
            );

            if ($team->wasRecentlyCreated === false && $team->rbac_permissions !== $permissions) {
                $team->forceFill(['rbac_permissions' => $permissions])->save();
            }
        }

        // Administration als aktives Team für Lucas setzen.
        $administration = Team::where('name', 'Administration')->where('personal_team', false)->first();
        if ($administration) {
            $owner->teams()->syncWithoutDetaching([$administration->id]);
            if (! $owner->current_team_id) {
                $owner->forceFill(['current_team_id' => $administration->id])->save();
            }
        }
    }
}
