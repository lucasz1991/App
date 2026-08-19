<x-dialog-modal
    id="device-provider-readiness-modal"
    wire:model.live="showProviderReadiness"
    maxWidth="4xl"
>
    <x-slot:title>Provider- und Produktionsbereitschaft</x-slot:title>

    <x-slot:content>
        <div class="space-y-4" data-device-provider-readiness>
            <section @class([
                'flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between',
                'border-emerald-200 bg-emerald-50/80 dark:border-emerald-900 dark:bg-emerald-950/25' => $productionCommandsEnabled,
                'border-amber-200 bg-amber-50/80 dark:border-amber-900 dark:bg-amber-950/25' => ! $productionCommandsEnabled,
            ]) aria-labelledby="provider-production-status-title">
                <div class="flex min-w-0 items-start gap-3">
                    <span @class([
                        'grid h-10 w-10 shrink-0 place-items-center rounded-xl',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300' => $productionCommandsEnabled,
                        'bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-300' => ! $productionCommandsEnabled,
                    ])>
                        <i data-feather="{{ $productionCommandsEnabled ? 'check-circle' : 'lock' }}" class="h-5 w-5" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <h3 id="provider-production-status-title" class="text-sm font-bold text-rt-text dark:text-white">
                            {{ $productionCommandsEnabled ? 'Produktive Geräteaktionen sind freigegeben' : 'Produktive Geräteaktionen sind sicher gesperrt' }}
                        </h3>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            Ein aktivierter Connector allein führt noch keinen Befehl aus. Berechtigung, Providerfähigkeit und der globale Mutationsschalter werden vor jeder Aktion erneut geprüft.
                        </p>
                    </div>
                </div>
                <span @class([
                    'shrink-0 self-start rounded-lg px-2.5 py-1 text-[11px] font-semibold sm:self-center',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/70 dark:text-emerald-200' => $productionCommandsEnabled,
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/70 dark:text-amber-200' => ! $productionCommandsEnabled,
                ])>{{ $productionCommandsEnabled ? 'Freigegeben' : 'Not-Aus aktiv' }}</span>
            </section>

            <section aria-labelledby="provider-connectors-title">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h3 id="provider-connectors-title" class="text-sm font-bold text-rt-text dark:text-white">Connectoren und belegte Funktionen</h3>
                        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Der Status zeigt die aktuell in RailTime wirksame Konfiguration.</p>
                    </div>
                    <span class="text-xs font-medium tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $providerCards->where('enabled', true)->count() }} / {{ $providerCards->count() }} aktiv</span>
                </div>

                <div class="mt-3 grid gap-2.5 md:grid-cols-2">
                    @foreach($providerCards as $provider)
                        <article class="rounded-xl border border-rt-border/80 bg-rt-surface-muted/30 p-3.5 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/35">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $provider['label'] }}</p>
                                    <p class="mt-1 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ implode(' · ', $provider['capabilities']['platforms'] ?? []) ?: 'Keine Plattform belegt' }}</p>
                                </div>
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-white px-2 py-1 text-[11px] font-semibold text-rt-muted shadow-rt-xs dark:bg-rt-dark-surface dark:text-rt-dark-muted">
                                    <span class="h-2 w-2 rounded-full {{ $provider['enabled'] ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600' }}" aria-hidden="true"></span>
                                    {{ $provider['enabled'] ? 'Aktiv' : 'Inaktiv' }}
                                </span>
                            </div>

                            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div class="rounded-lg bg-white/80 px-2.5 py-2 dark:bg-rt-dark-surface/80">
                                    <dt class="text-rt-muted dark:text-rt-dark-muted">Kommandos</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-rt-text dark:text-white">{{ count($provider['capabilities']['commands'] ?? []) }} belegt</dd>
                                </div>
                                <div class="rounded-lg bg-white/80 px-2.5 py-2 dark:bg-rt-dark-surface/80">
                                    <dt class="text-rt-muted dark:text-rt-dark-muted">Fernsupport</dt>
                                    <dd class="mt-0.5 font-semibold text-rt-text dark:text-white">{{ ($provider['capabilities']['remote_support'] ?? false) ? 'Verfügbar' : 'Nicht belegt' }}</dd>
                                </div>
                            </dl>

                            <p class="mt-2 flex items-center gap-1.5 text-[11px] font-medium {{ $provider['commands_enabled'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-rt-muted dark:text-rt-dark-muted' }}">
                                <i data-feather="{{ $provider['commands_enabled'] ? 'check' : 'lock' }}" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                {{ $provider['commands_enabled'] ? 'Befehlsausführung freigegeben' : 'Befehlsausführung gesperrt' }}
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>
        </div>
    </x-slot:content>

    <x-slot:footer>
        <button
            type="button"
            wire:click="closeProviderReadiness"
            class="inline-flex min-h-11 items-center justify-center rounded-lg border border-rt-border bg-white px-4 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted"
        >Schließen</button>

        @if(auth()->user()?->isSuperAdmin())
            <a
                href="{{ route('admin.settings') }}"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-rt-red px-4 text-sm font-semibold text-white transition hover:bg-rt-red-dark active:scale-[0.98] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
            >
                <i data-feather="settings" class="h-4 w-4" aria-hidden="true"></i>
                Geräte-Setup öffnen
            </a>
        @endif
    </x-slot:footer>
</x-dialog-modal>
