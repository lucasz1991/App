function mapKey(value) {
    if (typeof value !== 'string') return '';
    const key = value.trim();
    return key.length > 0 && key.length <= 256 && key !== 'unlocated' ? key : '';
}

/** Local display state: no location lookup, timers or changes to map positions. */
export function mapHighlight() {
    return {
        mapHoveredKey: null,
        mapFocusedKey: null,

        mapHover(key) {
            // An unlocated row deliberately suppresses a separately focused pin.
            this.mapHoveredKey = mapKey(key);
        },

        mapUnhover(key) {
            if (this.mapHoveredKey === mapKey(key)) this.mapHoveredKey = null;
        },

        mapFocus(key) {
            this.mapHoveredKey = null;
            this.mapFocusedKey = mapKey(key);
        },

        mapBlur(key) {
            if (this.mapFocusedKey === mapKey(key)) this.mapFocusedKey = null;
        },

        mapIsActive(key) {
            const point = mapKey(key);
            return point !== '' && point === (this.mapHoveredKey ?? this.mapFocusedKey);
        },

        mapReset() {
            this.mapHoveredKey = null;
            this.mapFocusedKey = null;
        },

        destroy() {
            this.mapReset();
        },
    };
}
