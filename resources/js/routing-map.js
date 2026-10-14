import { loadLeaflet } from './live-location-map.js';

export function registerRoutingMap(Alpine, leafletLoader = loadLeaflet) {
    Alpine.data('rtTravelPreview', (wire) => ({
        activeKey: '',
        loading: false,
        errorText: '',
        disposed: false,
        requestVersion: 0,
        queuedRequest: null,
        requestRunning: false,
        requestRoute(employeeId, direction) {
            if (this.disposed) return;
            const id = Number(employeeId);
            if (!Number.isSafeInteger(id) || id <= 0 || !['before', 'after', 'arrival'].includes(direction)) return;
            const key = `${id}:${direction}`;
            if (this.activeKey === key) return;

            this.activeKey = key;
            this.loading = true;
            this.errorText = '';
            this.queuedRequest = { employeeId: id, direction, key, version: ++this.requestVersion };
            if (!this.requestRunning) return this.drainRequests();
        },
        async drainRequests() {
            this.requestRunning = true;
            while (!this.disposed && this.queuedRequest) {
                const request = this.queuedRequest;
                this.queuedRequest = null;
                let failed = false;
                try {
                    // Keep the shared Livewire geometry state ordered. A newer hover
                    // waits for the in-flight response and replaces only the queued one.
                    await wire.showRoute(request.employeeId, request.direction);
                } catch {
                    failed = true;
                }
                if (this.disposed) return;
                if (this.activeKey === request.key && this.requestVersion === request.version) {
                    this.errorText = failed ? 'Route nicht verfügbar' : '';
                    this.loading = false;
                }
            }
            if (!this.disposed) this.requestRunning = false;
        },
        dismissRoute(key) {
            // Exclusive dropdowns can deliver the old close after the new open.
            if (this.disposed || this.activeKey !== key) return;
            ++this.requestVersion;
            this.activeKey = '';
            this.loading = false;
            this.errorText = '';
            this.queuedRequest = null;
        },
        destroy() {
            this.disposed = true;
            ++this.requestVersion;
            this.activeKey = '';
            this.loading = false;
            this.errorText = '';
            this.queuedRequest = null;
            this.requestRunning = false;
        },
    }));

    Alpine.data('rtRoutingMap', (points, tileUrl) => ({
        map: null,
        tiles: null,
        observer: null,
        disposed: false,
        errorText: '',
        async init() {
            const normalized = (points || []).map((point) => Array.isArray(point) ? { latitude: point[0], longitude: point[1] } : point);
            const valid = normalized.filter((point) => point && Number.isFinite(Number(point.latitude)) && Number.isFinite(Number(point.longitude)) && Math.abs(Number(point.latitude)) <= 90 && Math.abs(Number(point.longitude)) <= 180);
            if (valid.length < 2) return;
            let L;
            try {
                L = await leafletLoader();
            } catch {
                if (!this.disposed) this.errorText = 'Die Karte konnte nicht geladen werden. Die Fahrzeitprüfung bleibt verfügbar.';
                return;
            }
            if (this.disposed || !this.$el.isConnected) return;
            this.map = L.map(this.$el, { scrollWheelZoom: false });
            const path = valid.map((point) => [Number(point.latitude), Number(point.longitude)]);
            const template = tileUrl.replace('__z__', '{z}').replace('__x__', '{x}').replace('__y__', '{y}');
            // The authenticated server endpoint owns reuse and always returns no-store to the browser.
            this.tiles = L.tileLayer(template, { maxZoom: 20, attribution: '&copy; TomTom' });
            this.tiles
                .on('tileerror', () => { if (!this.disposed) this.errorText = 'Kartenhintergrund derzeit nicht verfügbar. Die berechnete Route bleibt sichtbar.'; })
                .addTo(this.map);
            const route = L.polyline(path, { color: '#c62828', weight: 4 }).addTo(this.map);
            L.circleMarker(path[0], { radius: 6 }).addTo(this.map);
            L.circleMarker(path[path.length - 1], { radius: 6 }).addTo(this.map);
            this.map.fitBounds(route.getBounds(), { padding: [20, 20], maxZoom: 16, animate: false });
            if (typeof ResizeObserver === 'function') {
                this.observer = new ResizeObserver(() => this.map?.invalidateSize({ animate: false }));
                this.observer.observe(this.$el);
            }
        },
        destroy() {
            this.disposed = true;
            this.observer?.disconnect();
            this.map?.remove();
            this.observer = null;
            this.tiles = null;
            this.map = null;
        },
    }));
}
