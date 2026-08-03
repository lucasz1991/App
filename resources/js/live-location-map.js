const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const DEFAULT_TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
const DEFAULT_MAX_ZOOM = 19;

let leafletPromise = null;

function finiteNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

export function validLiveLocationCoordinates(latitude, longitude) {
    const lat = finiteNumber(latitude);
    const lng = finiteNumber(longitude);

    return lat !== null
        && lng !== null
        && lat >= -90
        && lat <= 90
        && lng >= -180
        && lng <= 180;
}

function metaContent(documentLike, name) {
    return documentLike?.querySelector?.(`meta[name="${name}"]`)?.content?.trim?.() || '';
}

export function readLiveLocationMapConfig(documentLike = globalThis.document) {
    const configuredMaxZoom = Number(metaContent(documentLike, 'rt-live-location-tile-max-zoom'));

    return {
        tileUrl: metaContent(documentLike, 'rt-live-location-tile-url') || DEFAULT_TILE_URL,
        attribution: metaContent(documentLike, 'rt-live-location-tile-attribution')
            || DEFAULT_TILE_ATTRIBUTION,
        maxZoom: Number.isFinite(configuredMaxZoom) && configuredMaxZoom > 0
            ? configuredMaxZoom
            : DEFAULT_MAX_ZOOM,
    };
}

/**
 * Leaflet and its CSS stay out of the initial application bundle. The browser
 * requests both only when a location card or the large preview becomes visible.
 */
export function loadLeaflet() {
    if (!leafletPromise) {
        leafletPromise = Promise.all([
            import('leaflet'),
            import('leaflet/dist/leaflet.css'),
        ]).then(([leafletModule]) => leafletModule.default || leafletModule);
    }

    return leafletPromise;
}

function point(latitude, longitude) {
    return [Number(latitude), Number(longitude)];
}

function positiveAccuracy(value) {
    const accuracy = finiteNumber(value);

    return accuracy !== null && accuracy > 0 ? accuracy : 0;
}

/**
 * Creates a deliberately small adapter around Leaflet. Tests can inject a
 * fake loader, while production receives the lazy ESM/CSS chunk above.
 */
