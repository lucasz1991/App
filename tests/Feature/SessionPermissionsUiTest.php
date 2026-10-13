<?php

namespace Tests\Feature;

use App\Livewire\Settings\SessionPermissions;
use App\Models\SessionPermissionGrant;
use App\Models\User;
use App\Services\Privacy\SessionPermissionService;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Minishlink\WebPush\VAPID;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class SessionPermissionsUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private array $state;

    private array $decisions = [];

    private array $revocations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->state = [
            'needs_decision' => true, 'push_enabled' => true, 'location_enabled' => true,
            'push_decision' => null, 'location_decision' => null, 'expires_at' => '2026-10-14T10:00:00Z',
            'push_version' => 1, 'location_version' => 2, 'location_text' => 'Erklärung: 15 Minuten, sieben Tage, freiwillig während laufender Arbeitszeit.',
        ];
        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.enabled' => true, 'webpush.auto_provision' => false,
            'webpush.vapid.subject' => 'mailto:test@example.test', 'webpush.vapid.public_key' => $vapid['publicKey'],
            'webpush.vapid.private_key' => $vapid['privateKey'], 'session.employee_lifetime' => 4320,
        ]);
        $service = Mockery::mock(SessionPermissionService::class);
        $service->shouldReceive('snapshot')->andReturnUsing(fn (User $actor) => $this->state);
        $service->shouldReceive('decide')->andReturnUsing(function (User $actor, string $purpose, bool $accepted, int $version): SessionPermissionGrant {
            $this->assertSame($this->employee->id, $actor->id);
            if ($version !== $this->state[$purpose.'_version']) {
                throw new HttpException(409, 'Changed explanation.');
            }
            $this->decisions[] = [$purpose, $accepted, $version];
            $this->state[$purpose.'_decision'] = $accepted ? 'accepted' : 'declined';

            return new SessionPermissionGrant;
        });
        $service->shouldReceive('revoke')->andReturnUsing(function (User $actor, string $purpose): void {
            $this->assertSame($this->employee->id, $actor->id);
            $this->revocations[] = $purpose;
            $this->state[$purpose.'_decision'] = 'declined';
        });
        $this->app->instance(SessionPermissionService::class, $service);
    }

    public function test_missing_decisions_open_one_optional_modal_with_independent_unselected_actions(): void
    {
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)
            ->assertSet('dialogOpen', true)->assertSee('Push und Standort freigeben')
            ->assertSee('Noch keine Entscheidung')->assertSee('Ohne Push fortfahren')->assertSee('Ohne Standort fortfahren')
            ->assertSee('72 Stunden')->assertSee('keine erneute tägliche Nachfrage')
            ->assertSee('Browser- und Geräteberechtigungen')->assertSee('während aktiver App-Nutzung')
            ->assertSee('Im Standby werden keine neuen Positionen erfasst.')
            ->assertSee('data-permission-allow="push"', false)->assertSee('data-permission-allow="location"', false)
            ->assertDontSee(' checked', false);
        $this->assertSame([], $this->decisions);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_push_acceptance_and_location_decline_are_independent_and_suppress_repeated_prompt(): void
    {
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)
            ->call('decide', 'push', true)->assertHasNoErrors()->assertDispatched('session-permissions-updated')
            ->call('decide', 'location', false)->assertHasNoErrors()
            ->call('dismiss')->assertSet('dialogOpen', false);
        $this->assertSame([['push', true, 1], ['location', false, 2]], $this->decisions);
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)->assertSet('dialogOpen', false);
    }

    public function test_closing_prompt_only_declines_unanswered_features_and_keeps_prior_acceptance(): void
    {
        $this->state['push_decision'] = 'accepted';
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)
            ->call('dismiss')->assertSet('dialogOpen', false)->assertDispatched('railtime-session-permissions-changed');
        $this->assertSame([['location', false, 2]], $this->decisions);
        $this->assertSame('accepted', $this->state['push_decision']);
    }

    public function test_joint_acceptance_records_both_purposes_but_waits_for_explicit_completion(): void
    {
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)
            ->call('acceptAll', ['push', 'location'])->assertReturned(true)->assertHasNoErrors()
            ->assertSet('dialogOpen', true)->assertDispatched('session-permissions-updated')
            ->assertDispatched('railtime-session-permissions-changed')
            ->call('complete')->assertReturned(true)->assertSet('dialogOpen', false);

        $this->assertSame([['location', true, 2], ['push', true, 1]], $this->decisions);
        $this->assertSame([], $this->revocations);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_completion_never_declines_an_unanswered_purpose(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $component->call('complete')->assertReturned(false)->assertSet('dialogOpen', true);
        $this->assertSame([], $this->decisions);

        $component->call('decide', 'push', true)->assertReturned(true)
            ->call('complete')->assertReturned(false)->assertSet('dialogOpen', true);
        $this->assertSame([['push', true, 1]], $this->decisions);

        $component->call('decide', 'location', false)->assertReturned(true)
            ->call('complete')->assertReturned(true)->assertSet('dialogOpen', false);
        $this->assertSame([['push', true, 1], ['location', false, 2]], $this->decisions);
    }

    public function test_joint_acceptance_can_cover_only_the_one_available_purpose(): void
    {
        foreach (['push', 'location'] as $purpose) {
            $this->state['push_enabled'] = $purpose === 'push';
            $this->state['location_enabled'] = $purpose === 'location';
            $this->state['push_decision'] = null;
            $this->state['location_decision'] = null;
            $this->decisions = [];

            Livewire::actingAs($this->employee)->test(SessionPermissions::class)
                ->assertSet('dialogOpen', true)->call('acceptAll', [$purpose])
                ->assertReturned(true)->assertHasNoErrors()->assertSet('dialogOpen', true)
                ->call('complete')->assertReturned(true)->assertSet('dialogOpen', false);

            $this->assertSame([[$purpose, true, $this->state[$purpose.'_version']]], $this->decisions);
            $other = $purpose === 'push' ? 'location' : 'push';
            $this->assertNull($this->state[$other.'_decision']);
        }
    }

    public function test_joint_acceptance_rejects_invalid_purpose_sets_before_any_write(): void
    {
        foreach ([[], ['other'], ['push', 'other'], ['location', 'location'], ['push', 'push', 'location'], [false], [['push']]] as $purposes) {
            Livewire::actingAs($this->employee)->test(SessionPermissions::class)
                ->call('acceptAll', $purposes)->assertStatus(422);
            $this->assertSame([], $this->decisions);
        }
    }

    public function test_joint_acceptance_checks_each_selected_feature_availability_before_any_write(): void
    {
        foreach (['push', 'location'] as $disabled) {
            $this->state['push_enabled'] = true;
            $this->state['location_enabled'] = true;
            $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
            $this->state[$disabled.'_enabled'] = false;
            $component->call('acceptAll', ['push', 'location'])->assertStatus(422);
            $this->assertSame([], $this->decisions);
        }
    }

    public function test_joint_acceptance_rejects_push_if_server_configuration_disappeared(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        $component->call('acceptAll', ['push', 'location'])->assertStatus(422);
        $this->assertSame([], $this->decisions);
    }

    public function test_joint_acceptance_checks_all_rendered_versions_before_any_write_and_can_be_retried(): void
    {
        foreach (['push', 'location'] as $changed) {
            $this->state['push_version'] = 1;
            $this->state['location_version'] = 2;
            $this->state['push_decision'] = null;
            $this->state['location_decision'] = null;
            $this->decisions = [];
            $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
            $this->state[$changed.'_version']++;
            $component->call('acceptAll', ['push', 'location'])->assertReturned(false)
                ->assertHasErrors('permissions')->assertSet('dialogOpen', true)
                ->assertSet($changed.'Version', $this->state[$changed.'_version']);
            $this->assertSame([], $this->decisions);

            $component->call('acceptAll', ['push', 'location'])->assertReturned(true)->assertHasNoErrors();
            $this->assertSame([
                ['location', true, $this->state['location_version']],
                ['push', true, $this->state['push_version']],
            ], $this->decisions);
        }
    }

    public function test_completion_rechecks_fresh_decisions_after_a_new_explanation(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $component->call('acceptAll', ['push', 'location'])->assertReturned(true);
        $recorded = $this->decisions;
        $this->state['location_version'] = 3;
        $this->state['location_decision'] = null;

        $component->call('complete')->assertReturned(false)->assertSet('dialogOpen', true);
        $this->assertSame($recorded, $this->decisions);
    }

    public function test_joint_acceptance_and_completion_reject_inactive_or_switched_accounts(): void
    {
        foreach (['acceptAll', 'complete'] as $method) {
            foreach (['inactive', 'switched'] as $condition) {
                $this->employee->forceFill(['status' => true])->save();
                $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
                if ($condition === 'inactive') {
                    $this->employee->forceFill(['status' => false])->save();
                    auth()->setUser($this->employee);
                } else {
                    auth()->setUser(User::factory()->create(['role' => 'staff', 'status' => true]));
                }

                $parameters = $method === 'acceptAll' ? [['push', 'location']] : [];
                $component->call($method, ...$parameters)->assertStatus(403);
                $this->assertSame([], $this->decisions);
            }
        }
    }

    public function test_explanations_are_collapsed_native_disclosures_with_direct_joint_actions(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $document = new \DOMDocument;
        @$document->loadHTML('<html><body>'.$component->html().'</body></html>');
        $xpath = new \DOMXPath($document);
        foreach (['push', 'location'] as $purpose) {
            $details = $xpath->query('//details[@data-permission-explanation="'.$purpose.'"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $details);
            $this->assertFalse($details->hasAttribute('open'));
            $this->assertSame(1, $xpath->query('./summary', $details)->length);
            $this->assertSame(1, $xpath->query('.//button[@data-permission-allow="'.$purpose.'"]', $details)->length);
            $this->assertSame(1, $xpath->query('.//button[@data-permission-decline="'.$purpose.'"]', $details)->length);
        }

        $allow = $xpath->query('//button[@data-permission-allow-all]')->item(0);
        $continue = $xpath->query('//button[@data-session-permissions-continue]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $allow);
        $this->assertInstanceOf(\DOMElement::class, $continue);
        foreach ([$allow, $continue] as $action) {
            $this->assertSame(0, $xpath->query('ancestor::details', $action)->length);
            $this->assertStringContainsString('min-h-11', $action->getAttribute('class'));
            $this->assertFalse($action->hasAttribute('disabled'));
        }
        $this->assertSame($allow->getAttribute('class'), $continue->getAttribute('class'));
        $this->assertSame([], $this->decisions);
    }

    public function test_profile_controls_never_auto_open_and_allow_later_withdrawal(): void
    {
        $this->state['location_decision'] = 'accepted';
        Livewire::actingAs($this->employee)->test(SessionPermissions::class, ['automatic' => false])
            ->assertSet('dialogOpen', false)->assertSee('Deine Freigaben')->assertSee('Freigabe widerrufen')
            ->assertSee('data-session-permissions="profile"', false)->assertDontSee('id="session-permissions-dialog"', false)
            ->call('revoke', 'location')->assertHasNoErrors()->assertSee('Für diese Sitzung abgelehnt');
        $this->assertSame(['location'], $this->revocations);
        $this->assertSame([], $this->decisions);
    }

    public function test_disabled_or_unconfigured_features_do_not_prompt_or_record_a_decision(): void
    {
        config(['webpush.enabled' => false]);
        $this->state['push_enabled'] = false;
        $this->state['location_enabled'] = false;
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)
            ->assertSet('dialogOpen', false)->assertDontSee('Erklärung: 15 Minuten')
            ->call('dismiss')->assertHasNoErrors();
        $this->assertSame([], $this->decisions);
    }

    public function test_allow_and_decline_have_equal_visible_native_button_boundaries_and_focus(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $document = new \DOMDocument;
        @$document->loadHTML('<html><body>'.$component->html().'</body></html>');
        $xpath = new \DOMXPath($document);
        foreach (['push', 'location'] as $purpose) {
            $allow = $xpath->query('//button[@data-permission-allow="'.$purpose.'"]')->item(0);
            $decline = $xpath->query('//button[@data-permission-decline="'.$purpose.'"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $allow);
            $this->assertInstanceOf(\DOMElement::class, $decline);
            $this->assertSame($allow->getAttribute('class'), $decline->getAttribute('class'));
            foreach (['border-rt-border', 'min-h-11', 'focus:ring-2'] as $class) {
                $this->assertStringContainsString($class, $allow->getAttribute('class'));
            }
            $this->assertFalse($allow->hasAttribute('disabled'));
            $this->assertFalse($decline->hasAttribute('disabled'));
        }
    }

    public function test_stored_push_and_location_permissions_can_be_withdrawn_while_features_are_disabled(): void
    {
        config(['webpush.enabled' => false]);
        $this->state['push_enabled'] = false;
        $this->state['location_enabled'] = false;
        $this->state['push_decision'] = 'accepted';
        $this->state['location_decision'] = 'accepted';
        Livewire::actingAs($this->employee)->test(SessionPermissions::class, ['automatic' => false])
            ->assertSet('dialogOpen', false)
            ->assertSee('data-permission-withdraw="push"', false)->assertSee('data-permission-withdraw="location"', false)
            ->assertDontSee('data-permission-allow="push"', false)->assertDontSee('data-permission-allow="location"', false)
            ->call('revoke', 'push')->assertHasNoErrors()->call('revoke', 'location')->assertHasNoErrors()
            ->assertDontSee('data-permission-withdraw=', false);
        $this->assertSame(['push', 'location'], $this->revocations);
        $this->assertSame([], $this->decisions);
    }

    public function test_native_allow_buttons_compile_real_boolean_arguments_for_both_decision_states(): void
    {
        foreach ([false, true] as $accepted) {
            $this->state['push_decision'] = $accepted ? 'accepted' : null;
            $this->state['location_decision'] = $accepted ? 'accepted' : null;
            $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
            $document = new \DOMDocument;
            @$document->loadHTML('<html><body>'.$component->html().'</body></html>');
            $xpath = new \DOMXPath($document);
            foreach (['push', 'location'] as $purpose) {
                $allow = $xpath->query('//button[@data-permission-allow="'.$purpose.'"]')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $allow);
                $this->assertSame("allow('".$purpose."', ".($accepted ? 'true' : 'false').')', $allow->getAttribute('x-on:click'));
                $this->assertStringNotContainsString('@js', $allow->getAttribute('x-on:click'));
            }
        }
    }

    public function test_new_explanation_cannot_be_accepted_with_the_old_rendered_version(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $this->state['location_version'] = 3;
        $component->call('decide', 'location', true)->assertHasErrors('permissions')
            ->assertSet('locationVersion', 3)->assertSee('Die Erklärung wurde geändert');
        $this->assertSame([], $this->decisions);
        $component->call('decide', 'location', true)->assertHasNoErrors();
        $this->assertSame([['location', true, 3]], $this->decisions);
    }

    public function test_disabled_location_is_checked_fresh_before_acceptance(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $this->state['location_enabled'] = false;
        $component->call('decide', 'location', true)->assertStatus(422);
        $this->assertSame([], $this->decisions);
    }

    public function test_unknown_purpose_is_rejected_before_any_decision_write(): void
    {
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)->call('decide', 'other', true)->assertStatus(422);
        $this->assertSame([], $this->decisions);
    }

    public function test_inactive_account_cannot_change_profile_permissions_after_mount(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        $this->employee->forceFill(['status' => false])->save();
        auth()->setUser($this->employee);
        $component->call('revoke', 'push')->assertStatus(403);
        $this->assertSame([], $this->revocations);
    }

    public function test_client_cannot_forge_explanation_version(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)->set('locationVersion', 999);
    }

    public function test_component_cannot_apply_an_old_users_decision_after_an_account_switch(): void
    {
        $component = Livewire::actingAs($this->employee)->test(SessionPermissions::class);
        auth()->setUser(User::factory()->create(['role' => 'staff', 'status' => true]));
        $component->call('decide', 'push', true)->assertStatus(403);
        $this->assertSame([], $this->decisions);
    }

    public function test_client_cannot_forge_the_component_owner(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->employee)->test(SessionPermissions::class)->set('ownerUserId', 999);
    }

    public function test_user_profile_integration_keeps_native_push_panel_and_security_tabs(): void
    {
        $view = file_get_contents(resource_path('views/livewire/profile/profile-tab-content.blade.php'));
        $this->assertStringContainsString('<livewire:settings.session-permissions :automatic="false" />', $view);
        $this->assertStringContainsString('<livewire:settings.push-settings />', $view);
        $this->assertStringContainsString('<livewire:profile.logout-other-browser-sessions-form />', $view);
        $layout = file_get_contents(resource_path('views/layouts/master.blade.php'));
        $this->assertStringContainsString("@if (\$chrome)\n                <livewire:settings.session-permissions />", str_replace("\r\n", "\n", $layout));
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertSame(1, DB::table('users')->count());
    }
}
