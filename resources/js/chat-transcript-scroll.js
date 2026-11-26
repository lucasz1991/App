export const chatTranscriptScroll = () => ({
    messageObserver: null,
    scrollHandler: null,
    stickToBottom: true,
    animatedRows: new WeakSet(),
    knownMessageKeys: new Set(),
    prependSnapshot: null,
    highlightTimer: null,

    init() {
        this.$el.querySelectorAll('[data-chat-message-row]').forEach((row) => {
            this.animatedRows.add(row);
            const key = row.getAttribute('wire:key');
            if (key) {
                this.knownMessageKeys.add(key);
            }
        });
        this.scrollHandler = () => {
            const bottomGap = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight;
            this.stickToBottom = bottomGap <= 96;
        };
        this.$el.addEventListener('scroll', this.scrollHandler, { passive: true });
        this.scrollToLatest();
        this.messageObserver = new MutationObserver((mutations) => {
            const newRows = [];

            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) {
                        return;
                    }

                    if (node.matches('[data-chat-message-row]')) {
                        newRows.push(node);
                    }
                    node.querySelectorAll?.('[data-chat-message-row]').forEach((row) => newRows.push(row));
                });
            });

            const freshRows = [...new Set(newRows)].filter((row) => {
                const key = row.getAttribute('wire:key');

                if ((key && this.knownMessageKeys.has(key)) || this.animatedRows.has(row)) {
                    return false;
                }

                if (key) {
                    this.knownMessageKeys.add(key);
                }
                this.animatedRows.add(row);

                return true;
            });

            if (!freshRows.length) {
                return;
            }

            this.animateRows(freshRows);

            if (this.stickToBottom) {
                this.scrollToLatest(true);
            }
        });
        this.messageObserver.observe(this.$el, { childList: true, subtree: true });
    },

    animateRows(rows) {
        if (!rows.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        window.gsap?.fromTo(
            rows,
            { autoAlpha: 0, y: 6, scale: 0.98 },
            {
                autoAlpha: 1,
                y: 0,
                scale: 1,
                duration: 0.18,
                stagger: 0.025,
                ease: 'power2.out',
                clearProps: 'transform,opacity,visibility',
                overwrite: 'auto',
            }
        );
    },

    scrollToLatest(smooth = false) {
        this.stickToBottom = true;

        requestAnimationFrame(() => {
            if (! this.$el?.isConnected) {
                return;
            }

            this.$el.scrollTo({
                top: this.$el.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        });
    },

    rememberPrependPosition() {
        this.prependSnapshot = {
            height: this.$el.scrollHeight,
            top: this.$el.scrollTop,
        };
    },

    restorePrependPosition() {
        if (!this.prependSnapshot || !this.$el?.isConnected) {
            return;
        }

        const addedHeight = this.$el.scrollHeight - this.prependSnapshot.height;
        this.$el.scrollTop = this.prependSnapshot.top + Math.max(0, addedHeight);
        this.prependSnapshot = null;
    },

    scrollToMessage(messageId) {
        const numericId = Number(messageId || 0);
        if (!numericId) {
            return;
        }

        const row = this.$el.querySelector(`[data-chat-message-id="${numericId}"]`);
        if (!row) {
            return;
        }

        row.scrollIntoView({
            block: 'center',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        row.classList.remove('is-reply-target');
        void row.offsetWidth;
        row.classList.add('is-reply-target');
        window.clearTimeout(this.highlightTimer);
        this.highlightTimer = window.setTimeout(() => row.classList.remove('is-reply-target'), 1800);
    },

    destroy() {
        this.messageObserver?.disconnect();
        this.messageObserver = null;
        this.$el?.removeEventListener('scroll', this.scrollHandler);
        this.scrollHandler = null;
        window.clearTimeout(this.highlightTimer);
        this.highlightTimer = null;
        window.gsap?.killTweensOf(this.$el.querySelectorAll('[data-chat-message-row]'));
    },
});
