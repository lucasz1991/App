<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Employees;
use App\Livewire\Tools\HeaderInbox;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\PageViews;
use App\Support\WelcomeIntroCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
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

    public function test_dashboard_auto_opens_the_welcome_intro_once_and_keeps_it_replayable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('data-rt-welcome-intro', escape: false)
            ->assertSee('data-rt-welcome-initially-open="true"', escape: false)
            ->assertSee('data-rt-welcome-audience="admin"', escape: false)
            ->assertSee('data-welcome-intro-trigger', escape: false)
            ->assertSee(trans('app.welcome_intro_content.admin.label'))
            ->assertSee(trans('app.welcome_intro_original_recording'))
            ->assertSee('data-rt-welcome-video', escape: false)
            ->assertSee('data-rt-welcome-module-nav', escape: false)
            ->assertSee('role="progressbar"', escape: false)
            ->assertSee(__('app.skip_intro'))
            // Das Willkommens-Intro ersetzt die automatische Seiteninfo.
            ->assertDontSee('data-rt-intro-auto', escape: false);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('data-rt-welcome-intro', escape: false)
            ->assertSee('data-rt-welcome-initially-open="false"', escape: false)
            ->assertSee('x-on:rt-welcome:open.window="openIntro($event)"', escape: false)
            ->assertSee('data-welcome-intro-trigger', escape: false);

        $this->assertTrue(PageViews::hasSeen($admin, WelcomeIntroCatalog::TRACKING_KEY));
    }

    public function test_welcome_intro_has_dedicated_content_for_every_dashboard_audience(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $managementTeam = $this->team($owner, 'Verwaltung');
        $employeeTeam = $this->team($owner, 'Mitarbeiter');

        $cases = [
            'admin' => [
                'user' => User::factory()->create(['role' => 'admin']),
                'modules' => ['intro', 'devices', 'orders', 'shifts', 'communication', 'wagon-lists', 'files', 'support', 'integrations'],
            ],
            'management' => [
                'user' => User::factory()->create(['role' => 'staff', 'current_team_id' => $managementTeam->id]),
                'modules' => ['intro', 'communication', 'wagon-lists', 'files', 'support'],
            ],
            'employee' => [
                'user' => User::factory()->create(['role' => 'staff', 'current_team_id' => $employeeTeam->id]),
                'modules' => ['intro', 'communication', 'wagon-lists', 'files', 'support'],
            ],
            'guest' => [
                'user' => User::factory()->create(['role' => 'editor']),
                'modules' => ['intro', 'communication', 'files', 'support'],
            ],
        ];

        foreach ($cases as $audience => $case) {
            $user = $case['user'];
            $this->actingAs($user);

            $html = Blade::render('<x-ui.welcome-intro :initially-open="false" />');
            $content = trans('app.welcome_intro_content.'.$audience);
            $catalog = app(WelcomeIntroCatalog::class)->forUser($user);

            $this->assertStringContainsString(
                'data-rt-welcome-audience="'.$audience.'"',
                $html,
                "Das Intro fuer {$audience} traegt nicht die richtige Zielgruppe.",
            );
            $this->assertStringContainsString($content['label'], $html);
            $this->assertSame($case['modules'], array_column($catalog['slides'], 'id'));

            foreach ($case['modules'] as $module) {
                $this->assertStringContainsString('data-rt-welcome-module', $html);
                $this->assertStringContainsString($module, $html);
            }
        }
    }

    public function test_delegated_device_permission_adds_the_device_module_for_every_audience(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $employeeTeam = $this->team($owner, 'Mitarbeiter');
        $employeeTeam->forceFill(['rbac_permissions' => ['devices.view' => true]])->save();

        $employee = User::factory()->create([
            'role' => 'staff',
            'current_team_id' => $employeeTeam->id,
        ]);

        $modules = array_column(
            app(WelcomeIntroCatalog::class)->forUser($employee)['slides'],
            'id',
        );

        $this->assertSame('devices', $modules[1]);
    }

    public function test_management_dashboard_mounts_the_shared_welcome_intro_without_a_dashboard_view_copy(): void
    {
        $this->withoutMiddleware(LogActivity::class);

        $owner = User::factory()->create(['role' => 'admin']);
        $managementTeam = $this->team($owner, 'Verwaltung');
        $manager = User::factory()->create([
            'role' => 'staff',
            'current_team_id' => $managementTeam->id,
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-rt-welcome-audience="management"', escape: false)
            ->assertSee('data-rt-welcome-initially-open="true"', escape: false)
            ->assertSee('data-welcome-intro-trigger', escape: false);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-rt-welcome-audience="management"', escape: false)
            ->assertSee('data-rt-welcome-initially-open="false"', escape: false)
            ->assertSee('data-welcome-intro-trigger', escape: false);
    }

    public function test_welcome_intro_exposes_progress_navigation_keyboard_and_focus_contracts(): void
    {
        $view = File::get(resource_path('views/components/ui/welcome-intro.blade.php'));
        $controller = File::get(resource_path('js/welcome-intro.js'));
        $application = File::get(resource_path('js/app.js'));

        foreach ([
            'role="progressbar"',
            'x-bind:aria-valuenow="completion"',
            'x-on:click="previous()"',
            'x-on:click="next()"',
            'x-on:click="skip()"',
            'x-trap.inert.noscroll="open"',
            'x-on:keydown="handleKey($event)"',
            'aria-live="polite"',
            'controlslist="nodownload"',
            'playsinline',
            'preload="metadata"',
        ] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }

        foreach ([
            "event.key === 'ArrowRight'",
            "event.key === 'ArrowLeft'",
            "event.key === 'Home'",
            "event.key === 'End'",
            'target.focus({ preventScroll: true })',
            'this.$refs.heading?.focus({ preventScroll: true })',
            'this.pauseVideo()',
            "target.closest('video, button, a, input, textarea, select",
            'destroy()',
        ] as $contract) {
            $this->assertStringContainsString($contract, $controller);
        }

        $this->assertStringContainsString("import { welcomeIntro } from './welcome-intro';", $application);
        $this->assertStringContainsString("Alpine.data('welcomeIntro', welcomeIntro);", $application);
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

    private function team(User $owner, string $name): Team
    {
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
    }
}
