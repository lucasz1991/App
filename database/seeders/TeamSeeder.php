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
     * - Gast:          keine Rechte (nur Downloads/Chat im Nutzerbereich)
     * - Mitarbeiter:   Basisrechte (Nutzerbereich)
     * - Verwaltung:    Benutzer-, Datei- und Nachrichtenverwaltung (Admin-Layout)
     * - Administrator: fast alle Rechte, aber KEINE Rollen-/Systemverwaltung (Admin-Layout)
     */
    public function run(): void
    {
        // Lucas bleibt der einzige globale Administrator.
        User::query()
            ->where('email', '!=', 'lucas@zacharias-net.de')
            ->where('role', 'admin')
            ->update(['role' => 'staff']);

        // Historischer Name: "Administration" -> "Administrator"
        Team::query()
            ->where('personal_team', false)
            ->where('name', 'Administration')
            ->update(['name' => 'Administrator']);

        $all = RbacCatalog::allPermissions();

        $teams = [
            'Gast' => [],
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
            'Administrator' => array_values(array_diff($all, [
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

            // Bestehende Mitarbeiter werden Mitglied der beiden Admin-Teams
            // (Verwaltung/Administrator); Gast + Mitarbeiter bleiben fuer
            // normale Nutzer reserviert. Nur Lucas erhaelt Team-Admin-Rolle.
            if (in_array($name, ['Verwaltung', 'Administrator'], true)) {
                $employees = User::query()->whereIn('role', ['admin', 'staff'])->get();
                $team->users()->syncWithoutDetaching(
                    $employees->mapWithKeys(fn (User $employee) => [
                        $employee->id => ['role' => $employee->email === 'lucas@zacharias-net.de' ? 'admin' : 'staff'],
                    ])->all()
                );
            }

            if ($team->wasRecentlyCreated === false && $team->rbac_permissions !== $permissions) {
                $team->forceFill(['rbac_permissions' => $permissions])->save();
            }
        }

        // Administrator als aktives Team für Lucas setzen.
        $administration = Team::where('name', 'Administrator')->where('personal_team', false)->first();
        if ($administration) {
            $owner->teams()->syncWithoutDetaching([$administration->id]);
            if (! $owner->current_team_id) {
                $owner->forceFill(['current_team_id' => $administration->id])->save();
            }
        }
    }
}
