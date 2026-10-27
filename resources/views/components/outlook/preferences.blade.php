@props([
    'instancePrefix' => 'profile-outlook',
    'context' => 'self',
    'targetName' => '',
    'preferences' => [],
    'available' => false,
    'notice' => '',
    'choiceSelections' => [],
    'templateOptions' => [],
    'choicesAvailable' => false,
    'choicesNotice' => '',
    'choicesRevision' => '',
    'greeting' => '',
    'farewell' => '',
    'wordingAvailable' => false,
    'wordingNotice' => '',
])

@php
    $prefix = preg_replace('/[^A-Za-z0-9_-]/', '-', $instancePrefix) ?: 'profile-outlook';
    $employeeContext = $context === 'employee';
    $maxLength = \App\Support\OutlookAddin\OutlookAddinWordingPolicy::MAX_LENGTH;
@endphp

<section class="min-w-0 overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" aria-labelledby="{{ $prefix }}-heading" data-testid="profile-outlook-preferences" data-outlook-preferences-context="{{ $employeeContext ? 'employee' : 'self' }}">
    <header class="space-y-2 border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:px-6 sm:py-5">
        <h2 id="{{ $prefix }}-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Outlook-Einstellungen</h2>
        @if ($employeeContext)
            <p class="text-sm font-medium text-rt-text dark:text-rt-dark-text">Konto von {{ $targetName }}</p>
        @endif
        <p id="{{ $prefix }}-shared-hint" class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            {{ $employeeContext ? 'Diese Einstellungen gelten für dieses Mitarbeiterkonto' : 'Deine Einstellungen gelten' }} auf allen Geräten, auch auf dem iPhone. Änderungen greifen beim nächsten Verfassen; bereits geöffnete Nachrichten bleiben erhalten.
        </p>
    </header>

    <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
        <section class="space-y-3 p-4 sm:p-6" aria-labelledby="{{ $prefix }}-automatic-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 id="{{ $prefix }}-automatic-heading" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Automatische Einfügung</h3>
                @if ($available || $choicesAvailable)
                    <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wird sofort gespeichert</p>
                @endif
            </div>
            @if ($available || $choicesAvailable)
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['newMessages' => 'Neue Nachrichten', 'replies' => 'Antworten'] as $group => $title)
                        <fieldset class="min-w-0 space-y-3 rounded-xl border border-rt-border/60 p-3 dark:border-rt-dark-border/60" wire:key="{{ $prefix }}-{{ $group }}" wire:loading.attr="disabled" wire:target="updatePreference,updateTemplateChoice,reloadPreferences">
                            <legend class="px-1 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $title }}</legend>
                            @if ($group === 'newMessages')
                                <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Einschließlich Weiterleitungen</p>
                            @endif
                            @if ($available)
                                <div class="flex min-h-11 items-center justify-between gap-3">
                                    <span class="text-sm text-rt-text dark:text-rt-dark-text">Signatur einfügen</span>
                                    <x-ui.forms.checkbox
                                        :id="$prefix.'-'.$group.'-signature'"
                                        :toggle="true"
                                        :checked="$preferences[$group]['signature']"
                                        :aria-label="'Signatur automatisch einfügen: '.$title.($group === 'newMessages' ? ' und Weiterleitungen' : '')"
                                        class="min-h-11"
                                        wire:change="updatePreference('{{ $group }}', 'signature', $event.target.checked)"
                                        wire:loading.attr="disabled"
                                        wire:target="updatePreference,updateTemplateChoice,reloadPreferences"
                                        aria-describedby="{{ $prefix }}-shared-hint"
                                    />
                                </div>
                            @endif
                            @if ($choicesAvailable)
                                @php
                                    $selection = (string) ($choiceSelections[$group] ?? '');
                                    $missingChoice = ! in_array($selection, ['default', 'none'], true) && ! in_array($selection, array_column($templateOptions, 'id'), true);
                                    $changeAction = '$wire.updateTemplateChoice(\''.$group.'\', selected)';
                                    $choiceKey = substr(hash('sha256', $choicesRevision.'|'.$selection.'|'.$choicesNotice), 0, 16);
                                @endphp
                                <div wire:key="{{ $prefix }}-choice-{{ $group }}-{{ $choiceKey }}" class="min-w-0">
                                    <x-ui.forms.label :for="$prefix.'-'.$group.'-template'">Vorlage</x-ui.forms.label>
                                    <x-ui.forms.select :id="$prefix.'-'.$group.'-template'" :value="$selection" :change="$changeAction" :aria-label="'Vorlage für '.$title" aria-describedby="{{ $prefix }}-shared-hint{{ $missingChoice ? ' '.$prefix.'-'.$group.'-unavailable' : '' }}">
                                        <option value="none">Keine Vorlage</option>
                                        <option value="default">Standardvorlage verwenden</option>
                                        @foreach ($templateOptions as $template)
                                            <option value="{{ $template['id'] }}">{{ $template['name'] }}</option>
                                        @endforeach
                                        @if ($missingChoice)
                                            <option value="{{ $selection }}" disabled>Nicht mehr verfügbar · {{ $selection }}</option>
                                        @endif
                                    </x-ui.forms.select>
                                    @if ($missingChoice)
                                        <p id="{{ $prefix }}-{{ $group }}-unavailable" class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Die gespeicherte Vorlage ist nicht mehr freigegeben. Bitte eine verfügbare Vorlage oder „Keine Vorlage“ wählen.</p>
                                    @endif
                                </div>
                            @endif
                        </fieldset>
                    @endforeach
                </div>
            @endif
            <div role="status" aria-live="polite" class="space-y-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                @if ($notice !== '')
                    <p>{{ $notice }}</p>
                @endif
                @if ($choicesNotice !== '' && $choicesNotice !== $notice)
                    <p>{{ $choicesNotice }}</p>
                @endif
                @if (! $available && $wordingAvailable)
                    <p>Die Einschränkung betrifft nur die automatische Einfügung. Die Vorlagentexte können unabhängig davon gespeichert werden.</p>
                @endif
                <p wire:loading wire:target="updatePreference,updateTemplateChoice,reloadPreferences">Outlook-Einstellungen werden abgeglichen …</p>
            </div>
            @error('preferences')
                <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            @error('templateChoice')
                <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            <x-ui.buttons.button-basic type="button" size="sm" wire:click="reloadPreferences" wire:loading.attr="disabled" wire:target="updatePreference,updateTemplateChoice,reloadPreferences">Aktuellen Stand laden</x-ui.buttons.button-basic>
        </section>

        <section class="space-y-4 p-4 sm:p-6" aria-labelledby="{{ $prefix }}-wording-heading">
            <div>
                <h3 id="{{ $prefix }}-wording-heading" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Anrede und Grußformel</h3>
                <p id="{{ $prefix }}-wording-hint" class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Für „Standard – mit Anrede und Gruß“. {{ $employeeContext ? 'Der Name des Mitarbeiters' : 'Dein Name' }} wird automatisch unter der Grußformel ergänzt.</p>
            </div>
            @if ($wordingAvailable)
                <form wire:submit="saveWording" data-profile-outlook-wording data-outlook-wording-save="manual">
                    <fieldset class="min-w-0 space-y-4" wire:loading.attr="disabled" wire:target="saveWording,reloadWording">
                        <legend class="sr-only">Persönliche Vorlagentexte</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach (['greeting' => 'Anrede', 'farewell' => 'Grußformel'] as $field => $label)
                                <div class="min-w-0">
                                    <x-ui.forms.label :for="$prefix.'-'.$field">{{ $label }}</x-ui.forms.label>
                                    <x-ui.forms.input :id="$prefix.'-'.$field" type="text" wire:model="{{ $field }}" :value="$$field" :maxlength="$maxLength" autocomplete="off" :aria-invalid="$errors->has($field) ? 'true' : 'false'" aria-describedby="{{ $prefix }}-wording-help{{ $errors->has($field) ? ' '.$prefix.'-'.$field.'-error' : '' }}" />
                                    @error($field)
                                        <p id="{{ $prefix }}-{{ $field }}-error" role="alert" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                        <p id="{{ $prefix }}-wording-help" class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Einzeiliger Text, höchstens {{ $maxLength }} Zeichen je Feld. Gilt für diese Vorlage; die leere Standardvorlage, Signatur und Systemmails bleiben unverändert.</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.buttons.button-basic type="submit" mode="primary">Texte speichern</x-ui.buttons.button-basic>
                            <x-ui.buttons.button-basic type="button" wire:click="reloadWording">Gespeicherte Texte laden</x-ui.buttons.button-basic>
                            <span wire:dirty wire:target="greeting,farewell" class="text-xs text-rt-muted dark:text-rt-dark-muted">Ungespeicherte Änderungen</span>
                        </div>
                    </fieldset>
                </form>
            @endif
            <div role="status" aria-live="polite" class="space-y-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                @if ($wordingNotice !== '' && $wordingNotice !== $notice)
                    <p>{{ $wordingNotice }}</p>
                @endif
                <p wire:loading wire:target="saveWording,reloadWording">Persönliche Texte werden abgeglichen …</p>
            </div>
            @error('wording')
                <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </section>
    </div>
</section>
