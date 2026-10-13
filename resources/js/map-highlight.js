function mapKey(value) {
    if (typeof value !== 'string') return '';
    const key = value.trim();
    return key.length > 0 && key.length <= 256 && key !== 'unlocated' ? key : '';
}

function mapKeys(values) {
    return Array.isArray(values) ? [...new Set(values.map(mapKey).filter(Boolean))].slice(0, 32) : [];
}

/** Local display state: no location lookup, timers or changes to map positions. */
export function mapHighlight() {
    return {
        mapHoveredKey: null,
        mapFocusedKey: null,
        mapHoveredPoints: [],
        mapFocusedPoints: [],

        mapHover(key, points = [key]) {
            // An unlocated row deliberately suppresses a separately focused pin.
            this.mapHoveredKey = mapKey(key);
            this.mapHoveredPoints = mapKeys(points);
        },

        mapUnhover(key) {
            if (this.mapHoveredKey === mapKey(key)) {
                this.mapHoveredKey = null;
                this.mapHoveredPoints = [];
            }
        },

        mapFocus(key, points = [key]) {
            this.mapHoveredKey = null;
            this.mapHoveredPoints = [];
            this.mapFocusedKey = mapKey(key);
            this.mapFocusedPoints = mapKeys(points);
        },

        mapBlur(key) {
            if (this.mapFocusedKey === mapKey(key)) {
                this.mapFocusedKey = null;
                this.mapFocusedPoints = [];
            }
        },

        mapIsActive(key) {
            const point = mapKey(key);
            return point !== '' && (this.mapHoveredKey !== null ? this.mapHoveredPoints : this.mapFocusedPoints).includes(point);
        },

        mapMatchesPlace(place, points) {
            const keys = mapKeys(points);
            return place === 'all' || (place === 'unlocated' ? keys.length === 0 : keys.includes(mapKey(place)));
        },

        mapReset() {
            this.mapHoveredKey = null;
            this.mapFocusedKey = null;
            this.mapHoveredPoints = [];
            this.mapFocusedPoints = [];
        },

        destroy() {
            this.mapReset();
        },
    };
}
