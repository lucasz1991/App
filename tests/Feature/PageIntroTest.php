<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Employees;
use App\Livewire\Tools\HeaderInbox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Support\PageViews;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Seitenaufrufe werden je Nutzer vermerkt; beim allerersten Besuch erscheint
 * das Willkommens-Intro, beim ersten Besuch einer Unterseite oeffnet sich die
 * Seiteninfo automatisch — beides genau einmal und ueberspringbar.
 */
class PageIntroTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_page_views_are_recorded_once_per_user_and_page(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(PageViews::hasSeen($user, 'page:demo'));
        $this->assertTrue(PageViews::firstVisit($user, 'page:demo'));
        $this->assertFalse(PageViews::firstVisit($user, 'page:demo'));
        $this->assertTrue(PageViews::hasSeen($user, 'page:demo'));

        // Ein anderer Nutzer hat seine eigene Historie.
        $other = User::factory()->create();
        $this->assertTrue(PageViews::firstVisit($other, 'page:demo'));
    }

    public function test_subpage_opens_its_info_automatically_only_on_the_first_visit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Employees::class)
            ->assertSee('data-rt-intro-auto', escape: false)
            ->assertSee('rt-info:open', escape: false)
            // Js::from maskiert die Anfuehrungszeichen des JSON als ".
            ->assertSee('\u0022intro\u0022:true', escape: false);

        // Zweiter Aufruf: kein automatisches Intro mehr.
        Livewire::actingAs($admin)
            ->test(Employees::class)
            ->assertDontSee('data-rt-intro-auto', escape: false);
    }

    public function test_dashboard_shows_the_welcome_intro_exactly_once_instead_of_the_page_info(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('data-rt-welcome-intro', escape: false)
            ->assertSee(__('app.welcome_intro_eyebrow'))
            ->assertSee(__('app.lets_go'))
            ->assertSee(__('app.skip_intro'))
            // Das Willkommens-Intro ersetzt die automatische Seiteninfo.
            ->assertDontSee('data-rt-intro-auto', escape: false);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertDontSee('data-rt-welcome-intro', escape: false);
    }

    public function test_inbox_dropdown_uses_tabs_and_preselects_the_tab_with_news(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create();

        // Nur eine ungelesene CHAT-Nachricht -> Chats-Tab vorausgewaehlt.
        $chat = Chat::create(['type' => 'direct', 'created_by' => $sender->id]);
        $chat->participants()->attach([$sender->id, $recipient->id]);
        ChatMessage::create(['chat_id' => $chat->id, 'user_id' => $sender->id, 'body' => 'Hallo!']);

        $html = Livewire::actingAs($recipient)->test(HeaderInbox::class)->html();
        $this->assertStringContainsString('data-inbox-tab="chats"', $html);
        $this->assertStringContainsString('data-inbox-tab="messages"', $html);
        $this->assertStringContainsString('data-inbox-panel="chats"', $html);
        $this->assertStringContainsString('data-inbox-panel="messages"', $html);
        $this->assertStringContainsString("inboxTab: 'chats'", $html);

        // Mehr ungelesene NACHRICHTEN als Chats -> Nachrichten-Tab vorne.
        foreach (['Info 1', 'Info 2'] as $subject) {
            Message::create([
                'subject' => $subject,
                'message' => 'Bitte lesen.',
                'from_user' => $sender->id,
                'to_user' => $recipient->id,
                'status' => 1,
            ]);
        }

        $html = Livewire::actingAs($recipient)->test(HeaderInbox::class)->html();
        $this->assertStringContainsString("inboxTab: 'messages'", $html);
    }
}
