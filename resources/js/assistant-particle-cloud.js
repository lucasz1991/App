/**
 * AI-Partikelwolke des Assistenten — Zustands-Choreografien.
 *
 * Jeder Assistenten-Zustand (petState() in chatbot.js) hat eine EIGENE
 * Bewegungssprache und eine leicht verschobene Farbwelt:
 *
 *   idle      Atmen        ruhige Wolke, klassisches RailTime-Rot
 *   listening Fokus-Ring   Partikel ziehen sich zu einem pulsierenden
 *                          Ring zusammen, Palette kippt ins Rosa
 *   speaking  Schallwellen radiale Wellen laufen nach aussen, warmes Koralle
 *   thinking  Wirbel       die Wolke kreist als Strudel, violetter Einschlag
 *   curious   Neigen       die Wolke kippt neugierig hin und her, sattes Pink
 *   happy     Funkenregen  freudiges Zittern mit Gold-Funken
 *   wave      Winken       eine Querwelle laeuft durch die Wolke
 *   offline   Ruhen        fast still, entsaettigtes Graublau
 *
 * Zustandswechsel blenden Position UND Farbe weich ueber
 * (ASSISTANT_CLOUD_TRANSITION_MS). Der fruehere Wolke-zu-RT-Zyklus ist
 * bewusst entfernt. Das noch aeltere 3D-Maskottchen bleibt als Backup in
 * assistant-pet-3d.js liegen, ist aber nicht eingebunden.
 */

const TAU = Math.PI * 2;

export const ASSISTANT_CLOUD_PARTICLES = 110;
export const ASSISTANT_CLOUD_TRANSITION_MS = 650;

/**
 * Bewegungs- und Farbsprache je Zustand. Alle Werte sind bewusst als Daten
 * exportiert: Tests sichern damit ab, dass jeder Zustand eine EIGENE
 * Choreografie und eine EIGENE Palette traegt.
 *
 * palette: {main, deep, spark} als [r, g, b].
 */
