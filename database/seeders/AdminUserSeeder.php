<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database with a default admin user and team.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'lucas@zacharias-net.de'],
            [
                'name' => 'Lucas Zacharias',
                'password' => Hash::make('Caludalu1991!'),
                'role' => 'admin',
                'status' => true,
                'profile_photo_path' => '',
                'email_verified_at' => now(),
            ]
        );

        $team = Team::firstOrCreate(
            ['name' => 'RailTime Team', 'personal_team' => false],
            [
                'user_id' => $admin->id,
                'rbac_permissions' => RbacCatalog::defaultTeamPermissions(),
            ]
        );

        $admin->teams()->syncWithoutDetaching([$team->id]);

        if (! $admin->current_team_id) {
            $admin->forceFill(['current_team_id' => $team->id])->save();
        }
    }
}
