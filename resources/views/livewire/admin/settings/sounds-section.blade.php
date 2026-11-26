{{-- Einstellungen > System > Töne. Wurzel ist die bisherige Abschnittskarte. --}}
<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
    aria-labelledby="admin-settings-sounds-trigger"
    data-sound-settings
    data-autosave-scope
>
    <x-ui.autosave-status event="sound-settings-saved" target="saveSounds" dirty-target="sounds" />
    <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
        <x-ui.forms.sound-picker model="sounds" />
    </div>
</section>
