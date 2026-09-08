<?php

namespace Tests\Feature;

use App\Livewire\Devices\DeviceDesktopClients;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceDesktopClient;
use App\Models\DeviceDesktopEnrollment;
use App\Models\DeviceDesktopJob;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\Desktop\DeviceDesktopService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeviceDesktopClientTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Device $device;

    private DeviceAssignment $assignment;

    private DeviceDesktopService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->device = Device::query()->create(['display_name' => 'Pilot Windows', 'platform' => 'windows',
            'ownership' => 'corporate', 'lifecycle_status' => 'assigned', 'management_status' => 'unmanaged']);
        $this->assignment = DeviceAssignment::query()->create(['device_id' => $this->device->id,
            'user_id' => $this->employee->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now(), 'status' => 'active']);
        $this->service = app(DeviceDesktopService::class);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->globalGate(false);
    }

    public function test_pairing_uses_hashed_single_use_expiring_tokens_and_never_marks_device_managed(): void
    {
        $issued = $this->issue();
        $this->assertSame(hash('sha256', $issued['enrollment_token']), DeviceDesktopEnrollment::first()->token_hash);
        $this->assertStringNotContainsString($issued['enrollment_token'], DB::table('device_desktop_enrollments')->first()->token_hash);
        $response = $this->postJson('/api/device-client/v1/enroll', $this->enrollmentInput($issued));
        $response->assertOk()->assertJsonPath('device_id', $this->device->public_id)->assertJsonPath('jobs', [])
            ->assertJsonPath('policy.allow_software_install', false)->assertJsonPath('setup_ready', false)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $client = DeviceDesktopClient::first();
        $this->assertSame(hash('sha256', $response['access_token']), $client->token_hash);
        $this->assertArrayNotHasKey('token_hash', $client->toArray());
        $this->assertSame('unmanaged', $this->device->fresh()->management_status->value);
        $this->assertNull($this->device->fresh()->last_seen_at);
        $this->postJson('/api/device-client/v1/enroll', $this->enrollmentInput($issued))->assertUnauthorized();
        $audit = json_encode(DB::table('activity_log')->get());
        $this->assertStringNotContainsString($response['access_token'], $audit);
        $this->assertStringNotContainsString($issued['enrollment_token'], $audit);
        Mail::assertNothingSent();
    }

    public function test_expired_and_revoked_invitations_cannot_be_claimed(): void
    {
        $issued = $this->issue();
        $this->travel(16)->minutes();
        $this->postJson('/api/device-client/v1/enroll', $this->enrollmentInput($issued))->assertUnauthorized();
        $this->service->revoke($issued['client'], $this->admin);
        $this->postJson('/api/device-client/v1/enroll', $this->enrollmentInput($issued))->assertUnauthorized();
        $this->assertDatabaseCount('device_desktop_clients', 1);
        $this->assertNull($issued['client']->fresh()->token_hash);
    }

    public function test_issuer_revocation_blocks_pending_enrollment(): void
    {
        $issued = $this->issue();
        $this->admin->update(['status' => false]);
        $this->postJson('/api/device-client/v1/enroll', $this->enrollmentInput($issued))->assertForbidden();
    }

    public function test_explicit_endpoint_confirmation_and_single_open_client_are_required(): void
    {
        $this->invalid(fn () => $this->service->issue($this->device, $this->admin, false), 'endpointConfirmed');
        $this->issue();
        $this->invalid(fn () => $this->issue(), 'device');
        $this->assertDatabaseCount('device_desktop_clients', 1);
    }

    public function test_controller_only_metadata_never_allows_enrollment_or_client_sync(): void
    {
        foreach (['controller_only', 'desktop_controller_only'] as $key) {
            $this->device->update(['metadata' => [$key => true]]);
            $this->forbidden(fn () => $this->issue());
        }
        $this->device->update(['metadata' => ['management_role' => 'controller']]);
        $this->forbidden(fn () => $this->issue());
        $this->device->update(['metadata' => []]);
        [, $token] = $this->paired();
        $this->device->update(['metadata' => ['controller_only' => true]]);
        $this->syncApi($token)->assertForbidden();
    }

    public function test_inactive_unverified_and_superadmin_assignees_are_blocked(): void
    {
        $this->employee->update(['status' => false]);
        $this->forbidden(fn () => $this->issue());
        $this->employee->forceFill(['status' => true, 'email_verified_at' => null])->save();
        $this->forbidden(fn () => $this->issue());
        $this->assignment->update(['user_id' => $this->admin->id]);
        $this->forbidden(fn () => $this->issue());
        $this->assertDatabaseCount('device_desktop_clients', 0);
    }

    public function test_missing_or_ambiguous_assignment_and_nonwindows_devices_are_blocked(): void
    {
        $this->assignment->update(['returned_at' => now()]);
        $this->forbidden(fn () => $this->issue());
        $this->assignment->update(['returned_at' => null]);
        $this->device->update(['platform' => 'macos']);
        $this->forbidden(fn () => $this->issue());
        $this->device->update(['platform' => 'windows']);
        $this->assignment->replicate()->save();
        $this->forbidden(fn () => $this->issue());
    }

    public function test_retired_lost_and_private_devices_are_blocked(): void
    {
        foreach (['retired', 'lost'] as $status) {
            $this->device->update(['lifecycle_status' => $status]);
            $this->forbidden(fn () => $this->issue());
        }
        $this->device->update(['lifecycle_status' => 'assigned', 'ownership' => 'personal']);
        $this->forbidden(fn () => $this->issue());
    }

    public function test_read_sync_works_with_global_gate_closed_but_never_offers_jobs(): void
    {
        [$client, $token] = $this->paired();
        $this->softwareAllowed($client);
        $this->softwareJob($client);
        $response = $this->syncApi($token);
        $response->assertOk()->assertJsonPath('jobs', [])->assertJsonPath('policy.allow_software_install', false);
        $this->assertNotNull($client->fresh()->last_seen_at);
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_sticky_leases_and_terminal_results_are_client_bound_and_idempotent(): void
    {
        [$client, $token] = $this->paired();
        $this->globalGate(true);
        $this->softwareAllowed($client);
        $job = $this->softwareJob($client);
        $first = $this->syncApi($token)->assertOk()['jobs'][0];
        $second = $this->syncApi($token)->assertOk()['jobs'][0];
        $this->assertSame($first['lease_id'], $second['lease_id']);
        $this->assertSame('install_software', $first['type']);
        $this->assertSame(['package_id' => 'Vendor.Product', 'version' => '1.2.3'], $first['payload']);
        $body = ['lease_id' => $first['lease_id'], 'status' => 'succeeded', 'exit_code' => 0, 'message' => 'Done'];
        $this->resultApi($token, $job, $body)->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('job_id', $job->public_id);
        $this->resultApi($token, $job, $body)->assertOk();
        $this->resultApi($token, $job, array_replace($body, ['status' => 'failed']))->assertConflict();
        $this->syncApi($token)->assertJsonPath('jobs', []);
    }

    public function test_issued_lease_receipt_is_accepted_after_expiry_and_global_disable(): void
    {
        [$client, $token] = $this->paired();
        $this->globalGate(true);
        $job = $this->notificationJob($client);
        $offer = $this->syncApi($token)['jobs'][0];
        $this->travel(31)->minutes();
        $this->syncApi($token)->assertJsonPath('jobs', []);
        $this->assertSame('expired', $job->fresh()->status);
        $this->globalGate(false);
        $this->resultApi($token, $job, ['lease_id' => $offer['lease_id'], 'status' => 'succeeded'])->assertOk();
        $this->assertSame('succeeded', $job->fresh()->status);
    }

    public function test_a_client_cannot_report_another_client_job_or_an_unoffered_job(): void
    {
        [$client, $token] = $this->paired();
        $job = $this->notificationJob($client);
        $this->resultApi($token, $job, ['lease_id' => (string) Str::uuid(), 'status' => 'succeeded'])->assertNotFound();
        $this->globalGate(true);
        $offer = $this->syncApi($token)['jobs'][0];
        $otherDevice = $this->device->replicate();
        $otherDevice->public_id = (string) Str::uuid();
        $otherDevice->save();
        $otherAssignment = $this->assignment->replicate();
        $otherAssignment->device_id = $otherDevice->id;
        $otherAssignment->save();
        $issued = $this->service->issue($otherDevice, $this->admin, true);
        $other = $this->service->enroll($this->enrollmentInput($issued));
        $this->resultApi($other['access_token'], $job, ['lease_id' => $offer['lease_id'], 'status' => 'succeeded'])->assertNotFound();
        $this->assertSame('offered', $job->fresh()->status);
    }

    public function test_instance_identity_cannot_be_reused_even_after_revocation(): void
    {
        $issued = $this->issue();
        $input = $this->enrollmentInput($issued);
        $this->service->enroll($input);
        $this->service->revoke($issued['client'], $this->admin);
        $next = $this->issue();
        $input['enrollment_token'] = $next['enrollment_token'];
        $this->postJson('/api/device-client/v1/enroll', $input)->assertConflict();
    }

    public function test_changed_assignment_invalidates_existing_client_even_for_same_employee(): void
    {
        [, $token] = $this->paired();
        $this->assignment->update(['status' => 'returned', 'returned_at' => now()]);
        $next = $this->assignment->replicate();
        $next->status = 'active';
        $next->returned_at = null;
        $next->save();
        $this->syncApi($token)->assertUnauthorized();
    }

    public function test_employee_deactivation_and_deleted_devices_block_sync(): void
    {
        [, $token] = $this->paired();
        $this->employee->update(['status' => false]);
        $this->syncApi($token)->assertForbidden();
        $this->employee->update(['status' => true]);
        $this->device->delete();
        $this->syncApi($token)->assertForbidden();
    }

    public function test_revoke_cancels_open_jobs_and_invalidates_bearer_without_removing_audit(): void
    {
        [$client, $token] = $this->paired();
        $job = $this->notificationJob($client);
        $this->service->revoke($client, $this->admin);
        $this->syncApi($token)->assertUnauthorized();
        $this->assertNull($client->fresh()->token_hash);
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'device-desktop.revoked']);
    }

    public function test_software_requires_pinned_version_policy_and_safe_structured_payload(): void
    {
        [$client] = $this->paired();
        $this->invalid(fn () => $this->softwareJob($client), 'policy');
        $this->softwareAllowed($client);
        foreach ([['package_id' => 'Vendor.Product', 'version' => ''], ['package_id' => 'Vendor.Product;calc.exe', 'version' => '1.2.3'],
            ['package_id' => 'Vendor.Product', 'version' => '--force'], ['package_id' => 'https://evil.example/install.exe', 'version' => '1.2.3']] as $payload) {
            $this->invalid(fn () => $this->service->queueJob($client, 'install_software', $payload, 'Explicit pilot test', $this->admin));
        }
        $this->invalid(fn () => $this->service->queueJob($client, 'shell', ['command' => 'whoami'], 'Explicit pilot test', $this->admin), 'type');
        $this->assertDatabaseCount('device_desktop_jobs', 0);
    }

    public function test_policy_and_requester_revocation_stop_existing_offers_on_fresh_sync(): void
    {
        [$client, $token] = $this->paired();
        $this->globalGate(true);
        $this->softwareAllowed($client);
        $this->softwareJob($client);
        $this->assertCount(1, $this->syncApi($token)['jobs']);
        $this->service->savePolicy($client, $this->service->defaults(), $this->admin);
        $this->syncApi($token)->assertJsonPath('jobs', []);
        $this->softwareAllowed($client);
        $this->admin->update(['status' => false]);
        $this->syncApi($token)->assertJsonPath('jobs', []);
    }

    public function test_report_accepts_only_small_structured_nonsecret_fields_and_scrubs_messages(): void
    {
        [$client, $token] = $this->paired();
        $this->withToken($token)->postJson('/api/device-client/v1/sync', ['client_version' => '0.1.0', 'report' => ['password' => 'blocked']])->assertUnprocessable();
        $this->globalGate(true);
        $job = $this->notificationJob($client);
        $offer = $this->syncApi($token)['jobs'][0];
        $this->resultApi($token, $job, ['lease_id' => $offer['lease_id'], 'status' => 'failed', 'message' => 'token=synthetic-secret'])->assertOk();
        $this->assertSame('token=[redacted]', $job->fresh()->result['message']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode(DB::table('activity_log')->get()));
    }

    public function test_api_rejects_query_credentials_unknown_keys_and_oversized_json(): void
    {
        [, $token] = $this->paired();
        $this->postJson('/api/device-client/v1/sync?token='.$token, ['client_version' => '0.1.0'])->assertUnauthorized();
        $this->withToken($token)->postJson('/api/device-client/v1/sync', ['client_version' => '0.1.0', 'url' => 'https://example.test'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/device-client/v1/sync', ['client_version' => str_repeat('a', 9000)])->assertStatus(413);
        $this->withToken($token)->post('/api/device-client/v1/sync', ['client_version' => '0.1.0'], ['Accept' => 'application/json'])->assertStatus(415);
    }

    public function test_inactive_admin_and_staff_cannot_issue_pairing_or_change_policy(): void
    {
        [$client] = $this->paired();
        $this->admin->update(['status' => false]);
        $this->forbidden(fn () => $this->service->savePolicy($client, $this->service->defaults(), $this->admin));
        Gate::define('devices.enrollment.manage', fn (User $user) => false);
        $this->expectException(AuthorizationException::class);
        $this->service->issue($this->device, $this->employee, true);
    }

    public function test_livewire_admin_page_pairs_selects_and_saves_policy_without_background_writes(): void
    {
        $page = Livewire::actingAs($this->admin)->test(DeviceDesktopClients::class)
            ->assertSee('Desktopclients')->assertSee('Auftragsausgabe sicher blockiert')
            ->set('devicePublicId', $this->device->public_id)->set('endpointConfirmed', true)
            ->call('issuePairing')->assertHasNoErrors();
        $this->assertStringStartsWith('rtep_', $page->get('pairingToken'));
        $client = DeviceDesktopClient::first();
        $page->call('dismissPairing')->assertSet('pairingToken', null)
            ->call('selectClient', $client->public_id)->set('policy.allow_camera', true)
            ->call('savePolicy')->assertHasNoErrors();
        $this->assertTrue($client->fresh()->policy['allow_camera']);
        $this->assertDatabaseCount('device_desktop_jobs', 0);
        $this->assertFalse(app(DeviceManagementSettings::class)->productionCommandsEnabled(fresh: true));
    }

    public function test_livewire_revocation_requires_confirmation_and_active_actor(): void
    {
        [$client] = $this->paired();
        $page = Livewire::actingAs($this->admin)->test(DeviceDesktopClients::class)->call('selectClient', $client->public_id);
        $page->call('revoke')->assertHasErrors('revokeConfirmed');
        $page->set('revokeConfirmed', true)->call('revoke')->assertHasNoErrors();
        $this->assertSame('revoked', $client->fresh()->status);
        $this->admin->update(['status' => false]);
        Livewire::actingAs($this->admin)->test(DeviceDesktopClients::class)->assertForbidden();
    }

    public function test_unexpected_api_failure_never_exposes_or_logs_bearer_payload_or_trace(): void
    {
        [$client, $token] = $this->paired();
        DB::table('device_desktop_clients')->where('id', $client->id)->update(['policy' => 'invalid-encrypted-data']);
        config()->set('app.debug', true);
        Log::spy();
        $response = $this->syncApi($token)->assertStatus(500)->assertJsonStructure(['message', 'reference']);
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($token) {
            return $message === 'Desktopclient request failed.'
                && array_keys($context) === ['failure_class', 'reference']
                && ! str_contains(json_encode($context), $token);
        });
    }

    public function test_unknown_payload_keys_never_reach_client_and_policy_cannot_redirect_kiosk(): void
    {
        [$client, $token] = $this->paired();
        $this->globalGate(true);
        $this->service->savePolicy($client, array_replace($this->service->defaults(), [
            'allow_software_install' => true, 'kiosk_enabled' => true, 'kiosk_url' => 'https://untrusted.example',
        ]), $this->admin);
        $this->service->queueJob($client, 'install_software', ['package_id' => 'Vendor.Product', 'version' => '1.2.3',
            'url' => 'https://untrusted.example/install.exe', 'arguments' => '--silent'], 'Explicit test pilot approval', $this->admin);
        $response = $this->syncApi($token)->assertOk()->assertJsonPath('policy.kiosk_url', 'https://app.rail-time.de');
        $this->assertSame(['package_id' => 'Vendor.Product', 'version' => '1.2.3'], $response['jobs'][0]['payload']);
    }

    public function test_web_admin_route_is_protected_and_table_component_is_used(): void
    {
        $this->get('/geraete/desktop-clients')->assertRedirect('/login');
        $this->actingAs($this->admin)->get('/geraete/desktop-clients')->assertOk()->assertSee('data-rt-premium-table', false);
        Gate::define('devices.view', fn (User $user) => false);
        Livewire::actingAs($this->employee)->test(DeviceDesktopClients::class)->assertForbidden();
    }

    public function test_authoritative_setup_blocks_unoffered_and_expired_software_even_when_hidden(): void
    {
        [$client, $token] = $this->paired();
        $this->syncApi($token)->assertJsonPath('setup_ready', true);
        $this->softwareAllowed($client);
        $job = $this->softwareJob($client);
        $this->syncApi($token)->assertJsonPath('jobs', [])->assertJsonPath('setup_ready', false);
        $this->travel(31)->minutes();
        $this->globalGate(true);
        $this->syncApi($token)->assertJsonPath('jobs', [])->assertJsonPath('setup_ready', false);
        $this->assertSame('expired', $job->fresh()->status);
        foreach (['failed', 'unsupported', 'cancelled', 'requires_admin', 'declined'] as $status) {
            $job->forceFill(['status' => $status])->save();
            $this->syncApi($token)->assertJsonPath('setup_ready', false);
        }
    }

    public function test_setup_becomes_ready_only_after_all_this_binding_installations_succeed(): void
    {
        [$client, $token] = $this->paired();
        $this->softwareAllowed($client);
        $job = $this->softwareJob($client);
        $this->globalGate(true);
        $offer = $this->syncApi($token)->assertJsonPath('setup_ready', false)['jobs'][0];
        $this->globalGate(false);
        $this->syncApi($token)->assertJsonPath('jobs', [])->assertJsonPath('setup_ready', false);
        $this->resultApi($token, $job, ['lease_id' => $offer['lease_id'], 'status' => 'succeeded'])->assertOk();
        $this->notificationJob($client);
        $this->syncApi($token)->assertJsonPath('setup_ready', true)->assertJsonPath('jobs', []);
        $this->softwareJob($client);
        $this->syncApi($token)->assertJsonPath('setup_ready', false);
    }

    public function test_missing_desktop_tables_fail_closed_with_safe_503_without_auto_migration(): void
    {
        config()->set('app.debug', true);
        foreach (['device_desktop_clients', 'device_desktop_enrollments', 'device_desktop_jobs'] as $table) {
            Schema::rename($table, $table.'_unready');
            $this->postJson('/api/device-client/v1/enroll', ['client_version' => '0.1.0'])->assertStatus(503)
                ->assertExactJson(['message' => 'Clientanfrage nicht zugelassen.'])
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->postJson('/api/device-client/v1/sync', ['client_version' => '0.1.0'])->assertStatus(503);
            $this->postJson('/api/device-client/v1/jobs/'.Str::uuid().'/result', [
                'lease_id' => (string) Str::uuid(), 'status' => 'succeeded',
            ])->assertStatus(503);
            Livewire::actingAs($this->admin)->test(DeviceDesktopClients::class)->assertStatus(503);
            $this->assertFalse(Schema::hasTable($table), 'Guard must not create missing schema.');
            $this->assertDatabaseCount('users', 2);
            $this->assertDatabaseCount('devices', 1);
            Schema::rename($table.'_unready', $table);
        }
    }

    public function test_storage_inspection_errors_are_sanitized_and_do_not_become_raw_api_errors(): void
    {
        config()->set('app.debug', true);
        Schema::shouldReceive('hasTable')->andThrow(new \RuntimeException('synthetic-connection-secret'));
        Log::spy();
        $response = $this->postJson('/api/device-client/v1/enroll', ['client_version' => '0.1.0'])->assertStatus(503);
        $this->assertStringNotContainsString('synthetic-connection-secret', $response->getContent());
        Log::shouldNotHaveReceived('error');
    }

    private function issue(): array
    {
        return $this->service->issue($this->device, $this->admin, true);
    }

    private function enrollmentInput(array $issued): array
    {
        return ['enrollment_token' => $issued['enrollment_token'], 'client_instance_id' => (string) Str::uuid(),
            'client_version' => '0.1.0', 'platform' => 'windows', 'device_name' => 'Reported host'];
    }

    private function paired(): array
    {
        $issued = $this->issue();
        $enrolled = $this->service->enroll($this->enrollmentInput($issued));

        return [$issued['client']->fresh(), $enrolled['access_token']];
    }

    private function softwareAllowed(DeviceDesktopClient $client): void
    {
        $this->service->savePolicy($client, array_replace($this->service->defaults(), ['allow_software_install' => true]), $this->admin);
    }

    private function softwareJob(DeviceDesktopClient $client): DeviceDesktopJob
    {
        return $this->service->queueJob($client, 'install_software', ['package_id' => 'Vendor.Product', 'version' => '1.2.3'], 'Explicit test pilot approval', $this->admin);
    }

    private function notificationJob(DeviceDesktopClient $client): DeviceDesktopJob
    {
        return $this->service->queueJob($client, 'notification', ['title' => 'RailTime', 'body' => 'Pilot notification'], 'Explicit test pilot approval', $this->admin);
    }

    private function syncApi(string $token)
    {
        return $this->withToken($token)->postJson('/api/device-client/v1/sync', ['client_version' => '0.1.0',
            'capabilities' => ['winget_install', 'notification'], 'report' => ['software_runner' => 'available']]);
    }

    private function resultApi(string $token, DeviceDesktopJob $job, array $body)
    {
        return $this->withToken($token)->postJson('/api/device-client/v1/jobs/'.$job->public_id.'/result', $body);
    }

    private function globalGate(bool $enabled): void
    {
        // Isolated SQLite test state only; production activation guards are NOT bypassed in app code.
        Setting::setValue(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY,
            ['runtime' => ['production_commands_enabled' => $enabled]]);
    }

    private function invalid(callable $action, ?string $key = null): void
    {
        try {
            $action();
            $this->fail('Expected bounded validation failure.');
        } catch (ValidationException $exception) {
            $key ? $this->assertArrayHasKey($key, $exception->errors()) : $this->assertNotEmpty($exception->errors());
        }
    }

    private function forbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected authorization denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
