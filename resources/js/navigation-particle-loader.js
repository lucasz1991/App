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

/**
 * Zielkoordinaten der Partikelwolke: das ECHTE RT-Icon, je Partikelbudget
 * eine eigene Tabelle.
 *
 * Erzeugt aus Website/Shared/assets/icons/favicon.svg — den beiden roten
 * R-Pfaden und dem anthrazitfarbenen T.
 *
 * ZUR GEOMETRIE — nachgerechnet, nicht vermutet: R und T ueberlappen sich
 * NICHT. Die Schnittflaeche ist exakt 0 (Rasterprobe 1000x1000). Zwischen
 * beiden liegen durchgehend 40 Einheiten Luft, an der engsten Stelle 29,2.
 * Das Icon besteht aus drei getrennten Bloecken.
 *
 * WARUM VIER TABELLEN UND NICHT EINE
 *
 * Eine einzige Punktliste mit festem Radius war gestrichelt: bei 132
 * Partikeln lagen die Konturpunkte rund 8,5 px auseinander bei nur 3,5 px
 * Durchmesser. Der Radius muss also mit dem Punktabstand mitwachsen.
 *
 * Ein groesserer Radius auf KANTENPUNKTEN haette aber den 40-Einheiten-
 * Spalt zwischen R und T zugebrueckt. Deshalb liegen die Konturpunkte um
 * genau radiusUnits INNERHALB der jeweils eigenen Kante: der Scheibenrand
 * landet damit auf der wahren Kante, und der sichtbare Abstand zwischen R
 * und T bleibt der wahre Abstand — unabhaengig vom Radius.
 *
 * Gemessen am erzeugten Punktsatz (Abstand in Einheiten der 1000er-Box):
 *
 *   Budget  radiusUnits  Konturabstand  2r     verbleibende Luecke R/T
 *      132        38.34           80.6  76.7                      33.5
 *      180        28.07           56.1  56.1                      34.5
 *      236        20.57           42.0  41.1                      36.9
 *      300        17.23           35.4  34.5                      35.5
 *
 * Konturabstand ~ 2r heisst: die Scheiben beruehren sich, der Strich ist
 * geschlossen. Die Luecke bleibt bei rund 35 Einheiten — R und T kleben
 * nicht zusammen.
 *
 * ACHTUNG: radiusUnits ist eine EINHEIT, keine Pixelzahl. Die Umrechnung
 * steht in cacheLogoPositions(). Wer logoSize wieder auf eine Konstante
 * setzt, bekommt die gestrichelte Kontur zurueck — und kein Test schlaegt
 * an, ausser dem in navigation-particle-icon.test.js.
 *
 * Aufbau je Punkt: [x, y, ton] mit ton 0 = rot, 1 = anthrazit.
 * Erzeugt von rt-icon-sampler.mjs (.lmzdev/artifacts/partikel-icon/).
 */
