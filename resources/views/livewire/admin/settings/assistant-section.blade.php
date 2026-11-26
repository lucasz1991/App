{{-- Einstellungen > System > Chatbot-Assistent. Wurzel ist die bisherige Abschnittskarte. --}}
<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5"
    data-autosave-scope
>
    <x-ui.autosave-status
        event="assistant-settings-saved"
        target="saveAssistant"
        dirty-target="assistantEnabled"
    />
    <div class="space-y-4 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
        <div class="flex flex-col gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:p-4">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    {{ __('app.assistant_enabled') }}
                </p>
                <p class="mt-0.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.assistant_enabled_hint') }}
                </p>
            </div>
            <div class="shrink-0 self-start sm:self-center">
                <x-ui.forms.toggle-button
                    id="assistant-enabled"
                    model="assistantEnabled"
                    data-autosave-instant="true"
                />
            </div>
        </div>

    </div>
</section>
