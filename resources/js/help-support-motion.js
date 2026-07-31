function isInInitialViewport(element) {
    const rect = element.getBoundingClientRect();

    return rect.bottom > 0 && rect.top < window.innerHeight * 0.92;
}

function clearMotion(gsap, elements) {
    if (!elements.length) return;

    gsap.set(elements, {
        autoAlpha: 1,
        x: 0,
        y: 0,
        scale: 1,
        clearProps: 'transform,opacity,visibility,willChange',
    });
}

function revealElement(gsap, element, from, options = {}) {
    if (!element) return null;

    const vars = {
        autoAlpha: 1,
        x: 0,
        y: 0,
        scale: 1,
        duration: options.duration ?? 0.62,
        delay: options.delay ?? 0,
        ease: options.ease ?? 'power3.out',
        overwrite: 'auto',
        clearProps: 'transform,opacity,visibility,willChange',
    };

    if (!isInInitialViewport(element)) {
        vars.scrollTrigger = {
            trigger: element,
            start: 'clamp(top 88%)',
            once: true,
            invalidateOnRefresh: true,
        };
    }

    return gsap.fromTo(element, { ...from, willChange: 'transform,opacity' }, vars);
}

/**
 * Adds the page-specific choreography to the existing RailTime GSAP lifecycle.
 * The caller owns matchMedia and wire:navigate cleanup; this module deliberately
 * registers no document or Livewire listeners of its own.
 */
export function setupHelpSupportMotion(pageRoot, { gsap, reduceMotion }) {
    const experience = pageRoot.querySelector('[data-support-experience]');
    if (!experience) return null;

    const hero = experience.querySelector('[data-support-hero]');
    const heroCopy = experience.querySelector('[data-support-hero-copy]');
    const heroCopyItems = heroCopy ? Array.from(heroCopy.children) : [];
    const heroCard = experience.querySelector('[data-support-hero-card]');
    const quickNavItems = Array.from(experience.querySelectorAll('[data-support-quicknav] > a'));
    const revealTargets = Array.from(experience.querySelectorAll('[data-support-reveal]'));
    const form = experience.querySelector('[data-support-form]');
    const formItems = form
        ? Array.from(form.children).filter((element) => !element.matches('.rt-support-composer__alerts:empty'))
        : [];
    const routePaths = Array.from(experience.querySelectorAll('[data-support-route-path]'));
    const routeNodes = Array.from(experience.querySelectorAll('[data-support-route-node]'));
    const allTargets = Array.from(new Set([
        ...heroCopyItems,
        heroCard,
        ...quickNavItems,
        ...revealTargets,
        ...formItems,
        ...routePaths,
        ...routeNodes,
    ].filter(Boolean)));

    if (reduceMotion) {
        clearMotion(gsap, allTargets);
        routePaths.forEach((path) => gsap.set(path, { clearProps: 'strokeDasharray,strokeDashoffset' }));

        return () => {};
    }

    const entrance = gsap.timeline({
        defaults: { ease: 'power3.out' },
    });

    if (heroCopyItems.length) {
        entrance.fromTo(
            heroCopyItems,
            { autoAlpha: 0, y: 24, willChange: 'transform,opacity' },
            {
                autoAlpha: 1,
                y: 0,
                duration: 0.64,
                stagger: 0.075,
                clearProps: 'transform,opacity,visibility,willChange',
            },
            0.04,
        );
    }

    if (heroCard) {
        entrance.fromTo(
            heroCard,
            { autoAlpha: 0, x: 28, scale: 0.985, willChange: 'transform,opacity' },
            {
                autoAlpha: 1,
                x: 0,
                scale: 1,
                duration: 0.72,
                clearProps: 'transform,opacity,visibility,willChange',
            },
            0.18,
        );
    }

    routePaths.forEach((path, index) => {
        if (typeof path.getTotalLength !== 'function') return;

        const length = path.getTotalLength();
        entrance.fromTo(
            path,
            { strokeDasharray: length, strokeDashoffset: length },
            {
                strokeDashoffset: 0,
                duration: 1.35,
                ease: 'power2.inOut',
                clearProps: 'strokeDasharray,strokeDashoffset',
            },
            0.08 + index * 0.08,
        );
    });

    if (routeNodes.length) {
        entrance.fromTo(
            routeNodes,
            { autoAlpha: 0, scale: 0.35, transformOrigin: '50% 50%' },
            {
                autoAlpha: 1,
                scale: 1,
                duration: 0.4,
                stagger: 0.12,
                ease: 'back.out(2)',
                clearProps: 'transform,opacity,visibility',
            },
            0.72,
        );
    }

    if (quickNavItems.length) {
        entrance.fromTo(
            quickNavItems,
            { autoAlpha: 0, y: 16, willChange: 'transform,opacity' },
            {
                autoAlpha: 1,
                y: 0,
                duration: 0.5,
                stagger: 0.065,
                clearProps: 'transform,opacity,visibility,willChange',
            },
            0.48,
        );
    }

    revealTargets
        .filter((element) => element !== form)
        .forEach((element, index) => {
            revealElement(gsap, element, { autoAlpha: 0, y: 24, scale: 0.994 }, {
                delay: isInInitialViewport(element) ? 0.1 + index * 0.04 : 0,
            });
        });

    if (form) {
        const formTimeline = gsap.timeline({
            scrollTrigger: isInInitialViewport(form) ? undefined : {
                trigger: form,
                start: 'clamp(top 88%)',
                once: true,
                invalidateOnRefresh: true,
            },
        });

        formTimeline.fromTo(
            form,
            { autoAlpha: 0, y: 22, scale: 0.994, willChange: 'transform,opacity' },
            {
                autoAlpha: 1,
                y: 0,
                scale: 1,
                duration: 0.58,
                ease: 'power3.out',
                clearProps: 'transform,opacity,visibility,willChange',
            },
        );

        if (formItems.length) {
            formTimeline.fromTo(
                formItems,
                { autoAlpha: 0, y: 12, willChange: 'transform,opacity' },
                {
                    autoAlpha: 1,
                    y: 0,
                    duration: 0.4,
                    stagger: 0.045,
                    ease: 'power2.out',
                    clearProps: 'transform,opacity,visibility,willChange',
                },
                '-=0.32',
            );
        }
    }

    return () => {
        entrance.kill();
        clearMotion(gsap, allTargets);
        routePaths.forEach((path) => gsap.set(path, { clearProps: 'strokeDasharray,strokeDashoffset' }));
    };
}
