// ---------------------------------------------------------------
// Bewegungsschicht des Admin-Dashboards.
//
// Wird von der Alpine-Komponente `adminDashboardCharts` lazy nachgeladen und
// laeuft damit nur auf der Admin-Startseite. Die Basis-Reveals der Segmente
// bleiben in resources/js/gsap.js; hier liegen ausschliesslich die
// dashboardspezifischen Zaehler-Effekte. Der fruehere Hero mit gezeichneter
// RailTime-Strecke ist entfallen; deshalb braucht dieses Lazy-Modul nur noch
// ScrollTrigger und die gemeinsame Signaturkurve fuer die Zaehler.
//
// Alles laeuft in genau einem gsap.matchMedia()-Kontext pro DOM-Generation.
// `prefers-reduced-motion: reduce` erhaelt den fertigen Endzustand ohne
// Dauerschleifen; destroy() verwirft den Kontext vollstaendig.
// ---------------------------------------------------------------
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { CustomEase } from 'gsap/CustomEase';

gsap.registerPlugin(ScrollTrigger, CustomEase);

// Signaturkurve: schneller Antritt, langes Ausrollen — dieselbe Anmutung wie
// `ease-rt-spring` im Tailwind-Theme.
const RT_SIGNAL = CustomEase.create('rtSignal', 'M0,0 C0.14,0.9 0.24,1 1,1');

function counterTargets(root) {
    // Die Kennzahlkacheln bringen ihre eigene Zaehleranimation in der
    // Alpine-Komponente mit. Hier laufen nur Chart- und Listenwerte.
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

            setupCounters(root, formatter, animated);
        },
        root,
    );

    return {
        destroy() {
            media.revert();
        },
    };
}
