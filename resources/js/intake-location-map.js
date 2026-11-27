import { createLiveLocationMap, validLiveLocationCoordinates } from './live-location-map.js';

/** Display-only worksite preview. It never requests GPS or resolves user-entered place names. */
export function intakeLocationMap(point, { createMap = createLiveLocationMap, windowLike = globalThis.window } = {}) {
    let map = null;
    let observer = null;
    let disposed = false;
    let pending = false;

    return {
        status: 'idle',

        init() {
            if (!validLiveLocationCoordinates(point?.latitude, point?.longitude)) {
                this.status = 'error';
                return;
            }
            const Observer = windowLike?.IntersectionObserver;
            if (typeof Observer === 'function') {
                observer = new Observer((entries) => {
                    if (disposed || !entries.some((entry) => entry.isIntersecting)) return;
                    if (map) map.invalidate({ recenter: false });
                    else this.load();
                }, { threshold: 0.01 });
                observer.observe(this.$el);
            } else {
                this.load();
            }
        },

        async load() {
            if (disposed || pending || map || !this.$refs?.canvas?.isConnected) return;
            if (!validLiveLocationCoordinates(point?.latitude, point?.longitude)) return;
            pending = true;
            this.status = 'loading';
            try {
                const created = await createMap({
                    element: this.$refs.canvas,
                    latitude: Number(point.latitude),
                    longitude: Number(point.longitude),
                    zoom: [14, 15, 16].includes(point.zoom) ? point.zoom : 15,
                    interactive: true,
                    markerLabel: point.label,
                    windowLike,
                });
                if (disposed || !this.$refs.canvas.isConnected) {
                    created.destroy();
                    return;
                }
                map = created;
                map.map?.eachLayer?.((layer) => {
                    if (typeof layer?.getTileUrl !== 'function') return;
                    layer.on?.('tileerror', () => {
                        if (!disposed) this.status = 'error';
                    });
                });
                this.status = 'ready';
                map.invalidate({ recenter: true });
            } catch {
                if (!disposed) this.status = 'error';
            } finally {
                pending = false;
            }
        },

        destroy() {
            disposed = true;
            observer?.disconnect();
            observer = null;
            map?.destroy();
            map = null;
        },
    };
}

export function registerIntakeLocationMap(Alpine) {
    Alpine.data('rtIntakeLocationMap', (point) => intakeLocationMap(point));
}
