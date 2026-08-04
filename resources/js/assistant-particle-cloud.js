import { createRailTimeLogoTargets } from './navigation-particle-loader.js';

/**
 * AI-Partikelwolke des Assistenten.
 *
 * Ersetzt das fruehere 3D-Maskottchen (Backup: assistant-pet-3d.js bleibt
 * unveraendert im Repo, ist aber nicht mehr eingebunden). Eine weiche
 * Partikelwolke treibt dahin und formt sich in einem ruhigen Zyklus zum
 * RT-Monogramm und wieder zurueck — dieselbe Logo-Geometrie wie der
 * Navigations-Loader, aber eine eigenstaendige, zurueckhaltendere Anmutung:
 * kleiner, langsamer, ohne Kugelphase.
 */

const TAU = Math.PI * 2;

export const ASSISTANT_CLOUD_PARTICLES = 110;

export const ASSISTANT_CLOUD_CYCLE = Object.freeze({
    cloudMs: 6400,
    morphMs: 760,
    logoMs: 1900,
});

// Zustaende aus chatbot.js petState(). Sie modulieren nur Tempo, Dichte und
// Funkeln — die Wolke bleibt immer dieselbe Persoenlichkeit.
export const ASSISTANT_CLOUD_PROFILES = Object.freeze({
    idle: Object.freeze({ drift: 1, swirl: 0.14, contract: 0, pulse: 0, sparkle: 1, gray: false }),
    thinking: Object.freeze({ drift: 2.1, swirl: 0.55, contract: 0.06, pulse: 0.05, sparkle: 1.25, gray: false }),
    listening: Object.freeze({ drift: 0.8, swirl: 0.1, contract: 0.18, pulse: 0.1, sparkle: 1.1, gray: false }),
    speaking: Object.freeze({ drift: 1.35, swirl: 0.2, contract: 0.04, pulse: 0.22, sparkle: 1.3, gray: false }),
    curious: Object.freeze({ drift: 1.8, swirl: 0.4, contract: 0, pulse: 0.08, sparkle: 1.5, gray: false }),
    happy: Object.freeze({ drift: 1.6, swirl: 0.3, contract: 0, pulse: 0.14, sparkle: 1.7, gray: false }),
    wave: Object.freeze({ drift: 1.6, swirl: 0.35, contract: 0, pulse: 0.12, sparkle: 1.5, gray: false }),
    offline: Object.freeze({ drift: 0.45, swirl: 0.05, contract: 0.1, pulse: 0, sparkle: 0.4, gray: true }),
});

const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));
const mix = (from, to, progress) => from + ((to - from) * progress);
const easeInOutCubic = (value) => {
    const progress = clamp(value);

    return progress < 0.5
        ? 4 * progress * progress * progress
        : 1 - (Math.pow(-2 * progress + 2, 3) / 2);
};

function deterministicNoise(index, salt = 0) {
    const value = Math.sin(((index + 1) * 12.9898) + (salt * 78.233)) * 43758.5453;

    return value - Math.floor(value);
}

export function resolveAssistantCloudProfile(state) {
    return ASSISTANT_CLOUD_PROFILES[state] || ASSISTANT_CLOUD_PROFILES.idle;
}

/**
 * Position im Wolke-RT-Zyklus (reine Funktion, testbar):
 * cloud -> to-logo -> logo -> to-cloud. blend 0 = Wolke, 1 = Monogramm.
 */
export function assistantCloudCycle(elapsedMs, cycle = ASSISTANT_CLOUD_CYCLE) {
    const cloudMs = Math.max(1, cycle.cloudMs);
    const morphMs = Math.max(1, cycle.morphMs);
    const logoMs = Math.max(1, cycle.logoMs);
    const total = cloudMs + morphMs + logoMs + morphMs;
    const t = ((Number(elapsedMs) || 0) % total + total) % total;

    if (t < cloudMs) {
        return { phase: 'cloud', blend: 0 };
    }

    if (t < cloudMs + morphMs) {
        return { phase: 'to-logo', blend: easeInOutCubic((t - cloudMs) / morphMs) };
    }

    if (t < cloudMs + morphMs + logoMs) {
        return { phase: 'logo', blend: 1 };
    }

    return {
        phase: 'to-cloud',
        blend: 1 - easeInOutCubic((t - cloudMs - morphMs - logoMs) / morphMs),
    };
}

