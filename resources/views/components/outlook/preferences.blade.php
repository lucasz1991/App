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

<section class="min-w-0 overflow-hidden rounded-xl bg-rt-surface ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" aria-labelledby="{{ $prefix }}-heading" data-testid="profile-outlook-preferences" data-outlook-preferences-context="{{ $employeeContext ? 'employee' : 'self' }}">
    <header class="space-y-2 border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="{{ $prefix }}-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Outlook-Einstellungen</h2>
            @if ($employeeContext)
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Konto von {{ $targetName }}</p>
            @endif
        </div>
        <p id="{{ $prefix }}-shared-hint" class="max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            {{ $employeeContext ? 'Gilt für dieses Mitarbeiterkonto' : 'Gilt für dein Konto' }} auf allen Geräten, auch auf dem iPhone. Änderungen greifen beim nächsten Verfassen. Geöffnete Nachrichten bleiben erhalten.
        </p>
    </header>

    <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
        <section class="space-y-4 p-4 sm:p-6" aria-labelledby="{{ $prefix }}-automatic-heading">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <h3 id="{{ $prefix }}-automatic-heading" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Automatische Einfügung</h3>
                @if ($available || $choicesAvailable)
                    <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Änderungen werden sofort gespeichert</p>
                @endif
            </div>
            @if ($available || $choicesAvailable)
                <div class="grid gap-6 sm:grid-cols-2">
                    @foreach (['newMessages' => 'Neue Nachrichten', 'replies' => 'Antworten'] as $group => $title)
                        <fieldset class="min-w-0 space-y-3" wire:key="{{ $prefix }}-{{ $group }}" wire:loading.attr="disabled" wire:target="updatePreference,updateTemplateChoice,reloadPreferences">
                            <legend class="mb-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $title }}</legend>
                            @if ($available)
                                <div class="flex min-h-11 items-center justify-between gap-3">
                                    <label for="{{ $prefix }}-{{ $group }}-signature" class="text-sm text-rt-text dark:text-rt-dark-text">Signatur einfügen</label>
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
                                    $hasDefault = count(array_filter($templateOptions, static fn (array $option): bool => ($option['isDefault'] ?? false) === true)) === 1;
                                    $missingChoice = $selection === 'default' ? ! $hasDefault : (! in_array($selection, ['none'], true) && ! in_array($selection, array_column($templateOptions, 'id'), true));
                                    $changeAction = '$wire.updateTemplateChoice(\''.$group.'\', selected)';
                                    $choiceKey = substr(hash('sha256', $choicesRevision.'|'.$selection.'|'.$choicesNotice.'|'.$errors->first('templateChoices')), 0, 16);
                                @endphp
                                <div wire:key="{{ $prefix }}-choice-{{ $group }}-{{ $choiceKey }}" class="min-w-0">
                                    <x-ui.forms.label :for="$prefix.'-'.$group.'-template'">Vorlage</x-ui.forms.label>
                                    <x-ui.forms.select :id="$prefix.'-'.$group.'-template'" :change="$changeAction" :aria-label="'Vorlage für '.$title" aria-describedby="{{ $prefix }}-shared-hint{{ $missingChoice ? ' '.$prefix.'-'.$group.'-unavailable' : '' }}">
                                        <option value="none" @selected($selection === 'none')>Keine Vorlage</option>
                                        <option value="default" @selected($selection === 'default') @disabled(! $hasDefault)>{{ $hasDefault ? 'Standardvorlage verwenden' : 'Standardvorlage nicht verfügbar' }}</option>
                                        @foreach ($templateOptions as $template)
                                            <option value="{{ $template['id'] }}" @selected($selection === $template['id'])>{{ $template['name'] }}</option>
                                        @endforeach
                                        @if ($missingChoice && ! in_array($selection, ['default', 'none'], true))
                                            <option value="{{ $selection }}" selected disabled>Gespeicherte Vorlage nicht mehr verfügbar</option>
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
                <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Weiterleitungen verwenden die Einstellungen für neue Nachrichten.</p>
            @endif
            <div role="status" aria-live="polite" class="space-y-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
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
            @error('templateChoices')
                <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            <x-ui.buttons.button-basic type="button" mode="link" size="sm" class="min-h-11" wire:click="reloadPreferences" wire:loading.attr="disabled" wire:target="updatePreference,updateTemplateChoice,reloadPreferences">Aktuellen Stand laden</x-ui.buttons.button-basic>
        </section>

        <section class="space-y-4 p-4 sm:p-6" aria-labelledby="{{ $prefix }}-wording-heading">
            <div>
                <h3 id="{{ $prefix }}-wording-heading" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Anrede und Grußformel</h3>
                <p id="{{ $prefix }}-wording-hint" class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Für „Standard – mit Anrede und Gruß“. {{ $employeeContext ? 'Der Name des Mitarbeiters' : 'Dein Name' }} wird automatisch unter der Grußformel ergänzt.</p>
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
                        <p id="{{ $prefix }}-wording-help" class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Einzeiliger Text, höchstens {{ $maxLength }} Zeichen je Feld. Die leere Standardvorlage, Signatur und Systemmails bleiben unverändert.</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.buttons.button-basic type="submit" mode="primary" class="min-h-11">Texte speichern</x-ui.buttons.button-basic>
                            <x-ui.buttons.button-basic type="button" mode="link" class="min-h-11" wire:click="reloadWording">Gespeicherte Texte laden</x-ui.buttons.button-basic>
                            <span wire:dirty wire:target="greeting,farewell" class="text-xs text-rt-muted dark:text-rt-dark-muted">Ungespeicherte Änderungen</span>
                        </div>
                        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Diese Texte werden erst mit „Texte speichern“ übernommen.</p>
                    </fieldset>
                </form>
            @endif
            <div role="status" aria-live="polite" class="space-y-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
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
