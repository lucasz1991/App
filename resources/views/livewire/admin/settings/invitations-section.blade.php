{{-- Einstellungen > Benutzer > Einladungen. Wurzel ist die bisherige Abschnittskarte. --}}
<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
    data-anim="fade-up"
    data-autosave-scope
>
    <x-ui.autosave-status
        event="invitation-settings-saved"
        target="saveInvitations"
        dirty-target="invitationExpiryDays"
    />
    <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
    <div class="w-full sm:max-w-sm">
        <label for="invitation_expiry_days" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
            {{ __('app.invitation_expiry_days') }}
        </label>
        <div class="mt-1.5">
            {{-- nullable=false: die Komponente ist an ein typisiertes
                 int-Property gebunden, ein leeres Feld wuerde beim
                 Speichern einen Typfehler ausloesen. --}}
            <x-ui.forms.number-input
                id="invitation_expiry_days"
                min="1"
                max="365"
                :nullable="false"
                :unit="__('app.days')"
                wire:model="invitationExpiryDays"
            />
        </div>
        @error('invitationExpiryDays')
            <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
        @enderror
        <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">
            {{ __('app.invitation_expiry_hint') }}
        </p>
    </div>
    </div>
</section>
