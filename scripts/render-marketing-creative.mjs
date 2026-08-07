import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import puppeteer from 'puppeteer-core';

const allowedDimensions = new Set(['1080x1920', '1080x1080', '1200x630']);

function argument(name, fallback = null) {
    const index = process.argv.indexOf(`--${name}`);

    return index === -1 ? fallback : process.argv[index + 1];
}

function flag(name) {
    return process.argv.includes(`--${name}`);
}

async function firstExisting(candidates) {
    for (const candidate of candidates.filter(Boolean)) {
        try {
            await fs.access(candidate);
            return candidate;
        } catch {
            // Try the next explicitly known executable.
        }
    }

    return null;
}

function knownChromePaths() {
    if (process.platform === 'win32') {
        return [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        ];
    }

    if (process.platform === 'darwin') {
        return [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        ];
    }

    return [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function documentHtml(payload, width, height) {
    const base = payload.base_url
        ? `<base href="${escapeHtml(payload.base_url)}">`
        : '';

    return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: blob: http: https: file:; style-src 'unsafe-inline' data:; font-src data: http: https: file:;">
${base}
<style>
*{box-sizing:border-box}html,body{width:${width}px;height:${height}px;margin:0;overflow:hidden;background:#fff}body{font-family:Arial,Helvetica,sans-serif}
#rt-marketing-artboard{position:relative;width:${width}px;height:${height}px;overflow:hidden;isolation:isolate}
${payload.css ?? ''}
</style>
</head>
<body><main id="rt-marketing-artboard">${payload.html ?? ''}</main></body>
</html>`;
}

function watermarkDocument(artwork, width, height) {
    return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline';">
<style>
*{box-sizing:border-box}html,body{width:${width}px;height:${height}px;margin:0;overflow:hidden;background:#fff}
#rt-watermark-composite{position:relative;width:${width}px;height:${height}px;overflow:hidden;isolation:isolate}
#rt-watermark-artwork{display:block;width:100%;height:100%;object-fit:fill}
#rt-watermark-overlay{position:absolute;z-index:2147483647;left:-13%;top:43%;display:block;width:126%;padding:18px 0;background:rgba(177,0,33,.86);color:#fff;font:900 34px/1 Arial,sans-serif;letter-spacing:5px;text-align:center;opacity:1;visibility:visible;transform:rotate(-18deg);clip:auto;clip-path:none;filter:none;pointer-events:none}
</style>
</head>
<body><main id="rt-watermark-composite"><img id="rt-watermark-artwork" src="data:image/png;base64,${artwork}" alt=""><div id="rt-watermark-overlay" aria-hidden="true">ENTWURF – NICHT VERÖFFENTLICHEN</div></main></body>
</html>`;
}

function decodeEntities(value) {
    return value
        .replace(/&#x([0-9a-f]+);?/giu, (_, codepoint) => String.fromCodePoint(Number.parseInt(codepoint, 16)))
        .replace(/&#([0-9]+);?/gu, (_, codepoint) => String.fromCodePoint(Number.parseInt(codepoint, 10)))
        .replace(/&colon;/giu, ':')
        .replace(/&sol;/giu, '/')
        .replace(/&bsol;/giu, '\\');
}

function hasBlockedResourceReference(payload) {
    const html = String(payload.html ?? '');
    const css = String(payload.css ?? '');
    const resourceAttribute = /\b(?:src|poster|background|data|srcset|imagesrcset)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/giu;

    for (const match of html.matchAll(resourceAttribute)) {
        const value = decodeEntities(match[1] ?? match[2] ?? match[3] ?? '')
            .replace(/[\u0000-\u0020\u007f]+/gu, '')
            .toLowerCase();
        if (value !== '' && !value.startsWith('data:')) {
            return true;
        }
    }

    for (const match of css.matchAll(/url\s*\(\s*(?:"([^"]*)"|'([^']*)'|([^)]*))\s*\)/giu)) {
        const value = decodeEntities(match[1] ?? match[2] ?? match[3] ?? '')
            .replace(/[\u0000-\u0020\u007f]+/gu, '')
            .toLowerCase();
        if (value !== '' && !value.startsWith('data:')) {
            return true;
        }
    }

    return /@(?:import|charset|namespace)\b/iu.test(css);
}

async function closeBrowser(browser) {
    let timeoutId;
    const timeout = new Promise((_, reject) => {
        timeoutId = setTimeout(() => reject(new Error('Browser close timeout.')), 3_000);
    });

    try {
        await Promise.race([browser.close(), timeout]);
    } catch {
        browser.process()?.kill('SIGKILL');
    } finally {
        clearTimeout(timeoutId);
    }
}

async function main() {
    const inputPath = argument('input');
    const outputPath = argument('output');
    const width = Number.parseInt(argument('width', ''), 10);
    const height = Number.parseInt(argument('height', ''), 10);

    if (!inputPath || !outputPath || !allowedDimensions.has(`${width}x${height}`)) {
        throw new Error('Invalid renderer arguments or unsupported artboard dimensions.');
    }

    const payload = JSON.parse(await fs.readFile(inputPath, 'utf8'));
    if (/\bfile\s*:/iu.test(`${payload.html ?? ''}\n${payload.css ?? ''}`)) {
        throw new Error('Local file URLs are prohibited in marketing render content.');
    }
    if (hasBlockedResourceReference(payload)) {
        throw new Error('Blocked resource request detected during renderer preflight.');
    }
    const executablePath = await firstExisting([
        argument('chrome'),
        process.env.MARKETING_CHROME_PATH,
        process.env.PUPPETEER_EXECUTABLE_PATH,
        ...knownChromePaths(),
    ]);

    if (!executablePath) {
        throw new Error('No Chrome or Chromium executable was configured or found.');
    }

    await fs.mkdir(path.dirname(outputPath), { recursive: true });

    const browser = await puppeteer.launch({
        executablePath,
        headless: true,
        args: flag('no-sandbox') ? ['--no-sandbox', '--disable-setuid-sandbox'] : [],
    });
    const blockedResourceProtocols = [];

    try {
        const page = await browser.newPage();
        await page.setViewport({ width, height, deviceScaleFactor: 1 });
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const protocol = new URL(request.url()).protocol;
            if (['http:', 'https:', 'ws:', 'wss:', 'file:'].includes(protocol)) {
                blockedResourceProtocols.push(protocol);
                request.abort('blockedbyclient');
                return;
            }

            request.continue();
        });
        await page.setJavaScriptEnabled(false);
        await page.setContent(documentHtml(payload, width, height), {
            waitUntil: 'domcontentloaded',
            timeout: 30_000,
        });
        if (blockedResourceProtocols.length > 0) {
            throw new Error(`Blocked resource request detected (${[...new Set(blockedResourceProtocols)].join(', ')}).`);
        }
        const invalidImages = await page.evaluate(async () => {
            if (document.fonts?.ready) {
                await document.fonts.ready;
            }

            await Promise.all([...document.images].map((image) => {
                if (image.complete) {
                    return Promise.resolve();
                }

                return new Promise((resolve) => {
                    image.addEventListener('load', resolve, { once: true });
                    image.addEventListener('error', resolve, { once: true });
                    setTimeout(resolve, 2_000);
                });
            }));

            return [...document.images]
                .filter((image) => !image.complete || image.naturalWidth < 1 || image.naturalHeight < 1)
                .map((image) => image.currentSrc || image.src || '[empty image source]');
        });
        if (invalidImages.length > 0) {
            throw new Error(`Image resource failed to load (${invalidImages.length}).`);
        }
        if (blockedResourceProtocols.length > 0) {
            throw new Error(`Blocked resource request detected (${[...new Set(blockedResourceProtocols)].join(', ')}).`);
        }
        const screenshotOptions = {
            type: 'png',
            clip: { x: 0, y: 0, width, height },
            captureBeyondViewport: false,
        };
        if (payload.draft === true || payload.watermark === true) {
            const artwork = await page.screenshot(screenshotOptions);
            const compositePage = await browser.newPage();
            try {
                await compositePage.setViewport({ width, height, deviceScaleFactor: 1 });
                await compositePage.setJavaScriptEnabled(false);
                await compositePage.setContent(watermarkDocument(Buffer.from(artwork).toString('base64'), width, height), {
                    waitUntil: 'load',
                    timeout: 10_000,
                });
                const watermarkVisible = await compositePage.$eval('#rt-watermark-overlay', (element) => {
                    const style = getComputedStyle(element);
                    const bounds = element.getBoundingClientRect();

                    return style.display !== 'none'
                        && style.visibility === 'visible'
                        && Number.parseFloat(style.opacity) > 0.99
                        && bounds.width > 0
                        && bounds.height > 0;
                });
                if (!watermarkVisible) {
                    throw new Error('Draft watermark verification failed.');
                }
                await compositePage.screenshot({ ...screenshotOptions, path: outputPath });
            } finally {
                await compositePage.close();
            }
        } else {
            await page.screenshot({ ...screenshotOptions, path: outputPath });
        }
    } finally {
        await closeBrowser(browser);
    }

    const stat = await fs.stat(outputPath);
    process.stdout.write(`${JSON.stringify({ ok: true, width, height, bytes: stat.size })}\n`);
}

main().catch((error) => {
    process.stderr.write(`Marketing render failed: ${error instanceof Error ? error.message : 'unknown error'}\n`);
    process.exitCode = 1;
});
