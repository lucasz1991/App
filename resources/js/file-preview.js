export const FILE_PREVIEW_MIN_ZOOM = 0.25;
export const FILE_PREVIEW_MAX_ZOOM = 4;
export const FILE_PREVIEW_ZOOM_STEP = 0.25;

const asFiniteNumber = (value, fallback = 0) => {
    const number = Number(value);

    return Number.isFinite(number) ? number : fallback;
};

export function clampPreviewZoom(
    value,
    minimum = FILE_PREVIEW_MIN_ZOOM,
    maximum = FILE_PREVIEW_MAX_ZOOM,
) {
    return Math.min(maximum, Math.max(minimum, asFiniteNumber(value, 1)));
}

export function previewFitZoom({ naturalWidth, naturalHeight, stageWidth, stageHeight, inset = 0 }) {
    const width = Math.max(1, asFiniteNumber(stageWidth) - (asFiniteNumber(inset) * 2));
    const height = Math.max(1, asFiniteNumber(stageHeight) - (asFiniteNumber(inset) * 2));
    const imageWidth = Math.max(1, asFiniteNumber(naturalWidth, 1));
    const imageHeight = Math.max(1, asFiniteNumber(naturalHeight, 1));

    // Einpassen darf bei sehr grossen Bildern bewusst unter die regulaere
    // 25-%-Zoomgrenze gehen. Die manuellen Zoomschritte bleiben 25-400 %.
    return Math.min(FILE_PREVIEW_MAX_ZOOM, Math.max(0.01, Math.min(
        width / imageWidth,
        height / imageHeight,
    )));
}

export function zoomPanAroundPoint({
    zoom,
    nextZoom,
    panX = 0,
    panY = 0,
    clientX,
    clientY,
    stageRect,
}) {
    const previous = Math.max(0.0001, asFiniteNumber(zoom, 1));
    const next = Math.max(0.0001, asFiniteNumber(nextZoom, previous));
    const rect = stageRect || { left: 0, top: 0, width: 0, height: 0 };
    const anchorX = asFiniteNumber(clientX, rect.left + (rect.width / 2))
        - (rect.left + (rect.width / 2));
    const anchorY = asFiniteNumber(clientY, rect.top + (rect.height / 2))
        - (rect.top + (rect.height / 2));
    const ratio = next / previous;

    return {
        panX: anchorX - ((anchorX - asFiniteNumber(panX)) * ratio),
        panY: anchorY - ((anchorY - asFiniteNumber(panY)) * ratio),
    };
}

export function constrainPreviewPan({
    panX = 0,
    panY = 0,
    zoom = 1,
    naturalWidth = 0,
    naturalHeight = 0,
    stageWidth = 0,
    stageHeight = 0,
}) {
    const overflowX = Math.max(0, ((asFiniteNumber(naturalWidth) * zoom) - stageWidth) / 2);
    const overflowY = Math.max(0, ((asFiniteNumber(naturalHeight) * zoom) - stageHeight) / 2);
    const constrainAxis = (value, overflow) => overflow === 0
        ? 0
        : Math.min(overflow, Math.max(-overflow, asFiniteNumber(value)));

    return {
        panX: constrainAxis(panX, overflowX),
        panY: constrainAxis(panY, overflowY),
    };
}

const pointDistance = (first, second) => Math.hypot(
    second.x - first.x,
    second.y - first.y,
);

const midpoint = (first, second) => ({
    x: (first.x + second.x) / 2,
    y: (first.y + second.y) / 2,
});

/**
 * Alpine-Komponente fuer den globalen FilePool-/Chat-Dateibetrachter.
 *
 * Die Fabrik bleibt frei von einem Alpine-Import, damit sie in Node getestet
 * und im bestehenden manuell gebuendelten Livewire-Alpine registriert werden
 * kann: Alpine.data('filePreview', filePreview).
 */
