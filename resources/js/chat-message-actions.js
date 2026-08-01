const LONG_PRESS_DELAY_MS = 500;
const LONG_PRESS_MOVE_TOLERANCE_PX = 10;
const MENU_VIEWPORT_GAP_PX = 12;

export function clampChatMenuPosition(value, minimum, maximum) {
    return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
}

export function shouldCancelChatLongPress(startX, startY, currentX, currentY) {
    return Math.hypot(currentX - startX, currentY - startY) > LONG_PRESS_MOVE_TOLERANCE_PX;
}

export function chatMessageActions(config = {}) {
    return {
        messageId: Number(config.messageId || 0),
        disabled: Boolean(config.disabled),
        open: false,
        showMore: false,
        menuStyle: 'left:12px;top:12px;visibility:hidden;',
        anchorPoint: null,
        longPressTimer: null,
        longPressPointerId: null,
        longPressStartX: 0,
        longPressStartY: 0,
        suppressClickUntil: 0,

        isInteractiveTarget(target) {
            return target instanceof Element && Boolean(target.closest(
                'a, button, input, textarea, select, audio, video, [contenteditable="true"], [data-chat-action-ignore]'
            ));
        },

        openAtPointer(event) {
            if (this.disabled || this.isInteractiveTarget(event.target)) {
                return;
            }

            event.preventDefault();
            this.openAt(Number(event.clientX), Number(event.clientY));
        },

        openAtTrigger(trigger) {
            if (this.disabled) {
                return;
            }

            const rect = trigger?.getBoundingClientRect();
            this.openAt(
                rect ? rect.right : window.innerWidth / 2,
                rect ? rect.bottom + 4 : window.innerHeight / 2,
            );
        },

        openAt(x, y) {
            this.cancelLongPress();
            this.anchorPoint = { x, y };
            this.showMore = false;
            this.menuStyle = `left:${x}px;top:${y}px;visibility:hidden;`;
            this.open = true;
            this.$nextTick(() => window.requestAnimationFrame(() => this.positionMenu()));
        },

        positionMenu() {
            const menu = this.$refs.menu;
            if (!this.open || !menu || !this.anchorPoint) {
                return;
            }

            const viewport = window.visualViewport;
            const viewportLeft = Number(viewport?.offsetLeft || 0);
            const viewportTop = Number(viewport?.offsetTop || 0);
            const viewportWidth = Number(viewport?.width || document.documentElement.clientWidth || window.innerWidth);
            const viewportHeight = Number(viewport?.height || document.documentElement.clientHeight || window.innerHeight);
            const rect = menu.getBoundingClientRect();
            const width = Math.min(rect.width, viewportWidth - (MENU_VIEWPORT_GAP_PX * 2));
            const height = Math.min(rect.height, viewportHeight - (MENU_VIEWPORT_GAP_PX * 2));
            const left = clampChatMenuPosition(
                this.anchorPoint.x,
                viewportLeft + MENU_VIEWPORT_GAP_PX,
                viewportLeft + viewportWidth - width - MENU_VIEWPORT_GAP_PX,
            );
            const spaceBelow = viewportTop + viewportHeight - this.anchorPoint.y;
            const preferredTop = spaceBelow >= height + MENU_VIEWPORT_GAP_PX
                ? this.anchorPoint.y
                : this.anchorPoint.y - height;
            const top = clampChatMenuPosition(
                preferredTop,
                viewportTop + MENU_VIEWPORT_GAP_PX,
                viewportTop + viewportHeight - height - MENU_VIEWPORT_GAP_PX,
            );

            this.menuStyle = `left:${Math.round(left)}px;top:${Math.round(top)}px;visibility:visible;`;
        },

        close(restoreFocus = false) {
            this.cancelLongPress();
            this.open = false;
            this.showMore = false;
            this.anchorPoint = null;

            if (restoreFocus) {
                this.$nextTick(() => this.$refs.messageActionTrigger?.focus());
            }
        },

        startLongPress(event) {
            if (
                this.disabled
                || !['touch', 'pen'].includes(event.pointerType)
                || this.isInteractiveTarget(event.target)
            ) {
                return;
            }

            this.cancelLongPress();
            this.longPressPointerId = event.pointerId;
            this.longPressStartX = event.clientX;
            this.longPressStartY = event.clientY;
            this.longPressTimer = window.setTimeout(() => {
                this.longPressTimer = null;
                this.suppressClickUntil = Date.now() + 800;
                this.openAt(this.longPressStartX, this.longPressStartY);
                window.navigator?.vibrate?.(10);
            }, LONG_PRESS_DELAY_MS);
        },

        moveLongPress(event) {
            if (event.pointerId !== this.longPressPointerId || !this.longPressTimer) {
                return;
            }

            if (shouldCancelChatLongPress(
                this.longPressStartX,
                this.longPressStartY,
                event.clientX,
                event.clientY,
            )) {
                this.cancelLongPress();
            }
        },

        finishLongPress(event) {
            if (event.pointerId === this.longPressPointerId) {
                this.cancelLongPress();
            }
        },

        cancelLongPress() {
            window.clearTimeout(this.longPressTimer);
            this.longPressTimer = null;
            this.longPressPointerId = null;
        },

        suppressSyntheticClick(event) {
            if (Date.now() >= this.suppressClickUntil) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation?.();
            this.suppressClickUntil = 0;
        },

        handleKeyboard(event) {
            if (
                this.disabled
                || event.target !== this.$refs.messageBubble
                || !(event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))
            ) {
                return;
            }

            event.preventDefault();
            const rect = this.$refs.messageBubble.getBoundingClientRect();
            this.openAt(rect.left + (rect.width / 2), rect.top + Math.min(rect.height, 44));
        },

        destroy() {
            this.cancelLongPress();
        },
    };
}

export const chatMessageGesture = Object.freeze({
    longPressDelayMs: LONG_PRESS_DELAY_MS,
    moveTolerancePx: LONG_PRESS_MOVE_TOLERANCE_PX,
});
