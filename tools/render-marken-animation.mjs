/**
 * Rendert die transparenten Markenanimationen fuer Signatur und Mailvorlage.
 *
 * Wortmarke: R, A, I, L, T, I, M und E materialisieren nacheinander aus
 * ihrem eigenen Mittelpunkt. Danach wachsen die beiden Unterlinien von der
 * Mitte nach aussen; zuletzt setzen sich G, M, B und H in die Luecke.
 *
 * RT-Zeichen: zuerst der kleine rote Schenkel links unten, danach die rote
 * Aussensilhouette und zuletzt das helle beziehungsweise dunkle T.
 *
 * Beide Folgen spielen genau einmal und halten das vollstaendige Endbild.
 * Outlook Desktop erhaelt weiterhin die separat erzeugten PNG-Standbilder.
 *
 * Aufruf:
 *   node tools/mail-marken-bilder.mjs
 *   node tools/render-marken-animation.mjs
 *
 * Optionaler visueller Kontaktbogen:
 *   RT_ANIMATION_QA_DIR=.lmzdev/artifacts/mail-brand-animation \
 *     node tools/render-marken-animation.mjs
 */
import puppeteer from 'puppeteer-core';
import gifenc from 'gifenc';
import { PNG } from 'pngjs';
import {
    copyFileSync,
    mkdirSync,
    readFileSync,
    writeFileSync,
} from 'node:fs';

const { GIFEncoder, quantize, applyPalette } = gifenc;
const CHROME = process.env.RT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ASSETS = 'resources/mail-templates/assets';
const PUBLIC_ASSETS = 'public/mail-assets';
const QA_DIR = process.env.RT_ANIMATION_QA_DIR || '';
const TRANSPARENT_COLOR = [255, 0, 255];

const WORDMARK_FRAMES = 52;
const WORDMARK_DURATION_CS = 480;
const WORDMARK_HOLD_CS = 120;
const ICON_FRAMES = 44;
const ICON_DURATION_CS = 360;
const ICON_HOLD_CS = 120;

const WORDMARKS = [
    'wortmarke-signature-light',
    'wortmarke-signature-dark',
    'wortmarke-mail-dark',
];

const ICONS = [
    { key: 'icon-rt-light', tStops: [[0, '#59616b'], [0.48, '#303740'], [1, '#151b24']] },
    { key: 'icon-rt-dark', tStops: [[0, '#ffffff'], [0.55, '#e8ecf1'], [1, '#bec7d1']] },
];

const RED_STOPS = [[0, '#f51b3b'], [0.48, '#e4002b'], [1, '#ae0021']];

function delays(totalCs, frameCount, holdCs) {
    const base = Math.floor((totalCs - holdCs) / frameCount);
    const values = new Array(frameCount).fill(base);
    values[frameCount - 1] += totalCs - (base * frameCount);

    return values;
}

function encodeGif(frames, width, height, filename, frameDelays) {
    const prepared = frames.map((raw) => {
        const rgba = new Uint8Array(raw);
        for (let offset = 0; offset < rgba.length; offset += 4) {
            if (rgba[offset + 3] < 128) {
                rgba[offset] = TRANSPARENT_COLOR[0];
                rgba[offset + 1] = TRANSPARENT_COLOR[1];
                rgba[offset + 2] = TRANSPARENT_COLOR[2];
            }
            rgba[offset + 3] = 255;
        }

        return rgba;
    });

    // Das vollstaendige Endbild bestimmt die Farbtabelle. Damit behalten
    // Logo- und Metallverlaeufe ihre Farbtiefe; transparente Pixel erhalten
    // einen reservierten, im Motiv nicht vorkommenden Index.
    const palette = quantize(prepared.at(-1), 255, { format: 'rgb565' });
    palette.push(TRANSPARENT_COLOR.slice());
    const transparentIndex = palette.length - 1;
    const encoder = GIFEncoder();

    prepared.forEach((rgba, index) => {
        const indices = applyPalette(rgba, palette, 'rgb565');
        const raw = frames[index];
        for (let offset = 0, pixel = 0; offset < raw.length; offset += 4, pixel += 1) {
            if (raw[offset + 3] < 128) indices[pixel] = transparentIndex;
        }

        encoder.writeFrame(indices, width, height, {
            palette: index === 0 ? palette : undefined,
            delay: frameDelays[index] * 10,
            transparent: true,
            transparentIndex,
            dispose: index === frames.length - 1 ? 1 : 2,
            // Kein NETSCAPE-Block: Aufbau einmal abspielen und Endbild halten.
            repeat: -1,
        });
    });

    encoder.finish();
    const bytes = Buffer.from(encoder.bytes());
    writeFileSync(`${ASSETS}/${filename}`, bytes);
    copyFileSync(`${ASSETS}/${filename}`, `${PUBLIC_ASSETS}/${filename}`);

    console.log(
        `${filename.padEnd(32)} ${width}x${height}, ${frames.length} Bilder, `
        + `${frameDelays.reduce((sum, value) => sum + value, 0)} cs, ${(bytes.length / 1024).toFixed(1)} kB`,
    );
}

