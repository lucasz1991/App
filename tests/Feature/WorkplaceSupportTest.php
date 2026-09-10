<?php

namespace Tests\Feature;

use App\Livewire\Admin\WorkplaceSupportSettings;
use App\Livewire\Devices\DeviceManagement;
use App\Livewire\Devices\WorkplaceConsent;
use App\Livewire\SupportCases;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceDesktopEnrollment;
use App\Models\User;
use App\Services\DeviceManagement\Desktop\DeviceDesktopService;
use App\Services\DeviceManagement\DeviceReadinessService;
use App\Services\DeviceManagement\DeviceWorkplaceService;
use App\Services\DeviceManagement\WorkplaceProfileCatalog;
use App\Services\Support\SupportAttachmentService;
use App\Services\Support\SupportCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkplaceSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->device = Device::query()->create(['display_name' => 'Privater Pilot', 'platform' => 'windows', 'ownership' => 'byod', 'lifecycle_status' => 'assigned', 'management_status' => 'unmanaged']);
        DeviceAssignment::query()->create(['device_id' => $this->device->id, 'user_id' => $this->employee->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now(), 'status' => 'active']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function consent(): void
    {
        $service = app(DeviceWorkplaceService::class);
        $profile = $service->configure($this->device, 'byod_managed', $this->admin);
        $service->accept($this->device, $this->employee, $profile->revision, WorkplaceProfileCatalog::hash('byod_managed'), true);
    }

    private function supportInput(): array
    {
        return ['request_id' => (string) Str::uuid(), 'subject' => 'Einrichtung funktioniert nicht', 'message' => 'Der Client kann sich seit heute nicht mehr verbinden.', 'category' => 'technical_issue', 'diagnostics' => ['connection' => 'unreachable'], 'diagnostics_confirmed' => true];
    }

    public function test_private_device_requires_owner_consent_and_no_new_microsoft_license(): void
    {
        $service = app(DeviceWorkplaceService::class);
        $service->configure($this->device, 'byod_managed', $this->admin);
        $this->assertFalse($service->permitted($this->device));
        $this->consent();
        $this->assertTrue($service->permitted($this->device));
        $issued = app(DeviceDesktopService::class)->issue($this->device, $this->admin, true);
        $this->assertSame('unpaired', $issued['client']->status);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
        $this->assertSame('byod', $this->device->fresh()->ownership);
        $this->assertStringNotContainsString('system_service', DB::table('device_management_consents')->value('scope'));
    }

    public function test_administrator_cannot_impersonate_private_owner_consent(): void
    {
        app(DeviceWorkplaceService::class)->configure($this->device, 'byod_managed', $this->admin);
        $this->expectException(HttpException::class);
        app(DeviceWorkplaceService::class)->accept($this->device, $this->admin, 1, WorkplaceProfileCatalog::hash('byod_managed'), true);
    }

    public function test_profile_change_invalidates_old_consent(): void
    {
        $this->consent();
        $workplace = app(DeviceWorkplaceService::class)->configure($this->device, 'railtime_basic', $this->admin);
        $this->assertSame(2, $workplace->revision);
        $this->assertFalse(app(DeviceWorkplaceService::class)->permitted($this->device));
    }

    public function test_private_device_wipe_is_never_offered_even_after_consent(): void
    {
        $this->consent();
        $this->expectException(HttpException::class);
        app(DeviceWorkplaceService::class)->assertCommand($this->device, 'wipe');
    }

    public function test_program_scope_change_requires_new_consent_and_exact_version(): void
    {
        $service = app(DeviceWorkplaceService::class);
        $this->consent();
        $program = ['package_id' => 'Synthetic.Application', 'version' => '1.2.3'];
        $service->updatePrograms('byod_managed', [$program], $this->admin);
        $this->assertFalse($service->permitted($this->device));
        $this->consent();
        $service->assertSoftware($this->device, $program);
        $this->assertTrue($service->permitted($this->device));
        $this->expectException(HttpException::class);
        $service->assertSoftware($this->device, ['package_id' => 'Synthetic.Application', 'version' => '1.2.4']);
    }

    public function test_support_case_is_idempotent_encrypted_and_diagnostics_expire(): void
    {
        $service = app(SupportCaseService::class);
        $input = $this->supportInput();
        $case = $service->create($this->employee, $input);
        $this->assertSame($case->id, $service->create($this->employee, $input)->id);
        $this->assertDatabaseCount('support_cases', 1);
        $this->assertDatabaseCount('support_case_messages', 1);
        $this->assertNotSame($input['subject'], DB::table('support_cases')->value('subject'));
        $this->assertStringNotContainsString('unreachable', DB::table('support_cases')->value('diagnostics'));
        $this->travel(31)->days();
        $this->assertNull($service->serialize($case, $this->employee)['diagnostics']);
    }

    public function test_replies_deduplicate_and_employee_can_reopen_case(): void
    {
        $service = app(SupportCaseService::class);
        $case = $service->create($this->employee, $this->supportInput());
        $request = (string) Str::uuid();
        $service->reply($case, $this->admin, $request, 'Bitte die Verbindung erneut prüfen.');
        $service->reply($case, $this->admin, $request, 'Bitte die Verbindung erneut prüfen.');
        $this->assertDatabaseCount('support_case_messages', 2);
        $this->assertSame('waiting_user', $case->fresh()->status);
        $service->transition($case, $this->employee, 'resolved');
        $service->reply($case, $this->employee, (string) Str::uuid(), 'Der Fehler ist leider wieder aufgetreten.');
        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_foreign_support_case_is_private(): void
    {
        $service = app(SupportCaseService::class);
        $case = $service->create($this->employee, $this->supportInput());
        $stranger = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->expectException(HttpException::class);
        $service->serialize($case, $stranger);
    }

    public function test_support_and_workplace_modals_render_with_the_shared_table(): void
    {
        $case = app(SupportCaseService::class)->create($this->employee, $this->supportInput());
        Livewire::actingAs($this->employee)->test(SupportCases::class)
            ->assertSee('Hilfe & Supportfälle')->call('openCase', $case->public_id)->assertSet('showCase', true)->assertSee($case->subject);
        $this->consent();
        Livewire::actingAs($this->employee)->test(WorkplaceConsent::class, ['deviceId' => $this->device->public_id])
            ->call('open')->assertSet('showModal', true)->assertSee('kein isolierter Firmencontainer');
        Livewire::actingAs($this->admin)->test(WorkplaceSupportSettings::class)
            ->assertSee('Arbeitsplätze')->assertSet('clientSecret', '');
    }

    public function test_basic_profile_readiness_does_not_require_office_or_remote_agent(): void
    {
        app(DeviceWorkplaceService::class)->configure($this->device, 'railtime_basic', $this->admin);
        $service = app(DeviceReadinessService::class);
        $required = $service->requiredChecks($this->device);
        $this->assertArrayNotHasKey('identity', $required);
        $this->assertArrayNotHasKey('remote_support', $required);
        $this->assertFalse($service->isReady($this->device));
    }

    public function test_old_client_can_request_help_after_scope_change_but_cannot_run_jobs(): void
    {
        $this->consent();
        $service = app(DeviceDesktopService::class);
        $issued = $service->issue($this->device, $this->admin, true);
        $response = $this->postJson('/api/device-client/v1/enroll', ['enrollment_token' => $issued['enrollment_token'], 'client_instance_id' => (string) Str::uuid(), 'client_version' => '0.1.0', 'platform' => 'windows', 'device_name' => 'Private Pilot'])->assertOk();
        $token = $response['access_token'];
        app(DeviceWorkplaceService::class)->configure($this->device, 'railtime_basic', $this->admin);
        $this->withToken($token)->postJson('/api/device-client/v1/support', $this->supportInput())->assertOk();
        $this->withToken($token)->getJson('/api/device-client/v1/support')->assertOk()->assertJsonCount(1, 'cases');
        $this->withToken($token)->postJson('/api/device-client/v1/sync', ['client_version' => '0.1.0', 'capabilities' => ['notification'], 'report' => []])->assertForbidden();
    }

    public function test_withdraw_revokes_tokens_and_retains_pending_cleanup_truthfully(): void
    {
        $this->consent();
        $issued = app(DeviceDesktopService::class)->issue($this->device, $this->admin, true);
        app(DeviceWorkplaceService::class)->revoke($this->device, $this->employee);
        $this->assertSame('cleanup_pending', app(DeviceWorkplaceService::class)->summary($this->device)['management_state']);
        $this->assertSame('revoked', $issued['client']->fresh()->status);
        $this->assertNotNull(DeviceDesktopEnrollment::query()->first()->revoked_at);
        $this->assertSame('byod', $this->device->fresh()->ownership);
    }

    public function test_selected_support_attachment_is_private_encrypted_authorized_and_purged(): void
    {
        Storage::fake('private');
        $case = app(SupportCaseService::class)->create($this->employee, $this->supportInput());
        $service = app(SupportAttachmentService::class);
        $file = UploadedFile::fake()->image('explicitly-selected.png', 20, 20);
        $attachment = $service->store($case, $this->employee, $file, true);
        $encrypted = Storage::disk('private')->get($attachment->path);
        $this->assertNotSame(file_get_contents($file->getRealPath()), $encrypted);
        $this->actingAs($this->employee)->get(route('support.attachment', $attachment->public_id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $stranger = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($stranger)->get(route('support.attachment', $attachment->public_id))->assertForbidden();
        $this->travel(31)->days();
        $this->actingAs($this->employee)->get(route('support.attachment', $attachment->public_id))->assertNotFound();
        $this->artisan('devices:purge-support-data')->assertSuccessful();
        Storage::disk('private')->assertMissing($attachment->path);
        $this->assertNull($case->fresh()->diagnostics);
    }

    public function test_repeated_withdrawal_only_recovers_receipt_not_device_access(): void
    {
        $this->consent();
        $issued = app(DeviceDesktopService::class)->issue($this->device, $this->admin, true);
        $enrollment = $this->postJson('/api/device-client/v1/enroll', ['enrollment_token' => $issued['enrollment_token'], 'client_instance_id' => (string) Str::uuid(), 'client_version' => '0.1.0', 'platform' => 'windows', 'device_name' => 'Synthetic private pilot'])->assertOk();
        $token = $enrollment['access_token'];
        $this->withToken($token)->postJson('/api/device-client/v1/withdraw', ['confirmed' => true])->assertOk()->assertJsonPath('cleanup_state', 'pending');
        $this->withToken($token)->postJson('/api/device-client/v1/withdraw', ['confirmed' => true])->assertOk()->assertJsonPath('revoked', true);
        $this->assertDatabaseCount('device_withdrawal_receipts', 1);
        $this->assertNotSame($token, DB::table('device_withdrawal_receipts')->value('token_hash'));
        $this->withToken($token)->getJson('/api/device-client/v1/support')->assertUnauthorized();
        $this->withToken(str_repeat('z', 80))->postJson('/api/device-client/v1/withdraw', ['confirmed' => true])->assertUnauthorized();
    }

    public function test_device_table_filters_private_ownership_and_links_open_help(): void
    {
        app(SupportCaseService::class)->create($this->employee, $this->supportInput() + ['device_id' => $this->device->public_id]);
        Livewire::actingAs($this->admin)->test(DeviceManagement::class)
            ->assertSee('Privater Pilot')->assertSee('1 offene Hilfe')
            ->set('ownershipFilter', 'corporate')->assertDontSee('Privater Pilot')
            ->set('ownershipFilter', 'byod')->assertSee('Privater Pilot');
    }

    public function test_temporary_upload_requires_consent_and_private_storage_before_url_issuance(): void
    {
        $case = app(SupportCaseService::class)->create($this->employee, $this->supportInput());
        $info = [['name' => 'selected.png', 'size' => 1024, 'type' => 'image/png']];
        Livewire::actingAs($this->employee)->test(SupportCases::class)
            ->call('openCase', $case->public_id)->call('_startUpload', 'attachment', $info, false)->assertStatus(422);
        config()->set('filesystems.default', 'public');
        Livewire::actingAs($this->employee)->test(SupportCases::class)
            ->call('openCase', $case->public_id)->set('attachmentConfirmed', true)
            ->call('_startUpload', 'attachment', $info, false)->assertStatus(503);
    }
}
