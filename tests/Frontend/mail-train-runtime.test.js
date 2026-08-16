import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root));
const text = (path) => read(path).toString('utf8');

function skipSubBlocks(bytes, start) {
    let offset = start;
    while (offset < bytes.length) {
        const size = bytes[offset];
        offset += 1;
        if (size === 0) return offset;
        offset += size;
    }
    throw new Error('Unvollstaendige GIF-Subblocks.');
}

function gifTimeline(bytes) {
    assert.equal(bytes.subarray(0, 6).toString('ascii'), 'GIF89a');
    let offset = 13;
    const packed = bytes[10];
    if ((packed & 0x80) !== 0) offset += 3 * (1 << ((packed & 0x07) + 1));

    const delays = [];
    let pendingDelay = null;
    while (offset < bytes.length) {
        const marker = bytes[offset];
        offset += 1;
        if (marker === 0x3b) break;
        if (marker === 0x21) {
            const label = bytes[offset];
            offset += 1;
            if (label === 0xf9) {
                assert.equal(bytes[offset], 4);
                pendingDelay = bytes.readUInt16LE(offset + 2);
                offset += 6;
            } else {
                offset = skipSubBlocks(bytes, offset);
            }
            continue;
        }
        assert.equal(marker, 0x2c, `Unbekannter GIF-Marker 0x${marker.toString(16)}`);
        const imagePacked = bytes[offset + 8];
        offset += 9;
        if ((imagePacked & 0x80) !== 0) offset += 3 * (1 << ((imagePacked & 0x07) + 1));
        offset += 1; // LZW minimum code size
        offset = skipSubBlocks(bytes, offset);
        assert.notEqual(pendingDelay, null);
        delays.push(pendingDelay);
        pendingDelay = null;
    }

    return delays;
}

test('system mail projects one replayable train IMG and no cache endpoint', () => {
    const signature = text('app/Support/MailSignature.php');
    const carrier = text('app/Support/Mail/SignatureTrainCarrier.php');
    const cssSemantic = text('app/Support/Mail/CssSemantic.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');
    const routes = text('routes/api.php');

    assert.match(signature, /<img class="rt-train-main-image" data-rt-train-main-image/);
    assert.match(signature, /<span class="rt-train-main-layer" data-rt-train-main-layer/);
    assert.match(signature, /width:100%;height:0;max-height:0;overflow:visible/);
    assert.match(signature, /position:absolute;left:0;right:auto;bottom:0;z-index:0/);
    assert.match(signature, /width="100%"[\s\S]+?max-width:1815px;[\s\S]+?height:auto/);
    assert.match(signature, /<img class="rt-train-idle-surface rt-train-idle-image" data-rt-train-idle-image/);
    assert.match(signature, /\$idleSurface = str_contains\(\$html, 'data-rt-train-main-image'\)/);
    assert.match(signature, /<span class="rt-train-idle-surface"[\s\S]+?background-position:75% bottom;background-size:auto 100%/);
    assert.match(signature, /SignatureTrainCarrier::withoutMainLayer\(\$html\)/);
    assert.doesNotMatch(signature, /function injectClassicOutlookTrain/);
    assert.doesNotMatch(signature, /class="rt-classic-outlook-train"/);
    assert.match(signature, /str_contains\(\$html, 'data-rt-train-main-image'\)/);
    assert.match(carrier, /public static function withoutMainLayer\(string \$html\): string/);
    assert.match(carrier, /array_splice\(\$lists\[\$property\], \$mainIndex, 1\)/);
    for (const identifier of [
        'rt-train-main-image',
        'rt-train-main-layer',
        'rt-train-idle-image',
        'data-rt-train-main-image',
        'data-rt-train-main-layer',
        'data-rt-train-idle-overlay',
        'data-rt-train-idle-image',
    ]) {
        assert.match(cssSemantic, new RegExp(`'${identifier}'`));
    }
    assert.match(cssSemantic, /\$isProtectedAttribute = in_array\(/);
    assert.match(responsiveCss, /\.rt-train-main-image,\s*\.rt-train-idle-image\s*\{[\s\S]+?width: 100% !important;[\s\S]+?max-width: 1815px !important;[\s\S]+?height: auto !important;/);
    assert.match(responsiveCss, /\.rt-train-main-image\s*\{\s*z-index: 0 !important;\s*\}/);
    assert.match(responsiveCss, /\.rt-train-idle-image\s*\{\s*z-index: 1 !important;\s*\}/);
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.match(mobile, /\.rt-train-main-image,\s*\.rt-train-idle-image\s*\{[\s\S]+?left: -75% !important;[\s\S]+?width: 200% !important;[\s\S]+?max-width: none !important;[\s\S]+?height: auto !important;/);
    assert.doesNotMatch(routes, /mail-animations\/train/);
});

test('fluid main and idle IMG keep mobile and desktop train geometry', () => {
    const geometry = (containerWidth) => {
        const mobile = containerWidth <= 860;
        const displayedWidth = mobile ? containerWidth * 2 : Math.min(containerWidth, 1815);
        const left = mobile ? containerWidth * -0.75 : 0;

        return {
            displayedWidth,
            left,
            front: left + displayedWidth * 0.75,
            height: displayedWidth * 292 / 2880,
        };
    };

    assert.deepEqual(geometry(390), {
        displayedWidth: 780,
        left: -292.5,
        front: 292.5,
        height: 79.08333333333333,
    });
    assert.deepEqual(geometry(1440), {
        displayedWidth: 1440,
        left: 0,
        front: 1080,
        height: 146,
    });
    assert.deepEqual(geometry(1920), {
        displayedWidth: 1815,
        left: 0,
        front: 1361.25,
        height: 184.02083333333334,
    });
    assert.deepEqual(geometry(2560), geometry(1920));
});

test('train GIF starts quickly but preserves the 13 second handoff', () => {
    for (const theme of ['light', 'dark']) {
        const resource = read(`resources/mail-templates/assets/zug-dampf-${theme}.gif`);
        const publicCopy = read(`public/mail-assets/zug-dampf-${theme}.gif`);
        const outlook = read(`resources/mail-templates/assets/zug-dampf-outlook-${theme}.gif`);

        assert.deepEqual(resource, publicCopy);
        for (const bytes of [resource, outlook]) {
            const delays = gifTimeline(bytes);
            assert.equal(delays.length, 72);
            assert.equal(delays[0], 30);
            assert.equal(delays.reduce((sum, delay) => sum + delay, 0), 1300);
            const starts = [];
            delays.reduce((elapsed, delay) => {
                starts.push(elapsed);
                return elapsed + delay;
            }, 0);
            assert.equal(starts[2], 44);
            assert.equal(starts[52], 735);
            assert.equal(starts[53], 764);
            assert.equal(bytes.includes(Buffer.from('NETSCAPE2.0')), false);
        }
    }
});
