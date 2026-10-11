import { loadLeaflet } from './live-location-map.js';

export function registerRoutingMap(Alpine, leafletLoader = loadLeaflet) {
    Alpine.data('rtRoutingMap', (points, tileUrl) => ({
        map: null,
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
            L.tileLayer(tileUrl.replace('__z__', '{z}').replace('__x__', '{x}').replace('__y__', '{y}'), { maxZoom: 20, attribution: '&copy; TomTom' })
                .on('tileerror', () => { if (!this.disposed) this.errorText = 'Kartenhintergrund derzeit nicht verfügbar. Die berechnete Route bleibt sichtbar.'; })
                .addTo(this.map);
            const route = L.polyline(path, { color: '#c62828', weight: 4 }).addTo(this.map);
            L.circleMarker(path[0], { radius: 6 }).addTo(this.map);
            L.circleMarker(path[path.length - 1], { radius: 6 }).addTo(this.map);
            this.map.fitBounds(route.getBounds(), { padding: [20, 20], maxZoom: 16, animate: false });
            this.observer = new ResizeObserver(() => this.map?.invalidateSize({ animate: false }));
            this.observer.observe(this.$el);
        },
        destroy() {
            this.disposed = true;
            this.observer?.disconnect();
            this.map?.remove();
            this.map = null;
        },
    }));
}
