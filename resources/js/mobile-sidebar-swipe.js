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
