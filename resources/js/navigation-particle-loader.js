const TAU = Math.PI * 2;
const GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));

// Choreografie: Der Loader BEGINNT mit dem fertig geformten RT-Monogramm,
// loest es zur Kugel auf und formt es beim Verlassen wieder zurueck. Die
// Mindestlaufzeit ist exakt auf diese Phasen abgestimmt (siehe
// resolveMinimumLoaderPlaybackDelay): endet die Navigation noch waehrend
// der RT-Haltephase, blendet der Loader direkt aus dem Monogramm aus —
// das RT ist damit in JEDEM Durchlauf sichtbar.
export const NAVIGATION_LOADER_INTRO_HOLD_MS = 360;
export const NAVIGATION_LOADER_INTRO_MORPH_MS = 460;
export const NAVIGATION_LOADER_INTRO_STAGGER_MS = 115;
export const NAVIGATION_LOADER_SPHERE_DWELL_MS = 320;
export const NAVIGATION_LOADER_MIN_PLAYBACK_MS = NAVIGATION_LOADER_INTRO_HOLD_MS
    + NAVIGATION_LOADER_INTRO_MORPH_MS
    + NAVIGATION_LOADER_INTRO_STAGGER_MS
    + NAVIGATION_LOADER_SPHERE_DWELL_MS;

const OUTRO_FROM_LOGO = { morphMs: 0, holdMs: 140, fadeMs: 220 };
const OUTRO_FROM_SPHERE = { morphMs: 300, holdMs: 320, fadeMs: 180 };

const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));
const mix = (from, to, progress) => from + ((to - from) * progress);
const easeOutQuint = (value) => 1 - Math.pow(1 - clamp(value), 5);
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

export function clampParticleDpr(value) {
    const parsed = Number(value);

    if (!Number.isFinite(parsed) || parsed <= 0) {
        return 1;
    }

    return Math.min(parsed, 2);
}

export function resolveMinimumLoaderPlaybackDelay(
    visibleStartedAt,
    now,
    {
        reducedMotion = false,
        forcedColors = false,
        minimumMs = NAVIGATION_LOADER_MIN_PLAYBACK_MS,
    } = {}
) {
    if (
        reducedMotion
        || forcedColors
        || !Number.isFinite(visibleStartedAt)
        || !Number.isFinite(now)
    ) {
        return 0;
    }

    const elapsed = Math.max(0, now - visibleStartedAt);

    // Noch in der RT-Haltephase: das Monogramm steht bereits perfekt — der
    // Loader darf sofort daraus ausblenden. Warten wuerde schnelle
    // Seitenwechsel nur kuenstlich verlangsamen.
    if (elapsed <= NAVIGATION_LOADER_INTRO_HOLD_MS) {
        return 0;
    }

    // Mitten in der Aufloesung zur Kugel gibt es keinen wuerdigen Ausstieg:
    // erst die Kugel fertig formen und kurz atmen lassen, dann zurueck zum RT.
    const resolvedMinimum = Number.isFinite(minimumMs) && minimumMs > 0
        ? minimumMs
        : NAVIGATION_LOADER_MIN_PLAYBACK_MS;

    return Math.max(0, resolvedMinimum - elapsed);
}

export function resolveParticleBudget({
    viewportWidth = 1280,
    saveData = false,
    hardwareConcurrency = 8,
} = {}) {
    if (saveData) {
        return 132;
    }

    if (viewportWidth < 640 || hardwareConcurrency <= 4) {
        return 180;
    }

    if (viewportWidth < 1024) {
        return 236;
    }

    return 300;
}

export function createFibonacciSphere(count) {
    const resolvedCount = Math.max(1, Math.floor(Number(count) || 1));

    return Array.from({ length: resolvedCount }, (_, index) => {
        const y = 1 - (2 * (index + 0.5) / resolvedCount);
        const ringRadius = Math.sqrt(Math.max(0, 1 - (y * y)));
        const angle = index * GOLDEN_ANGLE;

        return {
            x: Math.cos(angle) * ringRadius,
            y,
            z: Math.sin(angle) * ringRadius,
        };
    });
}

