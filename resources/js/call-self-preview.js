export const SELF_PREVIEW_MARGIN = 8;
export const SELF_PREVIEW_RESTORE_SIZE = 44;

const finite = (value, fallback = 0) => Number.isFinite(Number(value))
    ? Number(value)
    : fallback;

const size = (value) => ({
    width: Math.max(0, finite(value?.width)),
    height: Math.max(0, finite(value?.height)),
});

const position = (value) => ({
    x: finite(value?.x),
    y: finite(value?.y),
});

const clamp = (value, minimum, maximum) => Math.min(
    Math.max(minimum, maximum),
    Math.max(minimum, finite(value, minimum)),
);

/**
 * Keep the complete preview inside the stage with a stable inset.
 *
 * On an unusually small stage the preview cannot physically keep both
 * margins. In that case it starts at the leading edge instead of producing
 * negative or NaN transforms.
 */
export function clampSelfPreviewPosition(candidate, stageSize, previewSize, margin = SELF_PREVIEW_MARGIN) {
    const stage = size(stageSize);
    const preview = size(previewSize);
    const inset = Math.max(0, finite(margin, SELF_PREVIEW_MARGIN));
    const maxX = Math.max(inset, stage.width - preview.width - inset);
    const maxY = Math.max(inset, stage.height - preview.height - inset);
    const current = position(candidate);

    return {
        x: clamp(current.x, inset, maxX),
        y: clamp(current.y, inset, maxY),
    };
}

/** A preview is docked only after its centre has actually left the stage. */
export function isSelfPreviewCenterOutside(candidate, stageSize, previewSize) {
    const stage = size(stageSize);
    const preview = size(previewSize);
    const current = position(candidate);
    const centerX = current.x + preview.width / 2;
    const centerY = current.y + preview.height / 2;

    return centerX < 0
        || centerX > stage.width
        || centerY < 0
        || centerY > stage.height;
}

/** Select the stage edge nearest to the preview centre. */
export function nearestSelfPreviewEdge(candidate, stageSize, previewSize) {
    const stage = size(stageSize);
    const preview = size(previewSize);
    const current = position(candidate);
    const centerX = current.x + preview.width / 2;
    const centerY = current.y + preview.height / 2;
    const distances = [
        ['left', Math.abs(centerX)],
        ['right', Math.abs(stage.width - centerX)],
        ['top', Math.abs(centerY)],
        ['bottom', Math.abs(stage.height - centerY)],
    ];

    distances.sort((left, right) => left[1] - right[1]);

    return distances[0][0];
}

/**
 * Place the 44px restore control at the exit edge while retaining the
 * preview's position along that edge.
 */
export function selfPreviewDockPosition(
    edge,
    candidate,
    stageSize,
    previewSize,
    buttonSize = SELF_PREVIEW_RESTORE_SIZE,
    margin = SELF_PREVIEW_MARGIN,
) {
    const stage = size(stageSize);
    const preview = size(previewSize);
    const current = position(candidate);
    const controlSize = Math.max(1, finite(buttonSize, SELF_PREVIEW_RESTORE_SIZE));
    const inset = Math.max(0, finite(margin, SELF_PREVIEW_MARGIN));
    const centerX = current.x + preview.width / 2;
    const centerY = current.y + preview.height / 2;
    const maxX = Math.max(inset, stage.width - controlSize - inset);
    const maxY = Math.max(inset, stage.height - controlSize - inset);
    const alongX = clamp(centerX - controlSize / 2, inset, maxX);
    const alongY = clamp(centerY - controlSize / 2, inset, maxY);

    if (edge === 'left') return { x: inset, y: alongY };
    if (edge === 'top') return { x: alongX, y: inset };
    if (edge === 'bottom') return { x: alongX, y: maxY };

    return { x: maxX, y: alongY };
}
