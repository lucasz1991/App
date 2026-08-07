import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import test from 'node:test';
import pngjs from 'pngjs';

const execFileAsync = promisify(execFile);
const projectRoot = path.resolve(import.meta.dirname, '../..');
const renderer = path.join(projectRoot, 'scripts', 'render-marketing-creative.mjs');
const { PNG } = pngjs;

async function existingChrome() {
    const candidates = [
        process.env.MARKETING_CHROME_PATH,
        process.env.PUPPETEER_EXECUTABLE_PATH,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ].filter(Boolean);

    for (const candidate of candidates) {
        try {
            await fs.access(candidate);
            return candidate;
        } catch {
            // Keep looking for a configured local browser.
        }
    }

    return null;
}

test('marketing renderer creates an exact story PNG with draft protection', async (t) => {
    const chrome = await existingChrome();
    if (!chrome) {
        t.skip('Chrome/Chromium is not installed in this test environment.');
        return;
    }

    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-marketing-render-'));
    const input = path.join(directory, 'input.json');
    const output = path.join(directory, 'story.png');

    try {
        await fs.writeFile(input, JSON.stringify({
            html: '<section class="sample"><h1>RailTime</h1><p>Gemeinsam sicher auf der Schiene.</p><a href="https://www.rail-time.de/de/karriere">Karriere</a></section>',
            css: '.sample{width:100%;height:100%;padding:80px;background:#09111b;color:#fff}.sample h1{color:#e4002b;font-size:92px}',
            watermark: true,
        }));

        const { stdout, stderr } = await execFileAsync(process.execPath, [
            renderer,
            '--input', input,
            '--output', output,
            '--width', '1080',
            '--height', '1920',
            '--chrome', chrome,
        ], { cwd: projectRoot, timeout: 45_000 });

        assert.equal(stderr, '');
        assert.equal(JSON.parse(stdout).ok, true);

        const png = await fs.readFile(output);
        assert.equal(png.subarray(1, 4).toString('ascii'), 'PNG');
        assert.equal(png.readUInt32BE(16), 1080);
        assert.equal(png.readUInt32BE(20), 1920);
        assert.ok(png.length > 20_000);
    } finally {
        await fs.rm(directory, { recursive: true, force: true });
    }
});

test('marketing renderer rejects dimensions outside the three product formats', async () => {
    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-marketing-render-invalid-'));
    const input = path.join(directory, 'input.json');
    const output = path.join(directory, 'invalid.png');

    try {
        await fs.writeFile(input, JSON.stringify({ html: '<p>invalid</p>', css: '' }));
        await assert.rejects(
            execFileAsync(process.execPath, [
                renderer,
                '--input', input,
                '--output', output,
                '--width', '800',
                '--height', '600',
            ], { cwd: projectRoot, timeout: 10_000 }),
            /Invalid renderer arguments or unsupported artboard dimensions/,
        );
    } finally {
        await fs.rm(directory, { recursive: true, force: true });
    }
});

test('marketing renderer rejects file URLs after trusted assets were hydrated', async () => {
    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-marketing-render-file-block-'));
    const secret = path.join(directory, 'secret.svg');
    const input = path.join(directory, 'input.json');
    const output = path.join(directory, 'blocked.png');

    try {
        await fs.writeFile(secret, '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#00ff00"/></svg>');
        const secretUrl = new URL(`file:///${secret.replaceAll('\\\\', '/')}`).href;
        await fs.writeFile(input, JSON.stringify({
            html: `<div class="sample"><img src="${secretUrl}" alt=""><span></span></div>`,
            css: '.sample{width:100%;height:100%;background:#e4002b}.sample img{position:absolute;width:100%;height:100%}',
            base_url: new URL(`file:///${directory.replaceAll('\\\\', '/')}/`).href,
        }));

        await assert.rejects(
            execFileAsync(process.execPath, [
                renderer,
                '--input', input,
                '--output', output,
                '--width', '1200',
                '--height', '630',
            ], { cwd: projectRoot, timeout: 10_000 }),
            /Local file URLs are prohibited/,
        );
        await assert.rejects(fs.access(output));
        // Der geheime grüne SVG-Inhalt darf nicht geladen worden sein; die rote Fläche bleibt sichtbar.
    } finally {
        await fs.rm(directory, { recursive: true, force: true });
    }
});

