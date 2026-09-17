@section('title', 'Dashboard')
<x-ui.page :auto-intro="false" :welcome-intro="false" content-class="rt-ops ops-stack" data-native-dashboard>
    @if($hasOperations)<livewire:operations.cockpit />@endif
    @if($hasPersonalWork)
        @if($hasOperations)<details class="ops-panel"><summary>Mein Arbeitstag</summary><livewire:operations.my-work /></details>
        @else<header class="ops-toolbar"><div><p class="ops-kicker">{{ now(config('operations.display_timezone'))->translatedFormat('l, d. F') }}</p><h1>Mein Arbeitstag</h1></div><span class="ops-muted">{{ auth()->user()->name }}</span></header><livewire:operations.my-work />@endif
    @endif
    {{--
        Vormals ein Panel "Nachrichten", das zugleich das Geraete-Widget und
        den Wagenliste-Link mittrug - drei unabhaengige Ziele ohne optische
        Trennung. Jetzt drei eigene Schnellzugriffs-Kacheln neben der Ablage.
    --}}
    <section class="ops-panel" data-anim="fade-up"><header class="ops-toolbar"><h2>Meine Ablage</h2><a class="ops-link" href="{{ route('files') }}" wire:navigate>{{ $filesTotal }} Dateien →</a></header>@forelse($recentFiles as $file)<div class="ops-row"><span>{{ $file->name ?? $file->title ?? 'Datei' }}</span><a class="ops-link" href="{{ route('files') }}" wire:navigate>Öffnen</a></div>@empty<div class="ops-empty">Keine Dateien.</div>@endforelse</section>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" data-anim-stagger>
        <a class="ops-tile" href="{{ route('messages') }}" wire:navigate><span class="ops-tile-ico ops-tone-{{ $unreadMessages > 0 ? 'brand' : 'ok' }}"><i data-feather="message-circle"></i></span><span><span class="ops-tile-title">Nachrichten</span><span class="ops-tile-sub">{{ $unreadMessages }} ungelesen</span></span></a>
        @if($deviceWidget['href'])<a class="ops-tile" href="{{ $deviceWidget['href'] }}" wire:navigate><span class="ops-tile-ico ops-tone-neutral"><i data-feather="{{ $deviceWidget['scope'] === 'fleet' ? 'monitor' : 'smartphone' }}"></i></span><span><span class="ops-tile-title">{{ $deviceWidget['scope'] === 'fleet' ? 'Geräte & Lager' : 'Meine Geräte' }}</span><span class="ops-tile-sub">{{ $deviceWidget['stats']['total'] }} Geräte</span></span></a>
        @else<div class="ops-tile"><span class="ops-tile-ico ops-tone-neutral"><i data-feather="{{ $deviceWidget['scope'] === 'fleet' ? 'monitor' : 'smartphone' }}"></i></span><span><span class="ops-tile-title">{{ $deviceWidget['scope'] === 'fleet' ? 'Geräte & Lager' : 'Meine Geräte' }}</span><span class="ops-tile-sub">Nicht verfügbar</span></span></div>
        @endif
        <a class="ops-tile" href="{{ route(auth()->user()->isAdmin() ? 'admin.operations.wagon-list' : 'operations.wagon-list') }}" wire:navigate><span class="ops-tile-ico ops-tone-neutral"><i data-feather="list"></i></span><span><span class="ops-tile-title">Wagenliste</span><span class="ops-tile-sub">Öffnen</span></span></a>
    </div>
    @if($canViewSystemData)<details class="ops-panel"><summary wire:click="loadSystemData">System</summary>@if($systemLoaded && $system)<dl class="ops-meta">@foreach(['appVersion'=>'Anwendung','environment'=>'Umgebung','php'=>'PHP','database'=>'Datenbank','queue'=>'Queue','lastActivity'=>'Letzte Aktivität','storage'=>'Dateispeicher','disk'=>'Datenträger'] as $key=>$label)<div><dt>{{ $label }}</dt><dd>{{ $system[$key] ?? '—' }}</dd></div>@endforeach</dl>@endif</details>@endif
</x-ui.page>