/**
 * Deterministische Wolkenform: mehrere ueberlappende Ballungen ergeben die
 * Silhouette einer kleinen Cumulus-Wolke (normierte Koordinaten -1..1).
 */
export function createAssistantCloudTargets(count = ASSISTANT_CLOUD_PARTICLES) {
    const lobes = [
        { x: 0, y: 0.1, r: 0.52 },
        { x: -0.4, y: 0.18, r: 0.34 },
        { x: 0.4, y: 0.2, r: 0.33 },
        { x: -0.14, y: -0.2, r: 0.4 },
        { x: 0.24, y: -0.14, r: 0.32 },
    ];
    const resolvedCount = Math.max(1, Math.floor(Number(count) || 1));

    return Array.from({ length: resolvedCount }, (_, index) => {
        const lobe = lobes[index % lobes.length];
        const angle = deterministicNoise(index, 21) * TAU;
        const radius = Math.sqrt(deterministicNoise(index, 22)) * lobe.r;

        return {
            x: lobe.x + (Math.cos(angle) * radius),
            y: lobe.y + (Math.sin(angle) * radius * 0.8),
        };
    });
}

function createParticles(count) {
    const cloudTargets = createAssistantCloudTargets(count);
    const logoTargets = createRailTimeLogoTargets(count);

    return cloudTargets.map((target, index) => ({
        cloud: target,
        logo: logoTargets[index],
        driftPhase: deterministicNoise(index, 31) * TAU,
        driftSpeed: 0.6 + (deterministicNoise(index, 32) * 0.8),
        driftAmount: 0.03 + (deterministicNoise(index, 33) * 0.05),
        size: 0.75 + (deterministicNoise(index, 34) * 1.05),
        alpha: 0.35 + (deterministicNoise(index, 35) * 0.6),
        sparkle: deterministicNoise(index, 36),
        pulse: deterministicNoise(index, 37) * TAU,
    }));
}

function particleColor(particle, { gray, dark, logoBlend }) {
    if (gray) {
        return dark ? '148, 163, 184' : '100, 116, 139';
    }

    // Im Monogramm uebernimmt jeder Partikel seinen Logo-Ton.
    if (logoBlend > 0.5 && particle.logo.tone === 'slate') {
        return dark ? '210, 220, 232' : '38, 47, 59';
    }

    if (particle.sparkle > 0.94) {
        return '255, 244, 247';
    }

    if (particle.sparkle > 0.6) {
        return '255, 76, 105';
    }

    return '228, 0, 43';
}

