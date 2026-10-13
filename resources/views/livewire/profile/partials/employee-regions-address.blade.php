<div class="rt-regions__base-field rt-regions__address" x-data="{
    query: '', results: [], active: -1, loading: false, selecting: false, open: false,
    message: '', timer: null, sequence: 0,
    init() {
        this.query = this.$wire.preferences.base_location || '';
        this.$watch('$wire.originVersion', () => {
            this.cancel(); this.query = this.$wire.preferences.base_location || '';
        });
    },
    destroy() { clearTimeout(this.timer); this.sequence++; },
    cancel() { clearTimeout(this.timer); this.sequence++; this.results = []; this.active = -1; this.open = false; this.loading = false; this.message = ''; },
    input() {
        this.cancel();
        this.$wire.set('preferences.base_location', this.query, false);
        if (this.query.trim().length < 3) return;
        const query = this.query.trim(), sequence = this.sequence;
        this.timer = setTimeout(() => this.search(query, sequence), 450);
    },
    async search(query, sequence) {
        this.loading = true;
        try {
            const result = await this.$wire.searchAddresses(query);
            if (sequence !== this.sequence || query !== this.query.trim()) return;
            this.results = result.results || []; this.active = -1; this.open = true;
            this.message = result.status === 'known' ? (this.results.length ? '' : 'Keine Adresse gefunden. Suche bitte genauer.') : ({
                disabled: 'Adresssuche ist deaktiviert.', missing_key: 'Adresssuche ist noch nicht eingerichtet.',
                budget_exhausted: 'TomTom-Verbrauchsgrenze erreicht.', rate_limited: 'Bitte einen Moment warten und erneut suchen.'
            }[result.reason] || 'Adresssuche derzeit nicht verfügbar. Bitte erneut versuchen.');
        } catch (error) {
            if (sequence === this.sequence) { this.message = 'Adresssuche derzeit nicht verfügbar.'; this.open = true; }
        } finally { if (sequence === this.sequence) this.loading = false; }
    },
    move(step) {
        if (!this.results.length) return;
        this.open = true;
        this.active = (this.active + step + this.results.length) % this.results.length;
        this.$nextTick(() => this.$refs.suggestions.querySelector('[data-index=\'' + this.active + '\']')?.scrollIntoView({ block: 'nearest' }));
    },
    async choose(index) {
        if (this.selecting || !this.results[index]) return;
        const token = this.results[index].token;
        this.cancel(); this.selecting = true;
        try { await this.$wire.selectAddress(token); }
        finally { this.selecting = false; }
    },
    precisionLabel(value) { return { address: 'Hausadresse', interpolated: 'Hausnummer · angenähert', street: 'Straße · ungefähr', locality: 'Ort · ungefähr' }[value] || 'Ungefähre Lage'; }
}" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false; active = -1">
    <label for="region-origin-{{ $userId }}">Standort
        @if($originPoint)<span class="rt-regions__address-quality">{{ ['address' => 'Hausadresse', 'interpolated' => 'Angenähert', 'street' => 'Straße', 'locality' => 'Ort'][$originPoint['precision']] ?? '' }}</span>@endif
    </label>
    <div class="rt-regions__origin-field">
        <i class="far fa-map-marker-alt" aria-hidden="true"></i>
        <input id="region-origin-{{ $userId }}" type="text" x-model="query" x-on:input="input()"
            x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter.prevent.stop="if (open && active >= 0) choose(active)"
            x-on:focus="if (results.length) open = true" x-bind:disabled="selecting"
            placeholder="Adresse oder Ort suchen" maxlength="200" autocomplete="off" spellcheck="false"
            role="combobox" aria-autocomplete="list" aria-haspopup="listbox" x-bind:aria-expanded="open"
            aria-controls="region-address-list-{{ $userId }}" x-bind:aria-activedescendant="open && active >= 0 ? 'region-address-{{ $userId }}-' + active : null"
            aria-describedby="region-distance-note-{{ $userId }}" x-bind:aria-busy="loading || selecting">
        <i class="far fa-spinner fa-spin rt-regions__search-loading" x-cloak x-show="loading || selecting" aria-hidden="true"></i>
    </div>
    <div class="rt-regions__address-results" x-cloak x-show="open" x-ref="suggestions">
        <ul id="region-address-list-{{ $userId }}" role="listbox" aria-label="Adressvorschläge">
            <template x-for="(result, index) in results" :key="result.token">
                <li role="option" x-bind:id="'region-address-{{ $userId }}-' + index" x-bind:aria-selected="active === index" x-bind:data-index="index" x-on:mousemove="active = index" x-on:mousedown.prevent x-on:click="choose(index)">
                    <span x-text="result.label"></span><small x-text="precisionLabel(result.precision)"></small>
                </li>
            </template>
        </ul>
        <p x-show="message" x-text="message" role="status"></p>
        <small class="rt-regions__address-provider" x-show="results.length">TomTom</small>
    </div>
</div>