function writeContactSheet(frames, width, height, filename, columns, darkBackground = false) {
    if (!QA_DIR) return;
    mkdirSync(QA_DIR, { recursive: true });

    const samples = 8;
    const sampleIndices = Array.from({ length: samples }, (_, index) => (
        Math.round((index / (samples - 1)) * (frames.length - 1))
    ));
    const rows = Math.ceil(samples / columns);
    const padding = 18;
    const gap = 12;
    const sheet = new PNG({
        width: (columns * width) + ((columns - 1) * gap) + (padding * 2),
        height: (rows * height) + ((rows - 1) * gap) + (padding * 2),
    });

    for (let y = 0; y < sheet.height; y += 1) {
        for (let x = 0; x < sheet.width; x += 1) {
            const offset = ((y * sheet.width) + x) * 4;
            const checker = (Math.floor(x / 12) + Math.floor(y / 12)) % 2;
            const base = darkBackground
                ? (checker ? [18, 23, 30] : [31, 38, 47])
                : (checker ? [187, 194, 203] : [221, 225, 230]);
            sheet.data[offset] = base[0];
            sheet.data[offset + 1] = base[1];
            sheet.data[offset + 2] = base[2];
            sheet.data[offset + 3] = 255;
        }
    }

    sampleIndices.forEach((frameIndex, sampleIndex) => {
        const frame = frames[frameIndex];
        const column = sampleIndex % columns;
        const row = Math.floor(sampleIndex / columns);
        const startX = padding + (column * (width + gap));
        const startY = padding + (row * (height + gap));

        for (let y = 0; y < height; y += 1) {
            for (let x = 0; x < width; x += 1) {
                const sourceOffset = ((y * width) + x) * 4;
                const alpha = frame[sourceOffset + 3] / 255;
                if (alpha <= 0) continue;
                const targetOffset = (((startY + y) * sheet.width) + startX + x) * 4;
                for (let channel = 0; channel < 3; channel += 1) {
                    sheet.data[targetOffset + channel] = Math.round(
                        (frame[sourceOffset + channel] * alpha)
                        + (sheet.data[targetOffset + channel] * (1 - alpha)),
                    );
                }
            }
        }
    });

    writeFileSync(`${QA_DIR}/${filename}`, PNG.sync.write(sheet, { deflateLevel: 9 }));
}

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new' });
const page = await browser.newPage();
await page.setContent('<!doctype html><html><body style="margin:0"></body></html>', { waitUntil: 'load' });

