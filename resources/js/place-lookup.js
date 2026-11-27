import { createLiveLocationMap } from './live-location-map.js';

const inflight = new Map();
let queue = Promise.resolve();
const quality = { address: 'Hausadresse', interpolated: 'Hausnummer ungefähr', street: 'Straße', locality: 'Ortslage', poi: 'Anlage', gps: 'Gerätestandort' };
const countries = { deutschland: 'DE', germany: 'DE', österreich: 'AT', austria: 'AT', schweiz: 'CH', switzerland: 'CH', niederlande: 'NL', netherlands: 'NL', polen: 'PL', poland: 'PL', tschechien: 'CZ', czechia: 'CZ' };

async function post(url, data, signal) {
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify(data) });
    if (!response.ok) {
        const failure = new Error(String(response.status));
        failure.retryAfter = Number(response.headers.get('Retry-After')) || (response.status === 409 ? 2 : 60);
        throw failure;
    }
    return response.json();
}

function resolveOnce(url, item) {
    if (!inflight.has(item.signature)) {
        const pending = queue.then(() => post(url, item));
        queue = pending.catch(() => {});
        inflight.set(item.signature, pending);
        pending.finally(() => inflight.delete(item.signature)).catch(() => {});
    }
    return inflight.get(item.signature);
}