const RT_ICON_BUDGETS = {
    132: {
        radiusUnits: 38.34,
        punkte: [
        [-0.6138, -0.0632, 0], [0.5592, 0.6188, 0], [0.3881, -0.4377, 0], [-0.5444, 0.0236, 0],
        [0.4972, 0.6188, 0], [-0.2691, 0.6188, 1], [0.1029, 0.1178, 0], [-0.1947, -0.47, 0],
        [0.3584, -0.4005, 0], [-0.1947, 0.6188, 1], [-0.6039, -0.47, 0], [-0.274, 0.2269, 1],
        [0.222, 0.4675, 0], [-0.2964, -0.6188, 0], [0.0434, 0.088, 0], [0.4055, -0.0781, 0],
        [-0.2691, -0.1252, 1], [0.0533, -0.2096, 1], [-0.6188, 0.4675, 0], [-0.217, -0.222, 1],
        [0.1203, -0.47, 0], [-0.4055, -0.3286, 1], [-0.4154, 0.4253, 0], [0.0682, -0.6188, 0],
        [0.2244, 0.3931, 0], [-0.1426, 0.4204, 1], [-0.4154, 0.0484, 0], [-0.1426, 0.0533, 1],
        [-0.6188, -0.5121, 0], [0.3435, -0.0632, 0], [0.1996, -0.0632, 0], [0.4228, 0.47, 0],
        [0.2368, 0.2666, 0], [-0.2269, 0.2864, 1], [0.088, -0.2542, 1], [0.3088, -0.2542, 0],
        [0.2517, -0.6188, 0], [-0.088, -0.3236, 1], [-0.4972, -0.2567, 1], [-0.2046, 0.0335, 1],
        [0.3286, 0.6188, 0], [-0.0012, -0.0459, 0], [-0.527, -0.2096, 1], [-0.4749, 0.2641, 0],
        [-0.4576, 0.6188, 0], [0.0707, 0.279, 0], [0.2988, 0.0583, 0], [-0.2492, -0.3236, 1],
        [-0.1153, -0.5121, 0], [0.2393, -0.5444, 0], [0.1674, -0.3212, 1], [-0.036, -0.47, 0],
        [-0.3484, -0.47, 0], [0.336, 0.5369, 0], [0.1104, 0.2542, 0], [-0.4154, 0.5791, 0],
        [-0.4154, 0.274, 0], [-0.1352, -0.1848, 1], [-0.4749, -0.6188, 0], [0.1773, -0.0211, 0],
        [-0.6188, -0.3236, 1], [-0.274, 0.4749, 1], [-0.1426, 0.279, 1], [0.3559, -0.2319, 0],
        [-0.6188, 0.1748, 0], [-0.1426, 0.5592, 1], [-0.274, 0.0136, 1], [0.3261, 0.3708, 0],
        [-0.1897, 0.4501, 1], [-0.4576, 0.4303, 0], [0.2542, -0.4501, 0], [-0.4799, -0.0682, 0],
        [-0.3708, -0.2046, 1], [-0.0682, -0.2815, 1], [-0.6113, 0.6188, 0], [-0.4774, -0.465, 0],
        [-0.0062, 0.1798, 0], [-0.2691, 0.3509, 1], [-0.3509, -0.2815, 1], [-0.6113, 0.3212, 0],
        [0.0335, -0.3286, 1], [0.15, 0.3708, 0], [0.2988, -0.1327, 0], [-0.3856, -0.5121, 0],
        [-0.4576, 0.1277, 0], [-0.1476, -0.0657, 1], [0.4055, -0.3212, 0], [0.1674, -0.2046, 1],
        [-0.1897, 0.1624, 1], [-0.1897, -0.0955, 1], [0.4055, -0.1922, 0], [-0.0062, 0.0657, 0],
        [-0.4104, 0.16, 0], [-0.6188, -0.2815, 1], [-0.0558, -0.6188, 0], [-0.1476, 0.1649, 1],
        [0.1897, 0.0533, 0], [-0.2691, 0.1203, 1], [0.0087, -0.5121, 0], [-0.2368, -0.5121, 0],
        [-0.5121, -0.3261, 1], [0.3088, -0.3608, 0], [0.098, -0.0682, 0], [-0.1773, -0.6163, 0],
        [0.1252, -0.5171, 0], [0.3088, -0.5344, 0], [0.4873, 0.5468, 0], [0.1674, 0.1947, 0],
        [-0.5196, 0.5245, 0], [-0.5865, -0.6188, 0], [-0.0434, -0.2096, 1], [0.2815, 0.5394, 0],
        [-0.6188, -0.2046, 1], [-0.5072, -0.5121, 0], [0.0657, -0.0211, 0], [0.3534, -0.0087, 0],
        [-0.1153, -0.465, 0], [-0.1699, -0.3286, 1], [0.3236, 0.4303, 0], [0.274, 0.0112, 0],
        [-0.4104, -0.031, 0], [-0.2716, -0.465, 0], [0.0409, -0.465, 0], [0.1699, -0.6188, 0],
        [0.3956, 0.6188, 0], [-0.3261, -0.3286, 1], [-0.4476, -0.2096, 1], [-0.2939, -0.1996, 1],
        [-0.4104, 0.5022, 0], [-0.4104, 0.3484, 0], [-0.2716, 0.5468, 1], [-0.1476, 0.3484, 1],
        ],
    },
    180: {
        radiusUnits: 28.07,
        punkte: [
        [0.1228, -0.0781, 0], [-0.403, 0.6188, 0], [-0.6188, -0.4576, 0], [0.0856, -0.0037, 0],
        [-0.6188, 0.6188, 0], [0.5741, 0.6188, 0], [0.2641, -0.6188, 0], [-0.403, 0.0707, 0],
        [-0.6188, -0.6188, 0], [0.5295, 0.6188, 0], [0.1525, 0.398, 0], [-0.1624, -0.4526, 0],
        [0.4179, -0.2889, 0], [0.2294, -0.6188, 0], [-0.5394, -0.0012, 0], [-0.1352, 0.2517, 1],
        [-0.5022, -0.1972, 1], [-0.1302, 0.5369, 1], [-0.1649, 0.4228, 1], [-0.1947, -0.4873, 0],
        [-0.398, 0.3435, 0], [-0.1352, -0.16, 1], [0.3856, -0.0236, 0], [0.2269, 0.3658, 0],
        [0.3832, -0.2592, 0], [0.0806, -0.341, 1], [0.3112, 0.6064, 0], [0.1476, 0.16, 0],
        [-0.2269, -0.1451, 1], [-0.4774, 0.3212, 0], [-0.3906, -0.4551, 0], [0.3708, 0.3956, 0],
        [-0.1302, 0.0459, 1], [-0.6188, -0.3038, 1], [0.0508, -0.3063, 1], [0.2641, -0.4179, 0],
        [-0.3162, -0.1947, 1], [0.2964, -0.1699, 0], [-0.2468, 0.1451, 1], [0.3336, -0.0161, 0],
        [-0.2864, 0.4724, 1], [-0.6188, -0.0756, 0], [-0.2864, 0.1872, 1], [-0.3856, -0.6188, 0],
        [-0.0037, -0.6188, 0], [-0.2815, -0.036, 1], [0.2616, 0.2765, 0], [-0.0136, 0.1525, 0],
        [-0.3906, -0.3063, 1], [0.0533, 0.2195, 0], [0.0186, -0.1972, 1], [-0.2691, -0.341, 1],
        [0.4724, 0.5072, 0], [0.3212, 0.5592, 0], [-0.2517, 0.6188, 1], [0.2666, 0.0682, 0],
        [-0.0136, -0.4576, 0], [0.0608, 0.2815, 0], [-0.4352, 0.5245, 0], [0.3435, -0.4551, 0],
        [-0.5295, -0.341, 1], [-0.4228, -0.0756, 0], [-0.0186, -0.0459, 0], [-0.6188, 0.1823, 0],
        [-0.1352, -0.3063, 1], [0.4055, -0.4328, 0], [-0.1302, 0.3931, 1], [-0.0831, -0.336, 1],
        [-0.6188, 0.4352, 0], [-0.4328, 0.1451, 0], [0.1748, -0.2368, 1], [0.2344, 0.4997, 0],
        [0.1401, -0.4576, 0], [-0.5022, -0.4873, 0], [0.1128, -0.4873, 0], [0.4179, -0.1451, 0],
        [-0.2815, 0.5964, 1], [0.2964, -0.2988, 0], [-0.1649, 0.0037, 1], [-0.2517, 0.2964, 1],
        [-0.403, 0.222, 0], [-0.2815, 0.3088, 1], [-0.408, -0.336, 1], [-0.2443, -0.6188, 0],
        [-0.336, -0.4873, 0], [-0.403, 0.4972, 0], [-0.6188, -0.1947, 1], [0.3286, -0.522, 0],
        [-0.0533, -0.4873, 0], [-0.5047, -0.2269, 1], [-0.2765, -0.4576, 0], [-0.5047, -0.4526, 0],
        [-0.284, 0.0756, 1], [0.3509, 0.4253, 0], [0.212, 0.036, 0], [0.2319, -0.0831, 0],
        [-0.1302, -0.0583, 1], [-0.1352, 0.1476, 1], [-0.2641, -0.2716, 1], [0.1823, 0.2418, 0],
        [0.1798, -0.336, 1], [-0.0161, 0.0533, 0], [-0.522, -0.0806, 0], [0.3212, -0.1451, 0],
        [-0.6064, 0.3088, 0], [0.1699, 0.0657, 0], [-0.1748, -0.341, 1], [-0.4104, -0.1922, 1],
        [0.4427, 0.5245, 0], [0.0161, 0.1004, 0], [-0.6188, -0.336, 1], [0.1029, -0.1922, 1],
        [-0.2815, -0.1203, 1], [0.1451, -0.2269, 1], [-0.1252, -0.589, 0], [-0.1352, 0.6188, 1],
        [-0.0012, -0.3385, 1], [-0.284, 0.3906, 1], [-0.0434, -0.2269, 1], [0.2319, -0.4972, 0],
        [-0.0632, -0.1972, 1], [0.2021, 0.2195, 0], [0.3162, 0.336, 0], [-0.5022, -0.6064, 0],
        [0.1128, -0.6064, 0], [0.0632, -0.4526, 0], [-0.4005, 0.4204, 0], [0.3336, 0.031, 0],
        [-0.6188, -0.4997, 0], [-0.2517, 0.5022, 1], [0.4204, 0.4526, 0], [0.522, 0.5642, 0],
        [0.0136, 0.222, 0], [-0.4328, -0.0459, 0], [-0.5022, 0.6188, 0], [-0.398, -0.0037, 0],
        [-0.398, 0.1451, 0], [-0.088, -0.4551, 0], [-0.5047, 0.4328, 0], [-0.1649, 0.2244, 1],
        [0.0484, -0.0806, 0], [0.1079, 0.3385, 0], [0.4129, -0.3608, 0], [-0.5369, 0.1079, 0],
        [-0.5146, 0.217, 0], [0.4129, -0.217, 0], [-0.1352, 0.465, 1], [-0.1302, 0.3212, 1],
        [-0.5617, 0.527, 0], [0.2542, 0.4749, 0], [-0.3385, -0.3385, 1], [0.1079, 0.1029, 0],
        [0.2096, -0.4576, 0], [0.3311, -0.3509, 0], [-0.1649, 0.5592, 1], [0.274, 0.5518, 0],
        [0.1947, 0.4476, 0], [0.3013, -0.3633, 0], [0.4055, 0.6188, 0], [-0.1476, -0.2071, 1],
        [0.3013, -0.2344, 0], [0.4179, -0.0806, 0], [-0.284, 0.5344, 1], [-0.2517, -0.0459, 1],
        [0.2443, -0.0558, 0], [-0.4675, -0.341, 1], [-0.2815, 0.2468, 1], [-0.398, 0.2815, 0],
        [-0.4576, 0.0508, 0], [-0.6188, 0.0558, 0], [-0.398, 0.5568, 0], [0.2914, -0.5667, 0],
        [0.3658, -0.4774, 0], [-0.5592, -0.1922, 1], [-0.4476, -0.4576, 0], [-0.5617, -0.4551, 0],
        [-0.2195, -0.4551, 0], [-0.3336, -0.4526, 0], [-0.2864, 0.0186, 1], [-0.2815, 0.1327, 1],
        ],
    },
    236: {
        radiusUnits: 20.57,
        punkte: [
        [-0.0236, -0.0558, 0], [0.589, 0.6188, 0], [-0.3931, 0.6188, 0], [-0.4724, -0.0136, 0],
        [0.5543, 0.6188, 0], [-0.6188, -0.4476, 0], [0.2716, -0.6188, 0], [0.1451, 0.4055, 0],
        [0.4005, -0.4154, 0], [-0.155, 0.6188, 1], [0.4253, -0.1798, 0], [-0.3931, 0.1798, 0],
        [-0.1922, -0.4476, 0], [-0.222, -0.6188, 0], [0.1004, 0.093, 0], [-0.6188, -0.088, 0],
        [0.1104, -0.346, 1], [0.2542, 0.0781, 0], [-0.6188, 0.4551, 0], [-0.6188, -0.47, 0],
        [-0.336, -0.1848, 1], [-0.1228, 0.2889, 1], [-0.1228, 0.5667, 1], [0.0186, -0.2988, 1],
        [0.2145, 0.4526, 0], [0.3112, 0.6188, 0], [0.4228, -0.4253, 0], [0.3807, 0.3931, 0],
        [-0.2269, 0.2592, 1], [0.4005, -0.0732, 0], [-0.3906, 0.398, 0], [-0.4055, -0.4452, 0],
        [-0.2914, 0.0087, 1], [0.1302, -0.6188, 0], [-0.3112, -0.3013, 1], [0.2468, -0.1128, 0],
        [-0.1252, 0.1004, 1], [0.2889, -0.3013, 0], [-0.1724, -0.036, 1], [-0.4154, 0.6188, 0],
        [0.2517, 0.2592, 0], [-0.0236, -0.4476, 0], [0.0434, 0.2691, 0], [-0.6188, 0.1972, 0],
        [-0.6188, -0.217, 1], [0.3931, -0.0161, 0], [0.0781, -0.1848, 1], [-0.4948, -0.1872, 1],
        [-0.4476, -0.6188, 0], [-0.4377, 0.3261, 0], [-0.1178, -0.1798, 1], [0.4824, 0.5072, 0],
        [0.2269, -0.4427, 0], [-0.0459, -0.5022, 0], [0.3435, 0.6188, 0], [-0.2914, 0.5096, 1],
        [-0.2914, 0.2889, 1], [-0.5121, -0.346, 1], [0.0905, 0.2939, 0], [-0.2691, 0.4551, 1],
        [-0.2988, -0.346, 1], [0.1178, 0.1054, 0], [-0.1252, 0.4278, 1], [0.3137, -0.2443, 0],
        [0.2096, -0.0632, 0], [0.2319, 0.5096, 0], [-0.1079, -0.346, 1], [-0.3931, -0.0707, 0],
        [-0.1476, -0.2195, 1], [0.0087, -0.0632, 0], [0.3435, -0.5196, 0], [-0.0236, 0.1674, 0],
        [0.4253, -0.3038, 0], [0.2269, -0.465, 0], [0.3956, 0.4476, 0], [0.1872, -0.2368, 1],
        [-0.3931, 0.0632, 0], [-0.2914, 0.1252, 1], [-0.3385, -0.4774, 0], [-0.4154, 0.1525, 0],
        [0.1352, -0.0856, 0], [-0.1252, -0.0112, 1], [-0.0236, 0.0558, 0], [-0.4824, -0.3236, 1],
        [-0.584, 0.6188, 0], [0.0831, -0.4476, 0], [0.0037, -0.346, 1], [-0.5146, -0.0856, 0],
        [0.2542, 0.2939, 0], [-0.2616, 0.1004, 1], [0.1848, 0.1823, 0], [-0.3931, 0.5022, 0],
        [-0.3931, 0.284, 0], [0.155, -0.2145, 1], [-0.6188, 0.0409, 0], [-0.2914, 0.6138, 1],
        [-0.5146, -0.4476, 0], [-0.3038, -0.4476, 0], [-0.4675, 0.4749, 0], [-0.5989, -0.6188, 0],
        [-0.6163, -0.346, 1], [-0.5964, -0.1872, 1], [-0.2914, -0.093, 1], [-0.1897, -0.47, 0],
        [0.2964, 0.0533, 0], [-0.2914, 0.408, 1], [-0.4104, -0.346, 1], [0.2914, -0.2021, 0],
        [-0.2691, -0.1426, 1], [-0.1476, 0.3782, 1], [-0.0211, -0.1872, 1], [-0.2046, -0.3484, 1],
        [-0.1252, 0.1947, 1], [-0.4799, -0.4824, 0], [0.088, -0.47, 0], [0.3187, 0.3236, 0],
        [0.336, 0.0484, 0], [0.0955, 0.336, 0], [-0.5741, 0.3261, 0], [-0.4104, -0.2096, 1],
        [-0.1079, -0.4452, 0], [-0.1228, -0.0955, 1], [0.4228, -0.0955, 0], [0.0062, 0.1897, 0],
        [0.2592, -0.5915, 0], [0.284, -0.3832, 0], [0.0533, -0.088, 0], [-0.2939, 0.2071, 1],
        [-0.1476, 0.1575, 1], [-0.0955, -0.6188, 0], [0.1872, -0.3187, 1], [-0.4154, -0.1872, 1],
        [0.5369, 0.5617, 0], [0.1649, 0.1972, 0], [-0.2691, 0.5766, 1], [0.4303, 0.4501, 0],
        [0.155, -0.4452, 0], [0.1823, 0.0756, 0], [0.4501, 0.5568, 0], [-0.1575, 0.4972, 1],
        [-0.1252, 0.3584, 1], [-0.1252, 0.4972, 1], [0.2716, 0.5642, 0], [-0.5295, 0.1178, 0],
        [-0.0955, -0.3236, 1], [-0.3906, -0.0037, 0], [0.1872, 0.4576, 0], [0.1451, -0.1848, 1],
        [-0.5072, 0.2319, 0], [-0.336, -0.594, 0], [0.4228, -0.2418, 0], [-0.4526, -0.088, 0],
        [0.3832, -0.4724, 0], [0.0186, -0.5964, 0], [0.3013, -0.1302, 0], [0.0012, 0.2244, 0],
        [0.3038, -0.5667, 0], [0.4253, -0.3658, 0], [-0.5741, -0.0632, 0], [0.1277, -0.3236, 1],
        [-0.2939, 0.3484, 1], [0.1947, -0.0856, 0], [-0.2939, 0.0657, 1], [-0.0012, 0.0484, 0],
        [0.3063, 0.5146, 0], [-0.3906, 0.1203, 0], [-0.3906, 0.5592, 0], [-0.3931, 0.341, 0],
        [0.1104, -0.0161, 0], [-0.594, -0.3236, 1], [-0.2914, -0.15, 1], [-0.2492, -0.4452, 0],
        [-0.3559, -0.3484, 1], [0.3311, -0.4997, 0], [-0.2046, -0.3236, 1], [-0.0533, -0.3484, 1],
        [-0.026, -0.0012, 0], [-0.1228, 0.0434, 1], [0.3038, 0.3906, 0], [-0.0409, -0.2096, 1],
        [-0.026, 0.1104, 0], [-0.46, -0.4476, 0], [0.284, -0.15, 0], [0.3137, -0.3534, 0],
        [0.4005, -0.1823, 0], [-0.5667, -0.4452, 0], [0.0285, -0.4452, 0], [-0.5642, -0.3484, 1],
        [-0.5146, -0.2244, 1], [0.1724, 0.3584, 0], [0.0558, -0.3484, 1], [-0.3906, 0.2319, 0],
        [-0.2939, 0.5617, 1], [0.1922, 0.0459, 0], [0.4005, -0.2988, 0], [-0.1252, 0.6188, 1],
        [-0.5667, -0.088, 0], [-0.3931, 0.4501, 0], [-0.2691, -0.0037, 1], [-0.2666, 0.3534, 1],
        [0.1525, 0.1426, 0], [0.2195, 0.2195, 0], [-0.3559, -0.4476, 0], [-0.4997, 0.5692, 0],
        [-0.16, 0.0608, 1], [-0.4625, -0.3484, 1], [-0.5468, -0.1848, 1], [-0.2939, -0.0434, 1],
        [0.0583, -0.2096, 1], [-0.2418, -0.2344, 1], [-0.2939, 0.4576, 1], [0.2889, -0.2517, 0],
        [0.0285, -0.1872, 1], [-0.2616, -0.532, 0], [-0.1748, -0.1302, 1], [-0.0707, -0.1872, 1],
        [-0.155, -0.3484, 1], [-0.1228, 0.1476, 1], [0.16, -0.5295, 0], [-0.5072, 0.3906, 0],
        [-0.2517, -0.3484, 1], [-0.1252, 0.2418, 1], [0.346, 0.3608, 0], [0.3137, -0.0384, 0],
        [-0.5245, -0.5642, 0], [0.3013, 0.0781, 0], [0.2864, 0.2889, 0], [0.0087, -0.088, 0],
        [0.4228, -0.0508, 0], [0.155, -0.3484, 1], [0.1872, -0.1922, 1], [0.3608, 0.0136, 0],
        [0.1203, 0.3708, 0], [-0.1252, -0.1376, 1], [0.0682, 0.3038, 0], [-0.15, -0.4476, 0],
        ],
    },
    300: {
        radiusUnits: 17.23,
        punkte: [
        [0.4278, -0.0856, 0], [-0.6188, -0.4427, 0], [-0.3881, 0.6188, 0], [0.3038, -0.0186, 0],
        [-0.6188, 0.6188, 0], [0.594, 0.6188, 0], [-0.0335, -0.4427, 0], [-0.1203, 0.1178, 1],
        [-0.6188, -0.6188, 0], [0.5642, 0.6188, 0], [0.1773, 0.4526, 0], [0.3732, -0.4948, 0],
        [-0.4501, -0.0905, 0], [0.0384, -0.6188, 0], [-0.408, -0.0012, 0], [-0.3881, 0.2964, 0],
        [0.1128, -0.093, 0], [-0.1203, 0.4848, 1], [-0.1401, 0.4352, 1], [0.4055, -0.4228, 0],
        [-0.3162, -0.3534, 1], [0.1872, 0.1748, 0], [0.4253, 0.4352, 0], [-0.0583, -0.217, 1],
        [0.2517, 0.3757, 0], [0.1897, -0.3286, 1], [-0.1203, -0.1624, 1], [0.3063, 0.6188, 0],
        [-0.2914, -0.4848, 0], [-0.6188, 0.2716, 0], [0.4303, -0.2939, 0], [-0.3856, 0.098, 0],
        [-0.6188, -0.1823, 1], [-0.6188, -0.274, 1], [-0.0087, 0.1203, 0], [0.0161, 0.2492, 0],
        [-0.2988, 0.4551, 1], [0.3261, 0.0608, 0], [-0.4104, 0.4452, 0], [-0.2542, 0.2046, 1],
        [0.3038, 0.3063, 0], [0.284, -0.1823, 0], [-0.3013, -0.1748, 1], [0.1699, -0.3286, 1],
        [-0.3137, -0.2344, 1], [-0.4824, -0.3509, 1], [-0.031, -0.0186, 0], [0.274, -0.6188, 0],
        [0.3212, 0.6088, 0], [-0.1823, -0.026, 1], [-0.1203, 0.3261, 1], [-0.16, -0.3509, 1],
        [-0.2964, -0.0236, 1], [0.2616, -0.5964, 0], [-0.2691, 0.6188, 1], [0.1079, -0.4427, 0],
        [-0.1178, 0.6188, 1], [0.0136, -0.1823, 1], [-0.6188, -0.0558, 0], [0.408, -0.2071, 0],
        [-0.2964, 0.1972, 1], [0.0955, 0.3509, 0], [0.5096, 0.527, 0], [0.0955, -0.0682, 0],
        [-0.0905, -0.4625, 0], [-0.4005, -0.4427, 0], [-0.2368, -0.4427, 0], [0.2244, -0.4402, 0],
        [-0.4923, -0.4625, 0], [0.1748, 0.1947, 0], [0.036, -0.3509, 1], [-0.5096, -0.1823, 1],
        [0.1748, -0.1823, 1], [-0.4452, 0.1872, 0], [0.4154, 0.4551, 0], [0.4303, -0.403, 0],
        [-0.5642, -0.0905, 0], [-0.2964, 0.5642, 1], [-0.408, -0.6188, 0], [-0.1748, -0.6188, 0],
        [0.1302, 0.0856, 0], [-0.2964, 0.3484, 1], [-0.3881, 0.5121, 0], [-0.589, 0.4452, 0],
        [-0.4452, 0.6163, 0], [0.2443, 0.5344, 0], [0.2864, -0.2889, 0], [-0.1178, 0.222, 1],
        [-0.5915, 0.1079, 0], [0.1376, -0.4898, 0], [0.4278, -0.1897, 0], [-0.3881, 0.4005, 0],
        [0.2269, -0.093, 0], [-0.1798, -0.3261, 1], [-0.4724, -0.2021, 1], [-0.4055, -0.1823, 1],
        [-0.0285, 0.1575, 0], [0.2294, 0.0831, 0], [-0.2765, 0.3584, 1], [0.1054, 0.3286, 0],
        [-0.1203, -0.0632, 1], [-0.5196, -0.4427, 0], [-0.5816, -0.3509, 1], [0.0856, -0.212, 1],
        [0.1773, 0.0508, 0], [-0.1302, -0.4427, 0], [-0.0632, -0.3509, 1], [-0.3881, 0.0012, 0],
        [-0.1401, 0.2914, 1], [0.3137, -0.3137, 0], [-0.2964, 0.0707, 1], [-0.3881, 0.1922, 0],
        [0.0211, -0.093, 0], [-0.4848, 0.3236, 0], [-0.1897, -0.1674, 1], [0.3856, -0.0062, 0],
        [-0.1178, 0.026, 1], [0.2443, 0.2418, 0], [-0.4154, -0.3311, 1], [-0.15, 0.1104, 1],
        [0.3633, 0.3708, 0], [0.284, -0.3757, 0], [-0.0285, 0.0682, 0], [0.2691, -0.4452, 0],
        [0.0186, -0.3311, 1], [-0.3187, -0.4402, 0], [-0.4005, -0.3534, 1], [0.3236, -0.5568, 0],
        [-0.1401, 0.5741, 1], [-0.2765, 0.0682, 1], [0.093, -0.1798, 1], [-0.1178, 0.4055, 1],
        [0.1153, -0.3534, 1], [-0.2592, 0.4873, 1], [-0.6188, -0.4898, 0], [-0.2368, -0.3534, 1],
        [-0.2964, -0.1004, 1], [-0.2988, 0.2716, 1], [0.2468, 0.5047, 0], [0.2964, -0.1451, 0],
        [0.1922, -0.2542, 1], [0.036, -0.4402, 0], [-0.3856, -0.0682, 0], [0.4452, 0.5791, 0],
        [0.408, -0.0831, 0], [-0.0558, -0.1823, 1], [-0.1203, 0.5518, 1], [0.1376, 0.4005, 0],
        [-0.0087, -0.0037, 0], [0.031, 0.2344, 0], [0.0583, 0.2988, 0], [-0.2988, 0.1327, 1],
        [0.465, 0.4824, 0], [-0.2914, -0.6039, 0], [0.0211, -0.5022, 0], [0.5493, 0.5741, 0],
        [-0.46, -0.4402, 0], [0.1674, -0.4427, 0], [-0.5022, -0.0707, 0], [-0.0682, -0.5766, 0],
        [-0.5072, -0.093, 0], [0.1699, -0.0905, 0], [-0.031, -0.0756, 0], [-0.2765, -0.093, 1],
        [-0.5146, -0.5741, 0], [0.4278, -0.3484, 0], [-0.5642, -0.1798, 1], [-0.6188, -0.093, 0],
        [-0.4997, 0.5171, 0], [0.15, -0.6088, 0], [-0.3856, 0.4551, 0], [-0.2988, 0.6188, 1],
        [-0.2988, 0.5096, 1], [-0.4824, 0.0806, 0], [0.2071, -0.0707, 0], [0.408, -0.4526, 0],
        [0.1476, 0.1376, 0], [0.2641, -0.1327, 0], [-0.5245, -0.3311, 1], [-0.3956, -0.5121, 0],
        [0.212, 0.4923, 0], [0.279, 0.5741, 0], [-0.1848, -0.4402, 0], [-0.1872, -0.5121, 0],
        [0.098, 0.1203, 0], [0.284, -0.2368, 0], [-0.4576, -0.1798, 1], [-0.3534, -0.1798, 1],
        [0.2468, 0.2716, 0], [-0.2815, -0.3311, 1], [0.4303, -0.1376, 0], [-0.3856, 0.2443, 0],
        [-0.3856, 0.3484, 0], [-0.5468, 0.1996, 0], [-0.0806, -0.3162, 1], [-0.2988, 0.4005, 1],
        [-0.3856, 0.5642, 0], [0.4278, -0.2418, 0], [0.3484, 0.3832, 0], [-0.5543, 0.0161, 0],
        [-0.1203, 0.1699, 1], [-0.1203, 0.274, 1], [-0.0136, 0.2071, 0], [0.408, -0.3261, 0],
        [0.0831, 0.026, 0], [0.2815, 0.0831, 0], [-0.5692, -0.4402, 0], [-0.532, -0.3534, 1],
        [0.341, 0.5146, 0], [0.3236, -0.522, 0], [-0.0136, -0.3534, 1], [-0.1178, -0.1128, 1],
        [0.1798, 0.0806, 0], [-0.4997, 0.4179, 0], [-0.5741, 0.3534, 0], [-0.0831, -0.4402, 0],
        [-0.1128, -0.3534, 1], [-0.2988, 0.0236, 1], [-0.408, 0.2716, 0], [-0.1624, 0.2021, 1],
        [-0.3856, 0.0484, 0], [-0.3881, 0.1451, 0], [0.4154, -0.0409, 0], [0.1624, 0.4005, 0],
        [-0.5642, -0.2021, 1], [0.0657, -0.0905, 0], [-0.1203, 0.0707, 1], [-0.031, 0.1128, 0],
        [-0.408, 0.5344, 0], [-0.6188, 0.5295, 0], [-0.1203, -0.0186, 1], [0.3584, 0.0285, 0],
        [0.2542, -0.408, 0], [-0.2244, -0.2492, 1], [0.1699, -0.2393, 1], [0.2145, 0.2096, 0],
        [0.274, 0.274, 0], [0.3336, 0.3385, 0], [-0.532, 0.6014, 0], [0.2666, 0.0608, 0],
        [0.3931, 0.403, 0], [-0.3584, -0.3509, 1], [0.2864, -0.3336, 0], [0.2195, -0.5196, 0],
        [-0.1897, 0.3633, 1], [-0.0285, 0.0236, 0], [0.1575, -0.3534, 1], [-0.3584, -0.4427, 0],
        [0.3236, -0.2269, 0], [-0.1376, -0.2492, 1], [-0.279, -0.4427, 0], [-0.4427, -0.3534, 1],
        [-0.2765, -0.3509, 1], [-0.2294, 0.2864, 1], [-0.398, -0.2468, 1], [0.1327, -0.1823, 1],
        [0.0756, -0.3534, 1], [0.0533, -0.1823, 1], [-0.408, 0.3584, 0], [-0.1401, -0.098, 1],
        [-0.1203, 0.3658, 1], [-0.1203, 0.4452, 1], [0.2964, -0.5865, 0], [0.093, -0.2939, 1],
        [0.088, -0.5543, 0], [0.346, -0.5245, 0], [-0.6188, -0.3534, 1], [-0.1972, -0.3534, 1],
        [-0.408, 0.1128, 0], [0.1128, 0.2468, 0], [0.1897, -0.217, 1], [-0.2988, -0.1376, 1],
        [-0.2988, -0.0632, 1], [-0.2616, -0.0112, 1], [0.3311, -0.3931, 0], [-0.2964, 0.3088, 1],
        [0.1897, -0.2914, 1], [-0.2988, 0.2344, 1], [-0.1798, 0.5047, 1], [0.0136, -0.2517, 1],
        [-0.4129, -0.093, 0], [0.0707, -0.4427, 0], [-0.0211, -0.1798, 1], [0.1848, 0.3236, 0],
        [-0.2269, 0.1302, 1], [0.0012, -0.4427, 0], [-0.0905, -0.1823, 1], [-0.3881, -0.0335, 0],
        [0.16, 0.4253, 0], [-0.1178, 0.5171, 1], [-0.1178, 0.584, 1], [-0.2988, 0.1649, 1],
        [0.036, 0.274, 0], [0.0781, 0.3236, 0], [0.1153, 0.3757, 0], [0.4873, 0.5047, 0],
        [0.5716, 0.5964, 0], [0.4427, 0.46, 0], [0.527, 0.5518, 0], [0.1376, -0.4402, 0],
        ],
    },
};

