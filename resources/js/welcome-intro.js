const clampStep = (value, total) => {
    if (total <= 0) {
        return 0;
    }

    return Math.min(Math.max(Number(value) || 0, 0), total - 1);
};

const interpolate = (template, replacements) => Object.entries(replacements)
    .reduce(
        (value, [key, replacement]) => value.replaceAll(`:${key}`, String(replacement)),
        String(template || ''),
    );

export const welcomeIntro = (config = {}) => ({
    open: Boolean(config.initiallyOpen),
    step: 0,
    slides: Array.isArray(config.slides) ? config.slides : [],
    labels: config.labels || {},
    returnFocus: null,

    init() {
        this.step = clampStep(this.step, this.slides.length);

        if (this.open) {
            this.focusHeading();
        }
    },

    get currentSlide() {
        return this.slides[this.step] || {
            id: 'welcome',
            eyebrow: '',
            title: '',
            description: '',
            icon: 'fa-compass',
            points: [],
        };
    },

    get isFirst() {
        return this.step === 0;
    },

    get isLast() {
        return this.step >= this.slides.length - 1;
    },

    get completion() {
        if (this.slides.length <= 0) {
            return 0;
        }

        return Math.round(((this.step + 1) / this.slides.length) * 100);
    },

    get progressText() {
        return interpolate(this.labels.progress, {
            current: this.step + 1,
            total: this.slides.length,
        });
    },

    openIntro(event = null) {
        const trigger = event?.target;

        this.returnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
        this.step = 0;
        this.open = true;
        this.focusHeading();
    },

    closeIntro(restoreFocus = true) {
        this.open = false;

        if (!restoreFocus || !(this.returnFocus instanceof HTMLElement)) {
            return;
        }

        const target = this.returnFocus;
        window.setTimeout(() => {
            if (target.isConnected) {
                target.focus({ preventScroll: true });
            }
        }, 40);
    },

    skip() {
        this.closeIntro();
    },

    next() {
        if (this.isLast) {
            this.closeIntro();

            return;
        }

        this.goTo(this.step + 1);
    },

    previous() {
        if (!this.isFirst) {
            this.goTo(this.step - 1);
        }
    },

    goTo(index) {
        const nextStep = clampStep(index, this.slides.length);

        if (nextStep === this.step) {
            return;
        }

        this.step = nextStep;
        this.focusHeading();
    },

    focusHeading() {
        this.$nextTick(() => {
            window.requestAnimationFrame(() => {
                this.$refs.heading?.focus({ preventScroll: true });
            });
        });
    },

    stepButtonLabel(index) {
        const slide = this.slides[index] || {};

        return interpolate(this.labels.openStep, {
            current: index + 1,
            total: this.slides.length,
            title: slide.title || '',
        });
    },

    handleKey(event) {
        if (
            !this.open
            || event.defaultPrevented
            || event.altKey
            || event.ctrlKey
            || event.metaKey
        ) {
            return;
        }

        if (event.key === 'ArrowRight' || event.key === 'PageDown') {
            event.preventDefault();
            this.next();
        } else if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
            event.preventDefault();
            this.previous();
        } else if (event.key === 'Home') {
            event.preventDefault();
            this.goTo(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            this.goTo(this.slides.length - 1);
        }
    },
});