export function registerPlaceLookup(Alpine) {
    Alpine.data('rtWorksites', (url, lookups) => ({
        dead: false, busy: false, error: '', retry: null, destroy() { this.dead = true; clearTimeout(this.retry); },
        async init() { await this.load(); },
        async load() {
            if (this.busy || !lookups.length) return;
            this.busy = true; this.error = '';
            let changed = false;
            for (const lookup of lookups) {
                if (this.dead) return;
                try { await resolveOnce(url, lookup); changed = true; }
                catch (error) {
                    this.error = 'Standortsuche unterbrochen.';
                    if (!this.dead && ['409', '429'].includes(error.message)) {
                        clearTimeout(this.retry);
                        this.retry = setTimeout(() => { if (!this.dead) this.load(); }, Math.max(1, error.retryAfter) * 1000);
                    }
                    break;
                }
            }
            this.busy = false;
            if (changed && !this.dead && this.$el.isConnected) await this.$wire.$refresh();
        },
    }));
    Alpine.data('rtWorksiteChoice', (url, selection) => ({
        busy: false, error: '',
        async choose(latitude, longitude) {
            if (this.busy) return;
            this.busy = true;
            try { await post(url, { ...selection, latitude, longitude }); await this.$wire.$refresh(); }
            catch { this.error = 'Auswahl abgelaufen. Bitte die Ansicht aktualisieren.'; }
            finally { this.busy = false; }
        },
    }));
    Alpine.data('rtWorksitePin', () => {
        let map = null, ticket = 0;
        return {
            open: false, message: '',
            async toggle() {
                this.open = !this.open;
                const version = ++ticket;
                if (!this.open) { map?.destroy(); map = null; return; }
                await this.$nextTick();
                const f = this.$wire.form;
                const hasPoint = f.latitude != null && f.latitude !== '' && f.longitude != null && f.longitude !== '';
                try {
                    const instance = await createLiveLocationMap({ element: this.$refs.canvas, interactive: true, showInitialMarker: hasPoint,
                        latitude: hasPoint ? Number(f.latitude) : 51, longitude: hasPoint ? Number(f.longitude) : 10,
                        zoom: hasPoint ? 16 : 6 });
                    if (version !== ticket) { instance.destroy(); return; }
                    map = instance;
                    map.map.on('click', ({latlng}) => {
                        const latitude = Number(latlng.lat.toFixed(7)), longitude = Number(latlng.lng.toFixed(7));
                        this.$wire.$set('form.latitude', latitude, false);
                        this.$wire.$set('form.longitude', longitude, false);
                        this.$wire.$set('form.coordinates_confirmed', false, false);
                        map.update({latitude, longitude});
                        this.message = 'Punkt gesetzt. Bitte den Übergabepunkt prüfen.';
                    });
                    if (!hasPoint) this.message = 'Ort auf der Karte anklicken, um den Übergabepunkt zu setzen.';
                } catch { this.message = 'Karte derzeit nicht verfügbar. Koordinaten können direkt eingetragen werden.'; }
            },
            destroy() { ticket++; map?.destroy(); },
        };
    });
    Alpine.data('rtCoordinateAddress', url => ({
        label: '', message: '', busy: false, key: '', version: 0,
        observe(active, lat, lon) {
            const key = active ? [lat, lon].join('|') : '';
            if (key !== this.key) {
                this.version++; this.key = key; this.label = ''; this.message = ''; this.busy = false;
            }
        },
        async locate(latitude, longitude) {
            const version = ++this.version;
            this.busy = true;
            try {
                const result = await post(url, { latitude, longitude });
                if (this.version === version) {
                    this.label = result.results[0]?.label || '';
                    this.message = this.label ? '' : 'Keine Adresse ermittelt.';
                }
            } catch { if (this.version === version) this.message = 'Adresse derzeit nicht verfügbar.'; }
            finally { if (this.version === version) this.busy = false; }
        },
    }));
    Alpine.data('rtPlaceField', config => ({
        rows: [], message: '', active: -1, timer: null, request: null, version: 0,
        portal: false,
        init() {
            this.portal = Boolean(this.$el.closest('.rt-customer-portal-page'));
            if (config.fields.country) this.$watch('$wire.' + config.fields.country, () => this.close());
        },
        destroy() { this.close(); },
        close() {
            clearTimeout(this.timer); this.request?.abort(); this.version++;
            this.rows = []; this.message = ''; this.active = -1;
        },
        country() {
            if (config.locality) return 'DE';
            if (!config.fields.country) return '';
            const value = String(this.$wire.$get(config.fields.country) || '').trim().toLowerCase();
            return countries[value] || value.toUpperCase();
        },
        input(event) {
            if (event.target !== this.$refs.input) return;
            this.close();
            this.clearSite();
            const query = event.target.value.trim();
            if (query.length < 3) return;
            const country = this.country();
            if (country && !['DE','AT','CH','NL','PL','CZ'].includes(country)) {
                this.message = 'Für dieses Land bitte die Adresse manuell eingeben.'; return;
            }
            const version = this.version;
            this.timer = setTimeout(async () => {
                this.request = new AbortController();
                try {
                    const result = await post(this.portal ? config.portalSearch : config.search, { query, country, places: config.place }, this.request.signal);
                    if (this.version !== version || this.$refs.input?.value.trim() !== query) return;
                    this.rows = config.locality ? result.results.filter(row => row.city || row.precision === 'locality') : result.results;
                    this.message = this.rows.length ? '' : (result.status === 'known' ? 'Kein passender Treffer.'
                        : ({ disabled: 'TomTom-Suche deaktiviert.', missing_key: 'TomTom-Suche nicht eingerichtet.', budget_exhausted: 'TomTom-Monatslimit erreicht.' }[result.reason] || 'Adresssuche derzeit nicht verfügbar.'));
                } catch { if (this.version === version) this.message = 'Adresssuche derzeit nicht verfügbar.'; }
            }, 450);
        },
        clearSite() {
            if (!config.fields.confirmed) return;
            this.$wire.$set(config.fields.confirmed, false, false);
            this.$wire.$set(config.fields.latitude, '', false);
            this.$wire.$set(config.fields.longitude, '', false);
        },
        async gps() {
            this.close();
            const version = this.version;
            try {
                const { coords } = await new Promise((ok, fail) => navigator.geolocation.getCurrentPosition(ok, fail, {timeout:10000, enableHighAccuracy:true}));
                if (version !== this.version) return;
                const result = await post(config.reverse, {latitude:coords.latitude, longitude:coords.longitude});
                if (version !== this.version) return;
                this.rows = result.results;
                this.message = this.rows.length ? 'Ermittelte Adresse zur Übernahme auswählen.' : 'Keine Adresse am Gerätestandort gefunden.';
                if (config.coordinatesOnly && !this.rows.length) this.rows = [{label:'Gemessener Standort', precision:'gps', latitude:coords.latitude, longitude:coords.longitude}];
            } catch { if (version === this.version) this.message = 'Gerätestandort oder Adresse derzeit nicht verfügbar.'; }
        },
        move(step) { if (this.rows.length) this.active = (this.active + step + this.rows.length) % this.rows.length; },
        text(row) { return [row.name, row.label].filter(Boolean).join(' · '); },
        detail(row) { return [quality[row.precision], row.country].filter(Boolean).join(' · '); },
        async select(row) {
            if (!row) return;
            if (config.model === 'address' && !['DE','AT','CH','NL','PL','CZ'].includes(row.country)) {
                this.message = 'Dieses Land wird im Formular nicht angeboten.'; return;
            }
            const f = config.fields;
            if (config.place && !config.coordinatesOnly) {
                const label = config.locality ? (row.city || row.label) : this.text(row);
                this.$wire.$set(config.model, label, false);
            } else if (!config.coordinatesOnly) {
                if (!row.street && !row.city) { this.message = 'Adresse bitte genauer eingeben.'; return; }
                for (const key of ['street','postal','city','country']) {
                    if (f[key]) this.$wire.$set(f[key], row[key] || '', false);
                }
            }
            if (f.latitude) {
                this.$wire.$set(f.latitude, row.latitude, false);
                this.$wire.$set(f.longitude, row.longitude, false);
                if (f.confirmed) this.$wire.$set(f.confirmed, false, false);
            }
            this.close();
            if (config.coordinatesOnly) this.$refs.input.value = this.text(row);
            if (config.inline) await this.$wire.savePendingInlineChanges();
        },
    }));
}