for (const name of WORDMARKS) {
    const source = readFileSync(`${ASSETS}/${name}.png`).toString('base64');
    const rendered = await page.evaluate(async ({ sourceData, frameCount, expectedLetters }) => {
        const image = new Image();
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = reject;
            image.src = `data:image/png;base64,${sourceData}`;
        });

        const width = image.naturalWidth;
        const height = image.naturalHeight;
        const sourceCanvas = document.createElement('canvas');
        sourceCanvas.width = width;
        sourceCanvas.height = height;
        const sourceContext = sourceCanvas.getContext('2d', { willReadFrequently: true });
        sourceContext.drawImage(image, 0, 0);
        const sourcePixels = sourceContext.getImageData(0, 0, width, height).data;

        const rowInk = [];
        for (let y = 0; y < height; y += 1) {
            let count = 0;
            for (let x = 0; x < width; x += 1) {
                if (sourcePixels[(((y * width) + x) * 4) + 3] > 16) count += 1;
            }
            rowInk.push(count);
        }
        let separator = height;
        for (let y = Math.floor(height * 0.5); y < height - 1; y += 1) {
            if (rowInk[y] === 0 && rowInk[y + 1] > 0) {
                separator = y + 1;
                break;
            }
        }

        const components = (fromY, toY) => {
            const localHeight = toY - fromY;
            const seen = new Uint8Array(width * localHeight);
            const result = [];

            for (let local = 0; local < seen.length; local += 1) {
                if (seen[local]) continue;
                seen[local] = 1;
                const localX = local % width;
                const localY = Math.floor(local / width);
                const globalPosition = ((localY + fromY) * width) + localX;
                if (sourcePixels[(globalPosition * 4) + 3] <= 16) continue;

                const stack = [local];
                const pixels = [];
                let minX = width; let maxX = 0; let minY = height; let maxY = 0;
                while (stack.length) {
                    const position = stack.pop();
                    const x = position % width;
                    const y = Math.floor(position / width);
                    const globalY = y + fromY;
                    pixels.push((globalY * width) + x);
                    minX = Math.min(minX, x); maxX = Math.max(maxX, x);
                    minY = Math.min(minY, globalY); maxY = Math.max(maxY, globalY);

                    for (const neighbor of [position - 1, position + 1, position - width, position + width]) {
                        if (neighbor < 0 || neighbor >= seen.length || seen[neighbor]) continue;
                        if (Math.abs((neighbor % width) - x) > 1) continue;
                        seen[neighbor] = 1;
                        const neighborX = neighbor % width;
                        const neighborY = Math.floor(neighbor / width) + fromY;
                        if (sourcePixels[(((neighborY * width) + neighborX) * 4) + 3] > 16) {
                            stack.push(neighbor);
                        }
                    }
                }
                result.push({ pixels, minX, maxX, minY, maxY });
            }

            return result;
        };

        const distance = (part, base) => {
            const dx = Math.max(0, base.minX - part.maxX, part.minX - base.maxX);
            const dy = Math.max(0, base.minY - part.maxY, part.minY - base.maxY);
            return (dx * dx) + (dy * dy);
        };

        const layer = (positions) => {
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d');
            const imageData = context.createImageData(width, height);
            for (const position of positions) {
                const offset = position * 4;
                imageData.data[offset] = sourcePixels[offset];
                imageData.data[offset + 1] = sourcePixels[offset + 1];
                imageData.data[offset + 2] = sourcePixels[offset + 2];
                imageData.data[offset + 3] = sourcePixels[offset + 3];
            }
            context.putImageData(imageData, 0, 0);

            return canvas;
        };

        const upperParts = components(0, separator);
        const letterBases = upperParts
            .filter((part) => (
                part.pixels.length > separator * 8
                && part.maxY - part.minY > separator * 0.5
            ))
            .sort((left, right) => left.minX - right.minX);
        if (letterBases.length !== expectedLetters) {
            throw new Error(`Erwartet ${expectedLetters} Buchstaben, gefunden ${letterBases.length}`);
        }

        const letterPixels = letterBases.map(() => []);
        for (const part of upperParts) {
            let target = letterBases.indexOf(part);
            if (target < 0) {
                target = letterBases
                    .map((base, index) => ({ index, value: distance(part, base) }))
                    .sort((left, right) => left.value - right.value)[0].index;
            }
            letterPixels[target].push(...part.pixels);
        }
        const letters = letterPixels.map((pixels, index) => ({
            image: layer(pixels),
            ...letterBases[index],
        }));

        const lowerParts = separator < height ? components(separator, height) : [];
        const legalFormBases = lowerParts
            .filter((part) => part.maxY - part.minY >= 5 && part.pixels.length >= 50)
            .sort((left, right) => left.minX - right.minX);
        if (separator < height && legalFormBases.length !== 4) {
            throw new Error(`Erwartet vier GMBH-Zeichen, gefunden ${legalFormBases.length}`);
        }

        const legalMin = legalFormBases.length ? Math.min(...legalFormBases.map((part) => part.minX)) : width;
        const legalMax = legalFormBases.length ? Math.max(...legalFormBases.map((part) => part.maxX)) : -1;
        const leftLinePixels = [];
        const rightLinePixels = [];
        const legalPixels = legalFormBases.map(() => []);
        for (const part of lowerParts) {
            if (part.maxX < legalMin) {
                leftLinePixels.push(...part.pixels);
                continue;
            }
            if (part.minX > legalMax) {
                rightLinePixels.push(...part.pixels);
                continue;
            }
            const target = legalFormBases
                .map((base, index) => ({ index, value: distance(part, base) }))
                .sort((left, right) => left.value - right.value)[0].index;
            legalPixels[target].push(...part.pixels);
        }

        const leftLine = layer(leftLinePixels);
        const rightLine = layer(rightLinePixels);
        const legalForm = legalPixels.map((pixels, index) => ({
            image: layer(pixels),
            ...legalFormBases[index],
        }));

        const clamp = (value) => Math.min(1, Math.max(0, value));
        const ease = (value) => 1 - ((1 - clamp(value)) ** 3);
        const materialize = (context, part, progress) => {
            if (progress <= 0) return;
            if (progress >= 1) {
                context.drawImage(part.image, 0, 0);
                return;
            }
            const eased = ease(progress);
            const centerX = (part.minX + part.maxX) / 2;
            const centerY = (part.minY + part.maxY) / 2;
            const pulse = 1 + (Math.sin(eased * Math.PI) * 0.025);
            const scale = (0.18 + (0.82 * eased)) * pulse;
            context.save();
            context.translate(centerX, centerY + ((1 - eased) * 5));
            context.scale(scale, scale);
            context.translate(-centerX, -centerY);
            context.filter = `blur(${(1 - eased) * 1.8}px)`;
            context.drawImage(part.image, 0, 0);
            context.restore();
        };

        const frames = [];
        for (let frame = 0; frame < frameCount; frame += 1) {
            const time = frame / (frameCount - 1);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d');

            letters.forEach((letter, index) => {
                materialize(context, letter, clamp((time - (0.02 + (index * 0.055))) / 0.10));
            });

            const lineProgress = ease((time - 0.54) / 0.10);
            if (lineProgress > 0 && legalFormBases.length) {
                context.save();
                context.beginPath();
                context.rect(
                    legalMin - ((legalMin + 1) * lineProgress),
                    separator,
                    (legalMin + 1) * lineProgress,
                    height - separator,
                );
                context.rect(
                    legalMax + 1,
                    separator,
                    (width - legalMax - 1) * lineProgress,
                    height - separator,
                );
                context.clip();
                context.drawImage(leftLine, 0, 0);
                context.drawImage(rightLine, 0, 0);
                context.restore();
            }

            legalForm.forEach((letter, index) => {
                materialize(context, letter, clamp((time - (0.66 + (index * 0.035))) / 0.08));
            });

            // Endphase exakt aus der hochaufgeloesten PNG-Quelle: keine
            // Transformationsweichheit und keine isolierten Restpixel.
            if (time >= 0.86) {
                context.clearRect(0, 0, width, height);
                context.drawImage(image, 0, 0);
            }

            frames.push(Array.from(context.getImageData(0, 0, width, height).data));
        }

        return { frames, width, height };
    }, { sourceData: source, frameCount: WORDMARK_FRAMES, expectedLetters: 8 });

    const frames = rendered.frames.map((frame) => new Uint8Array(frame));
    encodeGif(
        frames,
        rendered.width,
        rendered.height,
        `${name}.gif`,
        delays(WORDMARK_DURATION_CS, WORDMARK_FRAMES, WORDMARK_HOLD_CS),
    );
    writeContactSheet(
        frames,
        rendered.width,
        rendered.height,
        `${name}-phasen.png`,
        4,
        name.includes('dark'),
    );
}