export async function createLiveLocationMap({
    element,
    latitude,
    longitude,
    accuracy = 0,
    interactive = false,
    zoom = interactive ? 16 : 15,
    mapConfig = null,
    documentLike = globalThis.document,
    windowLike = globalThis.window,
    leafletLoader = loadLeaflet,
} = {}) {
    if (!element) {
        throw new TypeError('A map element is required.');
    }

    if (!validLiveLocationCoordinates(latitude, longitude)) {
        throw new RangeError('Valid map coordinates are required.');
    }

    const L = await leafletLoader();
    const tiles = {
        ...readLiveLocationMapConfig(documentLike),
        ...(mapConfig || {}),
    };
    const center = point(latitude, longitude);
    const map = L.map(element, {
        attributionControl: true,
        boxZoom: interactive,
        doubleClickZoom: interactive,
        dragging: interactive,
        keyboard: interactive,
        scrollWheelZoom: interactive,
        tap: interactive,
        touchZoom: interactive,
        zoomControl: interactive,
    });

    map.setView(center, zoom, { animate: false });

    const tileLayer = L.tileLayer(tiles.tileUrl, {
        attribution: tiles.attribution,
        maxZoom: tiles.maxZoom,
    }).addTo(map);
    const markerIcon = L.divIcon({
        className: '',
        html: '<span class="rt-live-location-marker"><i class="fas fa-location-dot" aria-hidden="true"></i></span>',
        iconAnchor: [18, 18],
        iconSize: [36, 36],
    });
    const marker = L.marker(center, {
        icon: markerIcon,
        interactive: false,
        keyboard: false,
    }).addTo(map);
    const accuracyCircle = L.circle(center, {
        radius: positiveAccuracy(accuracy),
        color: '#e4002b',
        fillColor: '#e4002b',
        fillOpacity: 0.1,
        opacity: 0.45,
        weight: 1,
    }).addTo(map);

    let current = {
        latitude: Number(latitude),
        longitude: Number(longitude),
        accuracy: positiveAccuracy(accuracy),
    };
    let removed = false;
    let resizeFrame = null;
    let resizeObserver = null;
    let resizeListener = null;

    const currentPoint = () => point(current.latitude, current.longitude);
    const currentZoom = () => map.getZoom?.() ?? zoom;
    const hasLayout = () => {
        const rectangle = element.getBoundingClientRect?.();

        return !rectangle || (rectangle.width > 0 && rectangle.height > 0);
    };
    const refreshLayout = ({ recenter = true } = {}) => {
        if (removed || !hasLayout()) {
            return false;
        }

        map.invalidateSize({
            animate: false,
            debounceMoveend: true,
            pan: true,
        });

        if (recenter) {
            map.setView(currentPoint(), currentZoom(), { animate: false });
        }

        return true;
    };
    const scheduleLayout = () => {
        if (removed || resizeFrame !== null) {
            return;
        }

        const callback = () => {
            resizeFrame = null;
            refreshLayout();
        };

        if (typeof windowLike?.requestAnimationFrame === 'function') {
            resizeFrame = windowLike.requestAnimationFrame(callback);
        } else {
            resizeFrame = windowLike?.setTimeout?.(callback, 0) ?? null;

            if (resizeFrame === null) {
                Promise.resolve().then(callback);
            }
        }
    };
    const ResizeObserverConstructor = windowLike?.ResizeObserver
        ?? globalThis.ResizeObserver;

    if (typeof ResizeObserverConstructor === 'function') {
        resizeObserver = new ResizeObserverConstructor(scheduleLayout);
        resizeObserver.observe(element);
    } else if (windowLike?.addEventListener) {
        resizeListener = scheduleLayout;
        windowLike.addEventListener('resize', resizeListener, { passive: true });
        windowLike.addEventListener('orientationchange', resizeListener, { passive: true });
    }

    scheduleLayout();

    return {
        get map() {
            return map;
        },

        get current() {
            return { ...current };
        },

        update(next = {}, { recenter = false } = {}) {
            if (removed || !validLiveLocationCoordinates(next.latitude, next.longitude)) {
                return false;
            }

            current = {
                latitude: Number(next.latitude),
                longitude: Number(next.longitude),
                accuracy: positiveAccuracy(next.accuracy),
            };
            const nextPoint = point(current.latitude, current.longitude);

            marker.setLatLng(nextPoint);
            accuracyCircle.setLatLng(nextPoint);
            accuracyCircle.setRadius(current.accuracy);

            if (recenter) {
                map.setView(nextPoint, currentZoom(), { animate: false });
            }

            return true;
        },

        recenter() {
            if (!removed) {
                map.setView(currentPoint(), currentZoom(), { animate: false });
            }
        },

        invalidate(options = {}) {
            return refreshLayout(options);
        },

        destroy() {
            if (removed) {
                return;
            }

            removed = true;
            resizeObserver?.disconnect();

            if (resizeListener) {
                windowLike?.removeEventListener?.('resize', resizeListener);
                windowLike?.removeEventListener?.('orientationchange', resizeListener);
            }

            if (resizeFrame !== null) {
                if (typeof windowLike?.cancelAnimationFrame === 'function') {
                    windowLike.cancelAnimationFrame(resizeFrame);
                } else {
                    windowLike?.clearTimeout?.(resizeFrame);
                }

                resizeFrame = null;
            }

            tileLayer.remove?.();
            marker.remove?.();
            accuracyCircle.remove?.();
            map.remove();
        },
    };
}

export const liveLocationMapDefaults = Object.freeze({
    tileUrl: DEFAULT_TILE_URL,
    attribution: DEFAULT_TILE_ATTRIBUTION,
    maxZoom: DEFAULT_MAX_ZOOM,
});
