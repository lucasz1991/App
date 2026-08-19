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

test('all delivered mail outputs use one regular train image', () => {
    const signature = text('app/Support/MailSignature.php');
    const signatureView = text('resources/views/emails/parts/signature.blade.php');
    const carrier = text('app/Support/Mail/SignatureTrainCarrier.php');
    const previewService = text('app/Services/PageBuilder/PageBuilderPreviewService.php');
    const cssSemantic = text('app/Support/Mail/CssSemantic.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');
    const routes = text('routes/api.php');

    assert.match(signature, /SignatureTrainCarrier::projectAsImage\(/);
    assert.doesNotMatch(signature, /data-rt-train-idle|injectDelayedIdleOverlay|TRAIN_IDLE_SRC' => \([^\n]+zug-dampf-idle/);
    assert.match(signature, /\$tokenizedTrainCarrier = \$this->usesTokenizedTrainCarrier\(\$values, \$layout\);/);
    assert.match(signature, /\$html = \$this->projectPublishedTrainAsImage\(\$html, \$singleTrainLayout\);/);
    assert.doesNotMatch(signature, /function appendClassicOutlookTrainFallback/);
    assert.doesNotMatch(signature, /<!--\[if mso\]><tr><td align="left"/);
    assert.doesNotMatch(signature, /class="rt-classic-outlook-train"/);
    assert.match(carrier, /public static function withoutMainLayer/);
    assert.match(carrier, /public static function projectAsImage/);
    assert.match(carrier, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train"/);
    assert.match(carrier, /style="position:absolute;left:0;right:auto;top:0;bottom:0;/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="1815"/);
    assert.match(previewService, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(cssSemantic, /'data-rt-train-idle-overlay'/);
    assert.match(cssSemantic, /\$isProtectedAttribute = in_array\(/);
    assert.doesNotMatch(responsiveCss, /rt-train-idle|rt-train-idle-reveal/);
    assert.doesNotMatch(routes, /mail-animations\/train/);
    assert.match(signatureView, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}"/);
    assert.match(signatureView, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;/);
    assert.doesNotMatch(signatureView, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.match(
        signatureView,
        /<td class="rt-sign-cell"[^>]+style="padding:0;position:relative;overflow:hidden;/,
    );
    assert.match(
        signatureView,
        /<td class="rt-pad rt-sign-content" style="padding:\{\{ \$padding \}\};position:relative;z-index:1;">/,
    );
    assert.doesNotMatch(signatureView, /class="rt-pad rt-sign-cell"/);
    assert.doesNotMatch(signatureView, /\$cellPadding/);
    assert.match(signatureView, /\$padding = \$padding \?\? '18px 36px 0';/);
    assert.match(signatureView, /\$outlookTrainPadding = \$outlookTrainPadding \?\? '0';/);
    assert.match(carrier, /compactDefaultContentPadding/);
    assert.match(carrier, /'padding:18px 36px 20px;' => 'padding:18px 36px 0;'/);
    assert.match(responsiveCss, /\.rt-sign-content \{ padding-bottom: 0 !important; \}/);
});

test('editor and delivery use the same regular train image without idle layer', () => {
    const signatureView = text('resources/views/emails/parts/signature.blade.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');

    assert.match(signatureView, /\$trainSrc = \$outlookTrainSrc !== ''/);
    assert.match(signatureView, /position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto/);
    assert.doesNotMatch(signatureView, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.match(mobile, /\.rt-sign-cell\s*\{[\s\S]+?background-position: left top, right center, center center !important;[\s\S]+?background-size: 64px 64px, auto 52%, 100% 100% !important;/);
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[\s\S]+?left: -75% !important;[\s\S]+?width: 200% !important;/);
    assert.doesNotMatch(responsiveCss, /rt-train-idle/);
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
