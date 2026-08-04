export const MOBILE_SIDEBAR_BREAKPOINT = 1024;

export const MOBILE_SIDEBAR_SWIPE_EXCLUSION_SELECTOR = [
    'input',
    'textarea',
    'select',
    'button',
    'audio',
    'video',
    '[contenteditable="true"]',
    '[role="dialog"]',
    '[role="slider"]',
    '[data-no-sidebar-swipe]',
].join(', ');

export function mobileSidebarSwipeThreshold(viewportWidth) {
    const width = Number(viewportWidth);

    if (!Number.isFinite(width) || width <= 0) {
        return 64;
    }

    return Math.max(64, Math.min(110, width * 0.2));
}

// Breite der mobilen Sidebar — muss dem CSS-Vertrag
// `.vertical-menu { width: min(86vw, 300px) }` entsprechen.
export function mobileSidebarWidth(viewportWidth) {
    const width = Number(viewportWidth);

    if (!Number.isFinite(width) || width <= 0) {
        return 300;
    }

    return Math.min(width * 0.86, 300);
}

// Ab dieser Randnaehe darf eine Oeffnen-Geste die Sidebar direkt am Finger
// fuehren. Weiter innen bleibt die klassische Schwellen-Geste aktiv, damit
// horizontale Scrollflaechen (Tabellen) nicht faelschlich die Navigation
// anziehen.
export const MOBILE_SIDEBAR_EDGE_ZONE_PX = 28;

/**
 * Fingerfuehrung der mobilen Sidebar als reine Zustandsmaschine.
 *
 * begin -> advance (je Touchmove) -> settle (Loslassen).
 * `progress` laeuft immer von 0 (zu) bis 1 (offen); die DOM-Schicht setzt
 * ihn als --rt-sidebar-drag-progress, sodass Sidebar und Schleier dem
 * Finger 1:1 folgen statt am Ende der Geste zu springen.
 */
export function beginSidebarDrag({
    startX,
    startY,
    sidebarOpen,
    viewportWidth,
    timestamp = 0,
}) {
    const width = Number(viewportWidth);
    const x = Number(startX);
    const y = Number(startY);

    if (
        !Number.isFinite(width)
        || width <= 0
        || width >= MOBILE_SIDEBAR_BREAKPOINT
        || !Number.isFinite(x)
        || !Number.isFinite(y)
    ) {
        return null;
    }

    const open = Boolean(sidebarOpen);

    // Oeffnen folgt dem Finger nur vom linken Rand aus; Schliessen darf
    // ueberall beginnen (die offene Sidebar plus Schleier gehoeren der Geste).
    if (!open && x > MOBILE_SIDEBAR_EDGE_ZONE_PX) {
        return null;
    }

    return {
        startX: x,
        startY: y,
        lastX: x,
        lastY: y,
        lastTimestamp: Number(timestamp) || 0,
        velocity: 0,
        sidebarOpen: open,
        sidebarWidth: mobileSidebarWidth(width),
        claimed: false,
        rejected: false,
        progress: open ? 1 : 0,
    };
}

export function advanceSidebarDrag(state, { x, y, timestamp = 0 }) {
    if (!state || state.rejected) {
        return state;
    }

    const nextX = Number(x);
    const nextY = Number(y);

    if (!Number.isFinite(nextX) || !Number.isFinite(nextY)) {
        return state;
    }

    const deltaX = nextX - state.startX;
    const deltaY = nextY - state.startY;

    if (!state.claimed) {
        // Absichtserkennung: erst eine klar horizontale Bewegung in die
        // passende Richtung uebernimmt die Geste; alles andere bleibt
        // natives Scrollen/Tippen.
        const horizontalIntent = Math.abs(deltaX) >= 12
            && Math.abs(deltaX) > Math.abs(deltaY) * 1.4;
        const correctDirection = state.sidebarOpen ? deltaX < 0 : deltaX > 0;

        if (Math.abs(deltaY) >= 18 && !horizontalIntent) {
            return { ...state, rejected: true };
        }

        if (!horizontalIntent || !correctDirection) {
            return state;
        }
    }

    const elapsed = Math.max(1, (Number(timestamp) || 0) - state.lastTimestamp);
    const instantVelocity = (nextX - state.lastX) / elapsed;
    const base = state.sidebarOpen ? 1 : 0;
    const progress = Math.min(1, Math.max(0, base + (deltaX / state.sidebarWidth)));

    return {
        ...state,
        claimed: true,
        lastX: nextX,
        lastY: nextY,
        lastTimestamp: Number(timestamp) || 0,
        // Geglaettete Geschwindigkeit (px/ms): das letzte Bewegungsfenster
        // dominiert, aeltere Messungen klingen ab — ein kurzer Schubser am
        // Ende der Geste reicht damit zum Umschlagen.
        velocity: (state.velocity * 0.4) + (instantVelocity * 0.6),
        progress,
    };
}

export function settleSidebarDrag(state) {
    if (!state || !state.claimed || state.rejected) {
        return null;
    }

    // Ein entschlossener Wurf gewinnt gegen die Position.
    if (state.velocity > 0.35) {
        return 'open';
    }

    if (state.velocity < -0.35) {
        return 'close';
    }

    return state.progress >= 0.5 ? 'open' : 'close';
}

export function resolveMobileSidebarSwipe({
    startX,
    startY,
    endX,
    endY,
    sidebarOpen,
    viewportWidth,
}) {
    const width = Number(viewportWidth);
    const coordinates = [startX, startY, endX, endY].map(Number);

    if (
        !Number.isFinite(width)
        || width <= 0
        || width >= MOBILE_SIDEBAR_BREAKPOINT
        || coordinates.some((coordinate) => !Number.isFinite(coordinate))
    ) {
        return null;
    }

    const [resolvedStartX, resolvedStartY, resolvedEndX, resolvedEndY] = coordinates;
    const deltaX = resolvedEndX - resolvedStartX;
    const deltaY = resolvedEndY - resolvedStartY;
    const threshold = mobileSidebarSwipeThreshold(width);
    const isHorizontal = Math.abs(deltaX) >= threshold
        && Math.abs(deltaX) > Math.abs(deltaY) * 1.25;

    if (!isHorizontal) {
        return null;
    }

    if (!sidebarOpen && deltaX > 0) {
        return 'open';
    }

    if (sidebarOpen && deltaX < 0) {
        return 'close';
    }

    return null;
}
