<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Livewire\Admin\SystemHealth;
use App\Models\User;
use App\Services\Ai\SpeechServiceClient;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemHealthComponentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]));
    }

    public function test_mount_only_reads_safe_snapshot_and_does_not_start_probes(): void
    {
        $service = $this->fakeService();
        $service->shouldNotReceive('check');
        $service->shouldNotReceive('poll');

        Livewire::test(SystemHealth::class)
            ->assertSet('rows.0.id', 'database')
            ->assertSee('Ein klarer Blick')
            ->assertSee('Alle erneut prüfen')
            ->assertSee('Kein Mailversand')
            ->assertDontSee('wire:poll', false)
            ->assertDontSee('wire:init', false);
    }

    public function test_actions_pass_only_fixed_check_id_force_flag_and_run_id_to_service(): void
    {
        $service = $this->fakeService();
        $completed = $this->row(['status' => 'ok', 'fresh' => true]);
        $service->shouldReceive('check')->once()->with('database', true)->andReturn($completed);
        $service->shouldReceive('poll')->once()->with('database', '11111111-2222-4333-8444-555555555555')->andReturn($completed);

        Livewire::test(SystemHealth::class)
            ->call('refreshSnapshot')
            ->call('checkOne', 'database', true)
            ->assertSet('rows.0.status', 'ok')
            ->call('pollCheck', 'database', '11111111-2222-4333-8444-555555555555')
            ->assertSet('rows.0.fresh', true);
    }

    public function test_arbitrary_urls_commands_or_run_ids_cannot_reach_checks(): void
    {
        $service = $this->fakeService();
        $service->shouldNotReceive('check');
        $service->shouldNotReceive('poll');

        Livewire::test(SystemHealth::class)
            ->call('checkOne', 'https://127.0.0.1/admin', true)
            ->assertHasErrors('systemHealth');
        Livewire::test(SystemHealth::class)
            ->call('pollCheck', 'database', '../credentials')
            ->assertHasErrors('systemHealth');
    }

    public function test_diagnostic_result_state_is_locked_against_browser_mutation(): void
    {
        $this->fakeService();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(SystemHealth::class)->set('rows.0.id', 'arbitrary-target');
    }

    public function test_regular_administrator_cannot_mount_system_health(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]));
        $service = Mockery::mock(SystemHealthService::class);
        $service->shouldNotReceive('snapshot');
        app()->instance(SystemHealthService::class, $service);
        Livewire::test(SystemHealth::class)->assertForbidden();
    }

    public function test_every_action_rechecks_superadmin_after_mount(): void
    {
        $superadmin = auth()->user();
        $administrator = User::factory()->create(['role' => 'admin', 'status' => true]);
        $service = $this->fakeService();
        $service->shouldNotReceive('check');
        $service->shouldNotReceive('poll');

        foreach ([['refreshSnapshot'], ['checkOne', 'database', true], ['pollCheck', 'database', '11111111-2222-4333-8444-555555555555']] as $arguments) {
            $this->actingAs($superadmin);
            $component = Livewire::test(SystemHealth::class);
            $this->actingAs($administrator);
            $component->call(...$arguments)->assertForbidden();
        }
    }

    public function test_every_action_also_requires_settings_manage(): void
    {
        $service = $this->fakeService();
        $service->shouldNotReceive('check');
        $component = Livewire::test(SystemHealth::class);
        Gate::before(fn ($user, $ability) => $ability === 'settings.manage' ? false : null);
        // Avoid the application's earlier administrator bypass: replace the
        // gate callback order for this narrowly scoped authorization regression.
        $gate = new \Illuminate\Auth\Access\Gate(app(), fn () => auth()->user());
        $gate->define('settings.manage', fn () => false);
        app()->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        Gate::swap($gate);
        $component->call('checkOne', 'database', true)->assertForbidden();
    }

    public function test_settings_view_preserves_normal_administrator_teasers(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/settings.blade.php'));
        $this->assertMatchesRegularExpression('/@if \(\$isSuperAdmin\).*?<livewire:admin\.system-health.*?@else.*?<x-admin\.settings-teaser/s', $view);
        $this->assertStringContainsString('rt-settings-open-section', $view);
    }

    public function test_settings_mount_leaves_speech_unchecked_without_any_network_probe(): void
    {
        $this->fakeService();
        $local = Mockery::mock(SpeechServiceClient::class);
        $local->shouldReceive('isConfigured')->andReturnTrue();
        $local->shouldNotReceive('status');
        app()->instance(SpeechServiceClient::class, $local);

        Livewire::test(Settings::class)
            ->assertSet('assistantSpeechStatus.providers.local.state', 'not_checked')
            ->assertSee('Beim Öffnen der Einstellungen wurde keine Verbindung aufgebaut.');
    }

    private function fakeService(): MockInterface
    {
        $service = Mockery::mock(SystemHealthService::class);
        $service->shouldReceive('snapshot')->andReturn([$this->row()]);
        app()->instance(SystemHealthService::class, $service);

        return $service;
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 'database', 'label' => 'Datenbank', 'group' => 'Anwendung und Daten',
            'settings_tab' => 'system', 'settings_section' => 'system',
            'status' => 'not_checked', 'evidence' => 'connection',
            'message' => 'Noch nicht geprüft', 'details' => [], 'checked_at' => null,
            'duration_ms' => null, 'fresh' => false, 'source' => 'cache', 'run_id' => null, 'pending' => false,
        ], $overrides);
    }
}
