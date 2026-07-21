<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\EmployeeFormModal;
use App\Models\Mail as MailModel;
use App\Models\Message;
use App\Models\StaffInvitation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MailNotification;
use App\Support\EmployeeWelcomeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeWelcomeMessageTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        Schema::create('mails', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('message');
            $table->boolean('status')->default(false);
            $table->json('content');
            $table->json('recipients');
            $table->timestamps();
        });

        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('role')->default('staff');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('position')->nullable();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
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

    public function test_each_standard_team_receives_its_own_welcome_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(EmployeeWelcomeService::class);
        $expectations = [
            'Administratoren' => 'erweiterten Zugriffsrechten',
            'Verwaltung' => 'Aufgaben in der Verwaltung',
            'Mitarbeiter' => 'persönlichen Bereich',
            'Gäste' => 'für Ihr Team freigegebenen Informationen',
        ];

        foreach ($expectations as $teamName => $expectedText) {
            $team = $this->createTeam($admin, $teamName);
            $user = User::factory()->create([
                'role' => 'staff',
                'current_team_id' => $team->id,
            ]);

            $content = $service->contentFor($user);

            $this->assertSame($teamName, $content['team']);
            $this->assertCount(4, $content['lines']);
            $this->assertStringContainsString($expectedText, $content['body']);
        }
    }

    public function test_directly_created_employee_receives_one_internal_message_and_one_email(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $team = $this->createTeam($admin, 'Mitarbeiter');

        Livewire::actingAs($admin)
            ->test(EmployeeFormModal::class)
            ->set('name', 'Mara Muster')
            ->set('email', 'mara@example.test')
            ->set('password', 'sicheres-passwort')
            ->set('password_confirmation', 'sicheres-passwort')
            ->set('primary_team_id', $team->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('employeeSaved');

        $employee = User::query()->where('email', 'mara@example.test')->firstOrFail();
        $message = Message::query()->where('to_user', $employee->id)->sole();
        $mail = MailModel::query()->sole();

        $this->assertStringContainsString('persönlichen Bereich', $message->message);
        $this->assertSame('both', $mail->type);
        $this->assertSame('employee_welcome', $mail->content['system_key']);
        $this->assertSame('Mitarbeiter', $mail->content['team']);
        $this->assertTrue($mail->status);
        Notification::assertSentToTimes($employee, MailNotification::class, 1);
    }

    public function test_accepted_invitation_sends_management_welcome_exactly_once(): void
    {
        $this->withoutExceptionHandling();
        Notification::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $team = $this->createTeam($admin, 'Verwaltung');
        $invitation = StaffInvitation::query()->create([
            'email' => 'verwaltung@example.test',
            'role' => 'staff',
            'team_id' => $team->id,
            'position' => 'Assistenz',
            'token' => Str::random(64),
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->post(route('invitation.register.store', $invitation->token), [
            'name' => 'Vera Verwaltung',
            'password' => 'sicheres-passwort',
            'password_confirmation' => 'sicheres-passwort',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('dashboard'));
        $employee = User::query()->where('email', $invitation->email)->firstOrFail();
        $message = Message::query()->where('to_user', $employee->id)->sole();

        $this->assertStringContainsString('Aufgaben in der Verwaltung', $message->message);
        $this->assertSame(1, MailModel::query()->count());
        $this->assertSame(1, Message::query()->where('to_user', $employee->id)->count());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        Notification::assertSentToTimes($employee, MailNotification::class, 1);
    }

    private function createTeam(User $owner, string $name): Team
    {
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
    }
}