export function filePreview(config = {}) {
    const environment = config.environment || {};
    const getWindow = () => environment.window || globalThis.window;
    const getDocument = () => environment.document || globalThis.document;
    const requestFrame = (callback) => {
        const browserWindow = getWindow();

        return browserWindow?.requestAnimationFrame
            ? browserWindow.requestAnimationFrame(callback)
            : setTimeout(callback, 0);
    };
    const cancelFrame = (frame) => {
        const browserWindow = getWindow();

        if (browserWindow?.cancelAnimationFrame) browserWindow.cancelAnimationFrame(frame);
        else clearTimeout(frame);
    };
    const now = () => environment.now?.() ?? Date.now();

    return {
        open: config.open ?? false,
        fileKey: null,
        mediaKind: null,
        mediaLoading: false,
        mediaError: false,
        overflowOpen: false,
        zoom: 1,
        fitZoom: 1,
        panX: 0,
        panY: 0,
        minZoom: FILE_PREVIEW_MIN_ZOOM,
        maxZoom: FILE_PREVIEW_MAX_ZOOM,
        pointerActive: false,
        _returnFocus: null,
        _pointerPositions: {},
        _lastPointer: null,
        _pinch: null,
        _gestureMoved: false,
        _lastTap: null,
        _resizeFrame: null,

        init() {
            this.$watch?.('open', (isOpen) => this.syncOpen(Boolean(isOpen)));

            if (this.open) this.syncOpen(true);
        },

        destroy() {
            if (this._resizeFrame !== null) cancelFrame(this._resizeFrame);
            this.pauseMedia();
            this.removeOpenClass();
            this.resetPointers();
        },

        dialog() {
            return getDocument()?.querySelector?.('[data-file-preview-dialog]') || null;
        },

        stage() {
            return this.dialog()?.querySelector?.('[data-file-preview-image-stage]') || null;
        },

        image() {
            return this.dialog()?.querySelector?.('[data-file-preview-image]') || null;
        },

        prepareOpen() {
            const activeElement = getDocument()?.activeElement;

            if (activeElement && !activeElement.closest?.('[data-file-preview-dialog]')) {
                this._returnFocus = activeElement;
            }
        },

        syncOpen(isOpen) {
            if (isOpen) {
                this.prepareOpen();
                getDocument()?.documentElement?.classList?.add('rt-file-preview-open');
                this.$nextTick?.(() => requestFrame(() => {
                    this.dialog()?.querySelector?.('[data-file-preview-heading]')?.focus?.({ preventScroll: true });
                }));

                return;
            }

            this.overflowOpen = false;
            this.pauseMedia();
            this.removeOpenClass();
            this.resetView();

            const returnTarget = this._returnFocus;
            this._returnFocus = null;
            this.$nextTick?.(() => requestFrame(() => {
                if (returnTarget?.isConnected !== false) {
                    returnTarget?.focus?.({ preventScroll: true });
                }
            }));
        },

        removeOpenClass() {
            getDocument()?.documentElement?.classList?.remove('rt-file-preview-open');
        },

        closePreview() {
            if (!this.open) return;

            this.pauseMedia();
            this.open = false;
            this.$wire?.close?.();
        },

        activateFile(fileKey, mediaKind) {
            this.fileKey = String(fileKey || '');
            this.mediaKind = mediaKind || null;
            this.mediaError = false;
            this.mediaLoading = mediaKind !== 'fallback';
            this.overflowOpen = false;
            this.resetTransform();

            this.$nextTick?.(() => {
                const image = this.image();

                if (mediaKind === 'image' && image?.complete) {
                    if (image.naturalWidth > 0) this.imageLoaded({ currentTarget: image });
                    else this.mediaFailed();
                }
            });
        },

        resetView() {
            this.fileKey = null;
            this.mediaKind = null;
            this.mediaLoading = false;
            this.mediaError = false;
            this.resetTransform();
        },

        resetTransform() {
            this.zoom = 1;
            this.fitZoom = 1;
            this.panX = 0;
            this.panY = 0;
            this.resetPointers();
        },

        resetPointers() {
            this._pointerPositions = {};
            this._lastPointer = null;
            this._pinch = null;
            this._gestureMoved = false;
            this.pointerActive = false;
        },

        mediaLoaded() {
            this.mediaLoading = false;
            this.mediaError = false;
        },

        mediaFailed() {
            this.mediaLoading = false;
            this.mediaError = true;
        },

        imageLoaded(event) {
            if (!event?.currentTarget?.naturalWidth) {
                this.mediaFailed();

                return;
            }

            this.mediaLoaded();
            this.$nextTick?.(() => requestFrame(() => this.fitImage()));
        },

        pauseMedia() {
            this.dialog()?.querySelectorAll?.('video, audio')?.forEach((media) => {
                try {
                    media.pause();
                } catch (_) {
                    // Ein gerade verworfenes Media-Element benoetigt keine
                    // weitere Behandlung; die Vorschau schliesst trotzdem.
                }
            });
        },

        fitImage() {
            const stage = this.stage();
            const image = this.image();

            if (!stage || !image?.naturalWidth || !image?.naturalHeight) return;

            const rect = stage.getBoundingClientRect();
            const viewportWidth = getWindow()?.innerWidth || rect.width;
            const inset = viewportWidth < 640 ? 12 : 32;
            this.fitZoom = previewFitZoom({
                naturalWidth: image.naturalWidth,
                naturalHeight: image.naturalHeight,
                stageWidth: rect.width,
                stageHeight: rect.height,
                inset,
            });
            this.zoom = this.fitZoom;
            this.panX = 0;
            this.panY = 0;
        },

        actualSize(clientX = null, clientY = null) {
            this.setZoom(1, clientX, clientY);
        },

        isFitted() {
            return Math.abs(this.zoom - this.fitZoom) < 0.005
                && Math.abs(this.panX) < 0.5
                && Math.abs(this.panY) < 0.5;
        },

        zoomLabel() {
            return `${Math.round(this.zoom * 100)} %`;
        },

        zoomIn(clientX = null, clientY = null) {
            const next = this.zoom < FILE_PREVIEW_MIN_ZOOM
                ? FILE_PREVIEW_MIN_ZOOM
                : this.zoom + FILE_PREVIEW_ZOOM_STEP;
            this.setZoom(next, clientX, clientY);
        },

        zoomOut(clientX = null, clientY = null) {
            this.setZoom(this.zoom - FILE_PREVIEW_ZOOM_STEP, clientX, clientY);
        },

        setZoom(value, clientX = null, clientY = null) {
            const stage = this.stage();

            if (!stage) return;

            const rect = stage.getBoundingClientRect();
            const nextZoom = clampPreviewZoom(value);
            const nextPan = zoomPanAroundPoint({
                zoom: this.zoom,
                nextZoom,
                panX: this.panX,
                panY: this.panY,
                clientX,
                clientY,
                stageRect: rect,
            });

            this.zoom = nextZoom;
            this.panX = nextPan.panX;
            this.panY = nextPan.panY;
            this.constrainPan();
        },

        constrainPan() {
            const stage = this.stage();
            const image = this.image();

            if (!stage || !image) return;

            const rect = stage.getBoundingClientRect();
            const constrained = constrainPreviewPan({
                panX: this.panX,
                panY: this.panY,
                zoom: this.zoom,
                naturalWidth: image.naturalWidth,
                naturalHeight: image.naturalHeight,
                stageWidth: rect.width,
                stageHeight: rect.height,
            });
            this.panX = constrained.panX;
            this.panY = constrained.panY;
        },

        canPan() {
            const stage = this.stage();
            const image = this.image();

            if (!stage || !image) return false;

            const rect = stage.getBoundingClientRect();

            return (image.naturalWidth * this.zoom) > rect.width + 1
                || (image.naturalHeight * this.zoom) > rect.height + 1;
        },

        wheelZoom(event) {
            if (!event) return;

            event.preventDefault?.();
            const direction = event.deltaY < 0 ? 1 : -1;

            if (direction > 0) this.zoomIn(event.clientX, event.clientY);
            else this.zoomOut(event.clientX, event.clientY);
        },

        toggleActualAt(event) {
            const clientX = event?.clientX ?? null;
            const clientY = event?.clientY ?? null;

            if (this.isFitted()) {
                const target = Math.abs(this.fitZoom - 1) < 0.01 ? 2 : 1;
                this.setZoom(target, clientX, clientY);
            } else {
                this.fitImage();
            }
        },

        pointerDown(event) {
            if (!event || (event.pointerType === 'mouse' && event.button !== 0)) return;

            event.preventDefault?.();
            event.currentTarget?.setPointerCapture?.(event.pointerId);
            const key = String(event.pointerId);
            const point = { x: event.clientX, y: event.clientY };
            this._pointerPositions[key] = point;
            const points = Object.values(this._pointerPositions);
            this._gestureMoved = false;
            this.pointerActive = true;

            if (points.length === 1) {
                this._lastPointer = point;
                this._pinch = null;
            } else if (points.length === 2) {
                this._lastPointer = null;
                this._pinch = {
                    distance: Math.max(1, pointDistance(points[0], points[1])),
                    midpoint: midpoint(points[0], points[1]),
                };
            }
        },

        pointerMove(event) {
            const key = String(event?.pointerId);

            if (!event || !this._pointerPositions[key]) return;

            event.preventDefault?.();
            const previousPoint = this._pointerPositions[key];
            const nextPoint = { x: event.clientX, y: event.clientY };
            if (Math.hypot(nextPoint.x - previousPoint.x, nextPoint.y - previousPoint.y) > 2) {
                this._gestureMoved = true;
            }
            this._pointerPositions[key] = nextPoint;
            const points = Object.values(this._pointerPositions);

            if (points.length === 2 && this._pinch) {
                const nextDistance = Math.max(1, pointDistance(points[0], points[1]));
                const nextMidpoint = midpoint(points[0], points[1]);
                const ratio = nextDistance / this._pinch.distance;
                const previousMidpoint = this._pinch.midpoint;
                this.setZoom(this.zoom * ratio, previousMidpoint.x, previousMidpoint.y);
                this.panX += nextMidpoint.x - previousMidpoint.x;
                this.panY += nextMidpoint.y - previousMidpoint.y;
                this.constrainPan();
                this._pinch = { distance: nextDistance, midpoint: nextMidpoint };

                return;
            }

            if (points.length === 1 && this._lastPointer) {
                this.panX += nextPoint.x - this._lastPointer.x;
                this.panY += nextPoint.y - this._lastPointer.y;
                this._lastPointer = nextPoint;
                this.constrainPan();
            }
        },

        pointerUp(event) {
            if (!event) return;

            const key = String(event.pointerId);
            if (!this._pointerPositions[key]) return;

            event.currentTarget?.releasePointerCapture?.(event.pointerId);
            delete this._pointerPositions[key];
            const points = Object.values(this._pointerPositions);

            if (points.length === 1) {
                this._lastPointer = points[0];
                this._pinch = null;
            } else if (points.length === 0) {
                this.pointerActive = false;
                this._lastPointer = null;
                this._pinch = null;

                if (event.pointerType === 'touch' && !this._gestureMoved) {
                    this.handleTouchTap(event);
                }
            }
        },

        pointerCancel(event) {
            if (event?.pointerId !== undefined) {
                delete this._pointerPositions[String(event.pointerId)];
            }

            if (Object.keys(this._pointerPositions).length === 0) this.resetPointers();
        },

        handleTouchTap(event) {
            const tap = {
                time: asFiniteNumber(event.timeStamp, now()),
                x: event.clientX,
                y: event.clientY,
            };
            const previous = this._lastTap;

            if (previous
                && tap.time - previous.time <= 320
                && Math.hypot(tap.x - previous.x, tap.y - previous.y) <= 24) {
                this._lastTap = null;
                this.toggleActualAt({ clientX: tap.x, clientY: tap.y });

                return;
            }

            this._lastTap = tap;
        },

        handleCanvasKeydown(event) {
            if (!event) return;

            const key = String(event.key || '').toLowerCase();
            const panStep = event.shiftKey ? 80 : 40;

            if (key === '+' || key === '=') {
                event.preventDefault();
                this.zoomIn();
            } else if (key === '-') {
                event.preventDefault();
                this.zoomOut();
            } else if (key === '0') {
                event.preventDefault();
                this.actualSize();
            } else if (key === 'f') {
                event.preventDefault();
                this.fitImage();
            } else if (['arrowleft', 'arrowright', 'arrowup', 'arrowdown'].includes(key)) {
                event.preventDefault();
                if (key === 'arrowleft') this.panX -= panStep;
                if (key === 'arrowright') this.panX += panStep;
                if (key === 'arrowup') this.panY -= panStep;
                if (key === 'arrowdown') this.panY += panStep;
                this.constrainPan();
            }
        },

        handleResize() {
            if (!this.open || this.mediaKind !== 'image') return;

            const wasFitted = this.isFitted();
            if (this._resizeFrame !== null) cancelFrame(this._resizeFrame);
            this._resizeFrame = requestFrame(() => {
                this._resizeFrame = null;
                if (wasFitted) this.fitImage();
                else this.constrainPan();
            });
        },
    };
}
