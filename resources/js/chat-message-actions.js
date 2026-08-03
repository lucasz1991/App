const LONG_PRESS_DELAY_MS = 500;
const LONG_PRESS_MOVE_TOLERANCE_PX = 10;

export function shouldCancelChatLongPress(startX, startY, currentX, currentY) {
    return Math.hypot(currentX - startX, currentY - startY) > LONG_PRESS_MOVE_TOLERANCE_PX;
}

export function chatMessageActions(config = {}) {
    return {
        messageId: Number(config.messageId || 0),
        controllerId: String(config.controllerId || config.messageId || ''),
        disabled: Boolean(config.disabled),
        canReact: Boolean(config.canReact),
        menuMode: 'actions',
        showMore: false,
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

        prepareActionMenu() {
            this.cancelLongPress();
            this.menuMode = 'actions';
            this.showMore = false;
        },

        showActionMenu() {
            this.prepareActionMenu();
            this.$nextTick(() => this.focusDropdownItem());
        },

        messageActionTrigger() {
            if (typeof document === 'undefined') {
                return null;
            }

            return document.querySelector(`[data-chat-message-action-trigger="${this.controllerId}"]`);
        },

        toggleActionMenu() {
            if (this.disabled) {
                return;
            }

            const trigger = this.messageActionTrigger();
            if (trigger?.getAttribute?.('aria-expanded') === 'true' && this.menuMode === 'actions') {
                this.cancelLongPress();
                this.showMore = false;
                trigger.click?.();

                return;
            }

            this.openMenu('actions');
        },

        showReactionMenu() {
            if (!this.canReact) {
                return;
            }

            this.menuMode = 'reactions';
            this.showMore = false;
            this.$nextTick(() => this.focusDropdownItem());
        },

        openReactionMenu() {
            if (!this.canReact) {
                return;
            }

            this.$nextTick(() => this.openMenu('reactions'));
        },

        openActionsAtPointer(event) {
            if (this.disabled || this.isInteractiveTarget(event.target)) {
                return;
            }

            event.preventDefault();
            this.openMenu('actions');
        },

        openMenu(mode = 'actions') {
            if (this.disabled || (mode === 'reactions' && !this.canReact)) {
                return;
            }

            this.cancelLongPress();
            this.menuMode = mode;
            this.showMore = false;

            this.$nextTick(() => {
                const trigger = this.messageActionTrigger();
                if (!trigger) {
                    return;
                }

                if (trigger.getAttribute?.('aria-expanded') !== 'true') {
                    trigger.click?.();
                }

                this.$nextTick(() => window.requestAnimationFrame(() => this.focusDropdownItem()));
            });
        },

        focusDropdownItem() {
            if (typeof document === 'undefined') {
                return;
            }

            const trigger = this.messageActionTrigger();
            const panelId = trigger?.getAttribute?.('aria-controls');
            const panel = panelId ? document.getElementById(panelId) : null;
            const visibleSection = panel?.querySelector(
                this.menuMode === 'reactions'
                    ? '[data-chat-reaction-menu]'
                    : '[data-chat-action-menu]'
            );

            visibleSection
                ?.querySelector(
                    this.menuMode === 'reactions'
                        ? '[data-chat-reaction-choice]'
                        : '[role="menuitem"], button:not([disabled]), a[href]'
                )
                ?.focus({ preventScroll: true });
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
                this.openMenu(this.canReact ? 'reactions' : 'actions');
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

            this.toggleActionMenu();
        },

        handleKeyboard(event) {
            if (this.disabled || event.target !== event.currentTarget) {
                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.toggleActionMenu();

                return;
            }

            if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
                event.preventDefault();
                this.openMenu('actions');
            }
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
