<section class="rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" aria-labelledby="profile-outlook-heading" data-testid="profile-outlook-preferences">
    <div class="border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
        <h2 id="profile-outlook-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Outlook-Einstellungen</h2>
        <p id="profile-outlook-shared-hint" class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            Bestimme die automatische Einfügung deiner Signatur und Vorlage sowie deine persönliche Anrede und Grußformel. Die Einstellungen gelten für dein Konto auf allen Geräten, auch auf dem iPhone.
        </p>
        <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            Änderungen gelten beim nächsten Verfassen. Bereits geöffnete Nachrichten werden nicht geändert.
        </p>
    </div>

    <div class="space-y-5 p-4 sm:p-6">
        <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Automatische Einfügung</h3>
        @if ($available)
            @foreach (['newMessages' => 'Neue Nachrichten und Weiterleitungen', 'replies' => 'Antworten'] as $group => $title)
                <fieldset class="space-y-3" wire:key="profile-outlook-{{ $group }}">
                    <legend class="mb-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $title }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['signature' => 'Signatur automatisch einfügen', 'template' => 'Vorlage automatisch einfügen'] as $field => $label)
                            <div class="flex min-h-11 items-center">
                                <x-ui.forms.checkbox
                                    :id="'profile-outlook-'.$group.'-'.$field"
                                    :label="$label"
                                    :toggle="true"
                                    :checked="$preferences[$group][$field]"
                                    wire:change="updatePreference('{{ $group }}', '{{ $field }}', $event.target.checked)"
                                    wire:loading.attr="disabled"
                                    wire:target="updatePreference,reloadPreferences"
                                    aria-describedby="profile-outlook-shared-hint"
                                />
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        @endif

        <div role="status" aria-live="polite" class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            @if ($notice !== '')
                <p>{{ $notice }}</p>
            @endif
            @if (! $available && $wordingAvailable)
                <p>Diese Einschränkung betrifft nur die automatische Einfügung. Deine persönlichen Vorlagentexte kannst du unten speichern.</p>
            @endif
            <p wire:loading wire:target="updatePreference,reloadPreferences">Outlook-Einstellungen werden abgeglichen …</p>
        </div>

        @error('preferences')
            <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        <x-ui.buttons.button-basic wire:click="reloadPreferences" wire:loading.attr="disabled" wire:target="updatePreference,reloadPreferences">
            Aktuellen Stand laden
        </x-ui.buttons.button-basic>

        <div class="space-y-4 border-t border-rt-border/60 pt-5 dark:border-rt-dark-border/60" aria-labelledby="profile-outlook-wording-heading">
            <div>
                <h3 id="profile-outlook-wording-heading" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Persönliche Anrede und Grußformel</h3>
                <p id="profile-outlook-wording-hint" class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    Nur für „Standard – mit Anrede und Gruß“. Dein Name wird automatisch in der nächsten Zeile unter der Grußformel ergänzt. Die leere Standardvorlage, deine Signatur und Systemmails bleiben unverändert.
                </p>
            </div>

            @if ($wordingAvailable)
                <form wire:submit="saveWording" class="space-y-4" data-profile-outlook-wording>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-ui.forms.label for="profile-outlook-greeting">Anrede</x-ui.forms.label>
                            <x-ui.forms.input id="profile-outlook-greeting" type="text" wire:model="greeting" :maxlength="\App\Support\OutlookAddin\OutlookAddinWordingPolicy::MAX_LENGTH" autocomplete="off" aria-describedby="profile-outlook-wording-hint" />
                            @error('greeting')
                                <p role="alert" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <x-ui.forms.label for="profile-outlook-farewell">Grußformel</x-ui.forms.label>
                            <x-ui.forms.input id="profile-outlook-farewell" type="text" wire:model="farewell" :maxlength="\App\Support\OutlookAddin\OutlookAddinWordingPolicy::MAX_LENGTH" autocomplete="off" aria-describedby="profile-outlook-wording-hint" />
                            @error('farewell')
                                <p role="alert" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled" wire:target="saveWording,reloadWording">Texte speichern</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic type="button" wire:click="reloadWording" wire:loading.attr="disabled" wire:target="saveWording,reloadWording">Gespeicherte Texte laden</x-ui.buttons.button-basic>
                    </div>
                </form>
            @endif

            <div role="status" aria-live="polite" class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                @if ($wordingNotice !== '' && $wordingNotice !== $notice)
                    <p>{{ $wordingNotice }}</p>
                @endif
                <p wire:loading wire:target="saveWording,reloadWording">Persönliche Texte werden abgeglichen …</p>
            </div>
            @error('wording')
                <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>
</section>