export function createAssistantCloudRenderer(controllerElement, options = {}) {
    const documentObject = options.documentObject
        || controllerElement?.ownerDocument
        || document;
    const view = options.windowObject || documentObject.defaultView || window;

    if (!controllerElement) {
        return null;
    }

    const reducedMotion = view.matchMedia('(prefers-reduced-motion: reduce)');
    const forcedColors = view.matchMedia('(forced-colors: active)');

    // Forced Colors: der Mask-Icon-Fallback im Slot ist die bessere Antwort.
    if (forcedColors.matches) {
        return null;
    }

    const root = controllerElement.closest('.rt-chatbot') || documentObject;
    const slots = Array.from(root.querySelectorAll('[data-assistant-cloud-slot]'));

    if (slots.length === 0) {
        return null;
    }

    const particles = createParticles(ASSISTANT_CLOUD_PARTICLES);
    const surfaces = [];

    slots.forEach((slot) => {
        const canvas = documentObject.createElement('canvas');
        canvas.className = 'rt-assistant-cloud__canvas';
        canvas.setAttribute('aria-hidden', 'true');

        let context = null;

        try {
            context = canvas.getContext('2d', { alpha: true });
        } catch (_) {
            context = null;
        }

        if (!context) {
            return;
        }

        slot.appendChild(canvas);
        slot.classList.add('is-cloud-ready');
        surfaces.push({
            slot,
            canvas,
            context,
            cssSize: 0,
            role: slot.getAttribute('data-assistant-cloud-slot') || 'launcher',
        });
    });

    if (surfaces.length === 0) {
        return null;
    }

    let animationFrame = null;
    let running = false;
    let lastFrameAt = 0;
    let startedAt = 0;

    function isDark() {
        return documentObject.documentElement.classList.contains('dark')
            || documentObject.body?.dataset.mode === 'dark';
    }

    function syncSurfaceSize(surface) {
        const measured = Math.min(
            surface.slot.clientWidth || 0,
            surface.slot.clientHeight || surface.slot.clientWidth || 0
        ) || 64;
        const dpr = Math.min(Number(view.devicePixelRatio) || 1, 2);
        const pixelSize = Math.max(1, Math.round(measured * dpr));

        if (surface.cssSize !== measured || surface.canvas.width !== pixelSize) {
            surface.cssSize = measured;
            surface.canvas.width = pixelSize;
            surface.canvas.height = pixelSize;
            surface.context.setTransform(dpr, 0, 0, dpr, 0, 0);
        }
    }

    function drawSurface(surface, elapsed, cycle, profile, dark) {
        syncSurfaceSize(surface);

        const { context, cssSize } = surface;
        const center = cssSize / 2;
        // Die Wolke nutzt die Flaeche grosszuegig, das Monogramm etwas
        // kompakter, damit es im runden Halo sauber steht.
        const cloudScale = cssSize * 0.44;
        const logoScale = cssSize * 0.36;
        const logoBlend = cycle.blend;
        const breath = 1 + (Math.sin(elapsed * 0.0011) * 0.035 * (1 + profile.pulse));

        context.clearRect(0, 0, cssSize, cssSize);

        particles.forEach((particle) => {
            const drift = profile.drift;
            const wispX = Math.sin((elapsed * 0.00042 * drift * particle.driftSpeed) + particle.driftPhase)
                * particle.driftAmount;
            const wispY = Math.cos((elapsed * 0.00036 * drift * particle.driftSpeed) + particle.driftPhase)
                * particle.driftAmount * 0.8;
            // Sanfte Rotation der ganzen Wolke (Denk-Zustand dreht schneller).
            const swirlAngle = elapsed * 0.00012 * profile.swirl * TAU;
            const cosSwirl = Math.cos(swirlAngle);
            const sinSwirl = Math.sin(swirlAngle);
            const contraction = 1 - profile.contract;
            const rawX = (particle.cloud.x + wispX) * contraction;
            const rawY = (particle.cloud.y + wispY) * contraction;
            const cloudX = (rawX * cosSwirl) - (rawY * sinSwirl);
            const cloudY = (rawX * sinSwirl) + (rawY * cosSwirl);

            const x = center + (mix(cloudX * cloudScale, particle.logo.x * logoScale, logoBlend) * breath);
            const y = center + (mix(cloudY * cloudScale, particle.logo.y * logoScale, logoBlend) * breath);

            const twinkle = 0.82
                + (Math.sin((elapsed * 0.0024 * profile.sparkle) + particle.pulse) * 0.18);
            const logoSize = particle.logo.tone === 'slate' ? 1.3 : 1.45;
            const size = mix(particle.size, logoSize, logoBlend)
                * twinkle
                * (cssSize / 96)
                * (1 + (profile.pulse * Math.sin(elapsed * 0.008)));
            const alpha = mix(particle.alpha * 0.82, 0.95, logoBlend) * twinkle;

            context.beginPath();
            context.arc(x, y, Math.max(0.3, size), 0, TAU);
            context.fillStyle = `rgba(${particleColor(particle, {
                gray: profile.gray,
                dark,
                logoBlend,
            })}, ${clamp(alpha, 0, 1)})`;
            context.fill();
        });
    }

    function drawStaticLogo() {
        const dark = isDark();

        surfaces.forEach((surface) => {
            syncSurfaceSize(surface);

            const { context, cssSize } = surface;
            const center = cssSize / 2;
            const logoScale = cssSize * 0.36;

            context.clearRect(0, 0, cssSize, cssSize);
            particles.forEach((particle) => {
                const logoSize = (particle.logo.tone === 'slate' ? 1.3 : 1.45) * (cssSize / 96);

                context.beginPath();
                context.arc(
                    center + (particle.logo.x * logoScale),
                    center + (particle.logo.y * logoScale),
                    Math.max(0.3, logoSize),
                    0,
                    TAU
                );
                context.fillStyle = `rgba(${particleColor(particle, {
                    gray: false,
                    dark,
                    logoBlend: 1,
                })}, 0.95)`;
                context.fill();
            });
        });
    }

    function render(timestamp) {
        animationFrame = null;

        if (!running) {
            return;
        }

        // 30 fps genuegen einer Ambient-Wolke — halbierte Renderlast.
        if (timestamp - lastFrameAt >= 33) {
            lastFrameAt = timestamp;

            const elapsed = timestamp - startedAt;
            const state = controllerElement.dataset.state || 'idle';
            const open = controllerElement.dataset.petOpen === 'true';
            const profile = resolveAssistantCloudProfile(state);
            const cycle = profile.gray
                ? { phase: 'cloud', blend: 0 }
                : assistantCloudCycle(elapsed);
            const dark = isDark();

            surfaces.forEach((surface) => {
                // Der Launcher ist bei geoeffnetem Panel ausgeblendet
                // (x-show) — zeichnen waere reine Waerme.
                if (surface.role === 'launcher' && open) {
                    return;
                }

                if (!surface.slot.isConnected) {
                    return;
                }

                drawSurface(surface, elapsed, cycle, profile, dark);
            });
        }

        queueFrame();
    }

    function queueFrame() {
        if (!running || animationFrame !== null || documentObject.visibilityState === 'hidden') {
            return;
        }

        animationFrame = view.requestAnimationFrame(render);
    }

    function handleVisibilityChange() {
        if (documentObject.visibilityState === 'hidden') {
            if (animationFrame !== null) {
                view.cancelAnimationFrame(animationFrame);
                animationFrame = null;
            }

            return;
        }

        queueFrame();
    }

    function start() {
        if (reducedMotion.matches) {
            drawStaticLogo();

            return;
        }

        startedAt = view.performance.now();
        lastFrameAt = 0;
        running = true;
        queueFrame();
    }

    function destroy() {
        running = false;

        if (animationFrame !== null) {
            view.cancelAnimationFrame(animationFrame);
            animationFrame = null;
        }

        documentObject.removeEventListener('visibilitychange', handleVisibilityChange);
        surfaces.forEach((surface) => {
            surface.canvas.remove();
            surface.slot.classList.remove('is-cloud-ready');
        });
        surfaces.length = 0;
    }

    documentObject.addEventListener('visibilitychange', handleVisibilityChange);

    return { start, destroy, surfaceCount: surfaces.length };
}

export function railtimeAssistantCloud() {
    return {
        cloudRenderer: null,

        init() {
            this.$nextTick(() => {
                if (!this.$el?.isConnected) {
                    return;
                }

                this.cloudRenderer = createAssistantCloudRenderer(this.$el);
                this.cloudRenderer?.start();
            });
        },

        destroy() {
            this.cloudRenderer?.destroy();
            this.cloudRenderer = null;
        },
    };
}