// Die Punkte liegen im Bereich -0.62..0.62 und werden ueber
// center + x * (cssSize * 0.42) abgebildet. Daraus folgt die Umrechnung
// von Icon-Einheiten in CSS-Pixel.
const RT_ICON_NORM = 1.24;
const RT_ICON_BOX = 1000;

/**
 * Waehlt die zum Budget passende Tabelle. resolveParticleBudget liefert
 * genau eine der vier Zahlen; alles andere faellt auf die naechstgroessere
 * Tabelle zurueck.
 */
export function resolveIconBudget(count) {
    const gewuenscht = Math.max(1, Math.floor(Number(count) || 1));
    const schluessel = Object.keys(RT_ICON_BUDGETS)
        .map(Number)
        .sort((a, b) => a - b);

    return RT_ICON_BUDGETS[schluessel.find((s) => s >= gewuenscht) ?? schluessel[schluessel.length - 1]];
}

/** Radius der Partikel im Logo-Zustand, in Icon-Einheiten. */
export function resolveIconRadiusUnits(count) {
    return resolveIconBudget(count).radiusUnits;
}

/** Rechnet Icon-Einheiten in CSS-Pixel um. */
export function iconUnitsToPixels(units, cssSize) {
    return units * cssSize * 0.42 * (RT_ICON_NORM / RT_ICON_BOX);
}

