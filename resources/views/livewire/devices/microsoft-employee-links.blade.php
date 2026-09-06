<div>
    <button type="button" wire:click="openModal" class="rt-ui-button rt-ui-button-secondary inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold" aria-haspopup="dialog">
        <i class="fab fa-microsoft" aria-hidden="true"></i>
        Microsoft-Konten
    </button>

    <x-dialog-modal id="microsoft-employee-links-modal" wire:model.live="showModal" maxWidth="3xl">
        <x-slot name="title">Microsoft-Konten zuordnen</x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                @unless($schemaReady)
                    <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Microsoft-Gerätemigration zuerst ausführen. Danach können Mitarbeiterkonten zugeordnet werden.</p>
                @endunless
                <x-input-error for="schema" />
                <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    Verbinde ein bestehendes Microsoft-Firmenkonto mit dem zugehörigen RailTime-Mitarbeiter. So kann der Geräteabgleich den Mitarbeiter anhand seiner eindeutigen Microsoft-Identität erkennen.
                </p>
                <div class="rounded-xl border border-rt-border bg-rt-surface-muted p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
                    <div class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">Konfigurierter Microsoft-Mandant</div>
                    <div class="mt-1 break-all font-mono text-sm">{{ $tenantId !== '' ? $tenantId : 'Noch nicht eingerichtet' }}</div>
                    <x-input-error for="tenant_id" class="mt-2" />
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        <a href="https://entra.microsoft.com/" target="_blank" rel="noopener noreferrer" class="font-semibold text-rt-red hover:underline">Microsoft Entra öffnen ↗</a>
                        <a href="https://admin.microsoft.com/" target="_blank" rel="noopener noreferrer" class="font-semibold text-rt-red hover:underline">Microsoft 365 Admin öffnen ↗</a>
                    </div>
                </div>

                @if($successMessage !== '')
                    <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">{{ $successMessage }}</p>
                @endif

                <form id="microsoft-employee-link-form" wire:submit="save" class="space-y-4">
                    <div>
                        <x-ui.forms.label for="microsoft-link-employee" value="RailTime-Mitarbeiter" />
                        <select id="microsoft-link-employee" wire:model="employee_id" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                            <option value="">Aktiven Mitarbeiter auswählen</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="employee_id" class="mt-1.5" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-ui.forms.label for="microsoft-link-principal" value="Microsoft-Anmeldename (UPN)" />
                            <x-ui.forms.input id="microsoft-link-principal" type="email" wire:model="principal" autocomplete="off" spellcheck="false" placeholder="vorname.nachname@firma.de" class="mt-1.5" />
                            <x-input-error for="principal" class="mt-1.5" />
                        </div>
                        <div>
                            <x-ui.forms.label for="microsoft-link-object-id" value="Microsoft-Benutzer-Objekt-ID" />
                            <x-ui.forms.input id="microsoft-link-object-id" type="text" wire:model="object_id" autocomplete="off" spellcheck="false" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" class="mt-1.5 font-mono" />
                            <x-input-error for="object_id" class="mt-1.5" />
                        </div>
                    </div>
                    <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">In Microsoft Entra: Identität → Benutzer → gewünschter Benutzer → Übersicht. Übernimm den Benutzerprinzipalnamen und die Objekt-ID aus diesem Mandanten. Hier werden keine Kennwörter benötigt. Die Zuordnung bestätigt weder eine Lizenz noch die Einrichtung eines Geräts.</p>
                </form>

                <section aria-labelledby="microsoft-linked-accounts-title" class="border-t border-rt-border pt-5 dark:border-rt-dark-border">
                    <h3 id="microsoft-linked-accounts-title" class="font-semibold">Zugeordnete Konten <span class="font-normal text-rt-muted dark:text-rt-dark-muted">({{ $accounts->count() }})</span></h3>
                    <ul class="mt-3 divide-y divide-rt-border dark:divide-rt-dark-border">
                        @forelse($accounts as $account)
                            <li wire:key="microsoft-employee-account-{{ $account->id }}" class="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $account->user?->name ?? 'Mitarbeiter nicht verfügbar' }}</div>
                                    <div class="break-all text-sm text-rt-muted dark:text-rt-dark-muted">{{ $account->principal }}</div>
                                    @if($account->external_id)
                                        <div class="mt-1 break-all font-mono text-xs text-rt-muted dark:text-rt-dark-muted">{{ $account->external_id }}</div>
                                    @endif
                                </div>
                                <span class="shrink-0 text-xs text-rt-muted dark:text-rt-dark-muted">
                                    @if($account->lifecycle_status !== 'active' || ! $account->user?->status)
                                        Inaktiv
                                    @elseif($account->external_id && $account->tenant_id === $tenantId)
                                        Verknüpft
                                    @else
                                        Objekt-ID / Mandant offen
                                    @endif
                                </span>
                            </li>
                        @empty
                            <li class="py-3 text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Microsoft-Konten für diesen Mandanten zugeordnet.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </x-slot>

        <x-slot name="footer">
            <button type="button" wire:click="closeModal" class="rt-ui-button rt-ui-button-secondary min-h-11 rounded-xl px-4 text-sm font-semibold">Schließen</button>
            <button type="submit" form="microsoft-employee-link-form" wire:loading.attr="disabled" wire:target="save" @disabled(! $schemaReady) class="rt-ui-button rt-ui-button-primary min-h-11 rounded-xl px-4 text-sm font-semibold disabled:opacity-40">
                <span wire:loading.remove wire:target="save">Konto zuordnen</span>
                <span wire:loading wire:target="save">Wird gespeichert …</span>
            </button>
        </x-slot>
    </x-dialog-modal>
</div>
