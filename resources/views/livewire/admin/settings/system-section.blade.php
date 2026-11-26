{{-- Einstellungen > System > Wartungsmodus. Die Wurzel wiederholt die Abstände des
     Akkordeon-Inhalts (space-y-3 sm:space-y-4), damit Banner und Karte wie bisher stehen. --}}
<div class="space-y-3 sm:space-y-4">
    {{-- Wartungsmodus-Warnbanner --}}
    @if ($maintenanceMode)
        <div
            class="flex items-start gap-3 rounded-xl border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-800 shadow-rt-sm ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25"
        >
            <i class="fad fa-exclamation-triangle mt-0.5 fa-lg" aria-hidden="true"></i>
            <span>{{ __('app.maintenance_mode_active_banner') }}</span>
        </div>
    @endif

    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-autosave-scope
    >
        <x-ui.autosave-status
            event="system-settings-saved"
            target="saveSystem"
            dirty-target="maintenanceMode"
        />
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <div class="flex flex-col gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:p-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                        {{ __('app.maintenance_mode') }}
                    </p>
                    <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.maintenance_mode_hint') }}
                    </p>
                </div>
                <div class="shrink-0 self-start sm:self-center">
                    <x-ui.forms.toggle-button model="maintenanceMode" data-autosave-instant="true" />
                </div>
            </div>
        </div>
    </section>
</div>
