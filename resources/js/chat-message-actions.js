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
        canReact: Boolean(config.canReact),
        actionOpen: false,
        reactionOpen: false,
        showMore: false,
        menuStyle: 'left:12px;top:12px;visibility:hidden;',
        anchorPoint: null,
        longPressTimer: null,
        longPressPointerId: null,
        longPressStartX: 0,
        longPressStartY: 0,
        suppressClickUntil: 0,
        shouldFocusMenu: false,

        isInteractiveTarget(target) {
            return target instanceof Element && Boolean(target.closest(
                'a, button, input, textarea, select, audio, video, [contenteditable="true"], [data-chat-action-ignore]'
            ));
        },

        openActionsAtPointer(event) {
            if (this.disabled || this.isInteractiveTarget(event.target)) {
                return;
            }

            event.preventDefault();
            this.openActionsAt(Number(event.clientX), Number(event.clientY));
        },

        openActionsAtTrigger(trigger) {
            if (this.disabled) {
                return;
            }

            const rect = trigger?.getBoundingClientRect();
            this.openActionsAt(
                rect ? rect.right : window.innerWidth / 2,
                rect ? rect.bottom + 4 : window.innerHeight / 2,
            );
        },

        openActionsAt(x, y) {
            this.openMenuAt('actions', x, y);
        },

        openReactionsAt(x, y) {
            if (!this.canReact) {
                return;
            }

            this.openMenuAt('reactions', x, y);
        },

        openReactionsFromActionMenu() {
            if (!this.anchorPoint) {
                return;
            }

            this.openReactionsAt(this.anchorPoint.x, this.anchorPoint.y);
        },

        openMenuAt(kind, x, y) {
            this.cancelLongPress();
            this.anchorPoint = { x, y };
            this.showMore = false;
            this.menuStyle = `left:${x}px;top:${y}px;visibility:hidden;`;
            this.shouldFocusMenu = true;
            this.actionOpen = kind === 'actions';
            this.reactionOpen = kind === 'reactions';
            this.$nextTick(() => window.requestAnimationFrame(() => this.positionMenu()));
        },

        positionMenu() {
            const menu = this.actionOpen ? this.$refs.actionMenu : this.$refs.reactionMenu;
            if ((!this.actionOpen && !this.reactionOpen) || !menu || !this.anchorPoint) {
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

            if (this.shouldFocusMenu) {
                this.shouldFocusMenu = false;
                this.$nextTick(() => menu.querySelector('[role="menuitem"], button')?.focus());
            }
        },

        close(restoreFocus = false) {
            this.cancelLongPress();
            this.actionOpen = false;
            this.reactionOpen = false;
            this.showMore = false;
            this.anchorPoint = null;
            this.shouldFocusMenu = false;

            if (restoreFocus) {
                this.$nextTick(() => this.$refs.messageActionTrigger?.focus());
            }
        },

        startLongPress(event) {
            if (
                this.disabled
                || !this.canReact
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
                this.openReactionsAt(this.longPressStartX, this.longPressStartY);
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
                return false;
            }

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation?.();
            this.suppressClickUntil = 0;

            return true;
        },

        handleClick(event) {
            if (this.suppressSyntheticClick(event) || this.disabled || this.isInteractiveTarget(event.target)) {
                return;
            }

            const rect = this.$refs.messageBubble?.getBoundingClientRect();
            const keyboardClick = Number(event.detail) === 0;
            this.openActionsAt(
                keyboardClick && rect ? rect.left + (rect.width / 2) : Number(event.clientX),
                keyboardClick && rect ? rect.top + Math.min(rect.height, 44) : Number(event.clientY),
            );
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
            this.openActionsAt(rect.left + (rect.width / 2), rect.top + Math.min(rect.height, 44));
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
