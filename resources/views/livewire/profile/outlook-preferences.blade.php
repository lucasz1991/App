<section class="rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" aria-labelledby="profile-outlook-heading" data-testid="profile-outlook-preferences">
    <div class="border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
        <h2 id="profile-outlook-heading" class="text-base font-semibold text-rt-text dark:text-rt-dark-text">Outlook-Automatik</h2>
        <p id="profile-outlook-shared-hint" class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            Bestimme, ob RailTime deine Signatur und Vorlage automatisch einfügt. Die Einstellungen gelten für dein Konto auf allen Geräten, auch auf dem iPhone.
        </p>
        <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            Änderungen gelten beim nächsten Verfassen. Bereits geöffnete Nachrichten werden nicht geändert.
        </p>
    </div>

    <div class="space-y-5 p-4 sm:p-6">
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
            <p wire:loading wire:target="updatePreference,reloadPreferences">Outlook-Einstellungen werden abgeglichen …</p>
        </div>

        @error('preferences')
            <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        <x-ui.buttons.button-basic wire:click="reloadPreferences" wire:loading.attr="disabled" wire:target="updatePreference,reloadPreferences">
            Aktuellen Stand laden
        </x-ui.buttons.button-basic>
    </div>
</section>
