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
    const watermark = payload.draft === true || payload.watermark === true
        ? '<div class="rt-marketing-watermark" aria-hidden="true">ENTWURF – NICHT VERÖFFENTLICHEN</div>'
        : '';

    return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: file: blob:; style-src 'unsafe-inline' data: file:; font-src data: file:;">
${base}
<style>
*{box-sizing:border-box}html,body{width:${width}px;height:${height}px;margin:0;overflow:hidden;background:#fff}body{font-family:Arial,Helvetica,sans-serif}
#rt-marketing-artboard{position:relative;width:${width}px;height:${height}px;overflow:hidden;isolation:isolate}
.rt-marketing-watermark{position:absolute;z-index:2147483647;left:-13%;top:43%;width:126%;padding:18px 0;background:rgba(177,0,33,.86);color:#fff;font:900 34px/1 Arial,sans-serif;letter-spacing:5px;text-align:center;transform:rotate(-18deg);pointer-events:none}
${payload.css ?? ''}
</style>
</head>
<body><main id="rt-marketing-artboard">${payload.html ?? ''}${watermark}</main></body>
</html>`;
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

    try {
        const page = await browser.newPage();
        await page.setViewport({ width, height, deviceScaleFactor: 1 });
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const protocol = new URL(request.url()).protocol;
            if (['http:', 'https:', 'ws:', 'wss:'].includes(protocol)) {
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
        await page.evaluate(async () => {
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
                });
            }));
        });
        await page.screenshot({
            path: outputPath,
            type: 'png',
            clip: { x: 0, y: 0, width, height },
            captureBeyondViewport: false,
        });
    } finally {
        await browser.close();
    }

    const stat = await fs.stat(outputPath);
    process.stdout.write(`${JSON.stringify({ ok: true, width, height, bytes: stat.size })}\n`);
}

main().catch((error) => {
    process.stderr.write(`Marketing render failed: ${error instanceof Error ? error.message : 'unknown error'}\n`);
    process.exitCode = 1;
});