export function createRailTimeLogoTargets(count) {
    const segments = [
        // Offenes, linienbasiertes R bleibt auch in einer nur rund 90 px
        // grossen Partikelwolke klarer als eine gefuellte Logo-Silhouette.
        { from: [-0.61, -0.62], to: [-0.61, 0.62], tone: 'red' },
        { from: [-0.61, -0.62], to: [-0.14, -0.62], tone: 'red' },
        { from: [-0.14, -0.62], to: [0.02, -0.46], tone: 'red' },
        { from: [0.02, -0.46], to: [0.02, -0.16], tone: 'red' },
        { from: [0.02, -0.16], to: [-0.14, 0], tone: 'red' },
        { from: [-0.61, 0], to: [-0.14, 0], tone: 'red' },
        { from: [-0.19, 0], to: [0.08, 0.62], tone: 'red' },
        // Das T steht bewusst separat: RailTime-Monogramm statt dichter
        // Ueberlagerung beider Originalflaechen.
        { from: [0.14, -0.62], to: [0.66, -0.62], tone: 'slate' },
        { from: [0.4, -0.62], to: [0.4, 0.62], tone: 'slate' },
    ];
    const pointsPerSegment = Math.ceil(count / segments.length);

    return Array.from({ length: count }, (_, index) => {
        const segment = segments[index % segments.length];
        const position = Math.floor(index / segments.length);
        const progress = (position + 0.5) / pointsPerSegment;

        return {
            x: mix(segment.from[0], segment.to[0], progress),
            y: mix(segment.from[1], segment.to[1], progress),
            tone: segment.tone,
        };
    });
}

function createParticles(count) {
    const sphere = createFibonacciSphere(count);
    const logoTargets = createRailTimeLogoTargets(count);

    return sphere.map((point, index) => ({
        ...point,
        morphDelay: deterministicNoise(index, 5) * NAVIGATION_LOADER_INTRO_STAGGER_MS,
        brightness: deterministicNoise(index, 7),
        pulse: deterministicNoise(index, 11) * TAU,
        logo: logoTargets[index],
        // Vorberechnete RT-Zielkoordinaten in CSS-Pixeln. Sie werden nur bei
        // einer echten Groessenaenderung des Canvas neu geschrieben — nie im
        // Frame-Takt (Cache statt Rechnen, siehe cacheLogoPositions).
        logoScreenX: 0,
        logoScreenY: 0,
        logoSize: logoTargets[index].tone === 'slate' ? 1.55 : 1.75,
        screenX: 0,
        screenY: 0,
        screenSize: 1,
        screenAlpha: 0,
        depth: 0,
        tone: 'red',
        outroX: 0,
        outroY: 0,
        outroSize: 1,
        outroAlpha: 0,
    }));
}

function unavailableController() {
    return {
        available: false,
        start() {},
        stop() {},
        leave() {
            return { mode: 'fallback', duration: 160 };
        },
    };
}