test('marketing renderer fails instead of exporting a blank image for blocked remote resources', async (t) => {
    const chrome = await existingChrome();
    if (!chrome) {
        t.skip('Chrome/Chromium is not installed in this test environment.');
        return;
    }

    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-marketing-render-remote-block-'));
    const input = path.join(directory, 'input.json');
    const output = path.join(directory, 'blocked.png');

    try {
        await fs.writeFile(input, JSON.stringify({
            html: '<div class="sample"><img src="https://attacker.invalid/pixel.png" alt=""></div>',
            css: '.sample{width:100%;height:100%;background:#e4002b}',
        }));

        await assert.rejects(
            execFileAsync(process.execPath, [
                renderer,
                '--input', input,
                '--output', output,
                '--width', '1200',
                '--height', '630',
                '--chrome', chrome,
            ], { cwd: projectRoot, timeout: 45_000 }),
            (error) => String(error?.stderr || error).includes('Blocked resource request detected'),
        );
        await assert.rejects(fs.access(output));
    } finally {
        await fs.rm(directory, { recursive: true, force: true });
    }
});

test('marketing renderer fails when an inline image cannot be decoded', async (t) => {
    const chrome = await existingChrome();
    if (!chrome) {
        t.skip('Chrome/Chromium is not installed in this test environment.');
        return;
    }

    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-marketing-render-invalid-image-'));
    const input = path.join(directory, 'input.json');
    const output = path.join(directory, 'invalid-image.png');

    try {
        await fs.writeFile(input, JSON.stringify({
            html: '<div class="sample"><img src="data:image/png;base64,aW52YWxpZA==" alt=""></div>',
            css: '.sample{width:100%;height:100%;background:#fff}',
        }));

        await assert.rejects(
            execFileAsync(process.execPath, [
                renderer,
                '--input', input,
                '--output', output,
                '--width', '1200',
                '--height', '630',
                '--chrome', chrome,
            ], { cwd: projectRoot, timeout: 20_000 }),
            (error) => String(error?.stderr || error).includes('Image resource failed to load'),
        );
        await assert.rejects(fs.access(output));
    } finally {
        await fs.rm(directory, { recursive: true, force: true });
    }
});

test('draft watermark remains visible when creative CSS tries to hide it', async (t) => {
    const chrome = await existingChrome();
    if (!chrome) {
        t.skip('Chrome/Chromium is not installed in this test environment.');
        return;
    }

    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-marketing-render-watermark-'));
    const input = path.join(directory, 'input.json');
    const output = path.join(directory, 'watermark.png');

    try {
        await fs.writeFile(input, JSON.stringify({
            html: '<section class="sample">Manipulationsversuch</section>',
            css: '*{display:none!important;opacity:0!important;visibility:hidden!important;clip-path:inset(100%)!important;transform:translate(-99999px,-99999px)!important}.rt-marketing-watermark{display:none!important}',
            watermark: true,
        }));

        await execFileAsync(process.execPath, [
            renderer,
            '--input', input,
            '--output', output,
            '--width', '1200',
            '--height', '630',
            '--chrome', chrome,
        ], { cwd: projectRoot, timeout: 20_000 });

        const png = PNG.sync.read(await fs.readFile(output));
        let watermarkPixels = 0;
        for (let offset = 0; offset < png.data.length; offset += 4) {
            const red = png.data[offset];
            const green = png.data[offset + 1];
            const blue = png.data[offset + 2];
            if (red > 150 && green < 100 && blue < 120) {
                watermarkPixels++;
            }
        }

        assert.ok(watermarkPixels > 10_000, 'expected a substantial visible red watermark overlay');
    } finally {
        await fs.rm(directory, { recursive: true, force: true });
    }
});
