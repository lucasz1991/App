export function sidebarScrollTarget({
    scrollTop,
    clientHeight,
    scrollHeight,
    containerTop,
    containerBottom,
    blockTop,
    blockBottom,
    padding = 12,
}) {
    const safeScrollTop = Number.isFinite(scrollTop) ? scrollTop : 0;
    const safeClientHeight = Math.max(0, Number.isFinite(clientHeight) ? clientHeight : 0);
    const safeScrollHeight = Math.max(safeClientHeight, Number.isFinite(scrollHeight) ? scrollHeight : 0);
    const maxScrollTop = Math.max(0, safeScrollHeight - safeClientHeight);
    const viewportTop = containerTop + padding;
    const viewportBottom = containerBottom - padding;
    const blockHeight = blockBottom - blockTop;
    const viewportHeight = Math.max(0, viewportBottom - viewportTop);

    if (blockTop >= viewportTop && blockBottom <= viewportBottom) {
        return null;
    }

    const delta = blockHeight > viewportHeight || blockTop < viewportTop
        ? blockTop - viewportTop
        : blockBottom - viewportBottom;

    return Math.min(maxScrollTop, Math.max(0, safeScrollTop + delta));
}

export function sidebarScrollBehavior(prefersReducedMotion) {
    return prefersReducedMotion ? 'auto' : 'smooth';
}