export function createRailTimeLogoTargets(count) {
    const gewuenscht = Math.max(1, Math.floor(Number(count) || 1));
    const tabelle = resolveIconBudget(gewuenscht).punkte;

    // Der Anfangsabschnitt genuegt: die Liste ist per Farthest-Point
    // sortiert, jede Teilmenge deckt die Form gleichmaessig ab.
    return Array.from({ length: gewuenscht }, (_, index) => {
        const [x, y, ton] = tabelle[index % tabelle.length];

        return { x, y, tone: ton === 1 ? 'slate' : 'red' };
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
        // Wird in cacheLogoPositions() aus radiusUnits berechnet — NICHT hier
        // festnageln. Der Wert haengt an der Canvasgroesse, und die steht
        // beim Erzeugen der Partikel noch nicht fest.
        logoSize: 0,
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
    // Gehoert zur Punkttabelle dieses Budgets: halber Punktabstand in
    // Icon-Einheiten. Siehe cacheLogoPositions().
    const logoRadiusUnits = resolveIconRadiusUnits(particleCount);
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
    // Aus cssSize abgeleitet, deshalb nur bei einer Groessenaenderung neu
    // gesetzt (siehe cacheLogoPositions) — nicht je Partikel und Bild.
    let canvasCenter = 72;
    let sphereRadius = 50.4;
    let pixelRatio = 1;
    let isDark = false;
    let themeCheckedAt = 0;
    let outroDuration = 0;
    let resizePending = true;

    function cacheLogoPositions() {
        const center = cssSize / 2;
        const scale = cssSize * 0.42;

        // Beide Werte hingen frueher IN der Partikelschleife und wurden je
        // Partikel und je Bild neu gerechnet. Sie aendern sich aber nur mit
        // der Canvasgroesse.
        canvasCenter = center;
        sphereRadius = cssSize * 0.35;

        // DIE KOPPLUNG, auf der die geschlossene Kontur beruht:
        // Der Punktradius ist der halbe Punktabstand des jeweiligen Budgets.
        // Nur so beruehren sich die Scheiben und ergeben einen Strich statt
        // einer gepunkteten Linie. radiusUnits kommt aus der Punkttabelle und
        // ist skaleninvariant — hier wird es in CSS-Pixel umgerechnet.
        //
        // Wer diese Zeile durch eine Konstante ersetzt, bekommt bei 132
        // Partikeln wieder eine gestrichelte Silhouette. Abgesichert durch
        // tests/Frontend/navigation-particle-icon.test.js.
        const logoSize = iconUnitsToPixels(logoRadiusUnits, cssSize);

        particles.forEach((particle) => {
            particle.logoScreenX = center + (particle.logo.x * scale);
            particle.logoScreenY = center + (particle.logo.y * scale);
            particle.logoSize = logoSize;
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

    // Ein einziger Zwischenspeicher fuer die Projektion. Frueher gab
    // projectSphere ein frisches Objektliteral zurueck — bei 300 Partikeln
    // und 60 Bildern je Sekunde sind das 18.000 Wegwerfobjekte pro Sekunde.
    // Der Aufraeumer muss sie einsammeln, und zwar genau waehrend der
    // Navigation, wenn der Hauptfaden ohnehin ausgelastet ist. Das Ergebnis
    // wird stets sofort ausgelesen, bevor der naechste Aufruf kommt.
    const projektion = { x: 0, y: 0, depth: 0 };

    function projectSphere(particle, rotation) {
        const rotatedX = (particle.x * rotation.cosYaw) + (particle.z * rotation.sinYaw);
        const rotatedZ = (-particle.x * rotation.sinYaw) + (particle.z * rotation.cosYaw);
        const rotatedY = (particle.y * rotation.cosTilt) - (rotatedZ * rotation.sinTilt);
        const depth = (particle.y * rotation.sinTilt) + (rotatedZ * rotation.cosTilt);
        const perspective = 1 / (1.58 - (depth * 0.3));

        projektion.x = rotatedX * perspective * 1.28;
        projektion.y = rotatedY * perspective * 1.28;
        projektion.depth = depth;

        return projektion;
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
        // shimmer wirkt NUR auf die Deckkraft, nicht auf die Groesse.
        //
        // Die Kontur haelt nur, solange sich die Scheiben beruehren: ihr
        // Radius ist der halbe Punktabstand. Ein Faktor 0,94-1,00 auf der
        // Groesse laesst die Kette periodisch aufreissen — je Partikel
        // phasenversetzt, also als unruhiges Flimmern der Silhouette.
        // Auf der Deckkraft ist derselbe Faktor unschaedlich.
        particle.screenSize = particle.logoSize;
        particle.screenAlpha = 0.96 * appear * shimmer;
        particle.depth = 0;
        particle.tone = particle.logo.tone;
    }

    function updateSphereParticle(particle, elapsed, rotation) {
        const radius = sphereRadius;
        const center = canvasCenter;
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

        const radius = sphereRadius;
        const center = canvasCenter;
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
        // Auch hier kein shimmer auf der Groesse: am Ende des Morphs steht
        // wieder die geschlossene Kontur, die er sonst aufreissen wuerde.
        particle.screenSize = mix(particle.outroSize, particle.logoSize, morph)
            * mix(1, 0.82, fade);
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