export const ASSISTANT_CLOUD_STATE_STYLES = Object.freeze({
    idle: Object.freeze({
        breathAmp: 0.045, breathSpeed: 0.0011,
        swirl: 0.00003, ringPull: 0, rippleAmp: 0, waveAmp: 0,
        tiltAmp: 0.02, jitter: 0, sparkle: 1, dim: 1,
        palette: Object.freeze({ main: [228, 0, 43], deep: [176, 0, 42], spark: [255, 244, 247] }),
    }),
    listening: Object.freeze({
        breathAmp: 0.06, breathSpeed: 0.003,
        swirl: 0.00002, ringPull: 0.55, rippleAmp: 0, waveAmp: 0,
        tiltAmp: 0, jitter: 0, sparkle: 1.15, dim: 1,
        palette: Object.freeze({ main: [255, 64, 110], deep: [205, 20, 80], spark: [255, 235, 242] }),
    }),
    speaking: Object.freeze({
        breathAmp: 0.03, breathSpeed: 0.0016,
        swirl: 0.00004, ringPull: 0, rippleAmp: 0.16, waveAmp: 0,
        tiltAmp: 0, jitter: 0, sparkle: 1.25, dim: 1,
        palette: Object.freeze({ main: [255, 92, 60], deep: [210, 60, 20], spark: [255, 244, 230] }),
    }),
    thinking: Object.freeze({
        breathAmp: 0.02, breathSpeed: 0.0013,
        swirl: 0.00035, ringPull: 0.12, rippleAmp: 0, waveAmp: 0,
        tiltAmp: 0, jitter: 0.004, sparkle: 1.1, dim: 1,
        palette: Object.freeze({ main: [190, 70, 230], deep: [130, 40, 180], spark: [245, 238, 255] }),
    }),
    curious: Object.freeze({
        breathAmp: 0.05, breathSpeed: 0.002,
        swirl: 0.00008, ringPull: 0, rippleAmp: 0, waveAmp: 0,
        tiltAmp: 0.14, jitter: 0.008, sparkle: 1.45, dim: 1,
        palette: Object.freeze({ main: [255, 45, 96], deep: [200, 10, 70], spark: [255, 240, 246] }),
    }),
    happy: Object.freeze({
        breathAmp: 0.07, breathSpeed: 0.004,
        swirl: 0.00012, ringPull: 0, rippleAmp: 0, waveAmp: 0,
        tiltAmp: 0.04, jitter: 0.018, sparkle: 1.75, dim: 1,
        palette: Object.freeze({ main: [250, 105, 45], deep: [205, 60, 25], spark: [255, 228, 176] }),
    }),
    wave: Object.freeze({
        breathAmp: 0.04, breathSpeed: 0.0014,
        swirl: 0.00004, ringPull: 0, rippleAmp: 0, waveAmp: 0.13,
        tiltAmp: 0.03, jitter: 0, sparkle: 1.35, dim: 1,
        palette: Object.freeze({ main: [240, 20, 60], deep: [185, 0, 48], spark: [255, 246, 248] }),
    }),
    offline: Object.freeze({
        breathAmp: 0.015, breathSpeed: 0.0006,
        swirl: 0, ringPull: 0.1, rippleAmp: 0, waveAmp: 0,
        tiltAmp: 0, jitter: 0, sparkle: 0.4, dim: 0.7,
        palette: Object.freeze({ main: [122, 134, 150], deep: [88, 99, 114], spark: [176, 186, 199] }),
    }),
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

export function resolveAssistantCloudStyle(state) {
    return ASSISTANT_CLOUD_STATE_STYLES[state] || ASSISTANT_CLOUD_STATE_STYLES.idle;
}

/**
 * Ueberblendfortschritt eines Zustandswechsels (reine Funktion, testbar):
 * 0 = alter Zustand, 1 = neuer Zustand vollstaendig uebernommen.
 */
export function assistantCloudStyleBlend(msSinceChange, transitionMs = ASSISTANT_CLOUD_TRANSITION_MS) {
    if (!Number.isFinite(msSinceChange) || msSinceChange <= 0) {
        return 0;
    }

    return easeInOutCubic(msSinceChange / Math.max(1, transitionMs));
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

    return cloudTargets.map((target, index) => ({
        cloud: target,
        driftPhase: deterministicNoise(index, 31) * TAU,
        driftSpeed: 0.6 + (deterministicNoise(index, 32) * 0.8),
        driftAmount: 0.03 + (deterministicNoise(index, 33) * 0.05),
        size: 0.75 + (deterministicNoise(index, 34) * 1.05),
        alpha: 0.35 + (deterministicNoise(index, 35) * 0.6),
        // 0..1: Position innerhalb der Palette (deep -> main).
        tone: deterministicNoise(index, 36),
        pulse: deterministicNoise(index, 37) * TAU,
        jitterPhase: deterministicNoise(index, 38) * TAU,
    }));
}

/**
 * Position eines Partikels unter einer Zustands-Choreografie
 * (normierte Koordinaten, ohne Canvas-Skalierung).
 */
export function styledParticlePosition(particle, elapsed, style) {
    // Wispern der Wolke: jede Partikel treibt leicht um ihren Platz.
    const wispX = Math.sin((elapsed * 0.00042 * particle.driftSpeed) + particle.driftPhase)
        * particle.driftAmount;
    const wispY = Math.cos((elapsed * 0.00036 * particle.driftSpeed) + particle.driftPhase)
        * particle.driftAmount * 0.8;

    let x = particle.cloud.x + wispX;
    let y = particle.cloud.y + wispY;

    // Fokus-Ring (Zuhoeren): zum pulsierenden Ring hinziehen.
    if (style.ringPull > 0) {
        const radius = Math.hypot(x, y) || 0.0001;
        const ringRadius = 0.52 + (Math.sin(elapsed * 0.004) * 0.05);
        const pull = style.ringPull * (0.65 + (0.35 * Math.sin((elapsed * 0.003) + particle.pulse)));
        const scale = mix(1, ringRadius / radius, clamp(pull));
        x *= scale;
        y *= scale;
    }

    // Schallwellen (Sprechen): radiale Wellen laufen nach aussen.
    if (style.rippleAmp > 0) {
        const radius = Math.hypot(x, y);
        const ripple = Math.sin((radius * 9) - (elapsed * 0.012)) * style.rippleAmp;
        const scale = 1 + ripple;
        x *= scale;
        y *= scale;
    }

    // Winken: eine Querwelle wandert horizontal durch die Wolke.
    if (style.waveAmp > 0) {
        y += Math.sin((x * 3.2) - (elapsed * 0.01)) * style.waveAmp;
    }

    // Wirbel (Denken) bzw. langsames Kreisen.
    if (style.swirl > 0) {
        const angle = elapsed * style.swirl * TAU;
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);
        const rotatedX = (x * cos) - (y * sin);
        const rotatedY = (x * sin) + (y * cos);
        x = rotatedX;
        y = rotatedY;
    }

    // Neigen (Neugier): die ganze Wolke kippt hin und her.
    if (style.tiltAmp > 0) {
        const tilt = Math.sin(elapsed * 0.0021) * style.tiltAmp;
        const cos = Math.cos(tilt);
        const sin = Math.sin(tilt);
        const tiltedX = (x * cos) - (y * sin);
        const tiltedY = (x * sin) + (y * cos);
        x = tiltedX;
        y = tiltedY;
    }

    // Funkenregen (Freude) bzw. nervoeses Denk-Zittern.
    if (style.jitter > 0) {
        x += Math.sin((elapsed * 0.02) + particle.jitterPhase) * style.jitter;
        y += Math.cos((elapsed * 0.023) + (particle.jitterPhase * 1.7)) * style.jitter;
    }

    // Atmen: sanfte Gesamtskalierung.
    const breath = 1 + (Math.sin(elapsed * style.breathSpeed * TAU) * style.breathAmp);

    return { x: x * breath, y: y * breath };
}

function paletteColor(particle, palette) {
    if (particle.tone > 0.94) {
        return palette.spark;
    }

    const base = particle.tone / 0.94;

    return [
        mix(palette.deep[0], palette.main[0], base),
        mix(palette.deep[1], palette.main[1], base),
        mix(palette.deep[2], palette.main[2], base),
    ];
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
    // Zustandswechsel werden weich uebergeblendet.
    let currentState = 'idle';
    let previousState = 'idle';
    let stateChangedAt = 0;

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

    function drawSurface(surface, elapsed, fromStyle, toStyle, blend) {
        syncSurfaceSize(surface);

        const { context, cssSize } = surface;
        const center = cssSize / 2;
        const scale = cssSize * 0.44;
        const sparkle = mix(fromStyle.sparkle, toStyle.sparkle, blend);
        const dim = mix(fromStyle.dim, toStyle.dim, blend);

        context.clearRect(0, 0, cssSize, cssSize);

        particles.forEach((particle) => {
            const fromPosition = styledParticlePosition(particle, elapsed, fromStyle);
            const toPosition = styledParticlePosition(particle, elapsed, toStyle);
            const x = center + (mix(fromPosition.x, toPosition.x, blend) * scale);
            const y = center + (mix(fromPosition.y, toPosition.y, blend) * scale);

            const twinkle = 0.82 + (Math.sin((elapsed * 0.0024 * sparkle) + particle.pulse) * 0.18);
            const size = particle.size * twinkle * (cssSize / 96);
            const alpha = particle.alpha * 0.85 * twinkle * dim;

            const fromColor = paletteColor(particle, fromStyle.palette);
            const toColor = paletteColor(particle, toStyle.palette);
            const r = Math.round(mix(fromColor[0], toColor[0], blend));
            const g = Math.round(mix(fromColor[1], toColor[1], blend));
            const b = Math.round(mix(fromColor[2], toColor[2], blend));

            context.beginPath();
            context.arc(x, y, Math.max(0.3, size), 0, TAU);
            context.fillStyle = `rgba(${r}, ${g}, ${b}, ${clamp(alpha, 0, 1)})`;
            context.fill();
        });
    }

    function drawStaticCloud() {
        const style = ASSISTANT_CLOUD_STATE_STYLES.idle;

        surfaces.forEach((surface) => {
            syncSurfaceSize(surface);

            const { context, cssSize } = surface;
            const center = cssSize / 2;
            const scale = cssSize * 0.44;

            context.clearRect(0, 0, cssSize, cssSize);
            particles.forEach((particle) => {
                const color = paletteColor(particle, style.palette);

                context.beginPath();
                context.arc(
                    center + (particle.cloud.x * scale),
                    center + (particle.cloud.y * scale),
                    Math.max(0.3, particle.size * (cssSize / 96)),
                    0,
                    TAU
                );
                context.fillStyle = `rgba(${Math.round(color[0])}, ${Math.round(color[1])}, ${Math.round(color[2])}, ${clamp(particle.alpha, 0, 1)})`;
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

            if (state !== currentState) {
                previousState = currentState;
                currentState = state;
                stateChangedAt = timestamp;
            }

            const blend = assistantCloudStyleBlend(timestamp - stateChangedAt);
            const fromStyle = resolveAssistantCloudStyle(previousState);
            const toStyle = resolveAssistantCloudStyle(currentState);

            surfaces.forEach((surface) => {
                // Der Launcher ist bei geoeffnetem Panel ausgeblendet
                // (x-show) — zeichnen waere reine Waerme.
                if (surface.role === 'launcher' && open) {
                    return;
                }

                if (!surface.slot.isConnected) {
                    return;
                }

                drawSurface(surface, elapsed, fromStyle, toStyle, blend);
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
            drawStaticCloud();

            return;
        }

        startedAt = view.performance.now();
        lastFrameAt = 0;
        currentState = controllerElement.dataset.state || 'idle';
        previousState = currentState;
        stateChangedAt = startedAt;
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
