@props(['id' => 'mail-outlook', 'connected' => null, 'current' => null, 'deployed' => null])
@php
    $configuration = app(\App\Support\OutlookAddin\OutlookAddinConfiguration::class);
    $deployed ??= $configuration->deployed();
    $connected ??= $configuration->availableTo(auth()->user());
    $current ??= $connected && app(\App\Support\OutlookAddin\OutlookAddinUserSnapshotStore::class)->isCurrentForUser(auth()->user());
    $statusLabel = $current ? 'Aktueller Stand' : ($connected ? 'Abgleich ausstehend' : 'Einrichtung prüfen');
    $glassButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border/70 bg-rt-surface/75 px-3.5 text-sm font-semibold text-rt-text shadow-sm backdrop-blur-xl transition hover:bg-rt-surface focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/75 dark:text-rt-dark-text';
@endphp
<div x-data="{ connectionOpen: false, statusOpen: false }" class="flex flex-wrap items-center justify-end gap-2" data-mail-outlook-access>
    <button type="button" class="{{ $glassButton }}" x-on:click="connectionOpen = true" aria-haspopup="dialog" aria-controls="{{ $id }}-connection" x-bind:aria-expanded="connectionOpen">
        <i class="fab fa-microsoft text-base" aria-hidden="true"></i>
        <span>{{ $connected ? 'Microsoft verbunden' : 'Microsoft verbinden' }}</span>
    </button>
    <button type="button" class="{{ $glassButton }}" x-on:click="statusOpen = true" aria-haspopup="dialog" aria-controls="{{ $id }}-status" x-bind:aria-expanded="statusOpen">
        <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => $current, 'bg-amber-500' => ! $current]) aria-hidden="true"></span>
        <span>{{ $statusLabel }}</span>
    </button>
    <x-ui.state-modal :id="$id.'-connection'" state="connectionOpen" title="Microsoft-Verbindung" icon="fab fa-microsoft" max-width="2xl">
        <div class="space-y-4 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
            <p>{{ $connected ? 'Ihr Microsoft-Firmenkonto ist mit RailTime verknüpft.' : ($deployed ? 'Für Ihr Konto muss die IT die Microsoft-Zuordnung und die Add-in-Zuweisung prüfen.' : 'Die Administration muss das RailTime-Add-in zuerst in Microsoft 365 bereitstellen.') }}</p>
            <ol class="list-decimal space-y-2 pl-5">
                <li>Outlook mit Ihrem Firmenkonto öffnen.</li>
                <li>Eine Nachricht verfassen und unter „Apps“ das RailTime-Add-in öffnen.</li>
                <li>Dort „Mit Microsoft verbinden“ wählen, falls eine Anmeldung erforderlich ist.</li>
            </ol>
            <p class="text-xs">Die Anmeldung erfolgt ausschließlich bei Microsoft. Diese Seite fragt kein Microsoft-Kennwort ab und verändert keine Postfachberechtigungen.</p>
            <a href="https://outlook.office.com/mail/" target="_blank" rel="noopener noreferrer" class="{{ $glassButton }}">Outlook öffnen <i class="far fa-external-link-alt" aria-hidden="true"></i></a>
        </div>
    </x-ui.state-modal>
    <x-ui.state-modal :id="$id.'-status'" state="statusOpen" title="Outlook-Status" icon="far fa-check-circle" max-width="2xl">
        <div class="space-y-4 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted"
            @if ($connected && $current) data-outlook-addin-managed @elseif ($connected) data-outlook-addin-pending @endif>
            <dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-3">
                <dt>Bereitstellung</dt><dd class="font-semibold">{{ $deployed ? 'Konfiguriert' : 'Ausstehend' }}</dd>
                <dt>Firmenkonto</dt><dd class="font-semibold">{{ $connected ? 'Verknüpft' : 'Nicht verknüpft' }}</dd>
                <dt>Persönlicher Stand</dt><dd class="font-semibold">{{ $current ? 'Aktuell' : 'Abgleich ausstehend' }}</dd>
            </dl>
            <p>{{ $current ? 'Die aktuelle Signatur und die bereitgestellten Vorlagen liegen für das Add-in vor.' : 'Bis zum erfolgreichen Abruf durch das Add-in bleibt die manuelle Einrichtung verfügbar.' }}</p>
            <p class="text-xs">Dieser Status bestätigt den RailTime-Datenstand, nicht die Installation auf jedem Gerät. Automatische Vorlagen benötigen einen unterstützten Outlook-Client. Apple Mail verwendet das Outlook-Add-in nicht.</p>
        </div>
    </x-ui.state-modal>
</div>
