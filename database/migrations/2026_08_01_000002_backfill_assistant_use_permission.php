<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateTeams(static function (array $permissions): array {
            $permissions['assistant.use'] = true;

            return $permissions;
        });
    }

    public function down(): void
    {
        $this->updateTeams(static function (array $permissions): array {
            unset($permissions['assistant.use']);

            return $permissions;
        });
    }

    /**
     * JSON wird bewusst in PHP veraendert, damit MySQL, MariaDB und SQLite
     * dieselbe reversible Migration ausfuehren und alle fremden Rechte
     * byte-unabhaengig erhalten bleiben.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $transform
     */
    private function updateTeams(callable $transform): void
    {
        DB::table('teams')
            ->where('personal_team', false)
            ->orderBy('id')
            ->chunkById(100, function ($teams) use ($transform): void {
                foreach ($teams as $team) {
                    $permissions = json_decode((string) ($team->rbac_permissions ?? ''), true);
                    $permissions = is_array($permissions) ? $permissions : [];

                    DB::table('teams')
                        ->where('id', $team->id)
                        ->update([
                            'rbac_permissions' => json_encode(
                                $transform($permissions),
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                            ),
                        ]);
                }
            }, 'id');
    }
};
