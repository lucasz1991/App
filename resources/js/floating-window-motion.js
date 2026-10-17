const frameFromRect = (rect, borderRadius) => ({
    left: `${rect.left}px`,
    top: `${rect.top}px`,
    width: `${rect.width}px`,
    height: `${rect.height}px`,
    borderRadius,
});

export function animateFloatingWindowGeometry(panel, from, to, {
    duration = 380,
    fromRadius = '18px',
    toRadius = '20px',
    onFinish = () => {},
} = {}) {
    if (!panel?.animate || !from?.width || !from?.height || !to?.width || !to?.height) return null;

    const animation = panel.animate([
        frameFromRect(from, fromRadius),
        frameFromRect(to, toRadius),
    ], {
        duration,
        easing: 'cubic-bezier(.22,.8,.25,1)',
        fill: 'both',
    });
    let completed = false;
    const finish = (cancelFill = false) => {
        if (completed) return;
        completed = true;
        if (cancelFill) animation.cancel();
        onFinish(animation);
    };
    animation.onfinish = () => finish(true);
    animation.oncancel = () => finish(false);
    return animation;
}
