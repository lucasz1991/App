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

test('all delivered mail outputs keep train GIFs as delayed non-flowing images with an in-carrier MSO image fallback', () => {
    const signature = text('app/Support/MailSignature.php');
    const signatureView = text('resources/views/emails/parts/signature.blade.php');
    const carrier = text('app/Support/Mail/SignatureTrainCarrier.php');
    const previewService = text('app/Services/PageBuilder/PageBuilderPreviewService.php');
    const builder = text('app/Support/EmailTemplateBuilder.php');
    const cssSemantic = text('app/Support/Mail/CssSemantic.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');
    const routes = text('routes/api.php');
    const msoFallback = carrier.slice(
        carrier.indexOf('public static function withMsoFallback'),
        carrier.indexOf('public static function withIdleOverlay'),
    );

    assert.match(signature, /SignatureTrainCarrier::projectAsImage\(/);
    assert.doesNotMatch(signature, /SignatureTrainCarrier::projectAsRuntimeBackground\(/);
    assert.equal((signature.match(/SignatureTrainCarrier::withMsoFallback\(/g) || []).length, 1);
    assert.match(signature, /\$outlookFallbackSource = trim\(\(string\) \([\s\S]+?\$values\['TRAIN_STILL_SRC'\]/);
    assert.match(signature, /zug-dampf-idle-/);
    assert.match(signature, /SignatureTrainCarrier::withIdleOverlay\(/);
    assert.match(signature, /\$tokenizedTrainCarrier = \$this->usesTokenizedTrainCarrier\(\$values, \$layout\);/);
    assert.match(signature, /\$html = \$this->projectPublishedTrainAsImage\(\$html, \$singleTrainLayout\);/);
    assert.doesNotMatch(signature, /function appendClassicOutlookTrainFallback/);
    assert.doesNotMatch(signature, /class="rt-classic-outlook-train"/);
    assert.match(carrier, /public static function withoutMainLayer/);
    assert.match(carrier, /public static function projectAsImage/);
    assert.doesNotMatch(carrier, /public static function projectAsRuntimeBackground/);
    assert.match(carrier, /public static function withMsoFallback/);
    assert.match(carrier, /public static function withIdleOverlay/);
    assert.match(carrier, /<img class="rt-train-idle-overlay rt-train-idle-image" data-rt-train-idle-overlay data-rt-train-idle-image[\s\S]*?src="'\.\$escapedSource\.'"/);
    assert.doesNotMatch(carrier, /background-image:[^;]*(?:TRAIN|train|\.gif)/);
    assert.doesNotMatch(carrier, /<!--\[if mso\]><tr><td class="rt-sign-train-mso"/);
    assert.match(carrier, /<!--\[if (?:gte )?mso(?: 9)?\]><img class="rt-sign-train-mso" data-rt-train-mso="1" src="'\.\$escapedSource\.'"/);
    assert.match(carrier, /rt-sign-train-mso[\s\S]*?width="720"[\s\S]*?style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto;/);
    assert.doesNotMatch(carrier, /rt-sign-train-mso[\s\S]*?width:720px;max-width:100%/);
    assert.match(carrier, /<!\[endif\]-->/);
    assert.match(msoFallback, /<img\b/);
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);
    assert.match(carrier, /<div class="rt-sign-stage" style="position:relative;overflow:hidden;">/);
    assert.match(carrier, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train"/);
    assert.match(carrier, /style="position:absolute;left:0;right:auto;top:0;bottom:0;[^"\r\n]*mso-hide:all;/);
    assert.match(carrier, /<img class="rt-sign-train" data-rt-train src="'\.\$source\.'" width="720"[^>]*style="[^"\r\n]*mso-hide:all;/);
    assert.match(previewService, /MailSignature::forCompany\(/);
    assert.match(previewService, /\$signatureRenderer->renderDocument\(/);
    assert.doesNotMatch(previewService, /SignatureTrainCarrier::projectAsImage\(/);
    assert.match(builder, /function placeBrowserCopyTrainBehindContent/);
    assert.match(builder, /SignatureTrainCarrier::assertRuntimeImages\(/);
    assert.doesNotMatch(
        builder.slice(
            builder.indexOf('function placeBrowserCopyTrainBehindContent'),
            builder.indexOf('private static function imageSources'),
        ),
        /\$trainImages|\$trainLayers|data-rt-layer-train/,
    );
    assert.match(cssSemantic, /'data-rt-train-idle-overlay'/);
    assert.match(cssSemantic, /'data-rt-train-idle-image'/);
    assert.match(cssSemantic, /\$isProtectedAttribute = in_array\(/);
    assert.match(responsiveCss, /@keyframes rt-train-idle-reveal/);
    assert.match(responsiveCss, /animation-delay:\s*13s/);
    assert.match(responsiveCss, /prefers-reduced-motion:[^)]+\)[\s\S]*?\.rt-train-idle-overlay/);
    assert.doesNotMatch(routes, /mail-animations\/train/);
    assert.match(signatureView, /<div class="rt-sign-stage" style="position:relative;overflow:hidden;">/);
    assert.match(signatureView, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}" width="720"[^>]+mso-hide:all;/);
    assert.match(signatureView, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;[^"\r\n]*mso-hide:all;/);
    assert.doesNotMatch(signatureView, /rt-sign-train-layer[^>]*height:100%/);
    assert.doesNotMatch(carrier, /rt-train-idle-overlay[^>]*height:100%/);
    assert.match(carrier, /rt-train-idle-overlay[^>]*position:absolute;[^>]*display:block;[^>]*height:auto;/);
    assert.doesNotMatch(signatureView, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.match(
        signatureView,
        /<td class="rt-sign-cell"[^>]+style="padding:0;overflow:hidden;/,
    );
    assert.doesNotMatch(signatureView, /<td class="rt-sign-cell"[^>]+position:relative/);
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
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.doesNotMatch(responsiveCss, /\.rt-sign-cell\.rt-sign-train-background/);
    assert.match(mobile, /\.rt-sign-cell\s*\{[\s\S]+?background-position:\s*left top,\s*right center,\s*center center !important;[\s\S]+?background-size:\s*64px 64px,\s*auto 52%,\s*100% 100% !important;/);
});

test('editor and delivery keep the default mobile train at 100 percent while explicit crops may zoom', () => {
    const signatureView = text('resources/views/emails/parts/signature.blade.php');
    const responsiveCss = text('resources/views/emails/parts/responsive-css.blade.php');

    assert.match(signatureView, /\$trainSrc = \$outlookTrainSrc !== ''/);
    assert.match(signatureView, /position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto/);
    assert.doesNotMatch(signatureView, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    const mobile = responsiveCss.slice(responsiveCss.indexOf('@media only screen and (max-width: 860px)'));
    assert.match(mobile, /\.rt-sign-cell\s*\{[\s\S]+?background-position: left top, right center, center center !important;[\s\S]+?background-size: 64px 64px, auto 52%, 100% 100% !important;/);
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[\s\S]+?left: 0 !important;[\s\S]+?width: 100% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="train"[^}]+left: 0 !important;[^}]+width: 100% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="left"[^}]+left: 0 !important;[^}]+width: 200% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="center"[^}]+left: -50% !important;[^}]+width: 200% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="right"[^}]+left: -100% !important;[^}]+width: 200% !important;/);
    assert.match(responsiveCss, /rt-train-idle-reveal/);
});

test('train GIF starts quickly but preserves the 13 second handoff', () => {
    for (const theme of ['light', 'dark']) {
        const resource = read(`resources/mail-templates/assets/zug-dampf-${theme}.gif`);
        const publicCopy = read(`public/mail-assets/zug-dampf-${theme}.gif`);
        const outlook = read(`resources/mail-templates/assets/zug-dampf-outlook-${theme}.gif`);
        const idle = read(`resources/mail-templates/assets/zug-dampf-idle-${theme}.gif`);
        const idlePublic = read(`public/mail-assets/zug-dampf-idle-${theme}.gif`);

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
        assert.deepEqual(idle, idlePublic);
        const idleDelays = gifTimeline(idle);
        assert.equal(idleDelays.length, 20);
        assert.equal(idleDelays.reduce((sum, delay) => sum + delay, 0), 200);
        assert.equal(idle.includes(Buffer.from('NETSCAPE2.0')), true);
    }
});
