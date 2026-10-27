<?php

namespace Tests\Feature;

use App\Livewire\Profile\ProfileIdentityCard;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OwnProfileIdentityHeaderTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private string $runtimePath;

    private array $viewEnvironment;

    public function createApplication(): Application
    {
        $this->runtimePath = sys_get_temp_dir().'/railtime-own-profile-identity-'.bin2hex(random_bytes(8));
        mkdir($this->runtimePath.'/views', 0700, true);
        $this->viewEnvironment = [getenv('VIEW_COMPILED_PATH'), $_ENV['VIEW_COMPILED_PATH'] ?? null, $_SERVER['VIEW_COMPILED_PATH'] ?? null];
        putenv('VIEW_COMPILED_PATH='.$this->runtimePath.'/views');
        $_ENV['VIEW_COMPILED_PATH'] = $this->runtimePath.'/views';
        $_SERVER['VIEW_COMPILED_PATH'] = $this->runtimePath.'/views';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        app()->setLocale('de');
        Http::preventStrayRequests();
        Notification::fake();
        Mail::fake();
        Bus::fake();
        config([
            'outlook_addin.snapshots.auto_refresh' => false,
            'jetstream.profile_photo_disk' => 'identity-header-photos',
            'livewire.temporary_file_upload.disk' => 'identity-header-uploads',
            'filesystems.disks.identity-header-photos' => ['driver' => 'local', 'root' => $this->runtimePath.'/photos', 'url' => '/synthetic-profile-photos', 'visibility' => 'public'],
            'filesystems.disks.identity-header-uploads' => ['driver' => 'local', 'root' => $this->runtimePath.'/uploads'],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$original, $env, $server] = $this->viewEnvironment;
        putenv($original === false ? 'VIEW_COMPILED_PATH' : 'VIEW_COMPILED_PATH='.$original);
        if ($env === null) {
            unset($_ENV['VIEW_COMPILED_PATH']);
        } else {
            $_ENV['VIEW_COMPILED_PATH'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['VIEW_COMPILED_PATH']);
        } else {
            $_SERVER['VIEW_COMPILED_PATH'] = $server;
        }
        $resolved = str_replace('\\', '/', (string) realpath($this->runtimePath));
        $expectedPrefix = rtrim(str_replace('\\', '/', (string) realpath(sys_get_temp_dir())), '/').'/railtime-own-profile-identity-';
        if (str_starts_with($resolved, $expectedPrefix)) {
            (new Filesystem)->deleteDirectory($this->runtimePath);
        }
    }

    #[DataProvider('verificationStates')]
    public function test_verified_icon_has_an_accessible_name_without_visible_status_text_and_retains_the_unverified_action(bool $verified): void
    {
        $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);
        $component = Livewire::actingAs($user)->test(ProfileIdentityCard::class)->assertSuccessful();
        $xpath = $this->xpath($component->html());
        $status = $xpath->query('//*[@data-profile-email-verified]');
        $this->assertCount(1, $status);
        $this->assertSame('img', $status->item(0)->getAttribute('role'));
        $this->assertSame(__('app.email_verified'), $status->item(0)->getAttribute('aria-label'));
        $this->assertSame('', trim($status->item(0)->textContent));
        $this->assertSame('emailVerified', $status->item(0)->getAttribute('x-show.important'));
        $this->assertStringContainsString('emailVerified: '.($verified ? 'true' : 'false'), $component->html());
        $resend = $xpath->query('//button[@*[name()="x-on:click.prevent"]="requestEmailVerification()"]');
        $this->assertCount(1, $resend);
        $this->assertSame('! emailVerified', $resend->item(0)->getAttribute('x-show.important'));
        $this->assertStringContainsString(__('app.verify_email_now'), $resend->item(0)->textContent);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public static function verificationStates(): array
    {
        return ['verified' => [true], 'unverified' => [false]];
    }

    public function test_photo_actions_keep_their_accessible_controls_and_only_show_delete_for_an_existing_photo(): void
    {
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(ProfileIdentityCard::class);
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//button[@*[name()="wire:click"]="deleteProfilePhoto"]'));
        $user->forceFill(['profile_photo_path' => 'synthetic.jpg'])->save();
        $component->call('$refresh');
        $xpath = $this->xpath($component->html());
        $photo = $xpath->query('//button[@aria-label="'.__('app.change_profile_photo').'"]');
        $this->assertCount(1, $photo);
        $this->assertSame('$refs.photo.click()', $photo->item(0)->getAttribute('x-on:click'));
        $this->assertCount(1, $xpath->query('.//i[contains(@class,"fa-camera")]', $photo->item(0)));
        $this->assertCount(1, $xpath->query('//input[@type="file" and @accept="image/*" and @*[name()="wire:model"]="photo"]'));
        $delete = $xpath->query('//button[@*[name()="wire:click"]="deleteProfilePhoto"]');
        $this->assertCount(1, $delete);
        $this->assertSame(__('app.remove_profile_photo'), $delete->item(0)->getAttribute('aria-label'));
        $this->assertSame('photo', $delete->item(0)->getAttribute('data-autosave-action-model'));
        $this->assertSame('true', $delete->item(0)->getAttribute('data-autosave-instant'));
    }

    public function test_long_identity_values_are_escaped_and_keep_the_native_label_input_and_keyboard_contract(): void
    {
        $name = str_repeat('Mustername ', 18).'<em>QA</em>';
        $email = str_repeat('x', 55).'@'.str_repeat('a', 45).'.example.invalid';
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $component = Livewire::actingAs($user)->test(ProfileIdentityCard::class);
        $xpath = $this->xpath($component->html());
        $this->assertCount(0, $xpath->query('//em'));
        foreach (['name' => $name, 'email' => $email] as $field => $value) {
            $id = 'profile-identity-'.$field;
            $label = $xpath->query('//label[@for="'.$id.'" and @data-native-inline-edit-trigger]');
            $this->assertCount(1, $label);
            $this->assertStringContainsString($value, $label->item(0)->textContent);
            $this->assertSame('button', $label->item(0)->getAttribute('role'));
            $this->assertSame('0', $label->item(0)->getAttribute('tabindex'));
            $this->assertSame("openEditorFromKeyboard('".$field."')", $label->item(0)->getAttribute('x-on:keydown.enter.prevent'));
            $this->assertCount(1, $xpath->query('//input[@id="'.$id.'" and @*[name()="wire:model"]="'.$field.'"]'));
        }
        Http::assertNothingSent();
    }

    public function test_instant_photo_upload_and_delete_preserve_unsaved_identity_and_another_users_photo(): void
    {
        $disk = Storage::disk('identity-header-photos');
        $disk->put('original.jpg', 'original fixture');
        $disk->put('other.jpg', 'other fixture');
        $user = User::factory()->create(['name' => 'Original', 'email' => 'original@example.invalid', 'profile_photo_path' => 'original.jpg']);
        $other = User::factory()->create(['profile_photo_path' => 'other.jpg']);
        $otherBefore = $other->fresh()->getAttributes();
        $component = Livewire::actingAs($user)->test(ProfileIdentityCard::class)
            ->set('name', 'Unsaved Name')
            ->set('email', 'unsaved@example.invalid')
            ->set('photo', UploadedFile::fake()->image('avatar.jpg', 48, 48))
            ->assertHasNoErrors()
            ->assertDispatched('identity-saved', fields: ['photo'])
            ->assertSet('name', 'Unsaved Name')
            ->assertSet('email', 'unsaved@example.invalid');
        $uploaded = $user->fresh()->profile_photo_path;
        $this->assertNotSame('original.jpg', $uploaded);
        $disk->assertExists($uploaded);
        $disk->assertMissing('original.jpg');
        $this->assertSame('Original', $user->fresh()->name);
        $this->assertSame('original@example.invalid', $user->fresh()->email);
        $component->call('deleteProfilePhoto')->assertHasNoErrors()->assertDispatched('identity-saved', fields: ['photo']);
        $this->assertNull($user->fresh()->profile_photo_path);
        $disk->assertMissing($uploaded);
        $disk->assertExists('other.jpg');
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_name_autosave_is_self_scoped_and_does_not_reset_verification(): void
    {
        $user = User::factory()->create(['name' => 'Original', 'email' => 'unchanged@example.invalid']);
        $other = User::factory()->create();
        $otherBefore = $other->fresh()->getAttributes();
        Livewire::actingAs($user)->test(ProfileIdentityCard::class)
            ->set('name', 'Updated Name')->call('saveIdentity')->assertHasNoErrors()
            ->assertDispatched('identity-saved', fields: ['name', 'email']);
        $this->assertSame('Updated Name', $user->fresh()->name);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        Notification::assertNothingSent();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
