const TAU = Math.PI * 2;
const GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));

const RT_RED_PATH = 'M0 0H738L862 152V462L755 583H617L1000 1000H731L460 658V410H675L715 364V206L673 160H0Z';
const RT_RED_STEM_PATH = 'M0 410H205V1000H0Z';
const RT_DARK_PATH = 'M0 200H670V370H420V1000H245V370H0Z';

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

function fallbackLogoTargets(count) {
    const segments = [
        { from: [-0.58, -0.62], to: [-0.58, 0.62], tone: 'red' },
        { from: [-0.58, -0.62], to: [0.02, -0.62], tone: 'red' },
        { from: [0.02, -0.62], to: [0.2, -0.43], tone: 'red' },
        { from: [0.2, -0.43], to: [0.2, -0.08], tone: 'red' },
        { from: [0.2, -0.08], to: [0, 0.1], tone: 'red' },
        { from: [-0.58, 0.1], to: [0, 0.1], tone: 'red' },
        { from: [-0.02, 0.1], to: [0.48, 0.62], tone: 'red' },
        { from: [-0.48, -0.34], to: [0.55, -0.34], tone: 'slate' },
        { from: [0.04, -0.34], to: [0.04, 0.62], tone: 'slate' },
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

function shuffleDeterministically(values) {
    const shuffled = [...values];
    let seed = 0x52a17e1;

    for (let index = shuffled.length - 1; index > 0; index -= 1) {
        seed = ((seed * 1664525) + 1013904223) >>> 0;
        const swapIndex = seed % (index + 1);
        [shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[index]];
    }

    return shuffled;
}

function createLogoTargets(canvas, count) {
    const view = canvas.ownerDocument?.defaultView || window;
    const Path = view.Path2D;

    if (typeof Path !== 'function') {
        return fallbackLogoTargets(count);
    }

    try {
        const scratch = canvas.ownerDocument.createElement('canvas');
        const scratchContext = scratch.getContext('2d');

        if (!scratchContext || typeof scratchContext.isPointInPath !== 'function') {
            return fallbackLogoTargets(count);
        }

        const redFace = new Path(RT_RED_PATH);
        const redStem = new Path(RT_RED_STEM_PATH);
        const darkFace = new Path(RT_DARK_PATH);
        const candidates = [];

        for (let y = 14; y <= 986; y += 24) {
            for (let x = 14; x <= 986; x += 24) {
                const inDark = scratchContext.isPointInPath(darkFace, x, y);
                const inRed = scratchContext.isPointInPath(redFace, x, y)
                    || scratchContext.isPointInPath(redStem, x, y);

                if (!inRed && !inDark) {
                    continue;
                }

                candidates.push({
                    x: ((x / 1000) - 0.5) * 1.34,
                    y: ((y / 1000) - 0.5) * 1.34,
                    // Das T liegt im echten Logo ueber dem roten R.
                    tone: inDark ? 'slate' : 'red',
                });
            }
        }

        if (candidates.length < 20) {
            return fallbackLogoTargets(count);
        }

        const shuffled = shuffleDeterministically(candidates);

        return Array.from({ length: count }, (_, index) => {
            const source = shuffled[index % shuffled.length];
            const jitter = index >= shuffled.length ? 0.006 : 0;

            return {
                x: source.x + ((deterministicNoise(index, 17) - 0.5) * jitter),
                y: source.y + ((deterministicNoise(index, 23) - 0.5) * jitter),
                tone: source.tone,
            };
        });
    } catch (_) {
        return fallbackLogoTargets(count);
    }
}

function createParticles(canvas, count) {
    const sphere = createFibonacciSphere(count);
    const logoTargets = createLogoTargets(canvas, count);

    return sphere.map((point, index) => {
        const galaxyRadius = Math.sqrt((index + 0.5) / count);
        const arm = index % 3;
        const galaxyAngle = (galaxyRadius * TAU * 2.15) + (arm * TAU / 3);

        return {
            ...point,
            galaxyX: Math.cos(galaxyAngle) * galaxyRadius * 1.52,
            galaxyY: Math.sin(galaxyAngle) * galaxyRadius * 0.48,
            galaxyZ: (deterministicNoise(index, 3) - 0.5) * 0.2,
            assembleDelay: deterministicNoise(index, 5) * 115,
            brightness: deterministicNoise(index, 7),
            pulse: deterministicNoise(index, 11) * TAU,
            logo: logoTargets[index],
            screenX: 0,
            screenY: 0,
            screenSize: 1,
            screenAlpha: 0,
            depth: 0,
            outroX: 0,
            outroY: 0,
            outroSize: 1,
            outroAlpha: 0,
        };
    });
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
    const particles = createParticles(canvas, particleCount);
    const drawOrder = [...particles];
    let animationFrame = null;
    let phase = 'idle';
    let startedAt = 0;
    let phaseStartedAt = 0;
    let running = false;
    let cssSize = 144;
    let pixelRatio = 1;
    let isDark = false;
    let themeCheckedAt = 0;
    let outroDuration = 0;

    function resizeCanvas() {
        const rect = canvas.getBoundingClientRect();
        const nextSize = Math.max(1, Math.min(rect.width || 144, rect.height || rect.width || 144));
        const nextDpr = clampParticleDpr(view.devicePixelRatio);
        const pixelSize = Math.max(1, Math.round(nextSize * nextDpr));

        cssSize = nextSize;
        pixelRatio = nextDpr;

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

    function projectSphere(particle, timestamp) {
        const elapsed = timestamp - startedAt;
        const yaw = (elapsed * 0.00034) + 0.55;
        const tilt = -0.24 + (Math.sin(elapsed * 0.0007) * 0.055);
        const cosYaw = Math.cos(yaw);
        const sinYaw = Math.sin(yaw);
        const cosTilt = Math.cos(tilt);
        const sinTilt = Math.sin(tilt);
        const rotatedX = (particle.x * cosYaw) + (particle.z * sinYaw);
        const rotatedZ = (-particle.x * sinYaw) + (particle.z * cosYaw);
        const rotatedY = (particle.y * cosTilt) - (rotatedZ * sinTilt);
        const depth = (particle.y * sinTilt) + (rotatedZ * cosTilt);
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

    function updateSphereParticle(particle, timestamp, assemble) {
        const radius = cssSize * 0.31;
        const center = cssSize / 2;
        const projected = projectSphere(particle, timestamp);
        const elapsed = timestamp - startedAt;
        const localAssemble = easeOutQuint((elapsed - particle.assembleDelay) / 405);
        const resolvedAssemble = Math.min(assemble, localAssemble);
        const galaxyRotation = elapsed * 0.00016;
        const cosGalaxy = Math.cos(galaxyRotation);
        const sinGalaxy = Math.sin(galaxyRotation);
        const galaxyX = (particle.galaxyX * cosGalaxy) - (particle.galaxyY * sinGalaxy);
        const galaxyY = (particle.galaxyX * sinGalaxy) + (particle.galaxyY * cosGalaxy);
        const twinkle = 0.88 + (Math.sin((elapsed * 0.0032) + particle.pulse) * 0.12);
        const depthScale = clamp((projected.depth + 1) / 2);

        particle.screenX = center + (mix(galaxyX, projected.x, resolvedAssemble) * radius);
        particle.screenY = center + (mix(galaxyY, projected.y, resolvedAssemble) * radius);
        particle.screenSize = mix(0.55, 1.08 + (depthScale * 1.25), resolvedAssemble) * twinkle;
        particle.screenAlpha = mix(0.08, 0.28 + (depthScale * 0.72), resolvedAssemble) * twinkle;
        particle.depth = mix(particle.galaxyZ, projected.depth, resolvedAssemble);
    }

    function captureOutroPose() {
        particles.forEach((particle) => {
            particle.outroX = particle.screenX;
            particle.outroY = particle.screenY;
            particle.outroSize = particle.screenSize;
            particle.outroAlpha = particle.screenAlpha;
        });
    }

    function updateOutroParticle(particle, timestamp, quick) {
        const elapsed = timestamp - phaseStartedAt;
        const center = cssSize / 2;

        if (quick) {
            const progress = easeInOutCubic(elapsed / outroDuration);
            particle.screenX = mix(particle.outroX, center, progress * 0.12);
            particle.screenY = mix(particle.outroY, center, progress * 0.12);
            particle.screenSize = particle.outroSize * mix(1, 0.66, progress);
            particle.screenAlpha = particle.outroAlpha * (1 - progress);
            particle.depth = 0;

            return;
        }

        const morph = easeInOutCubic(elapsed / 225);
        const dissolve = easeInOutCubic((elapsed - 255) / 165);
        const targetX = center + (particle.logo.x * cssSize * 0.42);
        const targetY = center + (particle.logo.y * cssSize * 0.42);
        const outwardX = targetX - center;
        const outwardY = targetY - center;

        particle.screenX = mix(particle.outroX, targetX, morph) + (outwardX * dissolve * 0.075);
        particle.screenY = mix(particle.outroY, targetY, morph) + (outwardY * dissolve * 0.075);
        particle.screenSize = mix(particle.outroSize, particle.logo.tone === 'slate' ? 1.55 : 1.75, morph)
            * mix(1, 0.62, dissolve);
        particle.screenAlpha = mix(particle.outroAlpha, 0.96, morph) * (1 - dissolve);
        particle.depth = 0;
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
        const assemble = easeOutQuint(elapsed / 430);
        const isQuickOutro = phase === 'quick-outro';
        const isFullOutro = phase === 'logo-outro';

        particles.forEach((particle) => {
            if (isQuickOutro || isFullOutro) {
                updateOutroParticle(particle, timestamp, isQuickOutro);
            } else {
                updateSphereParticle(particle, timestamp, assemble);
            }
        });

        const orbitOpacity = isQuickOutro || isFullOutro
            ? 1 - clamp((timestamp - phaseStartedAt) / 150)
            : assemble;
        drawOrbit(timestamp, orbitOpacity);

        drawOrder.sort((left, right) => left.depth - right.depth);
        drawOrder.forEach((particle) => {
            if (particle.screenAlpha <= 0.01) {
                return;
            }

            const tone = isFullOutro && ((timestamp - phaseStartedAt) > 80)
                ? particle.logo.tone
                : 'red';
            const color = particleColor(particle, particle.depth, tone);

            context.beginPath();
            context.arc(particle.screenX, particle.screenY, particle.screenSize, 0, TAU);
            context.fillStyle = `rgba(${color}, ${clamp(particle.screenAlpha, 0, 1)})`;
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

        if (
            (phase === 'quick-outro' || phase === 'logo-outro')
            && (timestamp - phaseStartedAt) >= outroDuration
        ) {
            running = false;

            return;
        }

        queueFrame();
    }

    function renderStaticSphere() {
        resizeCanvas();
        startedAt = view.performance.now() - 800;
        updateTheme(view.performance.now());
        particles.forEach((particle) => updateSphereParticle(particle, startedAt + 800, 1));
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
            resizeCanvas();
            context.clearRect(0, 0, cssSize, cssSize);
        }
    }

    function start() {
        stop();
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

        phase = 'sphere';
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
        phase = visibleDuration >= 460 ? 'logo-outro' : 'quick-outro';
        outroDuration = phase === 'logo-outro' ? 420 : 180;
        queueFrame();

        return {
            mode: phase === 'logo-outro' ? 'logo' : 'quick',
            duration: outroDuration,
        };
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

    documentObject.addEventListener('visibilitychange', handleVisibilityChange);

    return {
        available: true,
        start,
        stop,
        leave,
    };
}
