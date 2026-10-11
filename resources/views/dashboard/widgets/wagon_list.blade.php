{{--
    Wagenlisten-Entwuerfe leben ausschliesslich lokal im Browser
    (localStorage-Key "rt-wagon-list-prototype:v1", siehe
    resources/js/wagon-list-prototype.js) - es gibt dafuer keine
    Server-Tabelle. Dieses Widget liest denselben Schluessel deshalb
    direkt per Alpine, statt eine Zahl vorzutaeuschen, die der Server
    gar nicht kennen kann. Ein Wagen gilt als erfasst, sobald ein Teil
    der Wagennummer eingetragen ist.
--}}
<div class="widget-fill" x-data="{
        drafts: [],
        init() {
            try {
                const stored = JSON.parse(localStorage.getItem('rt-wagon-list-prototype:v1') || 'null');
                const drafts = Array.isArray(stored?.drafts) ? stored.drafts : [];
                this.drafts = drafts
                    .map((draft) => ({
                        id: draft.id,
                        train: draft.meta?.trainNumber || 'Ohne Zugnummer',
                        route: draft.meta?.origin && draft.meta?.destination ? draft.meta.origin + ' → ' + draft.meta.destination : '',
                        wagons: (draft.wagons || []).filter((w) => ['number12', 'number34', 'number58', 'number911'].some((k) => String(w?.[k] ?? '').trim() !== '')).length,
                        at: new Date(draft.persistedAt || draft.createdAt || 0),
                    }))
                    .sort((a, b) => b.at - a.at);
            } catch (e) { this.drafts = []; }
        },
        ago(date) {
            const minutes = Math.round((Date.now() - date.getTime()) / 60000);
            if (!Number.isFinite(minutes) || minutes < 1) return 'gerade eben';
            if (minutes < 60) return 'vor ' + minutes + ' Min.';
            if (minutes < 1440) return 'vor ' + Math.round(minutes / 60) + ' Std.';
            return date.toLocaleDateString('de-DE');
        },
    }"
>
    <div class="wv-inline">
        <span class="widget-primary-val" x-text="drafts.length">0</span>
        <span class="widget-primary-lbl" x-text="drafts.length === 1 ? 'Entwurf auf diesem Gerät' : 'Entwürfe auf diesem Gerät'">Entwürfe auf diesem Gerät</span>
        <span class="wv-pill" title="Entwürfe werden nur in diesem Browser gespeichert"><i data-feather="hard-drive"></i>lokal</span>
    </div>

    {{-- Zuletzt bearbeiteter Entwurf: der haeufigste Grund, die Kachel zu oeffnen. --}}
    <a class="wv-resume" href="{{ $data['href'] }}" wire:navigate x-show="drafts.length" x-cloak>
            <span class="wv-item-ico ops-tone-brand"><i data-feather="edit-3"></i></span>
            <span class="wv-item-main">
                <span class="wv-truncate" x-text="drafts.length ? drafts[0].train + (drafts[0].route ? ' · ' + drafts[0].route : '') : ''"></span>
                <small x-text="drafts.length ? drafts[0].wagons + ' Wagen erfasst · ' + ago(drafts[0].at) : ''"></small>
            </span>
            <span class="wv-resume-cta">Weiter</span>
        </a>

    @if($rows === 2)
        <div class="widget-detail wv-list" x-show="drafts.length > 1" x-cloak>
            <p class="wv-label" style="margin:0;padding-top:4px;">Weitere Entwürfe</p>
            <template x-for="draft in drafts.slice(1, 4)" :key="draft.id">
                <div class="wv-item">
                    <span class="wv-item-main">
                        <span class="wv-truncate" x-text="draft.train + (draft.route ? ' · ' + draft.route : '')"></span>
                        <small x-text="draft.wagons + ' Wagen · ' + ago(draft.at)"></small>
                    </span>
                </div>
            </template>
        </div>
    @endif

    <div class="wv-empty-cta" x-show="!drafts.length" x-cloak>
        <span>Noch keine Wagenliste auf diesem Gerät begonnen.</span>
        <a class="ops-link" href="{{ $data['href'] }}" wire:navigate>+ Neue Wagenliste</a>
    </div>

    <a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Wagenliste öffnen →</a>
</div>
