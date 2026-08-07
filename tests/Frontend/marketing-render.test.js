import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import test from 'node:test';

const execFileAsync = promisify(execFile);
const projectRoot = path.resolve(import.meta.dirname, '../..');
const renderer = path.join(projectRoot, 'scripts', 'render-marketing-creative.mjs');

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
            html: '<section class="sample"><h1>RailTime</h1><p>Gemeinsam sicher auf der Schiene.</p></section>',
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
