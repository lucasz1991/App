<div data-testid="sound-preferences">
    <section class="relative overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-autosave-scope>
        <x-ui.autosave-status event="sound-preferences-saved" target="save" dirty-target="sounds" />
        <div class="flex items-start gap-3 border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                <i class="far fa-music text-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ __('app.sound_settings') }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.sound_preferences_hint') }}
                </p>
            </div>
        </div>

        <div class="p-4 sm:p-6">
            {{-- Persoenliche Zuordnung: leer = Systemstandard (wird je Zeile
                 mit dem wirksamen Standardton angezeigt). --}}
            <x-ui.forms.sound-picker model="sounds" :allow-default="true" :system-map="$systemMap" />
        </div>
    </section>
</div>
