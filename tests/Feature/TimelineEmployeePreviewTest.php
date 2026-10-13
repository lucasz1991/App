<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users\Messages\MessageForm;
use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelineEmployeePreviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Http::preventStrayRequests();
        config(['app.locale' => 'de', 'app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->admin = User::factory()->create(['name' => 'Synthetic Timeline Manager', 'role' => 'admin', 'status' => true]);
    }

    public function test_timeline_reuses_the_employee_list_card_with_native_actions_and_no_private_contact_data(): void
    {
        $target = $this->staff('Synthetic Preview Person', ['calls.join' => true]);
        UserProfile::create(['user_id' => $target->id, 'position' => 'Synthetic Train Driver', 'first_name' => 'Synthetic', 'last_name' => 'Preview Person',
            'phone' => 'PRIVATE PHONE', 'mobile' => 'PRIVATE MOBILE', 'street' => 'PRIVATE ADDRESS', 'compensation_amount' => 9876]);
        $component = $this->timeline($this->admin);
        [$xpath, $card] = $this->card($component, $target);

        $this->assertSame(5, $xpath->query('.//button[@*[name()="x-on:click.prevent"]] | .//a[@*[name()="wire:navigate"]]', $card)->length);
        foreach ([['openDirectChat', $target->id], ['startDirectCall', $target->id, 'voice'], ['startDirectCall', $target->id, 'video'], ['openMessage', $target->id]] as $arguments) {
            $expression = '$wire.call('.implode(', ', array_map(fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $arguments)).')';
            $this->assertSame(1, $this->actions($xpath, $card, $expression)->length);
        }
        $profile = $xpath->query('.//a[@*[name()="wire:navigate"]]', $card)->item(0);
        $this->assertSame(route('admin.user-profile', $target->id), $profile->getAttribute('href'));
        foreach ([$target->name, $target->email, 'Synthetic Train Driver', 'Synthetic Preview Team'] as $publicFact) {
            $this->assertStringContainsString($publicFact, $card->textContent);
        }
        foreach (['PRIVATE PHONE', 'PRIVATE MOBILE', 'PRIVATE ADDRESS', '9876'] as $privateFact) {
            $this->assertStringNotContainsString($privateFact, $card->textContent);
        }
        $this->assertSame(1, $xpath->query('//*[@data-person-anchor-preview]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class,"rt-timeline-person-identity")]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(@class,"rt-person-selection-slot")]')->length);
        $this->assertNoCommunicationWrites();
    }

    public function test_missing_call_message_and_profile_permissions_hide_actions_but_keep_the_public_preview(): void
    {
        $viewer = $this->staff('Synthetic Restricted Viewer', ['operations.manage' => true]);
        $target = $this->staff('Synthetic Restricted Target', ['calls.join' => true]);
        $component = $this->timeline($viewer);
        [$xpath, $card] = $this->card($component, $target);

        $this->assertSame(1, $xpath->query('.//button[@*[name()="x-on:click.prevent"]]', $card)->length);
        $this->assertSame(1, $this->actions($xpath, $card, '$wire.call("openDirectChat", '.$target->id.')')->length);
        $this->assertSame(0, $xpath->query('.//a[@*[name()="wire:navigate"]]', $card)->length);
        $this->assertSame(0, $xpath->query('//*[@*[name()="wire:snapshot"] and contains(@*[name()="wire:snapshot"],"admin.users.messages.message-form")]')->length);
        $this->assertStringContainsString($target->email, $card->textContent);
        $this->assertNoCommunicationWrites();
    }

    public function test_management_profile_link_requires_employee_permission_and_uses_the_neutral_route(): void
    {
        $viewer = $this->staff('Synthetic Delegated Manager', ['operations.manage' => true, 'employees.view' => true], 'Verwaltung');
        $target = $this->staff('Synthetic Delegated Target');
        [$xpath, $card] = $this->card($this->timeline($viewer), $target);
        $this->assertSame(route('employees.show', $target->id), $xpath->query('.//a[@*[name()="wire:navigate"]]', $card)->item(0)->getAttribute('href'));

        $viewer->currentTeam->update(['rbac_permissions' => ['operations.manage' => true]]);
        [$xpath, $card] = $this->card($this->timeline($viewer->fresh()), $target);
        $this->assertSame(0, $xpath->query('.//a[@*[name()="wire:navigate"]]', $card)->length);
        $this->assertNoCommunicationWrites();
    }

    public function test_self_preview_has_no_communication_actions_and_call_recipient_permission_is_respected(): void
    {
        $viewer = $this->staff('Synthetic Self Manager', ['operations.manage' => true, 'employees.view' => true, 'users.messages.create' => true, 'calls.start' => true, 'calls.join' => true], 'Verwaltung');
        $target = $this->staff('Synthetic Noncalling Target');
        $component = $this->timeline($viewer);
        [$xpath, $selfCard] = $this->card($component, $viewer);
        $this->assertSame(0, $xpath->query('.//button[@*[name()="x-on:click.prevent"]]', $selfCard)->length);
        $this->assertSame(1, $xpath->query('.//a[@*[name()="wire:navigate"]]', $selfCard)->length);
        [$xpath, $targetCard] = $this->card($component, $target);
        $this->assertSame(2, $xpath->query('.//button[@*[name()="x-on:click.prevent"]]', $targetCard)->length);
        $this->assertSame(0, $this->actions($xpath, $targetCard, '$wire.call("startDirectCall", '.$target->id.', "voice")')->length);
        $this->assertNoCommunicationWrites();
    }

    public function test_inactive_assigned_employee_keeps_the_same_card_without_chat_or_call_actions(): void
    {
        $target = $this->staff('Synthetic Inactive Target', ['calls.join' => true]);
        $target->update(['status' => false]);
        $customer = Customer::create(['company_name' => 'Synthetic Preview Customer', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic Preview Service', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-10-10T08:00', 'ends_at' => '2026-10-10T16:00', 'required_staff' => 1, 'created_by' => $this->admin->id]);
        $shift = Shift::create(['order_id' => $order->id, 'title' => 'Synthetic Preview Duty', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-10-10T08:00', 'ends_at' => '2026-10-10T16:00', 'required_staff' => 1, 'status' => 'confirmed', 'created_by' => $this->admin->id]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $target->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);
        [$xpath, $card] = $this->card($this->timeline($this->admin), $target);
        $this->assertSame(0, $this->actions($xpath, $card, '$wire.call("openDirectChat", '.$target->id.')')->length);
        $this->assertSame(0, $this->actions($xpath, $card, '$wire.call("startDirectCall", '.$target->id.', "voice")')->length);
        $this->assertSame(0, $this->actions($xpath, $card, '$wire.call("openMessage", '.$target->id.')')->length, 'Unavailable recipients must not show an action that the server rejects');
        $this->assertSame(1, $xpath->query('.//a[@*[name()="wire:navigate"]]', $card)->length);
        $this->assertSame(0, DB::table('chats')->count());
        $this->assertSame(0, DB::table('rooms')->count());
        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(1, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_message_dialog_has_one_stable_owner_and_opening_only_dispatches_the_native_composer(): void
    {
        $target = $this->staff('Synthetic Message Target');
        $this->staff('Synthetic Second Message Target');
        $component = $this->timeline($this->admin);
        $xpath = $this->xpath($component->html());
        $hosts = $xpath->query('//*[@*[name()="wire:snapshot"] and contains(@*[name()="wire:snapshot"],"admin.users.messages.message-form")]');
        $this->assertSame(1, $hosts->length);
        $hostId = $hosts->item(0)->getAttribute('wire:id');

        $component->call('openMessage', $target->id)->assertStatus(200)->assertDispatchedTo(MessageForm::class, 'openMailModal', payload: $target->id);
        $hosts = $this->xpath($component->html())->query('//*[@*[name()="wire:id"]="'.$hostId.'"]');
        $this->assertSame(1, $hosts->length, 'A parent render retains the one child composer owner');
        $this->assertNoCommunicationWrites();
    }

    public function test_more_employee_previews_reuse_eager_team_and_profile_queries_without_activity_queries(): void
    {
        $this->staff('Synthetic Batched 01', ['calls.join' => true]);
        $queriesForOne = $this->previewQueries();
        foreach (range(2, 18) as $number) {
            $staff = $this->staff('Synthetic Batched '.sprintf('%02d', $number), ['calls.join' => true]);
            UserProfile::create(['user_id' => $staff->id, 'position' => 'Synthetic Batched Role']);
        }
        $queriesForMany = $this->previewQueries();
        foreach (['user_profiles', 'teams'] as $table) {
            $count = fn (array $queries) => count(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'from "'.$table.'"')));
            $this->assertSame(1, $count($queriesForOne), $table);
            $this->assertSame($count($queriesForOne), $count($queriesForMany), $table.' must not be queried per preview');
        }
        $this->assertSame(0, count(array_filter($queriesForMany, fn ($query) => str_contains(strtolower($query['query']), 'activity_log'))), 'Presence remains off without per-person activity queries');
        $this->assertNoCommunicationWrites();
    }

    public function test_native_actions_reject_unknown_inactive_nonstaff_and_self_targets_before_side_effects(): void
    {
        $viewer = $this->staff('Synthetic Guarded Viewer', ['operations.manage' => true, 'calls.start' => true, 'users.messages.create' => true]);
        $inactive = $this->staff('Synthetic Inactive Guard Target', ['calls.join' => true]);
        $inactive->update(['status' => false]);
        $guest = User::factory()->create(['role' => 'guest', 'status' => true]);
        foreach ([999999 => 404, $inactive->id => 404, $guest->id => 404, $viewer->id => 422] as $target => $status) {
            foreach (['openDirectChat', 'startDirectCall', 'openMessage'] as $action) {
                $arguments = $action === 'startDirectCall' ? [$target, 'voice'] : [$target];
                $component = $this->timeline($viewer->fresh());
                if ($status === 404) {
                    $this->assertTargetNotFound($component, $action, $arguments);
                } else {
                    $component->call($action, ...$arguments)->assertStatus($status)->assertNotDispatched('openMailModal');
                }
                $this->assertNoCommunicationWrites();
            }
        }
    }

    public function test_fresh_operations_or_actor_revocation_blocks_all_native_actions_before_side_effects(): void
    {
        $permissions = ['operations.manage' => true, 'calls.start' => true, 'users.messages.create' => true];
        $viewer = $this->staff('Synthetic Revoked Viewer', $permissions);
        $target = $this->staff('Synthetic Revocation Target', ['calls.join' => true]);
        foreach (['openDirectChat', 'startDirectCall', 'openMessage'] as $action) {
            $viewer->currentTeam->update(['rbac_permissions' => $permissions]);
            $component = $this->timeline($viewer->fresh());
            $viewer->currentTeam->update(['rbac_permissions' => []]);
            $arguments = $action === 'startDirectCall' ? [$target->id, 'voice'] : [$target->id];
            $component->call($action, ...$arguments)->assertForbidden()->assertNotDispatched('openMailModal');
            $this->assertNoCommunicationWrites();

            $this->admin->update(['status' => true]);
            $component = $this->timeline($this->admin->fresh());
            $this->admin->update(['status' => false]);
            $component->call($action, ...$arguments)->assertForbidden()->assertNotDispatched('openMailModal');
            $this->assertNoCommunicationWrites();
        }
    }

    public function test_native_call_and_message_specific_permissions_and_target_state_are_freshly_rechecked(): void
    {
        $permissions = ['operations.manage' => true, 'calls.start' => true, 'users.messages.create' => true];
        $viewer = $this->staff('Synthetic Permission Viewer', $permissions);
        $target = $this->staff('Synthetic Permission Target', ['calls.join' => true]);
        foreach (['calls.start' => 'startDirectCall', 'users.messages.create' => 'openMessage'] as $ability => $action) {
            $viewer->currentTeam->update(['rbac_permissions' => $permissions]);
            $component = $this->timeline($viewer->fresh());
            $viewer->currentTeam->update(['rbac_permissions' => array_diff_key($permissions, [$ability => true])]);
            $arguments = $action === 'startDirectCall' ? [$target->id, 'voice'] : [$target->id];
            $component->call($action, ...$arguments)->assertForbidden()->assertNotDispatched('openMailModal');
            $this->assertNoCommunicationWrites();
        }

        $component = $this->timeline($this->admin);
        $target->currentTeam->update(['rbac_permissions' => []]);
        $component->call('startDirectCall', $target->id, 'voice')->assertForbidden();
        $this->assertNoCommunicationWrites();
        $component = $this->timeline($this->admin);
        $target->update(['status' => false]);
        $this->assertTargetNotFound($component, 'openMessage', [$target->id]);
        $this->assertNoCommunicationWrites();
    }

    public function test_native_action_aliases_are_not_public_livewire_endpoints_and_invalid_call_mode_is_rejected(): void
    {
        foreach (['openTimelineDirectChat', 'startTimelineDirectCall', 'openTimelineMessage'] as $method) {
            $this->assertTrue((new \ReflectionMethod(StaffTimeline::class, $method))->isPrivate());
        }
        $target = $this->staff('Synthetic Invalid Mode Target', ['calls.join' => true]);
        $this->timeline($this->admin)->call('startDirectCall', $target->id, 'invalid')->assertStatus(422);
        $this->assertNoCommunicationWrites();
    }

    public function test_nonplanning_and_absence_only_timelines_retain_the_original_preview_without_new_action_capabilities(): void
    {
        $target = $this->staff('Synthetic Readonly Preview Target', ['calls.join' => true]);
        foreach ([['planningEnabled' => false], ['planningEnabled' => true, 'absencesOnly' => true]] as $scope) {
            $component = Livewire::actingAs($this->admin)->test(StaffTimeline::class, ['from' => '2026-10-10', 'until' => '2026-10-16', ...$scope]);
            [$xpath, $card] = $this->card($component, $target);
            $this->assertStringContainsString($target->email, $card->textContent);
            $this->assertSame(0, $xpath->query('.//button[@*[name()="x-on:click.prevent"]] | .//a[@*[name()="wire:navigate"]]', $card)->length);
            $this->assertSame(0, $xpath->query('//*[@*[name()="wire:snapshot"] and contains(@*[name()="wire:snapshot"],"admin.users.messages.message-form")]')->length);
            foreach (['openDirectChat', 'startDirectCall', 'openMessage'] as $action) {
                $arguments = $action === 'startDirectCall' ? [$target->id, 'voice'] : [$target->id];
                $component = Livewire::actingAs($this->admin)->test(StaffTimeline::class, ['from' => '2026-10-10', 'until' => '2026-10-16', ...$scope]);
                $component->call($action, ...$arguments)->assertForbidden()->assertNotDispatched('openMailModal');
                $this->assertNoCommunicationWrites();
            }
        }
    }

    private function staff(string $name, array $permissions = [], string $teamName = 'Synthetic Preview Team'): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'staff', 'status' => true]);
        $team = new Team(['name' => $teamName, 'personal_team' => false, 'rbac_permissions' => $permissions]);
        $team->user_id = $this->admin->id;
        $team->save();
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    private function timeline(User $viewer): Testable
    {
        return Livewire::actingAs($viewer)->test(StaffTimeline::class, ['from' => '2026-10-10', 'until' => '2026-10-16', 'planningEnabled' => true]);
    }

    private function card(Testable $component, User $target): array
    {
        $xpath = $this->xpath($component->html());
        $card = $xpath->query('//article[@aria-label="'.__('app.person_preview').': '.$target->name.'"]')->item(0);
        $this->assertNotNull($card);

        return [$xpath, $card];
    }

    private function actions(\DOMXPath $xpath, \DOMNode $card, string $expression): \DOMNodeList
    {
        return $xpath->query('.//button[@*[name()="x-on:click.prevent"]=\''.$expression.'\']', $card);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    private function previewQueries(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->timeline($this->admin);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }

    private function assertTargetNotFound(Testable $component, string $action, array $arguments): void
    {
        try {
            $component->call($action, ...$arguments);
            $this->fail('The unavailable employee must not reach the native action');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(User::class, $exception->getModel());
            $this->assertSame([(int) $arguments[0]], array_map('intval', $exception->getIds()));
            $component->assertNotDispatched('openMailModal');
        }
    }

    private function assertNoCommunicationWrites(): void
    {
        foreach (['chats', 'rooms', 'messages', 'shift_assignments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Http::assertNothingSent();
    }
}
