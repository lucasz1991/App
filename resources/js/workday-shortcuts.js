// Swiper owns gestures and geometry; this component owns the accessible rotation lifecycle.
export function workdayShortcuts({ loadSwiper = () => import('swiper').then(module => module.default) } = {}) {
    let slider = null;
    let timer = null;
    let generation = 0;
    let disposed = false;
    const cleanup = [];

    return {
        active: 0,
        ready: false,
        mobile: false,
        reduced: false,
        paused: false,
        hovered: false,
        visible: false,
        dragging: false,

        init() {
            const mobileQuery = window.matchMedia('(max-width: 767.98px)');
            const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            const listen = (target, event, callback) => {
                target.addEventListener(event, callback);
                cleanup.push(() => target.removeEventListener(event, callback));
            };
            const sync = () => {
                this.mobile = mobileQuery.matches;
                this.reduced = motionQuery.matches;
                if (slider) slider.params.speed = this.reduced ? 0 : 260;
                this.mountSlider();
            };
            listen(mobileQuery, 'change', sync);
            listen(motionQuery, 'change', sync);
            listen(document, 'visibilitychange', () => this.schedule());
            const visibility = new IntersectionObserver(entries => {
                this.visible = entries.some(entry => entry.isIntersecting && entry.intersectionRatio >= .5);
                this.schedule();
            }, { threshold: [0, .5] });
            visibility.observe(this.$el);
            cleanup.push(() => visibility.disconnect());
            // Keep server-rendered numbers fresh without allowing a Livewire morph
            // to reset Swiper's transform, focus or the user's paused state.
            const mutations = new MutationObserver(() => {
                if (!disposed && slider) {
                    slider.update();
                    this.active = slider.activeIndex;
                    this.schedule();
                }
            });
            mutations.observe(this.$refs.viewport, { childList: true, subtree: true, characterData: true });
            cleanup.push(() => mutations.disconnect());
            this.$nextTick(sync);
        },

        async mountSlider() {
            const token = ++generation;
            if (!this.mobile) {
                this.stop();
                slider?.destroy(true, true);
                slider = null;
                this.ready = false;
                return;
            }
            if (slider) {
                slider.update();
                this.schedule();
                return;
            }
            try {
                const Swiper = await loadSwiper();
                if (disposed || token !== generation || !this.mobile) return;
                slider = new Swiper(this.$refs.viewport, {
                    slidesPerView: 'auto', spaceBetween: 10, rewind: true,
                    speed: this.reduced ? 0 : 260, initialSlide: this.active,
                    watchOverflow: true, resizeObserver: true,
                    on: {
                        slideChange: instance => { this.active = instance.activeIndex; this.schedule(); },
                        touchStart: () => { this.dragging = true; this.stop(); },
                        touchEnd: () => { this.dragging = false; this.schedule(); },
                    },
                });
                this.ready = true;
                this.schedule();
            } catch {
                // An unavailable lazy chunk leaves the native scrollable links usable.
                this.ready = false;
            }
        },

        stop() {
            if (timer !== null) window.clearTimeout(timer);
            timer = null;
        },

        schedule() {
            this.stop();
            if (disposed || !slider || !this.mobile || this.reduced || this.paused || this.hovered || this.dragging || !this.visible || document.hidden || slider.slides.length < 2) return;
            timer = window.setTimeout(() => {
                timer = null;
                if (disposed || !slider) return;
                slider.slideNext();
                this.schedule();
            }, 6500);
        },

        pause() { this.paused = true; this.stop(); },
        select(index) {
            this.pause();
            slider?.slideTo(index, this.reduced ? 0 : 260);
        },
        toggle() {
            if (this.reduced) return;
            this.paused = !this.paused;
            this.schedule();
        },
        destroy() {
            disposed = true;
            generation++;
            this.stop();
            cleanup.forEach(dispose => dispose());
            slider?.destroy(true, true);
            slider = null;
        },
    };
}
