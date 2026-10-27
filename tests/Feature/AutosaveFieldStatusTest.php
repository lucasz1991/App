<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AutosaveFieldStatusTest extends TestCase
{
    public function test_autosave_controller_is_headless_and_navigation_waits_for_an_active_save(): void
    {
        $source = str_replace(["\r\n", "\r"], "\n", file_get_contents(resource_path('views/components/ui/autosave-status.blade.php')));

        $this->assertStringContainsString('data-autosave-controller', $source);
        $this->assertStringContainsString('if (nativeFocusTarget) {', $source);
        $this->assertStringNotContainsString('if (!target?.isConnected) return;', $source);
        $this->assertStringContainsString('class="sr-only"', $source);
        $this->assertStringNotContainsString('inline-flex h-7 w-7', $source);
        $this->assertStringNotContainsString('bg-amber-400 shadow-', $source);

        $flushStart = strpos($source, '        flush() {');
        $this->assertNotFalse($flushStart);

        $flush = substr($source, $flushStart);
        $this->assertStringContainsString(
            'if (this.saving) {'."\n"
            .'                return this.pendingSave.then(() => this.hasDirtyEntries() ? this.flush() : true);',
            $flush,
        );
        $this->assertLessThan(
            strpos($flush, 'const snapshot = this.dirtyEntries()'),
            strpos($flush, 'if (this.saving)'),
            'Der laufende Request muss vor einem neuen Snapshot abgefangen werden.',
        );
        $this->assertStringContainsString(
            'return Promise.all(',
            $source,
        );
        $this->assertStringContainsString(
            "document.addEventListener('livewire:navigate', coordinator.navigateListener, true);",
            $source,
        );
        $this->assertStringContainsString(
            "window.addEventListener('beforeunload', coordinator.beforeUnloadListener);",
            $source,
        );
        $this->assertStringContainsString('Boolean(event.detail?.history)', $source);
        $this->assertStringContainsString('window.location.reload();', $source);
        $this->assertStringNotContainsString('stopImmediatePropagation', $source);
        $this->assertStringContainsString(
            'if (Array.isArray(detail.savedFields)) fields.push(...detail.savedFields);',
            $source,
        );
        $this->assertStringContainsString('const externalFields = fields.filter', $source);
        $this->assertStringContainsString('const activeKeys = new Set(this.activeSnapshot.map(entry => entry.key));', $source);
        $this->assertStringContainsString('x-on:rt-autosave-reset.window="requestReset($event)"', $source);
    }

    public function test_shared_inputs_render_stable_field_level_autosave_metadata(): void
    {
        $input = Blade::render('<x-ui.forms.input id="profile-name" wire:model="name" />');
        $textarea = Blade::render('<x-ui.forms.textarea id="profile-note" wire:model="note" />');
        $toggle = Blade::render('<x-ui.forms.toggle-button id="maintenance" model="maintenanceMode" />');

        foreach ([$input, $textarea] as $control) {
            $this->assertStringContainsString('data-autosave-field', $control);
            $this->assertStringContainsString('data-autosave-visual', $control);
            $this->assertStringContainsString('data-autosave-state="idle"', $control);
        }

        $this->assertStringContainsString('data-autosave-model="name"', $input);
        $this->assertStringContainsString('data-autosave-field-id="profile-name"', $input);
        $this->assertStringContainsString('data-autosave-model="note"', $textarea);
        $this->assertStringContainsString('data-autosave-field-id="profile-note"', $textarea);
        $this->assertStringContainsString('data-autosave-model="maintenanceMode"', $toggle);
        $this->assertStringContainsString('data-autosave-field-id="maintenance"', $toggle);
        $this->assertStringContainsString('data-autosave-visual', $toggle);
    }

    public function test_custom_select_inline_identity_and_photo_keep_exact_field_instances(): void
    {
        $select = file_get_contents(resource_path('views/components/ui/forms/select.blade.php'));
        $inline = file_get_contents(resource_path('views/components/ui/inline-edit-field.blade.php'));
        $identity = file_get_contents(resource_path('views/livewire/profile/profile-identity-card.blade.php'));
        $notes = file_get_contents(resource_path('views/livewire/admin/user-profile/user-notes.blade.php'));
        $settings = file_get_contents(resource_path('views/livewire/admin/settings.blade.php'));

        $this->assertStringContainsString('data-autosave-model="{{ $wireModel }}"', $select);
        $this->assertStringContainsString('data-autosave-field-id="{{ $autosaveFieldId }}"', $select);
        $this->assertStringContainsString('data-autosave-visual', $select);
        $this->assertGreaterThanOrEqual(3, substr_count($select, "data-autosave-instant=\"true\""));
        $this->assertStringContainsString('data-autosave-field-id="{{ $inputId }}"', $inline);
        $this->assertStringContainsString("'rt-autosave-reset'", $inline);
        $this->assertStringContainsString('model: @js($wireModel)', $inline);
        $this->assertStringContainsString('fieldId: @js($inputId)', $inline);
        $this->assertStringContainsString('x-ref="editorShell"', $inline);
        $this->assertStringContainsString("shell?.style.removeProperty('display');", $inline);
        $this->assertStringContainsString('for="{{ $inputId }}"', $inline);
        $this->assertStringContainsString("pointerKind === 'mouse'", $inline);
        $this->assertStringContainsString('else { prepareOpen(); }', $inline);
        $this->assertStringContainsString('x-on:dblclick.prevent="openFromKeyboard()"', $inline);
        $this->assertStringContainsString('data-native-inline-edit-trigger', $inline);
        $this->assertStringNotContainsString('document.activeElement !== editor', $inline);
        $this->assertStringNotContainsString('x-on:dblclick.prevent="open()"', $inline);

        $this->assertStringContainsString('data-autosave-field-id="profile-identity-name"', $identity);
        $this->assertStringContainsString('data-autosave-field-id="profile-identity-email"', $identity);
        $this->assertStringContainsString("input?.style.removeProperty('display');", $identity);
        $this->assertStringContainsString('for="profile-identity-name"', $identity);
        $this->assertStringContainsString('for="profile-identity-email"', $identity);
        $this->assertStringContainsString('x-on:click="prepareEditor(\'name\')"', $identity);
        $this->assertStringContainsString('x-on:click="prepareEditor(\'email\')"', $identity);
        $this->assertSame(2, substr_count($identity, 'data-native-inline-edit-trigger'));
        $this->assertStringNotContainsString('document.activeElement !== input', $identity);
        $this->assertStringContainsString('data-autosave-action-model="photo"', $identity);
        $this->assertStringContainsString('data-autosave-instant="true"', $identity);
        $this->assertStringContainsString('target="saveIdentity"', $identity);
        $this->assertStringContainsString('emailVerified: @js($this->user->hasVerifiedEmail())', $identity);
        $this->assertStringContainsString('x-on:identity-saved.window', $identity);
        $this->assertStringContainsString('x-show.important="! emailVerified"', $identity);
        $this->assertStringNotContainsString('target="saveIdentity,photo,deleteProfilePhoto"', $identity);
        $this->assertStringContainsString('requestEmailVerification()', $identity);
        $this->assertStringNotContainsString('wire:click="sendEmailVerification"', $identity);

        $this->assertStringContainsString('for="user-note-editor-{{ $note->id }}"', $notes);
        $this->assertStringContainsString('id="user-note-editor-{{ $note->id }}"', $notes);
        $this->assertStringContainsString("pointerKind === 'mouse'", $notes);
        $this->assertStringContainsString('else { prepareOpen(); }', $notes);
        $this->assertStringContainsString('x-on:dblclick.prevent="openFromKeyboard()"', $notes);
        $this->assertStringContainsString('data-native-inline-edit-trigger', $notes);
        $this->assertStringNotContainsString('document.activeElement !== editor', $notes);
        $this->assertStringNotContainsString('x-on:dblclick.prevent="open()"', $notes);

        $this->assertStringContainsString('dirty-target="maintenanceMode"', $settings);
        $this->assertStringContainsString('model="maintenanceMode" data-autosave-instant="true"', $settings);
    }

    public function test_autosave_uses_one_visual_ring_for_focus_dirty_saving_saved_and_errors(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $feedbackStart = strpos($styles, '/* Einheitlicher Fokus- und Autosave-Ring');

        $this->assertIsInt($feedbackStart);
        $feedbackStyles = substr($styles, $feedbackStart);

        $this->assertStringContainsString('.rt-ui-control:focus,', $feedbackStyles);
        $this->assertStringContainsString('.rt-ui-number-input:focus-within,', $feedbackStyles);
        $this->assertStringContainsString('box-shadow: inset 0 0 0 3px rgba(228, 0, 43, 0.16);', $feedbackStyles);

        $this->assertStringContainsString('[data-autosave-scope] [data-autosave-visual][data-autosave-state="dirty"]', $feedbackStyles);
        $this->assertStringContainsString('[data-autosave-scope] [data-autosave-visual][data-autosave-state="saving"]', $feedbackStyles);
        $this->assertStringContainsString('[data-autosave-scope] [data-autosave-visual][data-autosave-state="saved"]', $feedbackStyles);
        $this->assertStringContainsString('box-shadow: inset 0 0 0 3px rgba(245, 158, 11, 0.25);', $feedbackStyles);
        $this->assertStringContainsString('box-shadow: inset 0 0 0 3px rgba(16, 185, 129, 0.24);', $feedbackStyles);
        $this->assertStringContainsString('[data-autosave-visual][aria-invalid="true"]', $feedbackStyles);
        $this->assertStringContainsString('box-shadow: inset 0 0 0 3px rgba(220, 38, 38, 0.22);', $feedbackStyles);
        $this->assertStringContainsString('html.dark [data-autosave-scope] [data-autosave-visual]', $feedbackStyles);

        $this->assertStringNotContainsString('[data-autosave-field][data-autosave-state=', $feedbackStyles);
        $this->assertStringNotContainsString('outline-offset:', $feedbackStyles);
        $this->assertStringNotContainsString('outline-color:', $feedbackStyles);
        $this->assertStringNotContainsString('outline: 2px', $feedbackStyles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $feedbackStyles);
    }

    public function test_profile_photo_and_verification_stay_isolated_from_dirty_identity_fields(): void
    {
        $component = file_get_contents(app_path('Livewire/Profile/ProfileIdentityCard.php'));
        $identity = file_get_contents(resource_path('views/livewire/profile/profile-identity-card.blade.php'));
        $notes = file_get_contents(resource_path('views/livewire/admin/user-profile/user-notes.blade.php'));

        $this->assertStringContainsString('Auth::user()->updateProfilePhoto($this->photo);', $component);
        $this->assertStringNotContainsString("'photo' => \$this->photo", $component);
        $this->assertStringContainsString('if ($this->email !== $user->email)', $component);
        $this->assertStringContainsString("scope?.dispatchEvent(new CustomEvent('rt-autosave-flush'", $identity);
        $this->assertStringContainsString("state === 'dirty' || state === 'saving'", $identity);
        $this->assertStringContainsString("'rt-autosave-reset'", $notes);
        $this->assertStringContainsString("model: @js('noteBodies.'.\$note->id)", $notes);
        $this->assertStringContainsString("fieldId: @js('user-note-'.\$note->id)", $notes);
    }

    public function test_batch_inline_events_keep_changed_fields_and_report_every_completed_snapshot_field(): void
    {
        $employee = file_get_contents(app_path('Livewire/Admin/UserProfile.php'));
        $notes = file_get_contents(app_path('Livewire/Admin/UserProfile/UserNotes.php'));

        $this->assertStringContainsString('fields: $changedFields', $employee);
        $this->assertStringContainsString('savedFields: $requestedFields', $employee);
        $this->assertStringContainsString('noteIds: $savedNoteIds', $notes);
        $this->assertStringContainsString('savedFields: array_map(', $notes);
        $this->assertStringContainsString('$requestedNoteIds', $notes);
    }
}
