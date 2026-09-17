{{--
    Wagenlisten-Entwuerfe leben ausschliesslich lokal im Browser
    (localStorage-Key "rt-wagon-list-prototype:v1", siehe
    resources/js/wagon-list-prototype.js) - es gibt dafuer keine
    Server-Tabelle. Dieses Widget liest denselben Schluessel deshalb
    direkt per Alpine, statt eine Zahl vorzutaeuschen, die der Server
    gar nicht kennen kann.
--}}
<div class="widget-fill" x-data="{
        drafts: [],
        init() {
            try {
                const stored = JSON.parse(localStorage.getItem('rt-wagon-list-prototype:v1') || 'null');
                this.drafts = Array.isArray(stored?.drafts) ? stored.drafts : [];
            } catch (e) { this.drafts = []; }
        },
    }"
>
    <span class="widget-primary-val" x-text="drafts.length"></span>
    <span class="widget-primary-lbl">lokale Entwürfe auf diesem Gerät</span>
    @if($rows === 2)
        <div class="widget-detail" x-show="drafts.length" x-cloak>
            <template x-for="draft in drafts.slice(0, 3)" :key="draft.id">
                <div class="ops-row">
                    <span x-text="(draft.meta?.trainNumber || 'Ohne Zugnummer') + (draft.meta?.origin && draft.meta?.destination ? ' · ' + draft.meta.origin + ' → ' + draft.meta.destination : '')"></span>
                    <span class="ops-muted" x-text="new Date(draft.persistedAt || draft.createdAt).toLocaleDateString('de-DE')"></span>
                </div>
            </template>
        </div>
        <div class="ops-empty widget-detail" x-show="!drafts.length" x-cloak>Noch keine Wagenliste begonnen.</div>
    @endif
    <a class="widget-footer" href="{{ $data['href'] }}" wire:navigate>Wagenliste öffnen →</a>
</div>
