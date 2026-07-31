// ---------------------------------------------------------------
// Bewegungsschicht des Admin-Dashboards.
//
// Wird von der Alpine-Komponente `adminDashboardCharts` lazy nachgeladen und
// laeuft damit nur auf der Admin-Startseite. Die Basis-Reveals der Segmente
// bleiben in resources/js/gsap.js; hier liegen ausschliesslich die
// dashboardspezifischen Plugin-Effekte:
//
//   * DrawSVG      – die RailTime-Strecke im Hero zeichnet sich einmalig ein
//   * MotionPath   – ein Signalpunkt faehrt anschliessend dauerhaft die Strecke
//   * CustomEase   – gemeinsame Signaturkurve fuer die Zaehler
//
// Alles laeuft in genau einem gsap.matchMedia()-Kontext pro DOM-Generation.
// `prefers-reduced-motion: reduce` erhaelt den fertigen Endzustand ohne
// Dauerschleifen; destroy() verwirft den Kontext vollstaendig.
// ---------------------------------------------------------------
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { DrawSVGPlugin } from 'gsap/DrawSVGPlugin';
import { MotionPathPlugin } from 'gsap/MotionPathPlugin';
import { CustomEase } from 'gsap/CustomEase';

gsap.registerPlugin(ScrollTrigger, DrawSVGPlugin, MotionPathPlugin, CustomEase);

// Signaturkurve: schneller Antritt, langes Ausrollen — dieselbe Anmutung wie
// `ease-rt-spring` im Tailwind-Theme.
const RT_SIGNAL = CustomEase.create('rtSignal', 'M0,0 C0.14,0.9 0.24,1 1,1');

function counterTargets(root) {
    // Die Kennzahlkacheln bringen ihre eigene Zaehleranimation in der
    // Alpine-Komponente mit. Hier laufen nur Hero-, Chart- und Listenwerte.
    return Array.from(root.querySelectorAll('[data-dashboard-count]'))
        .filter((element) => !element.closest('[data-dashboard-kpis]'))
        .map((element) => ({
            element,
            target: Number(element.dataset.dashboardCount),
            rendered: null,
        }))
        .filter((counter) => Number.isFinite(counter.target));
}

function setupCounters(root, formatter, animated) {
    const counters = counterTargets(root);

    if (!counters.length) return;

    if (!animated) {
        counters.forEach((counter) => {
            counter.element.textContent = formatter.format(counter.target);
        });

        return;
    }

    counters.forEach((counter) => {
        // Der serverseitig gerenderte Wert bleibt stehen, bis der Tween
        // wirklich startet. Feuert der ScrollTrigger nie, zeigt die Kachel
        // damit die echte Zahl statt einer stehengebliebenen Null.
        const state = { progress: 0 };

        gsap.to(state, {
            progress: 1,
            duration: 1.05,
            ease: RT_SIGNAL,
            overwrite: true,
            scrollTrigger: {
                trigger: counter.element,
                start: 'clamp(top 94%)',
                once: true,
                invalidateOnRefresh: true,
            },
            onUpdate: () => {
                const nextValue = Math.round(counter.target * state.progress);

                if (nextValue === counter.rendered) return;

                counter.rendered = nextValue;
                counter.element.textContent = formatter.format(nextValue);
            },
            onComplete: () => {
                counter.element.textContent = formatter.format(counter.target);
            },
        });
    });
}

function setupRoute(root, animated) {
    const route = root.querySelector('[data-rt-route]');

    if (!route) return;

    const bed = route.querySelector('[data-rt-route-bed]');
    const line = route.querySelector('[data-rt-route-line]');
    const train = route.querySelector('[data-rt-route-train]');
    const strokes = [bed, line].filter(Boolean);

    if (!strokes.length) return;

    // Solange GSAP die Strecke fuehrt, darf die CSS-Fallback-Animation nicht
    // gegen die von DrawSVG gesetzten Dash-Werte laufen.
    route.dataset.rtRouteMotion = 'on';

    if (!animated) {
        gsap.set(strokes, { drawSVG: '0% 100%' });
        if (train) gsap.set(train, { autoAlpha: 0 });

        return;
    }

    const timeline = gsap.timeline();

    timeline.fromTo(strokes, { drawSVG: '0% 0%' }, {
        drawSVG: '0% 100%',
        duration: 1.15,
        ease: 'power2.inOut',
        stagger: 0.16,
    });

    if (train && line) {
        timeline.set(train, { autoAlpha: 1 }, '>-0.25');
        timeline.to(train, {
            duration: 6.4,
            ease: 'none',
            repeat: -1,
            motionPath: {
                path: line,
                align: line,
                alignOrigin: [0.5, 0.5],
                start: 0,
                end: 1,
            },
        }, '<');
    }

    return () => {
        delete route.dataset.rtRouteMotion;
    };
}

/**
 * Baut die Bewegungsschicht fuer eine DOM-Generation des Admin-Dashboards auf.
 *
 * @param {HTMLElement} root Wurzelknoten der Alpine-Komponente
 * @returns {{destroy: () => void}}
 */
export function createAdminDashboardMotion(root) {
    const formatter = new Intl.NumberFormat(document.documentElement.lang || 'de-DE');
    const media = gsap.matchMedia();

    media.add(
        {
            reduceMotion: '(prefers-reduced-motion: reduce)',
            animateMotion: '(prefers-reduced-motion: no-preference)',
        },
        ({ conditions }) => {
            const animated = !conditions.reduceMotion;
            const revertRoute = setupRoute(root, animated);

            setupCounters(root, formatter, animated);

            return () => revertRoute?.();
        },
        root,
    );

    return {
        destroy() {
            media.revert();
        },
    };
}