const favicon = readFileSync('public/icons/favicon.svg', 'utf8');
const paths = [...favicon.matchAll(/<path d="([^"]+)"/g)].map((match) => match[1]);
if (paths.length !== 3) {
    throw new Error(`Erwartet drei Pfade im Favicon, gefunden ${paths.length}`);
}

for (const icon of ICONS) {
    const still = readFileSync(`${ASSETS}/${icon.key}.png`).toString('base64');
    const rendered = await page.evaluate(async ({ pathsData, stillData, frameCount, redStops, tStops }) => {
        const size = 132;
        const stillImage = new Image();
        await new Promise((resolve, reject) => {
            stillImage.onload = resolve;
            stillImage.onerror = reject;
            stillImage.src = `data:image/png;base64,${stillData}`;
        });

        const factor = (size / 1024) * 0.9;
        const margin = (size / 1024) * 60;
        const mappedBounds = [
            { minX: margin, maxX: margin + (1000 * factor), minY: margin, maxY: margin + (1000 * factor) },
            { minX: margin, maxX: margin + (205 * factor), minY: margin + (410 * factor), maxY: margin + (1000 * factor) },
            { minX: margin, maxX: margin + (670 * factor), minY: margin + (200 * factor), maxY: margin + (1000 * factor) },
        ];

        const makeLayer = (pathData, stops) => {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const context = canvas.getContext('2d');
            context.save();
            context.translate(margin, margin);
            context.scale(factor, factor);
            const gradient = context.createLinearGradient(0, 0, 1000, 1000);
            stops.forEach(([position, color]) => gradient.addColorStop(position, color));
            context.fillStyle = gradient;
            context.fill(new Path2D(pathData));
            context.restore();

            return canvas;
        };

        // Gewuenschte Reihenfolge: kleiner roter Schenkel, rotes Aussen-R,
        // T. Die statische Quelle zeichnet R vor dem Schenkel; fuer die
        // Animation ist die Reihenfolge bewusst vertauscht.
        const stages = [
            { image: makeLayer(pathsData[1], redStops), bounds: mappedBounds[1], start: 0.02, duration: 0.18, angle: -7 },
            { image: makeLayer(pathsData[0], redStops), bounds: mappedBounds[0], start: 0.22, duration: 0.20, angle: 3 },
            { image: makeLayer(pathsData[2], tStops), bounds: mappedBounds[2], start: 0.44, duration: 0.20, angle: -2 },
        ];

        const clamp = (value) => Math.min(1, Math.max(0, value));
        const ease = (value) => 1 - ((1 - clamp(value)) ** 3);
        const frames = [];
        for (let frame = 0; frame < frameCount; frame += 1) {
            const time = frame / (frameCount - 1);
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const context = canvas.getContext('2d');

            stages.forEach((stage) => {
                const raw = clamp((time - stage.start) / stage.duration);
                if (raw <= 0) return;
                if (raw >= 1) {
                    context.drawImage(stage.image, 0, 0);
                    return;
                }
                const progress = ease(raw);
                const centerX = (stage.bounds.minX + stage.bounds.maxX) / 2;
                const centerY = (stage.bounds.minY + stage.bounds.maxY) / 2;
                const pulse = 1 + (Math.sin(progress * Math.PI) * 0.035);
                const scale = (0.12 + (0.88 * progress)) * pulse;
                context.save();
                context.translate(centerX, centerY);
                context.rotate((stage.angle * (1 - progress) * Math.PI) / 180);
                context.scale(scale, scale);
                context.translate(-centerX, -centerY);
                context.filter = `blur(${(1 - progress) * 2}px)`;
                context.drawImage(stage.image, 0, 0);
                context.restore();
            });

            // Das Standbild und der GIF-Endzustand sind exakt identisch.
            if (time >= 0.72) {
                context.clearRect(0, 0, size, size);
                context.drawImage(stillImage, 0, 0, size, size);
            }
            frames.push(Array.from(context.getImageData(0, 0, size, size).data));
        }

        return { frames, width: size, height: size };
    }, {
        pathsData: paths,
        stillData: still,
        frameCount: ICON_FRAMES,
        redStops: RED_STOPS,
        tStops: icon.tStops,
    });

    const frames = rendered.frames.map((frame) => new Uint8Array(frame));
    encodeGif(
        frames,
        rendered.width,
        rendered.height,
        `${icon.key}.gif`,
        delays(ICON_DURATION_CS, ICON_FRAMES, ICON_HOLD_CS),
    );
    writeContactSheet(
        frames,
        rendered.width,
        rendered.height,
        `${icon.key}-phasen.png`,
        8,
        icon.key.includes('dark'),
    );
}

await browser.close();
console.log(`Nach ${PUBLIC_ASSETS} kopiert.`);
