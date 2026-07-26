<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Livewire\Settings\SoundPreferences;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sound\SoundLibrary;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Toene: systemweite Standards im Adminbereich, persoenliche Abweichungen im
 * Profil, Vorschau je Ereignis — und Speichern meldet sich als Toast.
 */
class SoundSettingsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_php_and_js_sound_signatures_stay_in_sync(): void
    {
        $script = File::get(public_path('js/rt-sounds.js'));

        foreach (SoundLibrary::signatures() as $signature) {
            $this->assertMatchesRegularExpression(
                '/^\s+'.preg_quote($signature, '/').'\s*:\s*function/m',
                $script,
                "Signatur '{$signature}' existiert in SoundLibrary, aber nicht in rt-sounds.js.",
            );
        }

        // Und die Gegenrichtung: Vorschau-API und Karten-Uebernahme vorhanden.
        $this->assertStringContainsString('preview:', $script);
        $this->assertStringContainsString("addEventListener('rt-sounds:map'", $script);
        $this->assertStringContainsString('rt-sound-map', $script);
    }

    public function test_admin_can_save_system_wide_sound_defaults(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->set('sounds.success', 'bell')
            ->set('sounds.chat', 'marimba')
            ->call('saveSounds')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast', type: 'success')
            ->assertDispatched('rt-sounds:map');

        $this->assertSame('bell', Setting::getValue('sounds', 'success'));
        $this->assertSame('marimba', Setting::getValue('sounds', 'chat'));
        $this->assertSame('bell', SoundLibrary::systemMap()['success']);
    }

    public function test_admin_sound_defaults_reject_unknown_signatures(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->set('sounds.success', 'kein-echter-ton')
            ->call('saveSounds')
            ->assertHasErrors(['sounds.success']);
    }

    public function test_user_overrides_win_and_empty_returns_to_the_system_default(): void
    {
        Setting::setValue('sounds', 'success', 'bell');
        $user = User::factory()->create();

        // Abweichung setzen: persoenlicher Ton gewinnt.
        Livewire::actingAs($user)
            ->test(SoundPreferences::class)
            ->set('sounds.success', 'ping')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast', type: 'success')
            ->assertDispatched('rt-sounds:map');

        $this->assertDatabaseHas('user_sound_preferences', [
            'user_id' => $user->id,
            'event' => 'success',
            'signature' => 'ping',
        ]);
        $this->assertSame('ping', SoundLibrary::mapFor($user->fresh())['success']);

        // Leer speichern: zurueck zum Systemstandard, Zeile verschwindet.
        Livewire::actingAs($user)
            ->test(SoundPreferences::class)
            ->set('sounds.success', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('user_sound_preferences', [
            'user_id' => $user->id,
            'event' => 'success',
        ]);
        $this->assertSame('bell', SoundLibrary::mapFor($user->fresh())['success']);
    }

    public function test_sound_picker_offers_every_event_with_instant_preview(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = Livewire::actingAs($admin)->test(Settings::class)->html();

        foreach (SoundLibrary::events() as $event) {
            $this->assertStringContainsString('data-sound-picker-row="'.$event.'"', $html);
            $this->assertStringContainsString('data-sound-preview="'.$event.'"', $html);
        }

        // Sofort-Vorschau beim Wechsel der Auswahl.
        $this->assertStringContainsString('window.RTSound?.preview(selected', $html);
    }

    public function test_profile_settings_tab_bundles_display_sounds_and_push(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('data-testid="display-settings"', escape: false)
            ->assertSee('data-testid="sound-preferences"', escape: false)
            ->assertSee('data-testid="push-settings"', escape: false)
            ->assertSee(__('app.sound_use_default'))
            ->assertSee(__('app.display_settings'));
    }

    public function test_inline_saved_messages_became_success_toasts(): void
    {
        // Die Komponente nutzt @this und rendert deshalb nur innerhalb von
        // Livewire (alle Verwendungen sind Livewire-Formulare) — geprueft wird
        // daher die Quelle statt eines isolierten Renders.
        $source = File::get(resource_path('views/components/action-message.blade.php'));

        // Unsichtbare Bruecke statt Inline-Text ...
        $this->assertStringContainsString('data-action-message-toast', $source);
        $this->assertStringContainsString('swal:toast', $source);
        $this->assertStringContainsString("type: 'success'", $source);
        // ... die frueheren Einblende-Stile sind weg.
        $this->assertStringNotContainsString('x-show.transition', $source);

        // Standardtext ist uebersetzt, kein hartes 'Saved.' mehr.
        $this->assertStringContainsString("__('app.saved')", $source);
        $this->assertStringNotContainsString("'Saved.'", $source);

        // Und im echten Kontext: das Profil rendert die Bruecke.
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('data-action-message-toast', escape: false);
    }
}