export function createNavigationParticleSphere(canvas, options = {}) {
    const view = options.windowObject || canvas.ownerDocument?.defaultView || window;
    const documentObject = options.documentObject || canvas.ownerDocument || document;
    let context = null;

    try {
        context = canvas.getContext('2d', { alpha: true, desynchronized: true });
    } catch (_) {
        return unavailableController();
    }

    if (!context) {
        return unavailableController();
    }

    const reducedMotion = view.matchMedia('(prefers-reduced-motion: reduce)');
    const forcedColors = view.matchMedia('(forced-colors: active)');
    const connection = view.navigator?.connection;
    const particleCount = resolveParticleBudget({
        viewportWidth: view.innerWidth,
        saveData: connection?.saveData === true,
        hardwareConcurrency: view.navigator?.hardwareConcurrency || 8,
    });
    const particles = createParticles(particleCount);
    const drawOrder = [...particles];
    const introMorphEndMs = NAVIGATION_LOADER_INTRO_HOLD_MS
        + NAVIGATION_LOADER_INTRO_MORPH_MS
        + NAVIGATION_LOADER_INTRO_STAGGER_MS;
    let animationFrame = null;
    let phase = 'idle';
    let startedAt = 0;
    let phaseStartedAt = 0;
    let outroPlan = OUTRO_FROM_SPHERE;
    let running = false;
    let cssSize = 144;
    let pixelRatio = 1;
    let isDark = false;
    let themeCheckedAt = 0;
    let outroDuration = 0;
    let resizePending = true;

    function cacheLogoPositions() {
        const center = cssSize / 2;
        const scale = cssSize * 0.42;

        particles.forEach((particle) => {
            particle.logoScreenX = center + (particle.logo.x * scale);
            particle.logoScreenY = center + (particle.logo.y * scale);
        });
    }

    function resizeCanvas(force = false) {
        const nextDpr = clampParticleDpr(view.devicePixelRatio);

        // Die Loader-Huelle skaliert beim Ein-/Ausblenden per transform. Ein
        // getBoundingClientRect() pro Frame wuerde diese Animation als echte
        // Groessenaenderung missverstehen und den Canvas-Backbuffer staendig
        // neu allozieren. clientWidth bleibt transform-unabhaengig und wird
        // nur nach einem realen Viewport-Wechsel erneut gelesen.
        if (!force && !resizePending && nextDpr === pixelRatio) {
            return;
        }

        const measuredWidth = canvas.clientWidth;
        const measuredHeight = canvas.clientHeight;
        const nextSize = Math.max(
            1,
            Math.min(
                measuredWidth || cssSize || 144,
                measuredHeight || measuredWidth || cssSize || 144
            )
        );
        const pixelSize = Math.max(1, Math.round(nextSize * nextDpr));

        cssSize = nextSize;
        pixelRatio = nextDpr;
        resizePending = false;
        cacheLogoPositions();

        if (canvas.width !== pixelSize || canvas.height !== pixelSize) {
            canvas.width = pixelSize;
            canvas.height = pixelSize;
        }

        context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    }

    function updateTheme(timestamp) {
        if (timestamp - themeCheckedAt < 500) {
            return;
        }

        themeCheckedAt = timestamp;
        isDark = documentObject.documentElement.classList.contains('dark')
            || documentObject.body?.dataset.mode === 'dark';
    }

    // Die Rotationswinkel gelten fuer ALLE Partikel eines Frames. Einmal pro
    // Frame berechnen statt einmal pro Partikel spart bei 300 Partikeln rund
    // 1800 trigonometrische Aufrufe je Frame — der frueher sichtbare
    // Mikro-Ruckler auf schwacher Hardware kam genau daher.
    function frameRotation(elapsed) {
        const yaw = (elapsed * 0.00034) + 0.55;
        const tilt = -0.24 + (Math.sin(elapsed * 0.0007) * 0.055);

        return {
            cosYaw: Math.cos(yaw),
            sinYaw: Math.sin(yaw),
            cosTilt: Math.cos(tilt),
            sinTilt: Math.sin(tilt),
        };
    }

    function projectSphere(particle, rotation) {
        const rotatedX = (particle.x * rotation.cosYaw) + (particle.z * rotation.sinYaw);
        const rotatedZ = (-particle.x * rotation.sinYaw) + (particle.z * rotation.cosYaw);
        const rotatedY = (particle.y * rotation.cosTilt) - (rotatedZ * rotation.sinTilt);
        const depth = (particle.y * rotation.sinTilt) + (rotatedZ * rotation.cosTilt);
        const perspective = 1 / (1.58 - (depth * 0.3));

        return {
            x: rotatedX * perspective * 1.28,
            y: rotatedY * perspective * 1.28,
            depth,
        };
    }

    function particleColor(particle, depth, tone = 'red') {
        if (tone === 'slate') {
            return isDark ? '210, 220, 232' : '38, 47, 59';
        }

        if (particle.brightness > 0.955 && depth > 0) {
            return '255, 244, 247';
        }

        if (depth > 0.35) {
            return '255, 76, 105';
        }

        if (depth < -0.42) {
            return isDark ? '145, 0, 39' : '176, 0, 42';
        }

        return '228, 0, 43';
    }

    function updateLogoHoldParticle(particle, elapsed) {
        const shimmer = 0.94 + (Math.sin((elapsed * 0.0028) + particle.pulse) * 0.06);
        const appear = easeOutQuint(elapsed / 160);

        particle.screenX = particle.logoScreenX;
        particle.screenY = particle.logoScreenY;
        particle.screenSize = particle.logoSize * shimmer;
        particle.screenAlpha = 0.96 * appear * shimmer;
        particle.depth = 0;
        particle.tone = particle.logo.tone;
    }

    function updateSphereParticle(particle, elapsed, rotation) {
        const radius = cssSize * 0.35;
        const center = cssSize / 2;
        const projected = projectSphere(particle, rotation);
        const twinkle = 0.88 + (Math.sin((elapsed * 0.0032) + particle.pulse) * 0.12);
        const depthScale = clamp((projected.depth + 1) / 2);

        particle.screenX = center + (projected.x * radius);
        particle.screenY = center + (projected.y * radius);
        particle.screenSize = (1.08 + (depthScale * 1.25)) * twinkle;
        particle.screenAlpha = (0.28 + (depthScale * 0.72)) * twinkle;
        particle.depth = projected.depth;
        particle.tone = 'red';
    }

    function updateIntroMorphParticle(particle, elapsed, rotation) {
        const local = easeInOutCubic(
            (elapsed - NAVIGATION_LOADER_INTRO_HOLD_MS - particle.morphDelay)
                / NAVIGATION_LOADER_INTRO_MORPH_MS
        );

        if (local <= 0) {
            updateLogoHoldParticle(particle, elapsed);

            return;
        }

        if (local >= 1) {
            updateSphereParticle(particle, elapsed, rotation);

            return;
        }

        const radius = cssSize * 0.35;
        const center = cssSize / 2;
        const projected = projectSphere(particle, rotation);
        const twinkle = 0.88 + (Math.sin((elapsed * 0.0032) + particle.pulse) * 0.12);
        const depthScale = clamp((projected.depth + 1) / 2);
        const sphereSize = (1.08 + (depthScale * 1.25)) * twinkle;
        const sphereAlpha = (0.28 + (depthScale * 0.72)) * twinkle;

        particle.screenX = mix(particle.logoScreenX, center + (projected.x * radius), local);
        particle.screenY = mix(particle.logoScreenY, center + (projected.y * radius), local);
        particle.screenSize = mix(particle.logoSize, sphereSize, local);
        particle.screenAlpha = mix(0.96, sphereAlpha, local);
        particle.depth = mix(0, projected.depth, local);
        particle.tone = local < 0.5 ? particle.logo.tone : 'red';
    }

    function captureOutroPose() {
        particles.forEach((particle) => {
            particle.outroX = particle.screenX;
            particle.outroY = particle.screenY;
            particle.outroSize = particle.screenSize;
            particle.outroAlpha = particle.screenAlpha;
        });
    }

    function updateOutroParticle(particle, timestamp) {
        const elapsed = timestamp - phaseStartedAt;
        const morph = outroPlan.morphMs > 0
            ? easeInOutCubic(elapsed / outroPlan.morphMs)
            : 1;
        const fade = easeInOutCubic(
            (elapsed - outroPlan.morphMs - outroPlan.holdMs) / outroPlan.fadeMs
        );
        const shimmer = 0.94 + (Math.sin((timestamp * 0.0028) + particle.pulse) * 0.06);
        const outwardX = particle.logoScreenX - (cssSize / 2);
        const outwardY = particle.logoScreenY - (cssSize / 2);

        particle.screenX = mix(particle.outroX, particle.logoScreenX, morph)
            + (outwardX * fade * 0.06);
        particle.screenY = mix(particle.outroY, particle.logoScreenY, morph)
            + (outwardY * fade * 0.06);
        particle.screenSize = mix(particle.outroSize, particle.logoSize, morph)
            * mix(1, 0.82, fade) * shimmer;
        particle.screenAlpha = mix(particle.outroAlpha, 0.96, morph) * (1 - fade);
        particle.depth = 0;
        particle.tone = morph > 0.45 ? particle.logo.tone : 'red';
    }

    function drawOrbit(timestamp, opacity) {
        if (opacity <= 0) {
            return;
        }

        const elapsed = timestamp - startedAt;
        const center = cssSize / 2;
        const radius = cssSize * 0.39;

        for (let index = 0; index < 34; index += 1) {
            const angle = (index / 34 * TAU) + (elapsed * 0.00024);
            const wave = 0.7 + (Math.sin((index * 1.73) + (elapsed * 0.002)) * 0.3);
            const x = center + (Math.cos(angle) * radius);
            const y = center + (Math.sin(angle) * radius * 0.26) - (Math.cos(angle) * radius * 0.12);
            const dotSize = 0.42 + (wave * 0.55);

            context.beginPath();
            context.arc(x, y, dotSize, 0, TAU);
            context.fillStyle = `rgba(228, 0, 43, ${0.08 + (wave * 0.2 * opacity)})`;
            context.fill();
        }
    }

    function draw(timestamp) {
        resizeCanvas();
        updateTheme(timestamp);
        context.clearRect(0, 0, cssSize, cssSize);

        const elapsed = timestamp - startedAt;
        const isOutro = phase === 'logo-outro';
        let needsDepthSort = false;
        let orbitOpacity = 0;

        if (isOutro) {
            particles.forEach((particle) => updateOutroParticle(particle, timestamp));
            orbitOpacity = 1 - clamp((timestamp - phaseStartedAt) / 150);
        } else if (elapsed <= NAVIGATION_LOADER_INTRO_HOLD_MS) {
            particles.forEach((particle) => updateLogoHoldParticle(particle, elapsed));
        } else if (elapsed < introMorphEndMs) {
            const rotation = frameRotation(elapsed);

            particles.forEach((particle) => updateIntroMorphParticle(particle, elapsed, rotation));
            needsDepthSort = true;
            orbitOpacity = easeInOutCubic(
                (elapsed - NAVIGATION_LOADER_INTRO_HOLD_MS) / NAVIGATION_LOADER_INTRO_MORPH_MS
            );
        } else {
            const rotation = frameRotation(elapsed);

            particles.forEach((particle) => updateSphereParticle(particle, elapsed, rotation));
            needsDepthSort = true;
            orbitOpacity = 1;
        }

        drawOrbit(timestamp, orbitOpacity);

        // Waehrend der reinen Logo-Phasen liegt jede Tiefe bei 0 — eine
        // Sortierung waere dann nur Arbeit ohne sichtbaren Effekt.
        if (needsDepthSort) {
            drawOrder.sort((left, right) => left.depth - right.depth);
        }

        let lastFillStyle = '';

        drawOrder.forEach((particle) => {
            if (particle.screenAlpha <= 0.01) {
                return;
            }

            const color = particleColor(particle, particle.depth, particle.tone);
            // Auf zwei Nachkommastellen gerundet buendeln sich viele Partikel
            // auf denselben Stil — jede vermiedene fillStyle-Zuweisung spart
            // einen teuren Canvas-State-Wechsel.
            const alpha = Math.round(clamp(particle.screenAlpha, 0, 1) * 100) / 100;
            const fillStyle = `rgba(${color}, ${alpha})`;

            context.beginPath();
            context.arc(particle.screenX, particle.screenY, particle.screenSize, 0, TAU);

            if (fillStyle !== lastFillStyle) {
                context.fillStyle = fillStyle;
                lastFillStyle = fillStyle;
            }

            context.fill();
        });
    }

    function queueFrame() {
        if (!running || animationFrame !== null || documentObject.visibilityState === 'hidden') {
            return;
        }

        animationFrame = view.requestAnimationFrame(render);
    }

    function render(timestamp) {
        animationFrame = null;

        if (!running) {
            return;
        }

        draw(timestamp);

        if (phase === 'logo-outro' && (timestamp - phaseStartedAt) >= outroDuration) {
            running = false;

            return;
        }

        queueFrame();
    }

    function renderStaticSphere() {
        resizeCanvas(true);
        const now = view.performance.now();
        startedAt = now - 800;
        updateTheme(now);
        const rotation = frameRotation(800);
        particles.forEach((particle) => updateSphereParticle(particle, 800, rotation));
        context.clearRect(0, 0, cssSize, cssSize);
        drawOrder.sort((left, right) => left.depth - right.depth);
        drawOrder.forEach((particle) => {
            context.beginPath();
            context.arc(particle.screenX, particle.screenY, particle.screenSize, 0, TAU);
            context.fillStyle = `rgba(${particleColor(particle, particle.depth)}, ${clamp(particle.screenAlpha, 0, 1)})`;
            context.fill();
        });
    }

    function stop({ clear = true } = {}) {
        running = false;
        phase = 'idle';

        if (animationFrame !== null) {
            view.cancelAnimationFrame(animationFrame);
            animationFrame = null;
        }

        if (clear) {
            resizeCanvas(true);
            context.clearRect(0, 0, cssSize, cssSize);
        }
    }

    function start() {
        stop();
        resizePending = true;
        startedAt = view.performance.now();
        phaseStartedAt = startedAt;
        isDark = documentObject.documentElement.classList.contains('dark')
            || documentObject.body?.dataset.mode === 'dark';
        themeCheckedAt = startedAt;

        if (reducedMotion.matches || forcedColors.matches) {
            phase = 'static';
            renderStaticSphere();

            return;
        }

        phase = 'intro';
        running = true;
        queueFrame();
    }

    function leave() {
        if (reducedMotion.matches || forcedColors.matches) {
            return { mode: 'reduced', duration: 0 };
        }

        const now = view.performance.now();
        const visibleDuration = Math.max(0, now - startedAt);

        if (!running) {
            return { mode: 'fallback', duration: 160 };
        }

        captureOutroPose();
        phaseStartedAt = now;
        phase = 'logo-outro';
        // Steht das Monogramm noch (RT-Haltephase), reicht ein kurzer
        // Halte-plus-Ausblendmoment. Aus jeder spaeteren Pose formt sich das
        // RT erst zurueck und bleibt dann bewusst lange genug stehen.
        outroPlan = visibleDuration <= NAVIGATION_LOADER_INTRO_HOLD_MS
            ? OUTRO_FROM_LOGO
            : OUTRO_FROM_SPHERE;
        outroDuration = outroPlan.morphMs + outroPlan.holdMs + outroPlan.fadeMs;
        queueFrame();

        return { mode: 'logo', duration: outroDuration };
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

    function handleViewportResize() {
        resizePending = true;

        if (phase === 'static') {
            renderStaticSphere();

            return;
        }

        queueFrame();
    }

    documentObject.addEventListener('visibilitychange', handleVisibilityChange);
    view.addEventListener?.('resize', handleViewportResize, { passive: true });

    return {
        available: true,
        start,
        stop,
        leave,
    };
}
