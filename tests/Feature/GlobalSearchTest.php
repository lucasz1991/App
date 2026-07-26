<?php

namespace Tests\Feature;

use App\Livewire\Admin\MailManagement;
use App\Livewire\Tools\GlobalSearch;
use App\Models\Chat;
use App\Models\Mail;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_only_returns_messages_and_chats_owned_by_the_current_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Anna Empfaenger',
            'role' => 'user',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        $chatPartner = User::factory()->create([
            'name' => 'Orbit Partner',
            'role' => 'user',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        $otherUser = User::factory()->create([
            'name' => 'Fremder Benutzer',
            'role' => 'user',
            'status' => true,
            'email_verified_at' => now(),
        ]);

        $ownMessage = Message::query()->create([
            'subject' => 'Orbit Eigene Nachricht',
            'message' => 'Nur fuer Anna',
            'from_user' => $chatPartner->id,
            'to_user' => $user->id,
            'status' => 1,
        ]);
        Message::query()->create([
            'subject' => 'Orbit Fremdes Geheimnis',
            'message' => 'Darf nicht erscheinen',
            'from_user' => $chatPartner->id,
            'to_user' => $otherUser->id,
            'status' => 1,
        ]);

        $ownChat = Chat::directBetween($user, $chatPartner);
        Chat::directBetween($otherUser, $chatPartner);

        Livewire::actingAs($user)
            ->test(GlobalSearch::class)
            ->set('query', 'Orbit')
            ->call('openResults')
            ->assertSet('showResults', true)
            ->assertSee('Orbit Eigene Nachricht')
            ->assertSee(route('messages', ['open' => $ownMessage->id]), false)
            ->assertSee('Orbit Partner')
            ->assertSee(route('chat', ['chat' => $ownChat->id]), false)
            ->assertDontSee('Orbit Fremdes Geheimnis')
            ->assertDontSee('Fremder Benutzer');
    }

    public function test_employee_results_are_hidden_without_permission_and_linked_for_admins(): void
    {
        $viewer = User::factory()->create([
            'role' => 'user',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => true,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'name' => 'Sicherheitspruefung Adler',
            'email' => 'adler-search@example.test',
            'role' => 'staff',
            'status' => true,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($viewer)
            ->test(GlobalSearch::class)
            ->set('query', 'Sicherheitspruefung')
            ->call('openResults')
            ->assertDontSee($employee->name)
            ->assertDontSee($employee->email);

        Livewire::actingAs($admin)
            ->test(GlobalSearch::class)
            ->set('query', 'Sicherheitspruefung')
            ->call('openResults')
            ->assertSee($employee->name)
            ->assertSee($employee->email)
            ->assertSee(route('admin.user-profile', ['userId' => $employee->id]), false);
    }

    public function test_short_queries_show_the_minimum_hint_without_results(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => true,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(GlobalSearch::class)
            ->set('query', 'a')
            ->call('openResults')
            ->assertSee(__('app.global_search_minimum'))
            ->assertDontSee('data-global-search-result', false);
    }

    public function test_help_installation_is_available_as_a_real_navigation_result(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => true,
            'email_verified_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(GlobalSearch::class)
            ->set('query', 'App installieren')
            ->call('openResults')
            ->assertSee(__('app.help'))
            ->assertSee(route('help'), false);
    }

    public function test_mail_table_search_filters_recipient_content_on_the_server(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => true,
            'email_verified_at' => now(),
        ]);

        Mail::withoutEvents(function (): void {
            Mail::query()->create([
                'type' => 'mail',
                'status' => false,
                'content' => ['subject' => 'Fahrplan'],
                'recipients' => [['email' => 'zielsuche@example.test', 'status' => false]],
            ]);
            Mail::query()->create([
                'type' => 'mail',
                'status' => false,
                'content' => ['subject' => 'Andere Nachricht'],
                'recipients' => [['email' => 'andere@example.test', 'status' => false]],
            ]);
        });

        Livewire::actingAs($admin)
            ->test(MailManagement::class)
            ->set('search', 'zielsuche@example.test')
            ->assertSet('search', 'zielsuche@example.test')
            ->assertViewHas(
                'mails',
                fn ($mails): bool => $mails->total() === 1
                    && data_get($mails->first()->recipients, '0.email') === 'zielsuche@example.test',
            );
    }
}
