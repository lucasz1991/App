<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile\EmployeeDocuments;
use App\Livewire\Admin\Employees\EmployeeFormModal;
use App\Models\EmployeeDocumentRequirement;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeMasterDataSecurityTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Schema::drop('user_profiles');
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('personnel_nr')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function test_profile_values_are_encrypted_at_rest_and_typed_after_decryption(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        DB::table('user_profiles')->insert([
            'user_id' => $user->id,
            'phone' => '040 123456',
            'birth_date' => '1991-05-12',
            'position' => 'Lokführer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migrateEmployeeSecurity();
        $profile = UserProfile::where('user_id', $user->id)->firstOrFail();
        $profile->update([
            'first_name' => 'Mara',
            'last_name' => 'Beispiel',
            'multiple_employment' => false,
            'weekly_working_hours' => '38.50',
            'iban' => 'DE001234567890',
            'compensation_amount' => '3200.75',
        ]);

        $raw = (array) DB::table('user_profiles')->where('id', $profile->id)->first();

        foreach (['first_name', 'last_name', 'phone', 'birth_date', 'multiple_employment', 'weekly_working_hours', 'iban', 'compensation_amount'] as $field) {
            $this->assertNotSame((string) $profile->{$field}, (string) $raw[$field]);
            $this->assertNotSame('', Crypt::decryptString($raw[$field]));
        }

        $fresh = $profile->fresh();
        $this->assertSame('Mara', $fresh->first_name);
        $this->assertSame('040 123456', $fresh->phone);
        $this->assertSame('Lokführer', $fresh->position);
        $this->assertSame('1991-05-12', $fresh->birth_date->format('Y-m-d'));
        $this->assertFalse($fresh->multiple_employment);
        $this->assertSame('38.50', $fresh->weekly_working_hours);
        $this->assertSame('3200.75', $fresh->compensation_amount);
    }

    public function test_new_sensitive_permissions_default_to_disabled_for_every_team(): void
    {
        $this->migrateEmployeeSecurity();
        $defaults = RbacCatalog::defaultTeamPermissions();

        foreach ([
            'employees.master-data.view',
            'employees.master-data.edit',
            'employees.compensation.view',
            'employees.compensation.edit',
        ] as $permission) {
            $this->assertArrayHasKey($permission, $defaults);
            $this->assertFalse($defaults[$permission]);
        }
    }

    public function test_neutral_employee_routes_require_delegated_permission(): void
    {
        $this->migrateEmployeeSecurity();
        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => array_replace(RbacCatalog::defaultTeamPermissions(), ['employees.view' => true]),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);
        $staff->teams()->attach($team);

        $this->actingAs($staff);
        $this->assertTrue(Gate::allows('employees.view'));
        $this->assertFalse(Gate::allows('employees.master-data.view'));
        $this->get(route('employees.index'))->assertOk();

        $unauthorized = User::factory()->create(['role' => 'staff']);
        $this->actingAs($unauthorized)->get(route('employees.index'))->assertForbidden();
    }

    public function test_employee_document_is_stored_separately_from_the_employee_file_pool(): void
    {
        $this->migrateEmployeeSecurity();
        Storage::fake('private');
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'staff']);
        $targetPool = $target->filePool()->create(['title' => 'Privat', 'type' => User::class, 'description' => '']);
        $targetPool->files()->create([
            'name' => 'Normale Datei.pdf',
            'path' => 'uploads/files/normal.pdf',
            'disk' => 'private',
            'type' => 'pdf',
        ]);

        Livewire::actingAs($admin)
            ->test(EmployeeDocuments::class, ['userId' => $target->id])
            ->set('uploads.identity_card', UploadedFile::fake()->create('Ausweis.pdf', 128, 'application/pdf'))
            ->call('save', 'identity_card')
            ->assertHasNoErrors();

        $requirement = EmployeeDocumentRequirement::with('file')->firstOrFail();
        $this->assertSame($target->id, $requirement->user_id);
        $this->assertSame('identity_card', $requirement->document_type);
        $this->assertNotNull($requirement->file);
        $this->assertSame(EmployeeDocumentRequirement::class, $requirement->file->fileable_type);
        $this->assertSame($requirement->id, $requirement->file->fileable_id);
        $this->assertNull($requirement->file->filepool_id);
        $this->assertSame('employee-document', $requirement->file->type);
        $this->assertSame(['Normale Datei.pdf'], $targetPool->files()->pluck('name')->all());
        Storage::disk('private')->assertExists($requirement->file->path);

        $view = file_get_contents(resource_path('views/livewire/admin/user-profile/employee-documents.blade.php'));
        $this->assertStringNotContainsString("__('app.status')", $view);
        $this->assertStringNotContainsString('availableStatuses', $view);
    }

    public function test_edit_flags_never_expose_sensitive_data_without_the_matching_view_flag(): void
    {
        $this->migrateEmployeeSecurity();
        $owner = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'staff']);
        UserProfile::create([
            'user_id' => $target->id,
            'personnel_nr' => 'RT-7788',
            'iban' => 'DE-SENSITIVE-IBAN',
        ]);

        foreach ([[false, false], [false, true], [true, false], [true, true]] as $index => [$view, $edit]) {
            $permissions = array_replace(RbacCatalog::defaultTeamPermissions(), [
                'employees.create' => true,
                'employees.master-data.view' => $view,
                'employees.master-data.edit' => $edit,
                'employees.compensation.view' => $view,
                'employees.compensation.edit' => $edit,
            ]);
            $team = Team::forceCreate([
                'user_id' => $owner->id,
                'name' => 'Rechte '.$index,
                'personal_team' => false,
                'rbac_permissions' => $permissions,
            ]);
            $manager = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);
            $manager->teams()->attach($team);

            $component = Livewire::actingAs($manager)
                ->test(EmployeeFormModal::class)
                ->call('open', $target->id);

            if ($view) {
                $component->assertSet('personnel_nr', 'RT-7788');
                $component->assertSet('iban', 'DE-SENSITIVE-IBAN');
            } else {
                $component->assertSet('personnel_nr', null);
                $component->assertSet('iban', null);
            }
        }
    }

    private function migrateEmployeeSecurity(): void
    {
        (require database_path('migrations/2026_07_22_000001_encrypt_and_expand_user_profiles.php'))->up();
        (require database_path('migrations/2026_07_22_000002_create_employee_document_requirements_table.php'))->up();
        (require database_path('migrations/2026_07_22_000003_separate_employee_documents_from_file_pools.php'))->up();
    }
}
